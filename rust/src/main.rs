mod signature;

use dotenv::dotenv;
use serde_json::json;
use std::env;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    dotenv().ok();

    let endpoint = env::var("QRIS_CREATE_ENDPOINT").unwrap_or_default();
    let merchant_id = env::var("MERCHANT_ID").unwrap_or_default();
    
    let request_id = signature::generate_request_id();

    let body = json!({
        "merchantId": merchant_id,
        "merchantTradeNo": format!("QRIS-{}", request_id),
        "requestId": request_id,
        "paymentType": "QRIS",
        "amount": "10000.00",
        "productName": "QRIS Payment",
        "expire": 3600,
        "feeType": "OUR",
        "payer": "John Doe",
        "notifyUrl": "https://ungirlishly-unmoralistic-antony.ngrok-free.dev/callback"
    });

    // println!("Creating QRIS Transaction...");
    // signature::create_transaction(&endpoint, &body).await?;
    
    // Example: SNAP Transaction
    let endpoint_snap = "/api/v1.0/transfer-va/create-va";
    let request_id_snap = signature::generate_request_id();
    let body_snap = json!({
        "partnerServiceId": format!("00{}", merchant_id),
        "customerNo": "00000000000000000000",
        "virtualAccountNo": "000105796289500005539",
        "virtualAccountName": "SUCCESS John - shoes**",
        "virtualAccountPhone": "+6281234567890",
        "trxId": format!("PYL{}", request_id_snap),
        "totalAmount": {
            "value": "10000.00",
            "currency": "IDR",
        },
        "billDetails": [
            {
                "billCode": "1",
                "billName": "Produk John",
                "billAmount": {
                    "value": "10000.00",
                    "currency": "IDR",
                },
            },
        ],
        "expiredDate": "2026-12-25T15:52:34+07:00",
        "virtualAccountTrxType": "1",
        "additionalInfo": {
            "paymentType": "MuamalatVA",
        },
        "requestId": request_id_snap
    });
    
    println!("Creating SNAP Transaction...");
    signature::create_transaction_snap(endpoint_snap, &body_snap).await?;

    Ok(())
}
