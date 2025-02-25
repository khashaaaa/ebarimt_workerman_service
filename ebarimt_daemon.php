<?php

require_once __DIR__ . '/vendor/autoload.php';

use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Medoo\Medoo;
use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;

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
                "CREATE TABLE connection_info (
                    port INT NOT NULL DEFAULT 1,
                    lottery_count INT NOT NULL DEFAULT 0,
                    is_working BOOLEAN NOT NULL DEFAULT FALSE,
                    last_sent_date DATETIME NOT NULL DEFAULT NOW(),
                    pos_id INT NOT NULL DEFAULT 0,
                    pos_no VARCHAR(255) NOT NULL DEFAULT '45',
                    updated_time DATETIME NOT NULL DEFAULT NOW(),
                    merchant_in VARCHAR(255) NOT NULL DEFAULT ''
                )",
                "CREATE TABLE category (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    bgf_code VARCHAR(255) NOT NULL UNIQUE,
                    ebarimt_code VARCHAR(255) NOT NULL DEFAULT '24',
                    company_reg VARCHAR(255) NOT NULL DEFAULT '',
                    percent FLOAT NOT NULL DEFAULT 0.0
                )",
                "CREATE TABLE group_bill (
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

abstract class BaseController {
    protected $db;
    protected $appLogger;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->appLogger = AppLogger::getInstance();
    }

    protected function checkIp(Request $request) {
        $realIp = $request->header('X-Real-IP');
        $remoteIp = $realIp ?: $request->connection->getRemoteIp();
        $this->appLogger->log(\Monolog\Logger::INFO, "Request from IP: " . $remoteIp);
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
            return $this->json(['status' => 'ok']);
        }
        return $this->json(['message' => 'Access denied'], 403);
    }
}

class PutCustomController extends BaseController {
    public function handle(Request $request, string $districtCode): Response {
        if (!$this->checkIp($request)) {
            return $this->json(['message' => 'Access denied'], 403);
        }

        $action = $request->get('store');
        if ($action === null) {
            return $this->json(['error' => 'Store parameter is required'], 400);
        }

        $store = rtrim(rtrim($action, 'put'), 'return');
        $port = (int)('9' . substr($store, -3));

        $requestData = json_decode($request->rawBody(), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->json(['error' => 'Invalid JSON data'], 400);
        }

        if (strpos($action, 'put') !== false) {
            return $this->generateEbarimt($districtCode, $requestData, $port);
        } elseif (strpos($action, 'return') !== false) {
            return $this->returnEbarimt($requestData, $port);
        }

        return $this->json(['error' => 'Invalid action'], 400);
    }

    private function generateEbarimt(string $districtCode, array $data, int $port): Response {
        try {
            $preparedData = $this->prepareData($districtCode, $data, $port);
            $url = str_replace('12345', (string)$port, Config::$settings['docker_url']) . 'rest/receipt';

            $client = new \GuzzleHttp\Client();
            $response = $client->post($url, [
                'json' => $preparedData,
                'timeout' => Config::$settings['request_timeout']
            ]);

            $responseData = json_decode($response->getBody(), true);
            $this->appLogger->log(\Monolog\Logger::INFO, 'Ebarimt response', ['response' => $responseData]);

            return $this->json([
                'transID' => $data['transID'] ?? '',
                'amount' => $responseData['totalAmount'] ?? 0,
                'billId' => $responseData['id'] ?? '',
                'lottery' => $responseData['lottery'] ?? '',
                'qrData' => $responseData['qrData'] ?? '',
                'success' => $response->getStatusCode() === 200
            ]);
            $this->appLogger->log(Logger::DEBUG, 'Request payload', ['payload' => $preparedData]);
        } catch (\Exception $e) {
            $this->appLogger->log(\Monolog\Logger::ERROR, 'Ebarimt generation failed', ['error' => $e->getMessage()]);
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function returnEbarimt(array $data, int $port): Response {
        try {
            $billId = $data['returnBillId'] ?? '';
            if (empty($billId)) {
                return $this->json(['error' => 'Return bill ID is required'], 400);
            }

            $dbSuffix = substr((string)$port, -3);
            $dbPath = "/opt/sites/env/ebarimt-3.0/vatps_00{$dbSuffix}.db";

            if (!file_exists($dbPath)) {
                return $this->json(['error' => 'Database not found'], 404);
            }

            $pdo = new \PDO("sqlite:{$dbPath}");
            $stmt = $pdo->prepare("SELECT check_date FROM checkreceipt WHERE receipt_id = ?");
            $stmt->execute([$billId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$row) {
                return $this->json(['error' => 'Receipt not found'], 404);
            }

            $url = str_replace('12345', (string)$port, Config::$settings['docker_url']) . 'rest/receipt';
            $client = new \GuzzleHttp\Client();
            $response = $client->delete($url, [
                'json' => [
                    'id' => $billId,
                    'date' => $row['check_date']
                ]
            ]);

            return $this->json(['message' => 'Receipt deleted successfully']);
        } catch (\Exception $e) {
            $this->appLogger->log(\Monolog\Logger::ERROR, 'Return bill failed', ['error' => $e->getMessage()]);
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function prepareData(string $districtCode, array $originalData, int $port): array {
        try {
            $billType = $originalData['billType'] ?? '';
            $receiptType = match($billType) {
                '1' => 'B2C_RECEIPT',
                '3' => 'B2B_RECEIPT',
                default => 'B2B_RECEIPT'
            };
    
            $merchantTin = $this->fetchMerchantTin($port);
            $merchantName = $this->fetchMerchantName($merchantTin, $port);
    
            $formattedDistrictCode = strlen($districtCode) === 4 
                ? $districtCode 
                : $districtCode . '01';
            $branchNo = strlen($districtCode) === 4 
                ? substr($districtCode, 2) 
                : $districtCode;
    
            $customerNo = $originalData['customerNo'] ?? '';
            $customerTin = '';
            if ($customerNo) {
                try {
                    $client = new \GuzzleHttp\Client();
                    $response = $client->get(Config::$settings['ebarimt_reg_no_url'] . $customerNo, [
                        'timeout' => Config::$settings['request_timeout']
                    ]);
                    $result = json_decode($response->getBody(), true);
                    $customerTin = $result['data'] ?? $customerNo;
                } catch (\Exception $e) {
                    $this->appLogger->log(\Monolog\Logger::ERROR, "Failed to fetch TIN for customerNo {$customerNo}: " . $e->getMessage());
                    $customerTin = $customerNo;
                }
            }
    
            $receipts = [];
            foreach ($originalData['stocks'] ?? [] as $stock) {
                $totalAmount = (float)($stock['totalAmount'] ?? 0);
                $cityTax = (float)($stock['cityTax'] ?? 0);
                
                $taxType = 'VAT_ABLE';
                $vatAmount = $totalAmount > 0 ? ($cityTax > 0 ? $totalAmount / 11.2 : $totalAmount / 11) : 0;
                $cityTaxAmount = $cityTax > 0 ? $vatAmount * 0.2 : 0;
    
                $barCode = $stock['barCode'] ?? '';
                $barCodeType = strlen($barCode) === 13 ? 'GS1' : 'UNDEFINED';
    
                $item = [
                    'name' => $stock['name'] ?? '',
                    #'taxProductCode' => $this->fetchTaxProductCode($stock['code'] ?? ''),
                    'taxProductCode' => '',
                    'barCode' => $barCode,
                    'barCodeType' => $barCodeType,
                    'classificationCode' => $this->fetchClassificationCode($stock['code'] ?? ''),
                    'measureUnit' => $stock['measureUnit'] ?? '',
                    'qty' => (int)((float)($stock['qty'] ?? 1)),
                    'unitPrice' => (float)($stock['unitPrice'] ?? 0),
                    'totalAmount' => $totalAmount,
                    'totalVAT' => round($vatAmount, 2),
                    'totalCityTax' => round($cityTaxAmount, 2),
                    'taxType' => $taxType
                ];
    
                $receipts = [[
                    'totalAmount' => $totalAmount,
                    'taxType' => $taxType,
                    'merchantTin' => $merchantTin,
                    'merchantName' => $merchantName,
                    'totalVAT' => round($vatAmount, 2),
                    'totalCityTax' => round($cityTaxAmount, 2),
                    'items' => [$item]
                ]];
            }
    
            $totalAmount = array_sum(array_column($receipts, 'totalAmount'));
            $totalVat = array_sum(array_column($receipts, 'totalVAT'));
            $totalCityTax = array_sum(array_column($receipts, 'totalCityTax'));
    
            $result = [
                'totalAmount' => $totalAmount,
                'totalVAT' => round($totalVat, 2),
                'totalCityTax' => round($totalCityTax, 2),
                'districtCode' => $formattedDistrictCode,
                'merchantTin' => $merchantTin,
                'merchantName' => $merchantName,
                'branchNo' => $branchNo,
                'customerTin' => $customerTin,
                'type' => $receiptType,
                'receipts' => $receipts,
                'payments' => []
            ];
    
            $nonCashAmount = (float)($originalData['nonCashAmount'] ?? 0);
            $cashAmount = (float)($originalData['cashAmount'] ?? 0);
    
            if ($nonCashAmount > 0) {
                $result['payments'][] = [
                    'status' => 'PAID',
                    'code' => 'PAYMENT_CARD',
                    'paidAmount' => round($nonCashAmount, 2)
                ];
            }
            if ($cashAmount > 0) {
                $result['payments'][] = [
                    'status' => 'PAID',
                    'code' => 'CASH',
                    'paidAmount' => round($cashAmount, 2)
                ];
            }
    
            return $result;
        } catch (\Exception $e) {
            $this->appLogger->log(\Monolog\Logger::ERROR, 'Error preparing data: ' . $e->getMessage());
            throw $e;
        }
    }
    
    private function fetchTaxProductCode(string $code): string {
        try {
            $result = $this->db->get('tax_products', 'code', [
                'item_code' => $code
            ]);
            return $result ?: '447';
        } catch (\Exception $e) {
            $this->appLogger->log(\Monolog\Logger::ERROR, 'Failed to fetch tax product code: ' . $e->getMessage());
            return '447';
        }
    }

    private function fetchMerchantTin(int $port): string {
        try {
            $result = $this->db->get('connection_info', 'merchant_in', [
                'port' => $port
            ]);
            return $result ?: Config::$settings['company_merchant_tin'];
        } catch (\Exception $e) {
            $this->appLogger->log(\Monolog\Logger::ERROR, 'Database access failed: ' . $e->getMessage());
            return '6215900';
        }
    }


    private function fetchMerchantName(string $merchantTin, int $port): string {
        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->get("http://10.10.90.234/api/getInformation?port={$port}");
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
            $this->appLogger->log(\Monolog\Logger::ERROR, 'Failed to fetch merchant info: ' . $e->getMessage());
            return '';
        }
    }

    private function fetchSubMerchantName(string $merchantTin, int $port): string {
        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->get("http://10.10.90.234/api/getInformation?port={$port}");
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
            $this->appLogger->log(\Monolog\Logger::ERROR, 'Failed to fetch sub-merchant info: ' . $e->getMessage());
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
            $this->logger->log(Logger::ERROR, 'Database access failed: ' . $e->getMessage());
            return '6215900';
        }
    }
}

class GetInformationController extends BaseController {
    public function handle(Request $request): Response {
        $requestId = uniqid('req_', true);
        $this->appLogger->log(\Monolog\Logger::INFO, 
            "GetInformation Request", [
                'request_id' => $requestId,
                'path' => $request->path(),
                'method' => $request->method()
            ]
        );

        if (!$this->checkIp($request)) {
            $this->appLogger->log(\Monolog\Logger::WARNING, 
                "Access denied", [
                    'request_id' => $requestId,
                    'ip' => $request->header('X-Real-IP') ?: $request->connection->getRemoteIp()
                ]
            );
            return $this->json(['message' => 'Access denied'], 403);
        }

        $port = $request->get('port');
        if (empty($port)) {
            $this->appLogger->log(\Monolog\Logger::ERROR, 
                "Missing port parameter", [
                    'request_id' => $requestId
                ]
            );
            return $this->json(['error' => 'Port parameter is required'], 400);
        }

        $this->appLogger->log(\Monolog\Logger::INFO, 
            "Processing request", [
                'request_id' => $requestId,
                'port' => $port
            ]
        );

        try {
            $info = $this->saveConnectionInfo($port, $requestId);
            if ($info) {
                $this->appLogger->log(\Monolog\Logger::INFO, 
                    "Request completed successfully", [
                        'request_id' => $requestId,
                        'port' => $port
                    ]
                );
                return new Response(
                    200,
                    ['Content-Type' => 'application/json'],
                    json_encode($info)
                );
            }
            
            $this->appLogger->log(\Monolog\Logger::ERROR, 
                "No info returned", [
                    'request_id' => $requestId,
                    'port' => $port
                ]
            );
            return $this->json(['error' => 'Info null']);
            
        } catch (\Exception $e) {
            $this->appLogger->log(\Monolog\Logger::ERROR, 
                'Failed to get information: ' . $e->getMessage(), [
                    'request_id' => $requestId,
                    'port' => $port,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]
            );
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function saveConnectionInfo(string $port, string $requestId): ?array {
        try {
            $url = str_replace('12345', $port, Config::$settings['docker_url']) . 'rest/info';
            
            $this->appLogger->log(\Monolog\Logger::INFO, 
                "Fetching info from docker service", [
                    'request_id' => $requestId,
                    'url' => $url
                ]
            );
            
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
            $this->appLogger->log(\Monolog\Logger::ERROR, 
                "Error saving connection info: " . $e->getMessage(), [
                    'request_id' => $requestId,
                    'port' => $port,
                    'error' => $e->getMessage()
                ]
            );
            return null;
        }
    }
}

class Router {
    private $routes = [];

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
                return $handler($request, ...array_values($params));
            }
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
