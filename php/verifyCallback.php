<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/PaylabsSignature.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

header('Content-Type: application/json');

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$callbackFile = __DIR__ . '/last_callback.json';

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

// SSE Endpoint
if ($uri === '/events') {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');

    $lastModified = 0;
    
    // Initial check
    if (file_exists($callbackFile)) {
        $lastModified = filemtime($callbackFile);
    }

    // Keep connection open and check for file updates
    while (true) {
        clearstatcache();
        if (file_exists($callbackFile)) {
            $currentModified = filemtime($callbackFile);
            if ($currentModified > $lastModified) {
                $data = file_get_contents($callbackFile);
                echo "data: {$data}\n\n";
                ob_flush();
                flush();
                $lastModified = $currentModified;
            }
        }
        sleep(1);
        
        // Break if connection closed
        if (connection_aborted()) break;
    }
    exit;
}

// Outbound Log Endpoint (from generateTransaction.php)
if ($uri === '/log' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawBody = file_get_contents('php://input');
    file_put_contents($callbackFile, $rawBody);
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
    
    // Broadcast via file for SSE (Initial capture without response body)
    $sseData = [
        'type' => 'inbound',
        'headers' => getallheaders(),
        'body' => $body,
        'endpoint' => '/callback',
        'verificationStatus' => $valid ? 'Valid' : 'Invalid',
    ];
    file_put_contents($callbackFile, json_encode($sseData));
    
    if (!$valid) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid Signature']);
        exit;
    }
    
    error_log("Signature is valid");
    
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

    // Broadcast again with responseBody
    $sseData['responseBody'] = $responseData;
    file_put_contents($callbackFile, json_encode($sseData));
    
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
    
    // Broadcast via file for SSE
    $sseData = json_encode([
        'headers' => getallheaders(),
        'body' => $body,
        'endpoint' => '/transfer-va/payment',
        'verificationStatus' => $valid ? 'Valid' : 'Invalid',
    ]);
    file_put_contents($callbackFile, $sseData);
    
    if (!$valid) {
        http_response_code(401);
        echo json_encode(['responseCode' => '4010000', 'responseMessage' => 'Unauthorized']);
        exit;
    }
    
    error_log("SNAP Signature is valid");
    
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

    // Update broadcast with responseBody
    $sseData['responseBody'] = $responseData;
    file_put_contents($callbackFile, json_encode($sseData));

    echo json_encode($responseData);
    exit;
}

// 404 for other routes
if ($uri !== '/') {
    http_response_code(404);
    echo json_encode(['error' => 'Not Found']);
    exit;
}
