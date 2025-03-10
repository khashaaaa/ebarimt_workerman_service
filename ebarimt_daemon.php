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
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Promise;

class Config {
    private static $instance = null;
    private $settings = [];
    
    private function __construct() {
        $this->settings = [
            'request_timeout' => 15,
            'port' => 8888,
            'ebarimt_reg_no_url' => 'https://api.ebarimt.mn/api/info/check/getTinInfo?regNo=',
            'ebarimt_tin_url' => 'https://api.ebarimt.mn/api/info/check/getInfo?tin=',
            'docker_url' => 'http://10.10.90.234:12345/',
            'company_register' => '0000038',
            'company_merchant_tin' => '77100185651',
            'bank_id' => '15',
            'ebarimt_group_enabled' => true,
            'worker_count' => 32,
            'log_level' => Logger::INFO,
            'cache_ttl' => 300,
            'max_connections' => 30,
            'memory_limit' => '256M',
            'request_limit' => 500,
            'db_host' => '10.10.90.234',
            'db_name' => 'ebarimt3_db',
            'db_user' => 'ebarimt_user',
            'db_pass' => 'Ebarimt_2022.',
            'db_port' => 3306
        ];
        
        foreach ($this->settings as $key => $default) {
            $envKey = 'EBARIMT_' . strtoupper($key);
            if (getenv($envKey) !== false) {
                $this->settings[$key] = getenv($envKey);
            }
        }
    }
    
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function get(string $key) {
        return $this->settings[$key] ?? null;
    }
}

class Cache {
    private static $instance = null;
    private $cache = [];
    private $expires = [];
    
    private function __construct() {}
    
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function get(string $key) {
        $this->cleanup();
        return isset($this->cache[$key]) ? $this->cache[$key] : null;
    }
    
    public function set(string $key, $value, int $ttl = 300): void {
        $this->cache[$key] = $value;
        $this->expires[$key] = time() + $ttl;
    }
    
    public function has(string $key): bool {
        $this->cleanup();
        return isset($this->cache[$key]);
    }
    
    private function cleanup(): void {
        $now = time();
        foreach ($this->expires as $key => $expiry) {
            if ($expiry < $now) {
                unset($this->cache[$key]);
                unset($this->expires[$key]);
            }
        }
    }
}

class AppLogger {
    private static $loggers = [];
    private $logger;
    private $requestId;
    private $logLevel;
    
    private function __construct(string $name = 'ebarimt') {
        $config = Config::getInstance();
        $this->logLevel = $config->get('log_level');
        $logDir = __DIR__ . '/ebarimt_log3';
        
        if (!is_dir($logDir) && !mkdir($logDir, 0755, true)) {
            throw new \RuntimeException("Failed to create log directory: $logDir");
        }
        
        $this->logger = new Logger($name);
        $handler = new RotatingFileHandler(
            "$logDir/{$name}.log",
            5,
            $this->logLevel
        );
        
        $processor = function ($record) {
            $record['extra']['request_id'] = $this->requestId;
            $record['extra']['memory'] = memory_get_usage(true);
            $record['extra']['pid'] = getmypid();
            return $record;
        };
        
        $this->logger->pushProcessor($processor);
        $this->logger->pushHandler($handler);
    }
    
    public static function getLogger(string $name = 'ebarimt'): self {
        if (!isset(self::$loggers[$name])) {
            self::$loggers[$name] = new self($name);
        }
        return self::$loggers[$name];
    }
    
    public function setRequestId(string $requestId): void {
        $this->requestId = $requestId;
    }
    
    public function log($level, $message, array $context = []): void {
        if ($level >= $this->logLevel) {
            foreach ($context as $key => $value) {
                if (is_string($value) && strlen($value) > 1000) {
                    $context[$key] = substr($value, 0, 1000) . '... [truncated]';
                }
            }
            $this->logger->log($level, $message, $context);
        }
    }
}

class Database {
    private static $pools = [];
    private $db;
    private $logger;
    private $inUse = false;
    
    private function __construct() {
        $config = Config::getInstance();
        $this->logger = AppLogger::getLogger('database');
        
        $this->connect();
    }
    
    private function connect() {
        $config = Config::getInstance();
        try {
            $this->db = new Medoo([
                'type' => 'mysql',
                'host' => $config->get('db_host'),
                'database' => $config->get('db_name'),
                'username' => $config->get('db_user'),
                'password' => $config->get('db_pass'),
                'charset' => 'utf8mb4',
                'port' => $config->get('db_port'),
                'options' => [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_PERSISTENT => false,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_TIMEOUT => 5,
                    PDO::MYSQL_ATTR_FOUND_ROWS => true
                ]
            ]);
            
            $this->db->query("SELECT 1")->fetch();
            $this->logger->log(Logger::DEBUG, "MySQL connection established");
        } catch (\PDOException $e) {
            $this->logger->log(Logger::ERROR, "MySQL connection failed: " . $e->getMessage());
            throw $e;
        }
    }
    
    public static function getConnection() {
        $workerId = getmypid();
        
        if (!isset(self::$pools[$workerId])) {
            self::$pools[$workerId] = [];
            $config = Config::getInstance();
            $maxConnections = $config->get('max_connections');
            
            for ($i = 0; $i < $maxConnections; $i++) {
                self::$pools[$workerId][] = new self();
            }
        }
        
        foreach (self::$pools[$workerId] as $conn) {
            if (!$conn->inUse) {
                $conn->inUse = true;
                return $conn;
            }
        }
        
        $logger = AppLogger::getLogger('database');
        $logger->log(Logger::WARNING, "All database connections in use, creating emergency connection");
        $conn = new self();
        $conn->inUse = true;
        self::$pools[$workerId][] = $conn;
        return $conn;
    }
    
    public function release() {
        $this->inUse = false;
    }
    
    public function getDb() {
        return $this->db;
    }
    
    public function __destruct() {
        $this->db = null;
    }
    
    public static function runMigrations() {
        $conn = self::getConnection();
        try {
            $db = $conn->getDb();
            
            $db->query("CREATE TABLE IF NOT EXISTS connection_info (
                port INT NOT NULL DEFAULT 1,
                lottery_count INT NOT NULL DEFAULT 0,
                is_working BOOLEAN NOT NULL DEFAULT FALSE,
                last_sent_date DATETIME NOT NULL DEFAULT NOW(),
                pos_id INT NOT NULL DEFAULT 0,
                pos_no VARCHAR(255) NOT NULL DEFAULT '45',
                updated_time DATETIME NOT NULL DEFAULT NOW(),
                merchant_in VARCHAR(255) NOT NULL DEFAULT ''
            )");
            
            $db->query("CREATE TABLE IF NOT EXISTS category (
                id INT AUTO_INCREMENT PRIMARY KEY,
                bgf_code VARCHAR(255) NOT NULL UNIQUE,
                ebarimt_code VARCHAR(255) NOT NULL DEFAULT '24',
                company_reg VARCHAR(255) NOT NULL DEFAULT '',
                percent FLOAT NOT NULL DEFAULT 0.0
            )");
            
            $db->query("CREATE TABLE IF NOT EXISTS group_bill (
                id INT AUTO_INCREMENT PRIMARY KEY,
                bar_code VARCHAR(255) NULL,
                group_tin VARCHAR(255) NULL,
                taxProductCode VARCHAR(255) NULL
            )");
            
        } finally {
            $conn->release();
        }
    }
}

class HttpClient {
    private static $instance = null;
    private $client;
    private $cache;
    private $logger;
    
    private function __construct() {
        $this->client = new Client([
            'timeout' => Config::getInstance()->get('request_timeout'),
            'connect_timeout' => 3,
            'http_errors' => false,
            'headers' => [
                'User-Agent' => 'EBarimt-Service/3.0',
                'Accept' => 'application/json'
            ]
        ]);
        
        $this->cache = Cache::getInstance();
        $this->logger = AppLogger::getLogger('http');
    }
    
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function get(string $url, array $options = [], int $cacheTtl = 0) {
        if ($cacheTtl > 0) {
            $cacheKey = 'http_get_' . md5($url . json_encode($options));
            if ($this->cache->has($cacheKey)) {
                $this->logger->log(Logger::DEBUG, "Cache hit for URL: {$url}");
                return $this->cache->get($cacheKey);
            }
        }
        
        try {
            $response = $this->client->get($url, $options);
            $statusCode = $response->getStatusCode();
            
            if ($statusCode >= 200 && $statusCode < 300) {
                $result = json_decode($response->getBody(), true);
                
                if ($cacheTtl > 0) {
                    $this->cache->set($cacheKey, $result, $cacheTtl);
                }
                
                return $result;
            } else {
                $this->logger->log(Logger::WARNING, "HTTP request failed", [
                    'url' => $url,
                    'status_code' => $statusCode
                ]);
                return null;
            }
        } catch (\Exception $e) {
            $this->logger->log(Logger::ERROR, "HTTP request exception", [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    public function post(string $url, array $data, array $options = []) {
        try {
            $options['json'] = $data;
            $response = $this->client->post($url, $options);
            
            return [
                'status_code' => $response->getStatusCode(),
                'data' => json_decode($response->getBody(), true),
                'success' => $response->getStatusCode() >= 200 && $response->getStatusCode() < 300
            ];
        } catch (\Exception $e) {
            $this->logger->log(Logger::ERROR, "HTTP POST request exception", [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            
            return [
                'status_code' => 500,
                'error' => $e->getMessage(),
                'success' => false
            ];
        }
    }
    
    public function delete(string $url, array $data, array $options = []) {
        try {
            $options['json'] = $data;
            $response = $this->client->delete($url, $options);
            
            return [
                'status_code' => $response->getStatusCode(),
                'data' => json_decode($response->getBody(), true),
                'success' => $response->getStatusCode() >= 200 && $response->getStatusCode() < 300
            ];
        } catch (\Exception $e) {
            $this->logger->log(Logger::ERROR, "HTTP DELETE request exception", [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            
            return [
                'status_code' => 500,
                'error' => $e->getMessage(),
                'success' => false
            ];
        }
    }
}

class RateLimiter {
    private static $instance = null;
    private $requests = [];
    private $limit;
    
    private function __construct() {
        $this->limit = Config::getInstance()->get('request_limit');
    }
    
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function check(string $ip): bool {
        $this->cleanup();
        
        $minute = floor(time() / 60);
        $key = $ip . ':' . $minute;
        
        if (!isset($this->requests[$key])) {
            $this->requests[$key] = 1;
            return true;
        }
        
        if ($this->requests[$key] >= $this->limit) {
            return false;
        }
        
        $this->requests[$key]++;
        return true;
    }
    
    private function cleanup(): void {
        $currentMinute = floor(time() / 60);
        foreach (array_keys($this->requests) as $key) {
            $parts = explode(':', $key);
            $minute = (int)($parts[1] ?? 0);
            
            if ($minute < $currentMinute - 2) {
                unset($this->requests[$key]);
            }
        }
    }
}

abstract class BaseController {
    protected $logger;
    protected $cache;
    protected $httpClient;
    protected $rateLimiter;
    protected $config;
    
    public function __construct() {
        $this->logger = AppLogger::getLogger();
        $this->cache = Cache::getInstance();
        $this->httpClient = HttpClient::getInstance();
        $this->rateLimiter = RateLimiter::getInstance();
        $this->config = Config::getInstance();
    }
    
    protected function checkIp(Request $request) {
        $realIp = $request->header('X-Real-IP');
        $remoteIp = $realIp ?: $request->connection->getRemoteIp();
        
        if (!$this->rateLimiter->check($remoteIp)) {
            $this->logger->log(Logger::WARNING, "Rate limit exceeded for IP: {$remoteIp}");
            return false;
        }
        
        return true;
    }
    
    protected function json(array $data, int $status = 200): Response {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)
        );
    }
    
    protected function getDbConnection() {
        return Database::getConnection();
    }
}

class MainController extends BaseController {
    public function index(Request $request): Response {
        if (!$this->checkIp($request)) {
            return $this->json(['message' => 'Rate limit exceeded'], 429);
        }
        
        return $this->json([
            'status' => 'ok', 
            'timestamp' => time(),
            'server_id' => php_uname('n')
        ]);
    }
}

class PutCustomController extends BaseController {
    private $requestId;
    
    public function handle(Request $request, string $districtCode): Response {
        $this->requestId = uniqid();
        $this->logger->setRequestId($this->requestId);
        
        if (!$this->checkIp($request)) {
            return $this->json(['message' => 'Rate limit exceeded'], 429);
        }
        
        $action = $request->get('store');
        if ($action === null) {
            return $this->json(['error' => 'Store parameter is required'], 400);
        }
        
        $store = rtrim(rtrim($action, 'put'), 'return');
        $port = (int)('9' . substr($store, -3));
        
        try {
            $requestData = json_decode($request->rawBody(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->json(['error' => 'Invalid JSON data'], 400);
            }
        } catch (\Exception $e) {
            return $this->json(['error' => 'Failed to parse request body'], 400);
        }
        
        if (strpos($action, 'put') !== false) {
            return $this->generateEbarimt($districtCode, $requestData, $port);
        } elseif (strpos($action, 'return') !== false) {
            return $this->returnEbarimt($requestData, $port);
        }
        
        return $this->json(['error' => 'Invalid action'], 400);
    }
    
    private function generateEbarimt(string $districtCode, array $data, int $port): Response {
        $transID = $data['transID'] ?? '';
        
        $this->logger->log(Logger::DEBUG, 'Processing original input', [
            'transID' => $transID,
        ]);
        
        try {
            $preparedData = $this->prepareData($districtCode, $data, $port);
            
            $this->logger->log(Logger::INFO, 'Generating receipt', [
                'transID' => $transID,
                'district_code' => $districtCode,
                'port' => $port
            ]);
            
            $url = str_replace('12345', (string)$port, $this->config->get('docker_url')) . 'rest/receipt';
            
            $response = $this->httpClient->post($url, $preparedData);
            
            if (!$response['success']) {
                return $this->handleApiError($response, $transID);
            }
            
            $responseData = $response['data'];
            
            $dataId = $responseData['id'] ?? '';
            $lottery = $responseData['lottery'] ?? '';
            $qrdata = $responseData['qrData'] ?? '';
            $totalAmount = $responseData['totalAmount'] ?? 0;
            
            $subBillInfo = $this->extractSubBillInfo($responseData, $dataId, $port);
            
            return $this->json([
                'transID' => $transID,
                'amount' => $totalAmount,
                'billId' => $dataId,
                'subBillId' => $subBillInfo['id'] ?? null,
                'subBillName' => $subBillInfo['name'] ?? null,
                'subBillAmount' => $subBillInfo['amount'] ?? 0,
                'lottery' => $lottery,
                'qrData' => $qrdata,
                'success' => true
            ]);
            
        } catch (\Exception $e) {
            $this->logger->log(Logger::ERROR, 'Exception generating ebarimt', [
                'transID' => $transID,
                'error' => $e->getMessage()
            ]);
            
            return $this->json([
                'error' => 'Internal server error',
                'success' => false,
                'transID' => $transID
            ], 500);
        }
    }
    
    private function handleApiError(array $response, string $transID): Response {
        $statusCode = $response['status_code'];
        $errorData = $response['data'] ?? null;
        
        if ($statusCode >= 400 && $statusCode < 500) {
            return $this->json([
                'error' => is_array($errorData) ? $errorData : 'Client error',
                'success' => false,
                'transID' => $transID
            ], 400);
        } else {
            return $this->json([
                'error' => 'Server error occurred',
                'details' => is_array($errorData) ? $errorData : null,
                'success' => false,
                'transID' => $transID
            ], 500);
        }
    }
    
    private function extractSubBillInfo(array $responseData, string $dataId, int $port): array {
        $result = ['id' => null, 'name' => null, 'amount' => 0];
        
        if (empty($dataId) || empty($responseData['receipts'])) {
            return $result;
        }
        
        $first10DataId = substr($dataId, 0, 10);
        
        foreach ($responseData['receipts'] as $receipt) {
            $receiptId = $receipt['id'] ?? '';
            if (!empty($receiptId) && substr($receiptId, 0, 10) === $first10DataId) {
                $result['id'] = $receiptId;
                $result['name'] = $this->fetchMerchantName($receipt['merchantTin'] ?? '', $port);
                $result['amount'] = $receipt['totalAmount'] ?? 0;
                break;
            }
        }
        
        return $result;
    }
    
    private function returnEbarimt(array $data, int $port): Response {
        $this->logger->log(Logger::INFO, 'Processing return bill request', [
            'port' => $port
        ]);
        
        $returnBillId = $data['returnBillId'] ?? '';
        if (empty($returnBillId)) {
            return $this->json(['error' => 'Missing returnBillId parameter'], 400);
        }
        
        $createdDate = $this->getBillCreatedDate($port, $returnBillId);
        
        $url = str_replace('12345', (string)$port, $this->config->get('docker_url')) . 'rest/receipt';
        
        $payload = [
            'id' => $returnBillId,
            'date' => $createdDate
        ];
        
        $response = $this->httpClient->delete($url, $payload);
        
        if ($response['success']) {
            $message = "Receipt deleted successfully";
            if (isset($response['data']['message'])) {
                $message = $response['data']['message'];
            }
            
            return $this->json(['message' => $message]);
        } else {
            $statusCode = $response['status_code'];
            $errorMessage = isset($response['data']['message']) 
                ? $response['data']['message'] 
                : "Failed with status code: {$statusCode}";
            
            return $this->json([
                'error' => 'Failed to delete receipt',
                'message' => $errorMessage
            ], $statusCode >= 500 ? 500 : 400);
        }
    }
    
    private function getBillCreatedDate(int $port, string $billId): ?string {
        $dbSuffix = substr((string)$port, -3);
        $dbPath = "/opt/sites/env/ebarimt-3.0/vatps_00{$dbSuffix}.db";
        
        if (!file_exists($dbPath)) {
            $this->logger->log(Logger::WARNING, "SQLite database not found", ['path' => $dbPath]);
            return null;
        }
        
        try {
            $pdo = new \PDO("sqlite:{$dbPath}", null, null, [
                \PDO::ATTR_TIMEOUT => 5,
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION
            ]);
            
            $stmt = $pdo->prepare("SELECT check_date FROM checkreceipt WHERE receipt_id = ? LIMIT 1");
            $stmt->execute([$billId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            $stmt = null;
            $pdo = null;
            
            if ($row && !empty($row['check_date'])) {
                if (preg_match('/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $row['check_date'], $matches)) {
                    return $matches[1];
                }
            }
            
            return null;
        } catch (\PDOException $e) {
            $this->logger->log(Logger::ERROR, "SQLite error", ['error' => $e->getMessage()]);
            return null;
        }
    }
    
    private function prepareData(string $districtCode, array $originalData, int $port): array {
        $dbConn = $this->getDbConnection();
        try {
            $db = $dbConn->getDb();
            
            $merchantTin = $this->fetchMerchantTin($db, $port);
            
            if (strlen($districtCode) === 4) {
                $formattedDistrictCode = $districtCode;
                $branchNo = substr($districtCode, 2);
            } else {
                $formattedDistrictCode = $districtCode . '01';
                $branchNo = $districtCode;
            }
            
            $customerNo = $originalData['customerNo'] ?? '';
            $customerTin = $this->getCustomerTin($customerNo);
            
            $groupBills = [];
            $groupBillsData = $db->select('group_bill', [
                'bar_code', 'group_tin', 'taxProductCode'
            ]);
            
            foreach ($groupBillsData as $gb) {
                if (!empty($gb['bar_code'])) {
                    $groupBills[$gb['bar_code']] = $gb;
                }
            }
            
            $merchantItems = $this->processStockItems(
                $originalData['stocks'] ?? [], 
                $groupBills, 
                $merchantTin, 
                $db,
                $port
            );
            
            $receipts = [];
            $totalVat = 0;
            $totalCityTax = 0;
            $totalAmount = 0;
            
            foreach ($merchantItems as $tin => $data) {
                $receipts[] = [
                    'totalAmount' => (float)$data['totalAmount'],
                    'taxType' => 'VAT_ABLE',
                    'merchantTin' => !empty($merchantTin) ? (string)$merchantTin : $this->config->get('company_merchant_tin'),
                    'merchantSubName' => $data['merchantSubName'],
                    'totalVAT' => (float)$data['totalVAT'],
                    'totalCityTax' => (float)$data['totalCityTax'],
                    'items' => $data['items']
                ];
                
                $totalVat += $data['totalVAT'];
                $totalCityTax += $data['totalCityTax'];
                $totalAmount += $data['totalAmount'];
            }
            
            $result = [
                'totalAmount' => (float)$totalAmount,
                'totalVAT' => (float)$totalVat,
                'totalCityTax' => (float)$totalCityTax,
                'districtCode' => $formattedDistrictCode,
                'merchantTin' => (string)$merchantTin,
                'merchantName' => $this->fetchMerchantName($merchantTin, $port),
                'branchNo' => $branchNo,
                'customerTin' => $customerTin ? $customerTin : ($originalData['customerNo'] ?? ''),
                'type' => ($originalData['billType'] ?? '') === '1' ? 'B2C_RECEIPT' : 'B2B_RECEIPT',
                'receipts' => $receipts,
                'payments' => $this->buildPayments($originalData)
            ];
            
            return $result;
        } finally {
            $dbConn->release();
        }
    }
    
    private function processStockItems(array $stocks, array $groupBills, string $merchantTin, $db, int $port): array {
        $merchantItems = [];
        
        foreach ($stocks as $stock) {
            $totalAmount = (float)($stock['totalAmount'] ?? 0);
            $cityTax = (float)($stock['cityTax'] ?? 0);
            
            $barCode = $stock['barCode'] ?? '';
            $groupBill = $groupBills[$barCode] ?? null;
            
            list($taxType, $vatAmount, $cityTaxAmount, $taxProductCode) = 
                $this->calculateTaxInfo($totalAmount, $cityTax, $groupBill);
            
            $barCodeType = $this->getBarCodeType($barCode);
            
            $item = [
                'name' => (string)($stock['name'] ?? ''),
                'taxProductCode' => (string)$taxProductCode ?: $this->fetchClassificationCode($db, $stock['code'] ?? ''),
                'barCode' => (string)$barCode,
                'barCodeType' => (string)$barCodeType,
                'classificationCode' => (string)$this->fetchClassificationCode($db, $stock['code'] ?? ''),
                'measureUnit' => (string)($stock['measureUnit'] ?? ''),
                'qty' => (int)((float)($stock['qty'] ?? 1)),
                'unitPrice' => (float)($stock['unitPrice'] ?? 0),
                'totalAmount' => (float)$totalAmount,
                'totalVAT' => (float)$vatAmount,
                'totalCityTax' => (float)$cityTaxAmount,
                'taxType' => (string)$taxType
            ];
            
            $itemMerchantTin = isset($groupBill['group_tin']) && !empty($groupBill['group_tin']) 
                ? $groupBill['group_tin'] 
                : $merchantTin;
            
            if (!isset($merchantItems[$itemMerchantTin])) {
                $merchantItems[$itemMerchantTin] = [
                    'items' => [],
                    'totalAmount' => 0,
                    'totalVAT' => 0,
                    'totalCityTax' => 0,
                    'merchantSubName' => $this->fetchSubMerchantName($itemMerchantTin, $port)
                ];
            }
            
            $merchantItems[$itemMerchantTin]['items'][] = $item;
            $merchantItems[$itemMerchantTin]['totalAmount'] += $totalAmount;
            $merchantItems[$itemMerchantTin]['totalVAT'] += $vatAmount;
            $merchantItems[$itemMerchantTin]['totalCityTax'] += $cityTaxAmount;
        }
        
        return $merchantItems;
    }
    
    private function calculateTaxInfo(float $totalAmount, float $cityTax, ?array $groupBill): array {
        $taxType = 'VAT_ABLE';
        $vatAmount = 0;
        $cityTaxAmount = 0;
        $taxProductCode = isset($groupBill['taxProductCode']) ? (string)$groupBill['taxProductCode'] : '';
        
        if (!empty($groupBill)) {
            $taxType = 'VAT_ZERO';
            $taxProductCode = '447';
        } elseif ($totalAmount > 0) {
            if ($cityTax > 0) {
                $vatAmount = $totalAmount / 11.2;
                $cityTaxAmount = $vatAmount * 0.2;
            } else {
                $vatAmount = $totalAmount / 11;
            }
        }
        
        return [$taxType, $vatAmount, $cityTaxAmount, $taxProductCode];
    }
    
    private function getBarCodeType(string $barCode): string {
        if (strlen($barCode) === 13) {
            return 'GS1';
        } elseif (in_array($barCode, ['6900456387254', '6757990902668'])) {
            return 'UNDEFINED';
        }
        return 'UNDEFINED';
    }
    
    private function buildPayments(array $originalData): array {
        $payments = [];
        $nonCashAmount = (float)($originalData['nonCashAmount'] ?? 0);
        $cashAmount = (float)($originalData['cashAmount'] ?? 0);
        
        if ($nonCashAmount > 0) {
            $payments[] = [
                'status' => 'PAID',
                'code' => 'PAYMENT_CARD',
                'paidAmount' => $nonCashAmount
            ];
        }
        
        if ($cashAmount > 0) {
            $payments[] = [
                'status' => 'PAID',
                'code' => 'CASH',
                'paidAmount' => $cashAmount
            ];
        }
        
        return $payments;
    }
    
    private function getCustomerTin(string $customerNo): ?string {
        if (empty($customerNo)) {
            return null;
        }
        
        $cacheKey = 'customer_tin_' . $customerNo;
        if ($this->cache->has($cacheKey)) {
            return $this->cache->get($cacheKey);
        }
        
        try {
            $result = $this->httpClient->get(
                $this->config->get('ebarimt_reg_no_url') . $customerNo,
                [],
                300
            );
            
            $customerTin = isset($result['data']) ? $result['data'] : null;
            
            if ($customerTin) {
                $this->cache->set($cacheKey, $customerTin, 3600);
            }
            
            return $customerTin;
        } catch (\Exception $e) {
            $this->logger->log(Logger::WARNING, "Failed to fetch TIN", [
                'customerNo' => $customerNo,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    private function fetchMerchantTin($db, int $port): string {
        try {
            $cacheKey = 'merchant_tin_' . $port;
            if ($this->cache->has($cacheKey)) {
                return $this->cache->get($cacheKey);
            }
            
            $result = $db->get('connection_info', 'merchant_in', [
                'port' => $port  
            ]);
            
            $tin = $result ?: $this->config->get('company_merchant_tin');
            $this->cache->set($cacheKey, $tin, 300);
            
            return $tin;
        } catch (\Exception $e) {
            $this->logger->log(Logger::WARNING, 'Database access failed when fetching merchant TIN', [
                'error' => $e->getMessage()
            ]);
            return $this->config->get('company_merchant_tin');
        }
    }
    
    private function fetchMerchantName(string $merchantTin, int $port): string {
        $cacheKey = 'merchant_name_' . $merchantTin . '_' . $port;
        if ($this->cache->has($cacheKey)) {
            return $this->cache->get($cacheKey);
        }
        
        try {
            $result = $this->httpClient->get(
                "http://10.10.90.234:8888/api/getInformation?port={$port}",
                [],
                60
            );
            
            if (isset($result['merchants'])) {
                foreach ($result['merchants'] as $merchant) {
                    if (($merchant['tin'] ?? '') === $merchantTin) {
                        $name = $merchant['name'] ?? '';
                        $this->cache->set($cacheKey, $name, 300);
                        return $name;
                    }
                }
            }
            return '';
        } catch (\Exception $e) {
            $this->logger->log(Logger::WARNING, 'Failed to fetch merchant name', [
                'error' => $e->getMessage()
            ]);
            return '';
        }
    }
    
    private function fetchSubMerchantName(string $merchantTin, int $port): string {
        $cacheKey = 'sub_merchant_name_' . $merchantTin . '_' . $port;
        if ($this->cache->has($cacheKey)) {
            return $this->cache->get($cacheKey);
        }
        
        try {
            $result = $this->httpClient->get(
                "http://10.10.90.234:8888/api/getInformation?port={$port}",
                [],
                60
            );
            
            if (isset($result['merchants'][0]['customers'])) {
                foreach ($result['merchants'][0]['customers'] as $customer) {
                    if (($customer['tin'] ?? '') === $merchantTin) {
                        $name = $customer['name'] ?? '';
                        $this->cache->set($cacheKey, $name, 300);
                        return $name;
                    }
                }
            }
            return '';
        } catch (\Exception $e) {
            $this->logger->log(Logger::WARNING, 'Failed to fetch sub-merchant name', [
                'error' => $e->getMessage()
            ]);
            return '';
        }
    }
    
    private function fetchClassificationCode($db, string $itemCode): string {
        if (empty($itemCode)) {
            return '6215900';
        }
        
        $cacheKey = 'classification_code_' . $itemCode;
        if ($this->cache->has($cacheKey)) {
            return $this->cache->get($cacheKey);
        }
        
        try {
            $result = $db->get('category', 'ebarimt_code', [
                'bgf_code' => $itemCode
            ]);
            
            $code = $result ?: '6215900';
            $this->cache->set($cacheKey, $code, 3600);
            
            return $code;
        } catch (\Exception $e) {
            $this->logger->log(Logger::WARNING, 'Database access failed when fetching classification code', [
                'error' => $e->getMessage()
            ]);
            return '6215900';
        }
    }
}

class GetInformationController extends BaseController {
    public function handle(Request $request): Response {
        $requestId = uniqid('req_', true);
        $this->logger->setRequestId($requestId);
        
        if (!$this->checkIp($request)) {
            return $this->json(['message' => 'Rate limit exceeded'], 429);
        }
        
        $port = $request->get('port');
        if (empty($port)) {
            return $this->json(['error' => 'Port parameter is required'], 400);
        }
        
        $this->logger->log(Logger::INFO, "Processing information request", [
            'port' => $port
        ]);
        
        try {
            $cacheKey = 'docker_info_' . $port;
            if ($this->cache->has($cacheKey)) {
                return new Response(
                    200,
                    ['Content-Type' => 'application/json'],
                    json_encode($this->cache->get($cacheKey))
                );
            }
            
            $info = $this->getConnectionInfo($port, $requestId);
            if ($info) {
                $this->cache->set($cacheKey, $info, 60);
                
                return new Response(
                    200,
                    ['Content-Type' => 'application/json'],
                    json_encode($info)
                );
            }
            
            return $this->json(['error' => 'No information available']);
            
        } catch (\Exception $e) {
            $this->logger->log(Logger::ERROR, 'Failed to get information', [
                'error' => $e->getMessage()
            ]);
            return $this->json(['error' => 'Internal server error'], 500);
        }
    }
    
    private function getConnectionInfo(string $port, string $requestId): ?array {
        try {
            $url = str_replace('12345', $port, $this->config->get('docker_url')) . 'rest/info';
            
            $this->logger->log(Logger::DEBUG, "Fetching info from docker service", [
                'url' => $url
            ]);
            
            $response = $this->httpClient->get($url);
            if (!$response) {
                return null;
            }
            
            $this->saveConnectionInfoToDb($port, $response);
            
            return $response;
            
        } catch (\Exception $e) {
            $this->logger->log(Logger::ERROR, "Error getting connection info", [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    private function saveConnectionInfoToDb(string $port, array $info): void {
        if (!isset($info['lastSentDate']) || $info['lastSentDate'] === null) {
            return;
        }
        
        $dbConn = $this->getDbConnection();
        try {
            $db = $dbConn->getDb();
            
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
            
            $exists = $db->count('connection_info', [
                'port' => $port
            ]);
            
            if (!$exists) {
                $data['port'] = $port;
                $db->insert('connection_info', $data);
            } else {
                $db->update('connection_info', 
                    $data,
                    [
                        'port' => $port
                    ]
                );
            }
        } finally {
            $dbConn->release();
        }
    }
}

class Router {
    private $routes = [];
    private $logger;
    
    public function __construct() {
        $this->logger = AppLogger::getLogger('router');
    }
    
    public function addRoute(string $method, string $pattern, $handler) {
        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'handler' => $handler
        ];
    }
    
    public function dispatch(Request $request): Response {
        $method = $request->method();
        $path = $request->path();
        $startTime = microtime(true);
        
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
                
                try {
                    $response = $handler($request, ...array_values($params));
                    
                    $endTime = microtime(true);
                    $this->logger->log(Logger::DEBUG, "Request completed", [
                        'path' => $path,
                        'method' => $method,
                        'time_ms' => round(($endTime - $startTime) * 1000),
                        'memory' => memory_get_usage(true)
                    ]);
                    
                    return $response;
                } catch (\Exception $e) {
                    $this->logger->log(Logger::ERROR, "Error handling request", [
                        'path' => $path,
                        'method' => $method,
                        'error' => $e->getMessage()
                    ]);
                    
                    return new Response(500, ['Content-Type' => 'application/json'], 
                        json_encode(['error' => 'Internal server error']));
                }
            }
        }
        
        return new Response(404, ['Content-Type' => 'application/json'], 
            json_encode(['error' => 'Not found']));
    }
}

class ResourceMonitor {
    private static $instance = null;
    private $stats = [];
    private $startTime;
    private $logger;
    
    private function __construct() {
        $this->startTime = time();
        $this->logger = AppLogger::getLogger('monitor');
        
        $this->stats = [
            'requests' => 0,
            'errors' => 0,
            'peak_memory' => 0,
            'start_time' => $this->startTime
        ];
    }
    
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function recordRequest(bool $isError = false): void {
        $this->stats['requests']++;
        if ($isError) {
            $this->stats['errors']++;
        }
        
        $currentMemory = memory_get_usage(true);
        if ($currentMemory > $this->stats['peak_memory']) {
            $this->stats['peak_memory'] = $currentMemory;
        }
        
        if ($this->stats['requests'] % 100 === 0) {
            $this->logStats();
        }
    }
    
    private function logStats(): void {
        $uptime = time() - $this->startTime;
        $stats = $this->stats;
        $stats['uptime_seconds'] = $uptime;
        $stats['requests_per_second'] = $uptime > 0 ? round($stats['requests'] / $uptime, 2) : 0;
        $stats['peak_memory_mb'] = round($stats['peak_memory'] / (1024 * 1024), 2);
        
        $this->logger->log(Logger::INFO, "Resource stats", $stats);
        
        if ($stats['peak_memory_mb'] > 100) {
            gc_collect_cycles();
        }
    }
}

$config = Config::getInstance();
$monitor = ResourceMonitor::getInstance();

ini_set('memory_limit', $config->get('memory_limit'));

$worker = new Worker('http://0.0.0.0:' . $config->get('port'));
$worker->count = $config->get('worker_count');
$worker->name = 'ebarimt-service';

$worker->daemonize = false;

$worker->onWorkerStart = function($worker) {
    try {
        $logger = AppLogger::getLogger();
        $logger->log(Logger::INFO, "Worker #{$worker->id} started", [
            'pid' => getmypid(),
            'memory' => memory_get_usage(true)
        ]);
        
        Database::runMigrations();
        
    } catch (\Exception $e) {
        $logger = AppLogger::getLogger();
        $logger->log(Logger::ERROR, "Worker #{$worker->id} start failed: " . $e->getMessage(), [
            'trace' => $e->getTraceAsString()
        ]);
    }
};

$router = new Router();
$router->addRoute('GET', '/', [new MainController(), 'index']);
$router->addRoute('GET', '/api/getInformation', [new GetInformationController(), 'handle']);
$router->addRoute('POST', '/{district_code}/api/', [new PutCustomController(), 'handle']);

$worker->onMessage = function(TcpConnection $connection, Request $request) use ($router, $monitor) {
    $isError = false;
    
    try {
        $monitor->recordRequest(false);
        $response = $router->dispatch($request);
        $connection->send($response);
        
    } catch (\Exception $e) {
        $isError = true;
        $monitor->recordRequest(true);
        
        $logger = AppLogger::getLogger();
        $logger->log(Logger::ERROR, 'Unhandled exception', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        
        $connection->send(new Response(
            500, 
            ['Content-Type' => 'application/json'],
            json_encode(['error' => 'Internal server error'])
        ));
    }
};

Worker::runAll();