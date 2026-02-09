<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/PaylabsSignature.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Performance & SSE Optimization
set_time_limit(0);
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', false);
while (ob_get_level()) ob_end_flush();
ob_implicit_flush(true);

header('Content-Type: application/json');

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$callbackFile = __DIR__ . '/last_callback.json';

function logEvent($data) {
    global $callbackFile;
    $history = [];
    if (file_exists($callbackFile)) {
        $content = file_get_contents($callbackFile);
        $history = json_decode($content, true);
        if (!is_array($history)) $history = [];
    }
    
    // Add unique ID and timestamp if not present
    if (!isset($data['id'])) {
        $data['id'] = uniqid('ev_', true);
    }
    if (!isset($data['timestamp'])) {
        $data['timestamp'] = date('H:i:s');
    }

    // Check if event already exists for update
    $foundIndex = -1;
    foreach ($history as $index => $item) {
        if ($item['id'] === $data['id']) {
            $foundIndex = $index;
            break;
        }
    }

    if ($foundIndex !== -1) {
        $history[$foundIndex] = array_merge($history[$foundIndex], $data);
    } else {
        // Prepend new event
        array_unshift($history, $data);
        // Limit to 50 items
        $history = array_slice($history, 0, 50);
    }
    
    file_put_contents($callbackFile, json_encode($history, JSON_PRETTY_PRINT));
}

// Serve Frontend
if ($uri === '/' || $uri === '/index.html') {
    header('Content-Type: text/html');
    echo file_get_contents(__DIR__ . '/views/index.html');
    exit;
}

// Serve CSS
if ($uri === '/css/style.css') {
    header('Content-Type: text/css');
    echo file_get_contents(__DIR__ . '/views/css/style.css');
    exit;
}

// Serve JS
if ($uri === '/js/client.js') {
    header('Content-Type: application/javascript');
    echo file_get_contents(__DIR__ . '/views/js/client.js');
    exit;
}

// One-shot SSE Endpoint
if ($uri === '/events') {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');

    if (file_exists($callbackFile)) {
        $content = file_get_contents($callbackFile);
        $history = json_decode($content, true);
        if (is_array($history)) {
            // Send history in reverse (oldest first) so client appends correctly
            foreach (array_reverse($history) as $data) {
                echo "data: " . json_encode($data) . "\n\n";
            }
        }
    }
    // End the connection immediately after history delivery to avoid blocking
    // the single-threaded PHP built-in server.
    exit;
}

// Outbound Log Endpoint (from generateTransaction.php)
if ($uri === '/log' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawBody = file_get_contents('php://input');
    $logData = json_decode($rawBody, true);
    if ($logData) {
        logEvent($logData);
    }
    echo json_encode(['status' => 'success']);
    exit;
}

// Handle Callback
if ($uri === '/callback' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get headers
    $signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
    $timestamp = $_SERVER['HTTP_X_TIMESTAMP'] ?? '';
    $publicKey = $_ENV['PAYLABS_PUBLIC_KEY'];
    
    error_log("Incoming Callback Headers:");
    foreach (getallheaders() as $name => $value) {
        error_log("  {$name}: {$value}");
    }
    
    // Read raw body
    $rawBody = file_get_contents('php://input');
    
    // Calculate hash
    $shaJson = strtolower(hash('sha256', $rawBody));
    
    // Build string to verify
    $stringToVerify = "POST:/callback:{$shaJson}:{$timestamp}";
    error_log("String to Verify: {$stringToVerify}");
    
    // Verify signature
    $valid = PaylabsSignature::verifySignature($stringToVerify, $signature, $publicKey);
    
    $body = json_decode($rawBody, true);
    
    $requestId = $body['requestId'] ?? '';
    $merchantId = $body['merchantId'] ?? '';
    $status = $body['status'] ?? '';

    $responseData = $status !== '02' ? [
        'requestId' => $requestId,
        'errCode' => '1',
        'errCodeDes' => 'Payment not completed',
        'merchantId' => $merchantId,
    ] : [
        'requestId' => $requestId,
        'errCode' => '0',
        'errCodeDes' => 'Success',
        'merchantId' => $merchantId,
    ];

    // Broadcast via file for SSE
    logEvent([
        'id' => $requestId ?: uniqid('ev_', true),
        'type' => 'inbound',
        'headers' => getallheaders(),
        'body' => $body,
        'endpoint' => '/callback',
        'verificationStatus' => $valid ? 'Valid' : 'Invalid',
        'responseBody' => $responseData
    ]);
    
    if (!$valid) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid Signature']);
        exit;
    }
    
    error_log("Signature is valid");
    
    echo json_encode($responseData);
    exit;
}

// Handle SNAP Callback
if ($uri === '/transfer-va/payment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get headers
    $signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
    $timestamp = $_SERVER['HTTP_X_TIMESTAMP'] ?? '';
    $partnerId = $_SERVER['HTTP_X_PARTNER_ID'] ?? '';
    $externalId = $_SERVER['HTTP_X_EXTERNAL_ID'] ?? '';
    $ipAddress = $_SERVER['HTTP_X_IP_ADDRESS'] ?? '';
    $publicKey = $_ENV['PAYLABS_PUBLIC_KEY'];
    
    error_log("Incoming SNAP Callback Headers:");
    foreach (getallheaders() as $name => $value) {
        error_log("  {$name}: {$value}");
    }
    
    // Read raw body
    $rawBody = file_get_contents('php://input');
    
    // Calculate hash
    $shaJson = strtolower(hash('sha256', $rawBody));
    
    // Build string to verify
    $stringToVerify = "POST:/transfer-va/payment:{$shaJson}:{$timestamp}";
    error_log("SNAP String to Verify: {$stringToVerify}");
    
    // Verify signature
    $valid = PaylabsSignature::verifySignature($stringToVerify, $signature, $publicKey);
    
    $body = json_decode($rawBody, true);
    
    // Filter allowed fields
    $allowedFields = [
        "paidBills", "virtualAccountNo", "paymentRequestId", "partnerServiceId",
        "virtualAccountPhone", "virtualAccountName", "journalNum", "flagAdvise",
        "trxId", "paymentFlagReason", "virtualAccountEmail", "billDetails",
        "totalAmount", "customerNo", "paymentType", "paidAmount", "referenceNo",
        "trxDateTime", "freeTexts", "paymentFlagStatus"
    ];
    
    $filteredBody = [];
    foreach ($allowedFields as $field) {
        if (array_key_exists($field, $body)) {
            $filteredBody[$field] = $body[$field];
        }
    }
    
    // paymentFlagStatus inside virtualAccountData
    $filteredBody['paymentFlagStatus'] = "00";
    
    $responseData = [
        'responseCode' => '2002500',
        'responseMessage' => 'Success',
        'virtualAccountData' => $filteredBody,
    ];

    $paymentRequestId = $body['paymentRequestId'] ?? '';

    // Broadcast via file for SSE
    logEvent([
        'id' => $paymentRequestId ?: uniqid('ev_', true),
        'type' => 'inbound',
        'headers' => getallheaders(),
        'body' => $body,
        'endpoint' => '/transfer-va/payment',
        'verificationStatus' => $valid ? 'Valid' : 'Invalid',
        'responseBody' => $responseData
    ]);
    
    if (!$valid) {
        http_response_code(401);
        echo json_encode(['responseCode' => '4010000', 'responseMessage' => 'Unauthorized']);
        exit;
    }
    
    error_log("SNAP Signature is valid");

    echo json_encode($responseData);
    exit;
}

// Handle SNAP Create VA Callback
if ($uri === '/api/v1.0/transfer-va/create-va' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
    $timestamp = $_SERVER['HTTP_X_TIMESTAMP'] ?? '';
    $publicKey = $_ENV['PAYLABS_PUBLIC_KEY'];

    error_log("Incoming SNAP Create VA Headers:");
    foreach (getallheaders() as $name => $value) {
        error_log("  {$name}: {$value}");
    }

    $rawBody = file_get_contents('php://input');
    $shaJson = strtolower(hash('sha256', $rawBody));

    $stringToVerify = "POST:/transfer-va/create-va:{$shaJson}:{$timestamp}";
    error_log("SNAP Create VA String to Verify: {$stringToVerify}");

    $valid = PaylabsSignature::verifySignature($stringToVerify, $signature, $publicKey);
    $body = json_decode($rawBody, true);

    $responseCode = $valid ? "2002700" : "4012701";
    $responseMessage = $valid ? "Success" : "Invalid Signature";

    $responseData = [
        'responseCode' => $responseCode,
        'responseMessage' => $responseMessage
    ];

    $paymentRequestId = $body['paymentRequestId'] ?? '';
    logEvent([
        'id' => $paymentRequestId ?: uniqid('ev_', true),
        'type' => 'inbound',
        'headers' => getallheaders(),
        'body' => $body,
        'endpoint' => '/api/v1.0/transfer-va/create-va',
        'verificationStatus' => $valid ? 'Valid' : 'Invalid',
        'responseBody' => $responseData
    ]);

    if (!$valid) {
        http_response_code(401);
    }
    echo json_encode($responseData);
    exit;
}

// 404 for other routes
if ($uri !== '/') {
    http_response_code(404);
    echo json_encode(['error' => 'Not Found']);
    exit;
}
