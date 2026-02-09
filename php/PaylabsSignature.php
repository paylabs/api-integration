<?php

class PaylabsSignature
{
    /**
     * Remove null values from array recursively.
     */
    public static function removeNulls($data)
    {
        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                if ($value !== null) {
                    $result[$key] = self::removeNulls($value);
                }
            }
            return $result;
        }
        return $data;
    }

    /**
     * Minify JSON without whitespace.
     */
    public static function minifyJson($body)
    {
        $cleaned = self::removeNulls($body);
        return json_encode($cleaned, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Generate SHA256 hash in lowercase hex.
     */
    public static function sha256Hex($data)
    {
        return strtolower(hash('sha256', $data));
    }

    /**
     * Generate timestamp in WIB timezone (UTC+7).
     */
    public static function generateTimestamp()
    {
        $tz = new DateTimeZone('Asia/Jakarta');
        $now = new DateTime('now', $tz);
        return $now->format('Y-m-d\TH:i:s') . '+07:00';
    }

    /**
     * Generate unique request ID with timestamp and random number.
     */
    public static function generateRequestId()
    {
        $tz = new DateTimeZone('Asia/Jakarta');
        $now = new DateTime('now', $tz);
        $dateStr = $now->format('YmdHis');
        $randomStr = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
        return $dateStr . $randomStr;
    }

    /**
     * Generate RSA-SHA256 signature for Paylabs API.
     */
    public static function generateSignature($method, $endpoint, $body, $privateKeyPem)
    {
        $timestamp = self::generateTimestamp();
        
        $bodyHash = self::sha256Hex(self::minifyJson($body));
        echo self::minifyJson($body) . "\n";
        
        $stringToSign = "{$method}:{$endpoint}:{$bodyHash}:{$timestamp}";
        echo "String to Sign: {$stringToSign}\n";
        
        $privateKeyPem = str_replace('\\n', "\n", $privateKeyPem);
        $privateKey = openssl_pkey_get_private($privateKeyPem);
        
        openssl_sign($stringToSign, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        
        $signatureBase64 = base64_encode($signature);
        echo "Generated Signature: {$signatureBase64}\n";
        
        return [$signatureBase64, $timestamp];
    }

    /**
     * Verify RSA-SHA256 signature from Paylabs callback.
     */
    public static function verifySignature($stringToVerify, $signatureBase64, $publicKeyPem)
    {
        try {
            $publicKeyPem = str_replace('\\n', "\n", $publicKeyPem);
            $publicKey = openssl_pkey_get_public($publicKeyPem);
            
            $signature = base64_decode($signatureBase64);
            
            $result = openssl_verify($stringToVerify, $signature, $publicKey, OPENSSL_ALGO_SHA256);
            
            return $result === 1;
        } catch (Exception $e) {
            echo "Verification failed: " . $e->getMessage() . "\n";
            return false;
        }
    }

    /**
     * Log outbound request/response to the visualizer local server.
     */
    public static function logToLocalServer($endpoint, $requestHeaders, $requestBody, $responseBody)
    {
        try {
            $logPort = $_ENV['PORT'] ?? '3000';
            $logData = [
                'type' => 'outbound',
                'endpoint' => $endpoint,
                'requestHeaders' => $requestHeaders,
                'requestBody' => $requestBody,
                'responseBody' => $responseBody
            ];

            $ch = curl_init("http://localhost:$logPort/log");
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($logData));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            curl_exec($ch);
            curl_close($ch);
        } catch (Exception $e) {
            // Silently fail if visualizer is not running
        }
    }

    /**
     * Generic function to create a transaction.
     */
    public static function createTransaction($endpoint, $body)
    {
        $baseUrl = $_ENV['PAYLABS_BASE_URL'];
        $merchantId = $_ENV['MERCHANT_ID'];
        $privateKey = $_ENV['MERCHANT_PRIVATE_KEY'];

        [$signature, $timestamp] = self::generateSignature('POST', $endpoint, $body, $privateKey);

        $headers = [
            'Content-Type: application/json',
            'X-PARTNER-ID: ' . $merchantId,
            'X-TIMESTAMP: ' . $timestamp,
            'X-SIGNATURE: ' . $signature,
            'X-REQUEST-ID: ' . $body['requestId'],
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $baseUrl . $endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $respData = json_decode($response, true);
        
        // Log to local server
        self::logToLocalServer($endpoint, $headers, $body, $respData);
        
        if (curl_errno($ch)) {
            echo 'Error: ' . curl_error($ch) . "\n";
        } else {
            echo "Response: " . json_encode($respData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        }

        curl_close($ch);
    }

    /**
     * Get Public IP.
     */
    public static function getPublicIp()
    {
        try {
            $context = stream_context_create(['http' => ['timeout' => 2]]);
            $json = @file_get_contents('https://api.ipify.org?format=json', false, $context);
            if ($json === false) return '127.0.0.1';
            $data = json_decode($json, true);
            return $data['ip'] ?? '127.0.0.1';
        } catch (Exception $e) {
            return '127.0.0.1';
        }
    }

    /**
     * Create SNAP Transaction.
     */
    public static function createTransactionSnap($endpoint, $body)
    {
        $baseUrl = $_ENV['PAYLABS_BASE_URL'];
        $merchantId = $_ENV['MERCHANT_ID'];
        $privateKey = $_ENV['MERCHANT_PRIVATE_KEY'];
        
        $externalId = $body['externalId'] ?? $body['requestId'] ?? self::generateRequestId();
        $ipAddress = self::getPublicIp();
        echo "Public IP: {$ipAddress}\n";

        // SNAP signature uses a modified endpoint logic
        $signatureEndpoint = $endpoint;
        if (strpos($endpoint, '/api/v1.0') === 0) {
            $signatureEndpoint = str_replace('/api/v1.0', '', $endpoint);
        }

        [$signature, $timestamp] = self::generateSignature('POST', $signatureEndpoint, $body, $privateKey);

        $headers = [
            'Content-Type: application/json;charset=utf-8',
            'X-PARTNER-ID: ' . $merchantId,
            'X-TIMESTAMP: ' . $timestamp,
            'X-SIGNATURE: ' . $signature,
            'X-EXTERNAL-ID: ' . $externalId,
            'X-IP-ADDRESS: ' . $ipAddress,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $baseUrl . $endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $respData = json_decode($response, true);

        // Log to local server
        self::logToLocalServer($endpoint, $headers, $body, $respData);
        
        if (curl_errno($ch)) {
            echo 'Error: ' . curl_error($ch) . "\n";
        } else {
            echo "Response: " . json_encode($respData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        }

        curl_close($ch);
    }
}
