<?php

require_once __DIR__ . '/vendor/autoload.php';

use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Medoo\Medoo;
use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class Config {
    public static $settings = [
        'request_timeout' => 15,
        'port' => 8888,
        'ebarimt_reg_no_url' => 'https://api.ebarimt.mn/api/info/check/getTinInfo?regNo=',
        'ebarimt_tin_url' => 'https://api.ebarimt.mn/api/info/check/getInfo?tin=',
        'docker_url' => 'http://localhost:12345/',
        'company_register' => '0000038',
        'company_merchant_tin' => '77100185651',
        'bank_id' => '15',
        'ebarimt_group_enabled' => true
    ];
}

class AppLogger {
    private static $instance = null;
    private $logger;
    private $requestId;

    private function __construct() {
        $logDir = __DIR__ . '/ebarimt_log3';
        
        if (!is_dir($logDir) && !mkdir($logDir, 0755, true)) {
            throw new \RuntimeException("Failed to create log directory: $logDir");
        }

        $this->logger = new Logger('ebarimt');
        $handler = new RotatingFileHandler(
            "$logDir/application.log",
            10,
            Logger::DEBUG
        );
        
        $processor = function ($record) {
            $record['extra']['request_id'] = $this->requestId;
            return $record;
        };
        
        $this->logger->pushProcessor($processor);
        $this->logger->pushHandler($handler);
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function setRequestId(string $requestId): void {
        $this->requestId = $requestId;
    }

    public function log($level, $message, array $context = []): void {
        $this->logger->log($level, $message, $context);
    }
}

class Database {
    private static $instance = null;
    private $db;
    private $logger;

    private function __construct() {
        $this->logger = AppLogger::getInstance();

        try {
            $this->db = new Medoo([
                'type' => 'mysql',
                'host' => '10.10.90.234',
                'database' => 'ebarimt3_db',
                'username' => 'ebarimt_user',
                'password' => 'Ebarimt_2022.',
                'charset' => 'utf8mb4',
                'port' => 3306
            ]);

            $this->db->query("SELECT 1")->fetch();
            $this->logger->log(Logger::INFO, "MySQL connection established successfully.");
            
            $this->initializeTables();
            
        } catch (\Exception $e) {
            $this->logger->log(Logger::ERROR, "MySQL connection failed: " . $e->getMessage());
            throw $e;
        }
    }

    private function initializeTables() {
        try {
            $queries = [
                "CREATE TABLE IF NOT EXISTS connection_info (
                    port INT NOT NULL DEFAULT 1,
                    lottery_count INT NOT NULL DEFAULT 0,
                    is_working BOOLEAN NOT NULL DEFAULT FALSE,
                    last_sent_date DATETIME NOT NULL DEFAULT NOW(),
                    pos_id INT NOT NULL DEFAULT 0,
                    pos_no VARCHAR(255) NOT NULL DEFAULT '45',
                    updated_time DATETIME NOT NULL DEFAULT NOW(),
                    merchant_in VARCHAR(255) NOT NULL DEFAULT ''
                )",
                "CREATE TABLE IF NOT EXISTS category (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    bgf_code VARCHAR(255) NOT NULL UNIQUE,
                    ebarimt_code VARCHAR(255) NOT NULL DEFAULT '24',
                    company_reg VARCHAR(255) NOT NULL DEFAULT '',
                    percent FLOAT NOT NULL DEFAULT 0.0
                )",
                "CREATE TABLE IF NOT EXISTS group_bill (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    bar_code VARCHAR(255) NULL,
                    group_tin VARCHAR(255) NULL,
                    taxProductCode VARCHAR(255) NULL
                )"
            ];

            foreach ($queries as $query) {
                $this->db->query($query);
            }
            
            $this->logger->log(Logger::INFO, "Database tables initialized successfully.");
        } catch (\Exception $e) {
            $this->logger->log(Logger::ERROR, "Failed to initialize tables: " . $e->getMessage());
            throw $e;
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->db;
    }
}

class DateTimeEncoder {
    public static function encode($obj): string {
        if ($obj instanceof \DateTime) {
            return $obj->format(\DateTime::ISO8601);
        } elseif ($obj instanceof \DateInterval) {
            $start = new \DateTime('@0');
            $end = clone $start;
            $end->add($obj);
            return $end->format(\DateTime::ISO8601);
        }
        return '';
    }
}

class WorkerPool {
    private static $instance = null;
    private $maxWorkers;
    private $activeWorkers = 0;
    private $queue = [];
    
    private function __construct(int $maxWorkers) {
        $this->maxWorkers = $maxWorkers;
    }
    
    public static function getInstance(int $maxWorkers = 100): self {
        if (self::$instance === null) {
            self::$instance = new self($maxWorkers);
        }
        return self::$instance;
    }
    
    public function submit(callable $task, array $params = []): Promise {
        $promise = new Promise();
        
        $wrappedTask = function() use ($task, $params, $promise) {
            try {
                $this->activeWorkers++;
                $result = call_user_func_array($task, $params);
                $promise->resolve($result);
            } catch (\Exception $e) {
                $promise->reject($e);
            } finally {
                $this->activeWorkers--;
                $this->processQueue();
            }
        };
        
        if ($this->activeWorkers < $this->maxWorkers) {
            $wrappedTask();
        } else {
            $this->queue[] = $wrappedTask;
        }
        
        return $promise;
    }
    
    private function processQueue(): void {
        if (!empty($this->queue) && $this->activeWorkers < $this->maxWorkers) {
            $task = array_shift($this->queue);
            $task();
        }
    }
}

class Promise {
    private $resolveCallbacks = [];
    private $rejectCallbacks = [];
    private $state = 'pending';
    private $value = null;
    private $reason = null;
    
    public function resolve($value): void {
        if ($this->state !== 'pending') {
            return;
        }
        
        $this->state = 'fulfilled';
        $this->value = $value;
        
        foreach ($this->resolveCallbacks as $callback) {
            $callback($this->value);
        }
    }
    
    public function reject($reason): void {
        if ($this->state !== 'pending') {
            return;
        }
        
        $this->state = 'rejected';
        $this->reason = $reason;
        
        foreach ($this->rejectCallbacks as $callback) {
            $callback($this->reason);
        }
    }
    
    public function then(callable $onFulfilled = null, callable $onRejected = null): self {
        $promise = new Promise();
        
        $resolveHandler = function($value) use ($onFulfilled, $promise) {
            if (!is_callable($onFulfilled)) {
                $promise->resolve($value);
                return;
            }
            
            try {
                $result = $onFulfilled($value);
                $promise->resolve($result);
            } catch (\Exception $e) {
                $promise->reject($e);
            }
        };
        
        $rejectHandler = function($reason) use ($onRejected, $promise) {
            if (!is_callable($onRejected)) {
                $promise->reject($reason);
                return;
            }
            
            try {
                $result = $onRejected($reason);
                $promise->resolve($result);
            } catch (\Exception $e) {
                $promise->reject($e);
            }
        };
        
        if ($this->state === 'fulfilled') {
            $resolveHandler($this->value);
        } elseif ($this->state === 'rejected') {
            $rejectHandler($this->reason);
        } else {
            $this->resolveCallbacks[] = $resolveHandler;
            $this->rejectCallbacks[] = $rejectHandler;
        }
        
        return $promise;
    }
    
    public function catch(callable $onRejected): self {
        return $this->then(null, $onRejected);
    }
}

abstract class BaseController {
    protected $db;
    protected $appLogger;
    protected $workerPool;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->appLogger = AppLogger::getInstance();
        $this->workerPool = WorkerPool::getInstance(100);
    }

    protected function checkIp(Request $request) {
        $realIp = $request->header('X-Real-IP');
        $remoteIp = $realIp ?: $request->connection->getRemoteIp();
        $requestId = uniqid('req_', true);
        $this->appLogger->setRequestId($requestId);
        $this->appLogger->log(\Monolog\Logger::INFO, $requestId . ' ' . $remoteIp);
        return true;
    }

    protected function json(array $data, int $status = 200): Response {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode($data)
        );
    }
}

class MainController extends BaseController {
    public function index(Request $request): Response {
        if ($this->checkIp($request)) {
            return new Response(200, [], '.');
        }
        return new Response(403, [], 'Access is denied');
    }
}

class SendDataHandler extends BaseController {
    public function handle(Request $request, string $districtCode): Response {
        if (!$this->checkIp($request)) {
            return $this->json(['message' => 'Access denied'], 403);
        }
        
        $number = $request->get('number');
        if (empty($number)) {
            return $this->json(['error' => 'Number parameter is required'], 400);
        }

        $promise = $this->workerPool->submit([$this, 'sendDataWithLogExec'], [$districtCode, $number]);
        
        $response = new Response();
        $response->withHeader('Content-Type', 'application/json');
        
        $promise->then(
            function($result) use ($response) {
                $response->withBody(json_encode($result));
                return $response;
            },
            function($error) use ($response) {
                $response->withStatus(500);
                $response->withBody(json_encode(['error' => $error->getMessage()]));
                return $response;
            }
        );
        
        return $response;
    }
    
    public function sendDataWithLogExec(string $districtCode, string $number): array {
        try {
            $this->appLogger->log(Logger::INFO, "Send data with log exec");
            
            $url = str_replace(
                '12345', 
                $districtCode, 
                Config::$settings['docker_url']
            ) . "send?number=" . $number;
            
            $this->appLogger->log(Logger::INFO, "URL: " . $url);
            
            $client = new \GuzzleHttp\Client();
            $response = $client->get(
                $url, 
                [
                    'headers' => ['Content-Type' => 'application/json;charset=utf-8'],
                    'timeout' => Config::$settings['request_timeout']
                ]
            );
            
            $result = json_decode($response->getBody(), true);
            $this->appLogger->log(Logger::INFO, "Response: " . json_encode($result));
            
            return $result;
            
        } catch (\Exception $e) {
            $this->appLogger->log(Logger::ERROR, 'Failed to send data: ' . $e->getMessage());
            throw $e;
        }
    }
}

class PutCustomController extends BaseController {
    public function handle(Request $request, string $districtCode): Response {
        $requestId = uniqid();
        $this->appLogger->setRequestId($requestId);
        
        if (!$this->checkIp($request)) {
            return $this->json(['message' => 'Access denied'], 403);
        }
        
        $action = $request->get('store');
        if ($action === null) {
            return $this->json(['error' => 'Store parameter is required'], 400);
        }
        
        $store = rtrim(rtrim($action, 'put'), 'return');
        $port = (int)('9' . substr($store, -3));
        
        $this->appLogger->log(Logger::INFO, "Action: {$action}, Port: {$port}");
        
        $requestData = json_decode($request->rawBody(), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->json(['error' => 'Invalid JSON data'], 400);
        }
        
        if (strpos($action, 'put') !== false) {
            $result = $this->generateEbarimt($districtCode, $requestData, $port);
            return $this->json($result);
        } elseif (strpos($action, 'return') !== false) {
            $result = $this->returnEbarimt($requestData, $port);
            return $this->json($result);
        }
        
        return $this->json(['error' => 'Invalid action'], 400);
    }

    public function generateEbarimt(string $districtCode, array $data, int $port): array {
        try {
            $this->appLogger->log(Logger::INFO, "--------- --------- Original input --------- ---------");
            $this->appLogger->log(Logger::INFO, json_encode($data));
            
            $transID = $data['transID'] ?? '';
            
            $preparedData = $this->prepareData($districtCode, $data, $port);
            
            $this->appLogger->log(Logger::INFO, "--------- --------- IN --------- ---------");
            $this->appLogger->log(Logger::INFO, $districtCode);
            $this->appLogger->log(Logger::INFO, json_encode($preparedData));
            
            $url = str_replace('12345', (string)$port, Config::$settings['docker_url']) . 'rest/receipt';
            
            $client = new \GuzzleHttp\Client();
            $response = $client->post($url, [
                'json' => $preparedData,
                'timeout' => Config::$settings['request_timeout']
            ]);
            
            $responseData = json_decode($response->getBody(), true);
            
            $loggableResponseData = $responseData;
            if (isset($loggableResponseData['qrData'])) {
                $loggableResponseData['qrData'] = '***HIDDEN***';
            }
            
            $this->appLogger->log(Logger::INFO, " --------- --------- OUT --------- ---------");
            $this->appLogger->log(Logger::INFO, json_encode($loggableResponseData));
            
            $dataId = $responseData['id'] ?? '';
            $lottery = $responseData['lottery'] ?? '';
            $qrdata = $responseData['qrData'] ?? '';
            $totalAmount = $responseData['totalAmount'] ?? 0;
            
            $success = $response->getStatusCode() === 200;
            
            $first10DataId = substr($dataId, 0, 10);
            $subBillId = null;
            $subBillName = null;
            $subBillAmount = 0;
            
            foreach ($responseData['receipts'] ?? [] as $receipt) {
                $receiptId = $receipt['id'] ?? '';
                if (substr($receiptId, 0, 10) === $first10DataId) {
                    $subBillId = $receiptId;
                    $subBillName = $this->fetchMerchantName($receipt['merchantTin'] ?? '', $port);
                    $subBillAmount = $receipt['totalAmount'] ?? 0;
                    break;
                }
            }
            
            return [
                "transID" => $transID,
                "amount" => $totalAmount,
                "billId" => $dataId,
                "subBillId" => $subBillId,
                "subBillName" => $subBillName,
                "subBillAmount" => $subBillAmount,
                "lottery" => $lottery,
                "qrData" => $qrdata,
                "success" => $success
            ];
            
        } catch (\Exception $e) {
            $this->appLogger->log(Logger::ERROR, "Failed to send POST request: " . $e->getMessage());
            return [
                'error' => 'Failed to communicate with the endpoint.',
                'success' => false
            ];
        }
    }

    public function returnEbarimt(array $data, int $port): array {
        $this->appLogger->log(Logger::INFO, '========================RETURN BILL========================');
        $this->appLogger->log(Logger::INFO, "Payload for DELETE request: " . json_encode($data));
        
        $returnBillId = $data['returnBillId'] ?? '';
        if (empty($returnBillId)) {
            return ['error' => 'Missing returnBillId parameter'];
        }
        
        $dbSuffix = substr((string)$port, -3);
        $dbPath = "/opt/sites/env/ebarimt-3.0/vatps_00{$dbSuffix}.db";
        
        if (!file_exists($dbPath)) {
            $this->appLogger->log(Logger::ERROR, "Database not found at {$dbPath}");
            return ['error' => "Database not found at {$dbPath}"];
        }
        
        try {
            $pdo = new \PDO("sqlite:{$dbPath}");
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            
            $stmt = $pdo->prepare("SELECT check_date FROM checkreceipt WHERE receipt_id = ?");
            $stmt->execute([$returnBillId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if ($row) {
                $createddate = $row['check_date'];
                if (preg_match('/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $createddate, $matches)) {
                    $formattedDatetime = $matches[1];
                    $data['date'] = $formattedDatetime;
                    $this->appLogger->log(Logger::INFO, "Updated payload with date: {$formattedDatetime}");
                } else {
                    $this->appLogger->log(Logger::WARNING, "Invalid datetime format for createddate: {$createddate}");
                    $data['date'] = null;
                }
            } else {
                $this->appLogger->log(Logger::WARNING, "No matching returnBillId found in the database for id: {$returnBillId}");
                $data['date'] = null;
            }
        } catch (\PDOException $e) {
            $this->appLogger->log(Logger::ERROR, "SQLite error: " . $e->getMessage());
            return ['error' => "SQLite error: " . $e->getMessage()];
        }
        
        $url = str_replace('12345', (string)$port, Config::$settings['docker_url']) . 'rest/receipt';
        
        $payload = [
            'id' => $returnBillId,
            'date' => $data['date'] ?? ''
        ];
        
        $this->appLogger->log(Logger::INFO, "Final payload for DELETE request: " . json_encode($payload));
        
        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->delete($url, [
                'json' => $payload,
                'headers' => ['Content-Type' => 'application/json'],
                'timeout' => Config::$settings['request_timeout']
            ]);
            
            $this->appLogger->log(Logger::INFO, "Response status code: " . $response->getStatusCode());
            
            if (in_array($response->getStatusCode(), [200, 204])) {
                $message = "Receipt deleted successfully";
                if ($response->getStatusCode() === 200 && $response->getBody()->getSize() > 0) {
                    try {
                        $responseData = json_decode((string)$response->getBody(), true);
                        if ($responseData) {
                            $message = $responseData['message'] ?? $message;
                        }
                    } catch (\Exception $e) {
                        $this->appLogger->log(Logger::ERROR, "Received non-JSON response with status 200");
                    }
                }
                $this->appLogger->log(Logger::INFO, $message);
                return ["message" => $message];
            } else {
                $responseData = json_decode((string)$response->getBody(), true);
                $message = isset($responseData['message']) ? $responseData['message'] : "";
                $this->appLogger->log(Logger::ERROR, "Failed to delete receipt. Status code: " . $response->getStatusCode());
                return [
                    "error" => "Failed to delete receipt", 
                    "status" => $response->getStatusCode(), 
                    "message" => $message
                ];
            }
        } catch (\Exception $e) {
            $this->appLogger->log(Logger::ERROR, "Exception during DELETE request: " . $e->getMessage());
            return ["error" => "Exception during DELETE request: " . $e->getMessage()];
        }
    }

    private function prepareData(string $districtCode, array $originalData, int $port): array {
        try {
            $receiptType = isset($originalData['billType']) && $originalData['billType'] === '1' ? 'B2C_RECEIPT' : 'B2B_RECEIPT';
            $merchantTin = $this->fetchMerchantTin($port);
            $merchantName = $this->fetchMerchantName($merchantTin, $port);
            
            if (strlen($districtCode) === 4) {
                $formattedDistrictCode = $districtCode;
                $branchNo = substr($districtCode, 2);
            } else {
                $formattedDistrictCode = $districtCode . '01';
                $branchNo = $districtCode;
            }
            
            $customerNo = $originalData['customerNo'] ?? '';
            $customerTin = '';
            
            if ($customerNo) {
                try {
                    $client = new \GuzzleHttp\Client();
                    $response = $client->get(
                        Config::$settings['ebarimt_reg_no_url'] . $customerNo,
                        ['timeout' => Config::$settings['request_timeout']]
                    );
                    $result = json_decode($response->getBody(), true);
                    $customerTin = $result['data'] ?? '';
                } catch (\Exception $e) {
                    $this->appLogger->log(Logger::ERROR, "Failed to fetch TIN for customerNo {$customerNo}: " . $e->getMessage());
                }
            }
            
            $amountTendered = (int)((float)($originalData['amountTendered'] ?? 0));
            $stocks = $originalData['stocks'] ?? [];
            
            $totalOriginalAmount = 0;
            foreach ($stocks as $stock) {
                $totalOriginalAmount += (float)($stock['totalAmount'] ?? 0);
            }
            
            $groupBillsResults = $this->db->select('group_bill', ['bar_code', 'group_tin', 'taxProductCode']);
            $groupBills = [];
            foreach ($groupBillsResults as $gb) {
                $groupBills[$gb['bar_code']] = $gb;
            }
            
            $receipts = [];
            
            foreach ($stocks as $stock) {
                $totalAmount = (float)($stock['totalAmount'] ?? 0);
                $cityTax = (float)($stock['cityTax'] ?? 0);
                $barCode = $stock['barCode'] ?? '';
                
                $groupBill = $groupBills[$barCode] ?? null;
                $taxType = $totalAmount > 0 ? "VAT_ABLE" : "VAT_ZERO";
                $vatAmount = 0;
                $cityTaxAmount = 0;
                $taxProductCode = "";
                
                if ($groupBill) {
                    $taxType = "VAT_ZERO";
                    $taxProductCode = "447";
                } elseif ($taxType === "VAT_ZERO") {
                    $taxProductCode = "447";
                }
                
                if ($taxType !== "VAT_ZERO") {
                    if ($cityTax > 0) {
                        $vatAmount = $totalAmount / 11.2;
                        $cityTaxAmount = $vatAmount * 0.2;
                    } else {
                        $vatAmount = $totalAmount / 11;
                    }
                }
                
                $itemMerchantTin = isset($groupBill['group_tin']) ? $groupBill['group_tin'] : $merchantTin;
                
                $item = [
                    'name' => $stock['name'] ?? '',
                    'taxProductCode' => $taxProductCode,
                    'barCode' => $barCode,
                    'barCodeType' => in_array($barCode, ['6900456387254', '6757990902668']) ? 'UNDEFINED' : 
                                     (strlen($barCode) === 13 ? 'GS1' : 'UNDEFINED'),
                    'classificationCode' => $this->fetchClassificationCode($stock['code'] ?? ''),
                    'measureUnit' => $stock['measureUnit'] ?? '',
                    'qty' => (int)((float)($stock['qty'] ?? 1)),
                    'unitPrice' => (float)($stock['unitPrice'] ?? 0),
                    'totalAmount' => $totalAmount,
                    'totalVAT' => $vatAmount,
                    'totalCityTax' => $cityTaxAmount,
                    'taxType' => 'VAT_ABLE'
                ];
                
                $merchantSubName = $this->fetchSubMerchantName($itemMerchantTin, $port);
                
                $found = false;
                foreach ($receipts as &$receipt) {
                    if ($receipt['merchantTin'] === $itemMerchantTin) {
                        $receipt['items'][] = $item;
                        $receipt['totalAmount'] += $totalAmount;
                        $receipt['totalVAT'] += $vatAmount;
                        $receipt['totalCityTax'] += $cityTaxAmount;
                        $found = true;
                        break;
                    }
                }
                
                if (!$found) {
                    $receipts[] = [
                        'totalAmount' => $totalAmount,
                        'taxType' => 'VAT_ABLE',
                        'merchantTin' => $itemMerchantTin,
                        'merchantSubName' => $merchantSubName,
                        'totalVAT' => $vatAmount,
                        'totalCityTax' => $cityTaxAmount,
                        'items' => [$item]
                    ];
                }
            }
            
            $totalVAT = 0;
            $totalCityTax = 0;
            $totalAmount = 0;
            
            foreach ($receipts as $receipt) {
                $totalVAT += $receipt['totalVAT'];
                $totalCityTax += $receipt['totalCityTax'];
                $totalAmount += $receipt['totalAmount'];
            }
            
            $newDict = [
                'totalAmount' => $totalAmount,
                'totalVAT' => $totalVAT,
                'totalCityTax' => $totalCityTax,
                'districtCode' => $formattedDistrictCode,
                'merchantTin' => $merchantTin,
                'merchantName' => $merchantName,
                'branchNo' => $branchNo,
                'customerTin' => $customerTin ?: $customerNo,
                'type' => $receiptType,
                'receipts' => $receipts,
                'payments' => []
            ];
            
            $nonCashAmount = (float)($originalData['nonCashAmount'] ?? 0);
            $cashAmount = (float)($originalData['cashAmount'] ?? 0);
            
            if ($nonCashAmount > 0 && $cashAmount > 0) {
                $newDict['payments'][] = [
                    'status' => 'PAID',
                    'code' => 'PAYMENT_CARD',
                    'paidAmount' => $nonCashAmount
                ];
                $newDict['payments'][] = [
                    'status' => 'PAID',
                    'code' => 'CASH',
                    'paidAmount' => $cashAmount
                ];
            } elseif ($nonCashAmount > 0) {
                $newDict['payments'][] = [
                    'status' => 'PAID',
                    'code' => 'PAYMENT_CARD',
                    'paidAmount' => $nonCashAmount
                ];
            } elseif ($cashAmount > 0) {
                $newDict['payments'][] = [
                    'status' => 'PAID',
                    'code' => 'CASH',
                    'paidAmount' => $cashAmount
                ];
            }
            
            return $newDict;
        } catch (\Exception $e) {
            $this->appLogger->log(Logger::ERROR, "Error preparing data: " . $e->getMessage());
            throw $e;
        }
    }

    private function fetchMerchantTin(int $port): string {
        try {
            $result = $this->db->get('connection_info', 'merchant_in', [
                'port' => $port
            ]);
            return $result ?: Config::$settings['company_merchant_tin'];
        } catch (\Exception $e) {
            $this->appLogger->log(Logger::ERROR, "Database access failed: " . $e->getMessage());
            return Config::$settings['company_merchant_tin'];
        }
    }

    private function fetchMerchantName(string $merchantTin, int $port): string {
        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->get("http://10.10.90.233/api/getInformation?port={$port}");
            $data = json_decode($response->getBody(), true);
            
            if (isset($data['merchants'])) {
                foreach ($data['merchants'] as $merchant) {
                    if (($merchant['tin'] ?? '') === $merchantTin) {
                        return $merchant['name'] ?? '';
                    }
                }
            }
            return '';
        } catch (\Exception $e) {
            $this->appLogger->log(Logger::ERROR, 'Failed to fetch merchant info: ' . $e->getMessage());
            return '';
        }
    }

    private function fetchSubMerchantName(string $merchantTin, int $port): string {
        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->get("http://10.10.90.233/api/getInformation?port={$port}");
            $data = json_decode($response->getBody(), true);
            
            if (isset($data['merchants'][0]['customers'])) {
                foreach ($data['merchants'][0]['customers'] as $customer) {
                    if (($customer['tin'] ?? '') === $merchantTin) {
                        return $customer['name'] ?? '';
                    }
                }
            }
            return '';
        } catch (\Exception $e) {
            $this->appLogger->log(Logger::ERROR, 'Failed to fetch sub-merchant info: ' . $e->getMessage());
            return '';
        }
    }

    private function fetchClassificationCode(string $itemCode): string {
        try {
            $result = $this->db->get('category', 'ebarimt_code', [
                'bgf_code' => $itemCode
            ]);
            return $result ?: '6215900';
        } catch (\Exception $e) {
            $this->appLogger->log(Logger::ERROR, 'Database access failed: ' . $e->getMessage());
            return '6215900';
        }
    }
}

class GetInformationController extends BaseController {
    public function handle(Request $request): Response {
        if (!$this->checkIp($request)) {
            return $this->json(['message' => 'Access denied'], 403);
        }

        $port = $request->get('port');
        if (empty($port)) {
            return $this->json(['error' => 'Port parameter is required'], 400);
        }

        try {
            $info = $this->saveConnectionInfo($port);
            if ($info) {
                return $this->json($info);
            }
            return $this->json(['error' => 'Info null']);
        } catch (\Exception $e) {
            $this->appLogger->log(Logger::ERROR, 'Failed to get information: ' . $e->getMessage());
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function saveConnectionInfo(string $port): ?array {
        try {
            $url = str_replace('12345', $port, Config::$settings['docker_url']) . 'rest/info';
            
            $client = new \GuzzleHttp\Client();
            $response = $client->get($url, [
                'headers' => [
                    'Content-Type' => 'application/json;charset=utf-8'
                ],
                'timeout' => Config::$settings['request_timeout']
            ]);

            $info = json_decode($response->getBody(), true);
            
            if (isset($info['lastSentDate']) && $info['lastSentDate'] !== null) {
                $lastSentDate = \DateTime::createFromFormat('Y-m-d H:i:s', $info['lastSentDate']);
                $operatorTin = $info['merchants'][0]['tin'] ?? null;
                
                $data = [
                    'lottery_count' => $info['leftLotteries'] ?? 0,
                    'last_sent_date' => $lastSentDate ? $lastSentDate->format('Y-m-d H:i:s') : null,
                    'updated_time' => (new \DateTime())->format('H:i:s'),
                    'pos_id' => $info['posId'] ?? 0,
                    'pos_no' => $info['posNo'] ?? '',
                    'merchant_in' => $operatorTin ?? '',
                    'is_working' => true
                ];

                $exists = $this->db->count('connection_info', [
                    'port' => $port
                ]);

                if (!$exists) {
                    $data['port'] = $port;
                    $this->db->insert('connection_info', $data);
                } else {
                    $this->db->update('connection_info', 
                        $data,
                        [
                            'port' => $port
                        ]
                    );
                }
            }

            return $info;

        } catch (\Exception $e) {
            $this->appLogger->log(Logger::ERROR, "Error info: " . $e->getMessage());
            return null;
        }
    }
}

class CheckRegnoHandler extends BaseController {
    public function handle(Request $request): Response {
        if (!$this->checkIp($request)) {
            return $this->json(['message' => 'Access denied'], 403);
        }

        $regNo = $request->get('reg_no');
        if (empty($regNo)) {
            return $this->json(['error' => 'Registration number is required'], 400);
        }

        try {
            $client = new \GuzzleHttp\Client();
            $headers = ['Content-Type' => 'application/json;charset=utf-8'];
            
            $response = $client->get(
                Config::$settings['ebarimt_reg_no_url'] . $regNo, 
                [
                    'headers' => $headers,
                    'timeout' => Config::$settings['request_timeout']
                ]
            );
            
            $result = json_decode($response->getBody(), true);
            $this->appLogger->log(Logger::INFO, "First API call response", ['result' => $result]);
            
            $tin = $result['data'] ?? null;
            if (!$tin) {
                $this->appLogger->log(Logger::ERROR, "No TIN found in the response");
                return $this->json(['error' => 'No TIN found in the response'], 404);
            }
            
            $responseTin = $client->get(
                Config::$settings['ebarimt_tin_url'] . $tin, 
                [
                    'headers' => $headers,
                    'timeout' => Config::$settings['request_timeout']
                ]
            );
            
            $tinResult = json_decode($responseTin->getBody(), true);
            $this->appLogger->log(Logger::INFO, "Second API call response", ['result' => $tinResult]);
            
            $data = $tinResult['data'] ?? [];
            $name = $data['name'] ?? '';
            
            $response = new Response(
                200,
                ['Content-Type' => 'application/json', 'Charset' => 'utf-8'],
                json_encode(['name' => $name])
            );
            
            return $response;
            
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $this->appLogger->log(Logger::ERROR, "HTTP Error", ['error' => $e->getMessage()]);
            return $this->json(['error' => $e->getMessage()], 500);
        } catch (\Exception $e) {
            $this->appLogger->log(Logger::ERROR, "An error occurred", ['error' => $e->getMessage()]);
            return $this->json(['error' => 'An internal error occurred'], 500);
        }
    }
}

class ErrorHandler {
    private $logger;
    
    public function __construct() {
        $this->logger = AppLogger::getInstance();
    }
    
    public function handle(Request $request): Response {
        $statusCode = 404;
        $this->logger->log(Logger::WARNING, "Request to non-existent route", [
            'path' => $request->path(),
            'method' => $request->method(),
            'ip' => $request->header('X-Real-IP') ?: $request->connection->getRemoteIp()
        ]);
        
        if ($statusCode === 404) {
            return new Response(404, [], 'Error 404');
        } elseif ($statusCode === 403) {
            return new Response(403, [], 'Error 403');
        } elseif ($statusCode === 500) {
            return new Response(500, [], 'Error 500');
        } elseif ($statusCode === 503) {
            return new Response(503, [], 'Error 503');
        } else {
            return new Response($statusCode, [], '');
        }
    }
}

class Router {
    private $routes = [];
    private $errorHandler;

    public function addRoute(string $method, string $pattern, $handler) {
        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'handler' => $handler
        ];
    }
    
    public function setErrorHandler($handler) {
        $this->errorHandler = $handler;
    }

    public function dispatch(Request $request): Response {
        $method = $request->method();
        $path = $request->path();

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $pattern = $route['pattern'];
            $pattern = preg_replace('/\{([^}]+)\}/', '(?P<$1>[^/]+)', $pattern);
            $pattern = '#^' . $pattern . '$#';

            if (preg_match($pattern, $path, $matches)) {
                $handler = $route['handler'];
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                return call_user_func_array($handler, [$request, ...array_values($params)]);
            }
        }

        if ($this->errorHandler) {
            return $this->errorHandler->handle($request);
        }
        
        return new Response(404, [], 'Not Found');
    }
}

$worker = new Worker('http://0.0.0.0:' . Config::$settings['port']);
$worker->count = 24;

$worker->daemonize = false;

$worker->onWorkerStart = function($worker) {
    try {
        $logger = AppLogger::getInstance();
        $logger->log(Logger::INFO, "Worker started on port " . Config::$settings['port']);
        
        Database::getInstance();
    } catch (\Exception $e) {
        $logger = AppLogger::getInstance();
        $logger->log(Logger::ERROR, "Worker start failed: " . $e->getMessage());
    }
};

$router = new Router();
$router->addRoute('GET', '/', [new MainController(), 'index']);
$router->addRoute('GET', '/api/getInformation', [new GetInformationController(), 'handle']);
$router->addRoute('POST', '/{district_code}/api/', [new PutCustomController(), 'handle']);
$router->addRoute('GET', '/{district_code}/send', [new SendDataHandler(), 'handle']);
$router->addRoute('GET', '/check_reg_no', [new CheckRegnoHandler(), 'handle']);
$router->addRoute('GET', '/static/{file}', function(Request $request, $file) {
    $staticPath = __DIR__ . '/static/' . $file;
    if (file_exists($staticPath)) {
        $mimeType = mime_content_type($staticPath);
        return new Response(200, ['Content-Type' => $mimeType], file_get_contents($staticPath));
    }
    return new Response(404, [], 'File not found');
});

$errorHandler = new ErrorHandler();
$router->setErrorHandler($errorHandler);

$worker->onMessage = function(TcpConnection $connection, Request $request) use ($router) {
    try {
        $response = $router->dispatch($request);
        $connection->send($response);
    } catch (\Exception $e) {
        AppLogger::getInstance()->log(\Monolog\Logger::ERROR, 'Server error', ['error' => $e->getMessage()]);
        $connection->send(new Response(500, [], 'Internal Server Error'));
    }
};

Worker::runAll();