<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/PaylabsSignature.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

function createQRIS()
{
    $endpoint = $_ENV['QRIS_CREATE_ENDPOINT'];
    $merchantId = $_ENV['MERCHANT_ID'];

    $requestId = PaylabsSignature::generateRequestId();

    $body = [
        'merchantId' => $merchantId,
        'merchantTradeNo' => 'QRIS-' . $requestId,
        'requestId' => $requestId,
        'paymentType' => 'QRIS',
        'amount' => '10000.00',
        'productName' => 'QRIS Payment',
        'expire' => 3600,
        'feeType' => 'OUR',
        'payer' => 'John Doe',
        'notifyUrl' => 'https://ungirlishly-unmoralistic-antony.ngrok-free.dev/callback',
    ];

    echo "Creating QRIS Transaction...\n";
    PaylabsSignature::createTransaction($endpoint, $body);
}

// Example: Create General Transaction
function createGeneralTransaction()
{
    $endpoint = '/payment/v2/transaction/create'; // Adjust endpoint
    $merchantId = $_ENV['MERCHANT_ID'];
    $requestId = PaylabsSignature::generateRequestId();

    $body = [
        'merchantId' => $merchantId,
        'merchantTradeNo' => 'TRX-' . $requestId,
        'requestId' => $requestId,
        'paymentType' => 'General',
        'amount' => '50000.00',
        'productName' => 'General Payment',
        'notifyUrl' => 'https://your-domain.ngrok-free.dev/callback',
    ];

    echo "Creating General Transaction...\n";
    PaylabsSignature::createTransaction($endpoint, $body);
}

// Example: Create SNAP Transaction
function createSnapTransaction()
{
    $endpoint = '/api/v1.0/transfer-va/create-va'; // Adjust endpoint for SNAP
    $merchantId = $_ENV['MERCHANT_ID'];
    $requestId = PaylabsSignature::generateRequestId();

    $body = [
        'partnerServiceId' => "00" . $merchantId,
        'customerNo' => "00000000000000000000",
        'virtualAccountNo' => "000105796289500005539",
        'virtualAccountName' => "SUCCESS John - shoes**",
        'virtualAccountPhone' => "+6281234567890",
        'trxId' => "PYL" . $requestId,
        'totalAmount' => [
            'value' => "10000.00",
            'currency' => "IDR",
        ],
        'billDetails' => [
            [
                'billCode' => "1",
                'billName' => "Produk John",
                'billAmount' => [
                    'value' => "10000.00",
                    'currency' => "IDR",
                ],
            ],
        ],
        'expiredDate' => "2026-12-25T15:52:34+07:00",
        'virtualAccountTrxType' => "1",
        'additionalInfo' => [
            'paymentType' => "MuamalatVA",
        ],
    ];

    echo "Creating SNAP Transaction...\n";
    PaylabsSignature::createTransactionSnap($endpoint, $body); // externalId auto-handled or pass $requestId if needed
}

// createQRIS();
// createGeneralTransaction();
createSnapTransaction();
