#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
E-Barimt POS API Service

A Tornado-based service that facilitates integration with the Mongolian
E-Barimt electronic receipt system. This service handles receipt generation,
verification, and management.

Author: [Your Name]
Date: February 27, 2025
"""

import json
import logging
import os
import sys
import re
import uuid
import sqlite3
import datetime
from concurrent.futures import ThreadPoolExecutor
from logging.handlers import RotatingFileHandler

import requests
import tornado.web
import tornado.httpserver
import tornado.ioloop
import tornado.gen
from tornado.options import define, options, parse_command_line
from tornado.concurrent import run_on_executor
import peewee

# =============================================================================
# Configuration
# =============================================================================

define('port', default=8888, help='Server port')
define('request_timeout', default=15, help='Request timeout in seconds')
define('ebarimt_reg_no_url', default='https://api.ebarimt.mn/api/info/check/getTinInfo?regNo=', 
       help='URL for registration number verification')
define('ebarimt_tin_url', default='https://api.ebarimt.mn/api/info/check/getInfo?tin=',
       help='URL for TIN verification')
define('docker_url', default='http://localhost:12345/', help='E-Barimt service URL')
define('company_register', default='0000038', help='Company registration number')
define('company_merchant_tin', default='77100185651', help='Company merchant TIN')
define('bank_id', default='15', help='Bank ID')
define('ebarimt_group_enabled', default=True, help='Enable group e-barimt')
define('debug', default=False, help='Enable debug mode')
define('db_host', default='10.10.90.231', help='Database host')
define('db_port', default=3306, help='Database port')
define('db_name', default='ebarimt3_db', help='Database name')
define('db_user', default='ebarimt_user', help='Database user')
define('db_password', default='Ebarimt_2022.', help='Database password')

# =============================================================================
# Environment Setup
# =============================================================================

# Determine base directory
if getattr(sys, 'frozen', False):
    BASE_DIR = os.path.dirname(sys.executable)
else:
    BASE_DIR = os.path.dirname(os.path.abspath(sys.argv[0]))

# Configure logging
LOG_DIR = os.path.join(BASE_DIR, 'logs')
if not os.path.exists(LOG_DIR):
    os.makedirs(LOG_DIR)

LOG_FILE = os.path.join(LOG_DIR, 'ebarimt_service.log')
handler = RotatingFileHandler(LOG_FILE, maxBytes=10 * 1024 * 1024, backupCount=50, encoding='utf-8')
formatter = logging.Formatter('%(asctime)s [%(levelname)s] %(message)s')
handler.setFormatter(formatter)

logger = logging.getLogger('ebarimt')
logger.setLevel(logging.INFO)
logger.addHandler(handler)
logger.propagate = False

# Add console handler in debug mode
console_handler = logging.StreamHandler()
console_handler.setFormatter(formatter)
console_handler.setLevel(logging.DEBUG)
logger.addHandler(console_handler)

# Constants
MAX_WORKERS = 100
REQUEST_COUNTER = 0  # Counter for request tracking

# =============================================================================
# Database Models
# =============================================================================

class EbarimtDatabase:
    """Database connection manager"""
    
    _instance = None
    _connection = None
    
    @classmethod
    def get_instance(cls):
        """Get database singleton instance"""
        if cls._instance is None:
            cls._instance = cls()
        return cls._instance
        
    def __init__(self):
        """Initialize database connection"""
        self.database = peewee.MySQLDatabase(
            options.db_name,
            host=options.db_host,
            port=options.db_port,
            user=options.db_user,
            password=options.db_password
        )
        
    def connect(self):
        """Connect to the database"""
        if self.database.is_closed():
            self.database.connect()
            
    def close(self):
        """Close the database connection"""
        if not self.database.is_closed():
            self.database.close()
            
    def get_db(self):
        """Get the database instance"""
        return self.database


class BaseModel(peewee.Model):
    """Base model for all database models"""
    
    class Meta:
        database = EbarimtDatabase.get_instance().get_db()
        

class Category(BaseModel):
    """Model for product category mapping"""
    
    bgf_code = peewee.CharField(unique=True)
    ebarimt_code = peewee.CharField(default="24")
    company_reg = peewee.CharField(default="")
    percent = peewee.FloatField(default=0.0)
    
    class Meta:
        table_name = 'category'


class GroupBill(BaseModel):
    """Model for group bill information"""
    
    bar_code = peewee.CharField(max_length=255, null=True)
    group_tin = peewee.CharField(max_length=255, null=True)
    taxProductCode = peewee.CharField(max_length=255, null=True)
    
    class Meta:
        table_name = 'group_bill'


class ConnectionInfo(BaseModel):
    """Model for POS connection information"""
    
    port = peewee.IntegerField(default=1)
    lottery_count = peewee.IntegerField(default=0)
    is_working = peewee.BooleanField(default=False)
    last_sent_date = peewee.DateTimeField(default=datetime.datetime.now)
    pos_id = peewee.IntegerField(default=0)
    pos_no = peewee.CharField(default='45')
    updated_time = peewee.DateTimeField(default=datetime.datetime.now)
    merchant_in = peewee.CharField(default='', max_length=255)
    
    class Meta:
        table_name = 'connection_info'


# =============================================================================
# Utility Functions
# =============================================================================

class DateTimeEncoder(json.JSONEncoder):
    """JSON encoder that can handle datetime objects"""
    
    def default(self, obj):
        if isinstance(obj, (datetime.datetime, datetime.date, datetime.time)):
            return obj.isoformat()
        elif isinstance(obj, datetime.timedelta):
            return (datetime.datetime.min + obj).time().isoformat()
        return super().default(obj)


def json_response(data):
    """Convert data to JSON string"""
    return json.dumps(data, cls=DateTimeEncoder)


def get_client_ip(request):
    """Get client IP address from request"""
    x_real_ip = request.headers.get('X-Real-IP')
    x_forwarded_for = request.headers.get('X-Forwarded-For')
    
    if x_real_ip:
        return x_real_ip
    elif x_forwarded_for:
        # Extract first IP in case of multiple IPs
        return x_forwarded_for.split(',')[0].strip()
    else:
        return request.remote_ip


def is_valid_access(request):
    """Validate request access"""
    # This is a placeholder for IP-based access control
    # In a production environment, implement proper authentication
    client_ip = get_client_ip(request)
    request_id = getattr(request, 'request_id', uuid.uuid4())
    logger.info(f"Request {request_id} from IP: {client_ip}")
    return True


def make_api_request(url, method='get', data=None, headers=None, timeout=None):
    """Make an API request with error handling"""
    if headers is None:
        headers = {'Content-Type': 'application/json'}
    if timeout is None:
        timeout = options.request_timeout
        
    try:
        session = requests.Session()
        response = getattr(session, method.lower())(
            url,
            json=data if method.lower() in ['post', 'put', 'patch', 'delete'] and data else None,
            params=data if method.lower() == 'get' and data else None,
            headers=headers,
            timeout=timeout
        )
        response.raise_for_status()
        return response.json() if response.content else {}
    except requests.exceptions.RequestException as e:
        logger.error(f"API request error: {e}")
        return None


def get_db_connection():
    """Get database connection"""
    return EbarimtDatabase.get_instance()


def initialize_database():
    """Initialize database tables"""
    db = get_db_connection()
    db.connect()
    
    try:
        db.get_db().create_tables([Category, GroupBill, ConnectionInfo], safe=True)
        logger.info("Database tables initialized")
    except Exception as e:
        logger.error(f"Database initialization error: {e}")
    finally:
        db.close()


def get_connection_info(port):
    """Get connection information from e-barimt service"""
    url = options.docker_url.replace("12345", str(port)) + "rest/info"
    
    # Make API request
    info = make_api_request(url)
    if not info:
        return None
        
    # Update connection info in database
    if "lastSentDate" in info and info["lastSentDate"]:
        try:
            last_sent_date = datetime.datetime.strptime(info["lastSentDate"], '%Y-%m-%d %H:%M:%S')
            merchant_tin = info.get("merchants", [{}])[0].get("tin", "")
            
            db = get_db_connection()
            db.connect()
            
            try:
                # Check if connection info exists
                conn_info = ConnectionInfo.select().where(ConnectionInfo.port == port).first()
                
                # Create or update connection info
                if conn_info:
                    ConnectionInfo.update(
                        lottery_count=info.get("leftLotteries", 0),
                        last_sent_date=last_sent_date,
                        updated_time=datetime.datetime.now(),
                        pos_id=info.get("posId", 0),
                        pos_no=info.get("posNo", ""),
                        merchant_in=merchant_tin
                    ).where(ConnectionInfo.port == port).execute()
                else:
                    ConnectionInfo.create(
                        port=port,
                        lottery_count=info.get("leftLotteries", 0),
                        last_sent_date=last_sent_date,
                        updated_time=datetime.datetime.now(),
                        pos_id=info.get("posId", 0),
                        pos_no=info.get("posNo", ""),
                        merchant_in=merchant_tin
                    )
            finally:
                db.close()
        except Exception as e:
            logger.error(f"Error updating connection info: {e}")
            
    return info


# =============================================================================
# Request Handlers
# =============================================================================

class BaseHandler(tornado.web.RequestHandler):
    """Base handler with common functionality"""
    
    def prepare(self):
        """Prepare the request"""
        # Generate request ID
        self.request_id = str(uuid.uuid4())
        # Count request
        global REQUEST_COUNTER
        REQUEST_COUNTER += 1
        
    def set_default_headers(self):
        """Set default headers for all responses"""
        self.set_header("Content-Type", "application/json; charset=UTF-8")
        self.set_header("X-Content-Type-Options", "nosniff")
        self.set_header("X-XSS-Protection", "1; mode=block")
        
    def write_error(self, status_code, **kwargs):
        """Write error response"""
        error_data = {
            "success": False,
            "error": f"HTTP {status_code}",
            "message": self._reason
        }
        
        if "exc_info" in kwargs:
            exception = kwargs["exc_info"][1]
            if hasattr(exception, "log_message") and exception.log_message:
                error_data["message"] = exception.log_message
                
        self.write(json_response(error_data))
        self.finish()


class MainHandler(BaseHandler):
    """Main handler for status check"""
    
    def get(self):
        """Handle GET request"""
        if is_valid_access(self):
            self.write(json_response({
                "status": "ok",
                "service": "E-Barimt POS API",
                "version": "3.0"
            }))
        else:
            self.send_error(403, reason="Access denied")


class SendDataHandler(BaseHandler):
    """Handler for sending data to e-barimt service"""
    
    executor = ThreadPoolExecutor(max_workers=MAX_WORKERS)
    
    @tornado.gen.coroutine
    def get(self, district_code):
        """Handle GET request"""
        number = self.get_argument("number", None)
        
        if not number:
            self.write(json_response({
                "success": False,
                "error": "Missing number parameter"
            }))
            return
            
        if is_valid_access(self):
            result = yield self.send_data_exec(district_code, number)
            self.write(json_response(result))
        else:
            self.send_error(403, reason="Access denied")
            
    @run_on_executor
    def send_data_exec(self, district_code, number):
        """Execute data sending in a thread"""
        url = f"{options.docker_url.replace('12345', district_code)}send?number={number}"
        response = make_api_request(url)
        
        if response:
            return {**response, "success": True}
        else:
            return {"success": False, "error": "Failed to send data"}


class EbarimtHandler(BaseHandler):
    """Handler for e-barimt operations"""
    
    executor = ThreadPoolExecutor(max_workers=MAX_WORKERS)
    
    def prepare(self):
        """Prepare the request"""
        super().prepare()
        
        # Parse and validate request body
        if self.request.body:
            try:
                self.request_data = json.loads(self.request.body)
            except json.JSONDecodeError:
                self.request_data = None
                self.send_error(400, reason="Invalid JSON data")
        else:
            self.request_data = {}
            
    @tornado.gen.coroutine
    def post(self, district_code):
        """Handle POST request for receipt generation or return"""
        action = self.get_argument('store', None)
        
        if not action:
            self.write(json_response({
                "success": False,
                "error": "Missing store parameter"
            }))
            return
            
        # Parse store and port
        store = action.rstrip('put').rstrip('return')
        port = int("9" + store[-3:]) if len(store) >= 3 else 9001
        
        logger.info(f"Request {self.request_id}: Action={action}, Port={port}")
        
        if not is_valid_access(self):
            self.send_error(403, reason="Access denied")
            return
            
        if "put" in action:
            # Generate receipt
            result = yield self.generate_receipt(district_code, port)
            self.write(json_response(result))
        elif "return" in action:
            # Return receipt
            result = yield self.return_receipt(port)
            self.write(json_response(result))
        else:
            self.write(json_response({
                "success": False,
                "error": "Invalid action"
            }))
            
    @run_on_executor
    def generate_receipt(self, district_code, port):
        """Generate e-barimt receipt"""
        logger.info(f"Request {self.request_id}: Generating receipt")
        
        if not self.request_data:
            return {"success": False, "error": "Missing or invalid request data"}
            
        # Get transaction ID
        trans_id = self.request_data.get('transID', '')
        
        try:
            # Prepare receipt data
            receipt_data = self._prepare_receipt_data(district_code, port)
            if not receipt_data:
                return {"success": False, "error": "Failed to prepare receipt data"}
                
            # Send receipt data to e-barimt service
            url = f"{options.docker_url.replace('12345', str(port))}rest/receipt"
            response = make_api_request(url, method='post', data=receipt_data)
            
            if not response:
                return {"success": False, "error": "Failed to generate receipt"}
                
            # Log response (without sensitive data)
            loggable_response = dict(response)
            if 'qrData' in loggable_response:
                loggable_response['qrData'] = '***REDACTED***'
            logger.info(f"Receipt response: {json.dumps(loggable_response)}")
            
            # Extract data from response
            bill_id = response.get('id', '')
            lottery = response.get('lottery', '')
            qr_data = response.get('qrData', '')
            total_amount = response.get('totalAmount', 0)
            
            # Extract sub-bill information
            first_10_chars = bill_id[:10] if bill_id else ''
            sub_bill_id = None
            sub_bill_name = None
            sub_bill_amount = 0
            
            for receipt in response.get('receipts', []):
                receipt_id = receipt.get('id', '')
                if receipt_id and receipt_id[:10] == first_10_chars:
                    sub_bill_id = receipt_id
                    sub_bill_name = self._get_merchant_name(receipt.get('merchantTin', ''), port)
                    sub_bill_amount = receipt.get('totalAmount', 0)
                    break
                    
            return {
                "success": True,
                "transID": trans_id,
                "amount": total_amount,
                "billId": bill_id,
                "subBillId": sub_bill_id,
                "subBillName": sub_bill_name,
                "subBillAmount": sub_bill_amount,
                "lottery": lottery,
                "qrData": qr_data,
            }
        except Exception as e:
            logger.error(f"Error generating receipt: {e}")
            return {"success": False, "error": str(e)}
            
    @run_on_executor
    def return_receipt(self, port):
        """Return (cancel) e-barimt receipt"""
        logger.info(f"Request {self.request_id}: Returning receipt")
        
        if not self.request_data:
            return {"success": False, "error": "Missing or invalid request data"}
            
        return_bill_id = self.request_data.get("returnBillId", "")
        if not return_bill_id:
            return {"success": False, "error": "Missing returnBillId"}
            
        try:
            # Get receipt date from database
            db_suffix = str(port)[-3:]
            db_path = f"/opt/sites/env/ebarimt-3.0/vatps_00{db_suffix}.db"
            
            if not os.path.exists(db_path):
                return {"success": False, "error": f"SQLite database not found: {db_path}"}
                
            created_date = None
            
            try:
                conn = sqlite3.connect(db_path)
                cursor = conn.cursor()
                
                cursor.execute("SELECT check_date FROM checkreceipt WHERE receipt_id = ?", (return_bill_id,))
                row = cursor.fetchone()
                
                if row:
                    date_str = row[0]
                    match = re.match(r'(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})', date_str)
                    if match:
                        created_date = match.group(1)
            except sqlite3.Error as e:
                logger.error(f"SQLite error: {e}")
                return {"success": False, "error": f"Database error: {e}"}
            finally:
                if 'conn' in locals():
                    conn.close()
                    
            # Send return request to e-barimt service
            url = f"{options.docker_url.replace('12345', str(port))}rest/receipt"
            payload = {
                "id": return_bill_id,
                "date": created_date
            }
            
            logger.info(f"Return payload: {payload}")
            
            response = make_api_request(url, method='delete', data=payload)
            
            if response:
                message = response.get('message', "Receipt returned successfully")
                return {"success": True, "message": message}
            else:
                return {"success": False, "error": "Failed to return receipt"}
        except Exception as e:
            logger.error(f"Error returning receipt: {e}")
            return {"success": False, "error": str(e)}
            
    def _prepare_receipt_data(self, district_code, port):
        """Prepare receipt data for e-barimt service"""
        try:
            # Get original data
            original_data = self.request_data
            
            # Determine receipt type
            receipt_type = "B2C_RECEIPT" if original_data.get("billType") == "1" else "B2B_RECEIPT"
            
            # Get merchant information
            merchant_tin = self._get_merchant_tin(port)
            merchant_name = self._get_merchant_name(merchant_tin, port)
            
            # Format district code
            if len(district_code) == 4:
                formatted_district_code = district_code
                branch_no = district_code[2:]
            elif len(district_code) == 2:
                formatted_district_code = district_code + "01"
                branch_no = district_code
            else:
                formatted_district_code = district_code
                branch_no = district_code[-2:] if len(district_code) >= 2 else district_code
                
            # Get customer information
            customer_no = original_data.get("customerNo", "")
            customer_tin = self._get_customer_tin(customer_no)
            
            # Process stocks (items)
            stocks = original_data.get("stocks", [])
            
            # Get group bills
            group_bills = self._get_group_bills()
            
            # Process receipts
            receipts = []
            for stock in stocks:
                receipt = self._process_stock_item(stock, merchant_tin, port, group_bills)
                if receipt:
                    receipts.append(receipt)
                    
            # Calculate totals
            total_vat = sum(r["totalVAT"] for r in receipts)
            total_city_tax = sum(r["totalCityTax"] for r in receipts)
            total_amount = sum(r["totalAmount"] for r in receipts)
            
            # Create receipt data
            receipt_data = {
                "totalAmount": total_amount,
                "totalVAT": total_vat,
                "totalCityTax": total_city_tax,
                "districtCode": formatted_district_code,
                "merchantTin": merchant_tin,
                "merchantName": merchant_name,
                "branchNo": branch_no,
                "customerTin": customer_tin if customer_tin else customer_no,
                "type": receipt_type,
                "receipts": receipts,
                "payments": []
            }
            
            # Process payment information
            non_cash_amount = float(original_data.get("nonCashAmount", 0))
            cash_amount = float(original_data.get("cashAmount", 0))
            
            if non_cash_amount > 0 and cash_amount > 0:
                receipt_data["payments"].append({
                    "status": "PAID",
                    "code": "PAYMENT_CARD",
                    "paidAmount": non_cash_amount
                })
                receipt_data["payments"].append({
                    "status": "PAID",
                    "code": "CASH",
                    "paidAmount": cash_amount
                })
            elif non_cash_amount > 0:
                receipt_data["payments"].append({
                    "status": "PAID",
                    "code": "PAYMENT_CARD",
                    "paidAmount": non_cash_amount
                })
            elif cash_amount > 0:
                receipt_data["payments"].append({
                    "status": "PAID",
                    "code": "CASH",
                    "paidAmount": cash_amount
                })
                
            return receipt_data
        except Exception as e:
            logger.error(f"Error preparing receipt data: {e}")
            return None
            
    def _process_stock_item(self, stock, merchant_tin, port, group_bills):
        """Process a stock item into a receipt entry"""
        try:
            # Extract stock information
            bar_code = stock.get("barCode", "")
            total_amount = float(stock.get("totalAmount", 0))
            city_tax = float(stock.get("cityTax", 0))
            
            # Check if item is in group bills
            group_bill = next((gb for gb in group_bills if gb.get('bar_code') == bar_code), None)
            
            # Determine tax type and product code
            tax_type = "VAT_ABLE" if total_amount > 0 else "VAT_ZERO"
            tax_product_code = group_bill.get('taxProductCode', '') if group_bill else ''
            
            # Calculate tax amounts
            vat_amount = 0
            city_tax_amount = 0
            
            if group_bill:
                tax_type = "VAT_ZERO"
                tax_product_code = "447"  # Code for VAT_ZERO items
            elif tax_type == "VAT_ZERO":
                tax_product_code = "447"
            elif tax_type == "VAT_ABLE":
                if city_tax > 0:
                    vat_amount = total_amount / 11.2
                    city_tax_amount = vat_amount * 0.2
                else:
                    vat_amount = total_amount / 11
                    
            # Determine merchant TIN for this item
            item_merchant_tin = group_bill.get("group_tin", merchant_tin) if group_bill else merchant_tin
            
            # Create item data
            item = {
                "name": stock.get("name", ""),
                "taxProductCode": tax_product_code,
                "barCode": bar_code,
                "barCodeType": self._determine_barcode_type(bar_code),
                "classificationCode": self._get_classification_code(stock.get("code", "")),
                "measureUnit": stock.get("measureUnit", ""),
                "qty": int(float(stock.get("qty", 0))),
                "unitPrice": float(stock.get("unitPrice", 0)),
                "totalAmount": total_amount,
                "totalVAT": vat_amount,
                "totalCityTax": city_tax_amount,
                "taxType": tax_type
            }
            
            # Get merchant name
            merchant_name = self._get_sub_merchant_name(item_merchant_tin, port)
            
            # Find or add to receipts
            existing_receipt = None
            for receipt in self.receipt_items:
                if receipt['merchantTin'] == item_merchant_tin:
                    existing_receipt = receipt
                    break
                    
            if existing_receipt:
                existing_receipt['items'].append(item)
                existing_receipt['totalAmount'] += total_amount
                existing_receipt['totalVAT'] += vat_amount
                existing_receipt['totalCityTax'] += city_tax_amount
                return None
            else:
                receipt = {
                    "totalAmount": total_amount,
                    "taxType": tax_type,
                    "merchantTin": item_merchant_tin,
                    "merchantSubName": merchant_name,
                    "totalVAT": vat_amount,
                    "totalCityTax": city_tax_amount,
                    "items": [item]
                }
                self.receipt_items.append(receipt)
                return receipt
        except Exception as e:
            logger.error(f"Error processing stock item: {e}")
            return None
            
    def _determine_barcode_type(self, barcode):
        """Determine barcode type based on format"""
        if not barcode:
            return "UNDEFINED"
            
        if barcode in ["6900456387254", "6757990902668"]:
            return "UNDEFINED"
            
        if len(barcode) == 13:
            return "GS1"
            
        return "UNDEFINED"
            
    def _get_merchant_tin(self, port):
        """Get merchant TIN for the given port"""
        try:
            db = get_db_connection()
            db.connect()
            
            try:
                conn_info = ConnectionInfo.select().where(ConnectionInfo.port == port).first()
                if conn_info and conn_info.merchant_in:
                    return conn_info.merchant_in
            finally:
                db.close()
                
            return options.company_merchant_tin
        except Exception as e:
            logger.error(f"Error getting merchant TIN: {e}")
            return options.company_merchant_tin
            
    def _get_merchant_name(self, merchant_tin, port):
        """Get merchant name for the given TIN and port"""
        url = f'http://10.10.90.233/api/getInformation?port={port}'
        response = make_api_request(url)
        
        if response:
            merchants = response.get('merchants', [])
            for merchant in merchants:
                if merchant.get('tin') == merchant_tin:
                    return merchant.get('name', '')
                    
        return ""
        
    def _get_sub_merchant_name(self, merchant_tin, port):
        """Get sub-merchant name for the given TIN and port"""
        url = f'http://10.10.90.233/api/getInformation?port={port}'
        response = make_api_request(url)
        
        if response:
            merchant_data = response.get('merchants', [{}])[0]
            customers = merchant_data.get('customers', [])
            for customer in customers:
                if customer.get('tin') == merchant_tin:
                    return customer.get('name', '')
                    
        return ""
        
    def _get_customer_tin(self, customer_no):
        """Get customer TIN from registration number"""
        if not customer_no:
            return ""
            
        url = f"{options.ebarimt_reg_no_url}{customer_no}"
        response = make_api_request(url)
        
        if response:
            return response.get('data', '')
            
        return ""
        
    def _get_classification_code(self, item_code):
        """Get classification code for item"""
        try:
            db = get_db_connection()
            db.connect()
            
            try:
                category = Category.select().where(Category.bgf_code == item_code).first()
                if category:
                    return category.ebarimt_code
            finally:
                db.close()
                
            return "6215900"  # Default classification code
        except Exception as e:
            logger.error(f"Error getting classification code: {e}")
            return "6215900"  # Default classification code
            
    def _get_group_bills(self):
        """Get group bills from database"""
        try:
            db = get_db_connection()
            db.connect()
            
            try:
                return list(GroupBill.select().dicts())
            finally:
                db.close()
                
            return []
        except Exception as e:
            logger.error(f"Error getting group bills: {e}")
            return []


class InfoHandler(BaseHandler):
    """Handler for getting e-barimt service information"""
    
    def get(self):
        """Handle GET request"""
        port = self.get_argument("port", None)
        
        if not port:
            self.write(json_response({
                "success": False,
                "error": "Missing port parameter"
            }))
            return
            
        if is_valid_access(self):
            info = get_connection_info(port)
            if info:
                self.write(json_response({
                    "success": True,
                    **info
                }))
            else:
                self.write(json_response({
                    "success": False,
                    "error": "Failed to get information"
                }))
        else:
            self.send_error(403, reason="Access denied")


class CheckRegNoHandler(BaseHandler):
    """Handler for checking registration numbers"""
    
    def get(self):
        """Handle GET request"""
        reg_no = self.get_argument("reg_no", None)
        
        if not reg_no:
            self.write(json_response({
                "success": False,
                "error": "Missing reg_no parameter"
            }))
            return
            
        if is_valid_access(self):
            # Get TIN from registration number
            tin_url = f"{options.ebarimt_reg_no_url}{reg_no}"
            tin_response = make_api_request(tin_url)
            
            if not tin_response:
                self.write(json_response({
                    "success": False,
                    "error": "Failed to get TIN"
                }))
                return
                
            tin = tin_response.get("data", "")
            if not tin:
                self.write(json_response({
                    "success": False,
                    "error": "No TIN found for the given registration number"
                }))
                return
                
            # Get information for TIN
            info_url = f"{options.ebarimt_tin_url}{tin}"
            info_response = make_api_request(info_url)
            
            if not info_response:
                self.write(json_response({
                    "success": False,
                    "error": "Failed to get information for TIN"
                }))
                return
                
            data = info_response.get("data", {})
            name = data.get("name", "")
            
            self.write(json_response({
                "success": True,
                "name": name,
                "tin": tin
            }))
        else:
            self.send_error(403, reason="Access denied")


# =============================================================================
# Application Setup
# =============================================================================

class Application(tornado.web.Application):
    """Main application class"""
    
    def __init__(self):
        """Initialize the application"""
        handlers = [
            (r"/", MainHandler),
            (r"/(?P<district_code>[^\/]+)/api/", EbarimtHandler),
            (r"/(?P<district_code>[^\/]+)/send/", SendDataHandler),
            (r"/api/getInformation", InfoHandler),
            (r"/check_reg_no/?", CheckRegNoHandler),
        ]
        
        settings = {
            "debug": options.debug,
            "compress_response": True,  # Enable gzip compression
            "default_handler_class": tornado.web.ErrorHandler,
            "default_handler_args": {"status_code": 404}
        }
        
        super().__init__(handlers, **settings)
        
        # Initialize the database
        initialize_database()


def main():
    """Main entry point of the application"""
    try:
        # Parse command line arguments
        parse_command_line()
        
        # Create and start the application
        app = Application()
        server = tornado.httpserver.HTTPServer(
            app,
            xheaders=True,  # Trust X-Real-IP and X-Forwarded-For headers
            max_buffer_size=50 * 1024 * 1024  # 50MB max request size
        )
        
        # Listen on the specified port
        server.listen(options.port)
        logger.info(f"Server started on port {options.port}")
        
        # Start the Tornado IO loop
        tornado.ioloop.IOLoop.current().start()
    except KeyboardInterrupt:
        logger.info("Server stopping...")
    except Exception as e:
        logger.error(f"Error starting server: {e}")
        sys.exit(1)


if __name__ == "__main__":
    main()