use actix_cors::Cors;
use actix_files::Files;
use actix_web::{web, App, HttpRequest, HttpResponse, HttpServer, Responder};
use base64::{engine::general_purpose::STANDARD, Engine};
use dotenv::dotenv;
use futures::StreamExt;
use rsa::pkcs8::DecodePublicKey;
use rsa::sha2::Sha256;
use rsa::{Pkcs1v15Sign, RsaPublicKey};
use serde::{Deserialize, Serialize};
use serde_json::json;
use sha2::{Digest, Sha256 as Sha256Hasher};
use std::env;
use std::sync::Arc;
use tokio::sync::broadcast;
use tokio_stream::wrappers::BroadcastStream;

fn sha256_hex(data: &str) -> String {
    let mut hasher = Sha256Hasher::new();
    hasher.update(data.as_bytes());
    hex::encode(hasher.finalize()).to_lowercase()
}

fn verify_signature(string_to_verify: &str, signature_base64: &str, public_key_pem: &str) -> bool {
    let public_key_pem = public_key_pem.replace("\\n", "\n");

    let public_key = match RsaPublicKey::from_public_key_pem(&public_key_pem) {
        Ok(key) => key,
        Err(e) => {
            println!("Failed to parse public key: {}", e);
            return false;
        }
    };

    let signature = match STANDARD.decode(signature_base64) {
        Ok(sig) => sig,
        Err(e) => {
            println!("Failed to decode signature: {}", e);
            return false;
        }
    };

    let signing_key = Pkcs1v15Sign::new::<Sha256>();
    let mut hasher = Sha256Hasher::new();
    hasher.update(string_to_verify.as_bytes());
    let hashed = hasher.finalize();

    public_key.verify(signing_key, &hashed, &signature).is_ok()
}

#[derive(Deserialize)]
struct CallbackBody {
    #[serde(rename = "requestId")]
    request_id: Option<String>,
    #[serde(rename = "merchantId")]
    merchant_id: Option<String>,
    status: Option<String>,
}

#[derive(Serialize)]
struct CallbackResponse {
    #[serde(rename = "requestId")]
    request_id: String,
    #[serde(rename = "errCode")]
    err_code: String,
    #[serde(rename = "errCodeDes")]
    err_code_des: String,
    #[serde(rename = "merchantId")]
    merchant_id: String,
}

struct AppState {
    tx: broadcast::Sender<String>,
}

async fn sse_client(state: web::Data<AppState>) -> impl Responder {
    let rx = state.tx.subscribe();
    let stream = BroadcastStream::new(rx).map(|msg| {
        match msg {
            Ok(msg) => Ok(web::Bytes::from(format!("data: {}\n\n", msg))),
            Err(_) => Err(actix_web::error::ErrorInternalServerError("Broadcast error")),
        }
    });

    HttpResponse::Ok()
        .insert_header(("Content-Type", "text/event-stream"))
        .insert_header(("Cache-Control", "no-cache"))
        .insert_header(("Connection", "keep-alive"))
        .streaming(stream)
}

async fn callback_handler(
    req: HttpRequest,
    body: web::Bytes,
    state: web::Data<AppState>,
) -> HttpResponse {
    let signature = req
        .headers()
        .get("X-Signature")
        .and_then(|v| v.to_str().ok())
        .unwrap_or("");
    let timestamp = req
        .headers()
        .get("X-Timestamp")
        .and_then(|v| v.to_str().ok())
        .unwrap_or("");
    let public_key = env::var("PAYLABS_PUBLIC_KEY").unwrap_or_default();

    println!("Incoming Callback Headers:");
    let mut headers_map = serde_json::Map::new();
    for (name, value) in req.headers() {
        println!("  {}: {:?}", name, value);
        if let Ok(v) = value.to_str() {
            headers_map.insert(name.to_string(), json!(v));
        }
    }

    let raw_body = String::from_utf8_lossy(&body);
    let sha_json = sha256_hex(&raw_body);

    let string_to_verify = format!("POST:/callback:{}:{}", sha_json, timestamp);
    println!("String to Verify: {}", string_to_verify);

    let valid = verify_signature(&string_to_verify, signature, &public_key);

    let body_json: Value = serde_json::from_slice(&body).unwrap_or(json!({}));
    let status = body_json["status"].as_str().unwrap_or("");
    let request_id = body_json["requestId"].as_str().unwrap_or("").to_string();
    let merchant_id = body_json["merchantId"].as_str().unwrap_or("").to_string();

    let response_data = if status != "02" {
        json!(CallbackResponse {
            request_id,
            err_code: "1".to_string(),
            err_code_des: "Payment not completed".to_string(),
            merchant_id,
        })
    } else {
        json!(CallbackResponse {
            request_id,
            err_code: "0".to_string(),
            err_code_des: "Success".to_string(),
            merchant_id,
        })
    };

    // Broadcast with responseBody
    let sse_data = json!({
        "type": "inbound",
        "headers": headers_map,
        "body": body_json,
        "endpoint": "/callback",
        "verificationStatus": if valid { "Valid" } else { "Invalid" },
        "responseBody": response_data
    });
    let _ = state.tx.send(sse_data.to_string());

    if !valid {
        return HttpResponse::BadRequest().body("Invalid Signature");
    }

    println!("Signature is valid");
    HttpResponse::Ok().json(response_data)
}

async fn snap_callback_handler(
    req: HttpRequest,
    body: web::Bytes,
    state: web::Data<AppState>,
) -> HttpResponse {
    let signature = req.headers().get("X-Signature").and_then(|v| v.to_str().ok()).unwrap_or("");
    let timestamp = req.headers().get("X-Timestamp").and_then(|v| v.to_str().ok()).unwrap_or("");
    let public_key = env::var("PAYLABS_PUBLIC_KEY").unwrap_or_default();

    println!("Incoming SNAP Callback Headers:");
    let mut headers_map = serde_json::Map::new();
    for (name, value) in req.headers() {
        println!("  {}: {:?}", name, value);
        if let Ok(v) = value.to_str() {
            headers_map.insert(name.to_string(), json!(v));
        }
    }

    let raw_body = String::from_utf8_lossy(&body);
    let sha_json = sha256_hex(&raw_body);

    let string_to_verify = format!("POST:/transfer-va/payment:{}:{}", sha_json, timestamp);
    println!("SNAP String to Verify: {}", string_to_verify);

    let valid = verify_signature(&string_to_verify, signature, &public_key);
    let body_json: Value = serde_json::from_slice(&body).unwrap_or(json!({}));

    let allowed_fields = vec![
        "paidBills", "virtualAccountNo", "paymentRequestId", "partnerServiceId",
        "virtualAccountPhone", "virtualAccountName", "journalNum", "flagAdvise",
        "trxId", "paymentFlagReason", "virtualAccountEmail", "billDetails",
        "totalAmount", "customerNo", "paymentType", "paidAmount", "referenceNo",
        "trxDateTime", "freeTexts", "paymentFlagStatus"
    ];

    let mut filtered_body = serde_json::Map::new();
    if let Some(obj) = body_json.as_object() {
        for field in allowed_fields {
            if let Some(val) = obj.get(field) {
                filtered_body.insert(field.to_string(), val.clone());
            }
        }
    }

    filtered_body.insert("paymentFlagStatus".to_string(), json!("00"));

    let response_data = json!({
        "responseCode": "2002500",
        "responseMessage": "Success",
        "virtualAccountData": filtered_body
    });

    // Update SSE with responseBody
    let sse_data = json!({
        "type": "inbound",
        "headers": headers_map,
        "body": body_json,
        "endpoint": "/transfer-va/payment",
        "verificationStatus": if valid { "Valid" } else { "Invalid" },
        "responseBody": response_data
    });
    let _ = state.tx.send(sse_data.to_string());

    if !valid {
        return HttpResponse::Unauthorized().json(json!({
            "responseCode": "4010000",
            "responseMessage": "Unauthorized"
        }));
    }

    println!("SNAP Signature is valid");
    HttpResponse::Ok().json(response_data)
}

async fn snap_create_va_handler(
    req: HttpRequest,
    body: web::Bytes,
    state: web::Data<AppState>,
) -> HttpResponse {
    let signature = req.headers().get("X-Signature").and_then(|v| v.to_str().ok()).unwrap_or("");
    let timestamp = req.headers().get("X-Timestamp").and_then(|v| v.to_str().ok()).unwrap_or("");
    let public_key = env::var("PAYLABS_PUBLIC_KEY").unwrap_or_default();

    println!("Incoming SNAP Create VA Headers:");
    let mut headers_map = serde_json::Map::new();
    for (name, value) in req.headers() {
        println!("  {}: {:?}", name, value);
        if let Ok(v) = value.to_str() {
            headers_map.insert(name.to_string(), json!(v));
        }
    }

    let raw_body = String::from_utf8_lossy(&body);
    let sha_json = sha256_hex(&raw_body);

    // Pattern: POST:/transfer-va/create-va:{bodyHash}:{timestamp}
    let string_to_verify = format!("POST:/transfer-va/create-va:{}:{}", sha_json, timestamp);
    println!("SNAP Create VA String to Verify: {}", string_to_verify);

    let valid = verify_signature(&string_to_verify, signature, &public_key);
    let body_json: Value = serde_json::from_slice(&body).unwrap_or(json!({}));

    let response_code = if valid { "2002700" } else { "4012701" };
    let response_message = if valid { "Success" } else { "Invalid Signature" };

    let response_data = json!({
        "responseCode": response_code,
        "responseMessage": response_message
    });

    // Broadcast
    let sse_data = json!({
        "type": "inbound",
        "headers": headers_map,
        "body": body_json,
        "endpoint": "/api/v1.0/transfer-va/create-va",
        "verificationStatus": if valid { "Valid" } else { "Invalid" },
        "responseBody": response_data
    });
    let _ = state.tx.send(sse_data.to_string());

    if !valid {
        return HttpResponse::Unauthorized().json(response_data);
    }

    println!("SNAP Create VA Signature is valid");
    HttpResponse::Ok().json(response_data)
}

async fn log_handler(
    body: web::Bytes,
    state: web::Data<AppState>,
) -> HttpResponse {
    let raw_body = String::from_utf8_lossy(&body);
    let _ = state.tx.send(raw_body.to_string());
    HttpResponse::Ok().finish()
}

#[actix_web::main]
async fn main() -> std::io::Result<()> {
    dotenv().ok();

    let port: u16 = env::var("PORT")
        .unwrap_or_else(|_| "3000".to_string())
        .parse()
        .unwrap_or(3000);

    let (tx, _) = broadcast::channel(100);
    let state = web::Data::new(AppState { tx });

    println!("Callback server listening on port {}", port);
    println!("Open http://localhost:{} to visualize callbacks", port);

    HttpServer::new(move || {
        App::new()
            .app_data(state.clone())
            .wrap(Cors::permissive())
            .route("/callback", web::post().to(callback_handler))
            .route("/transfer-va/payment", web::post().to(snap_callback_handler))
            .route("/api/v1.0/transfer-va/create-va", web::post().to(snap_create_va_handler))
            .route("/log", web::post().to(log_handler))
            .route("/events", web::get().to(sse_client))
            .service(Files::new("/", "./static").index_file("index.html"))
    })
    .bind(("127.0.0.1", port))?
    .run()
    .await
}
