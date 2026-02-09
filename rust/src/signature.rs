use base64::{engine::general_purpose::STANDARD, Engine};
use chrono::{FixedOffset, Utc};
use rand::Rng;
use rsa::pkcs8::DecodePrivateKey;
use rsa::sha2::Sha256;
use rsa::{Pkcs1v15Sign, RsaPrivateKey};
use serde_json::{json, Value};
use sha2::{Digest, Sha256 as Sha256Hasher};

pub fn remove_nulls(value: &Value) -> Value {
    match value {
        Value::Object(map) => {
            let filtered: serde_json::Map<String, Value> = map
                .iter()
                .filter(|(_, v)| !v.is_null())
                .map(|(k, v)| (k.clone(), remove_nulls(v)))
                .collect();
            Value::Object(filtered)
        }
        Value::Array(arr) => Value::Array(arr.iter().map(remove_nulls).collect()),
        _ => value.clone(),
    }
}

pub fn minify_json(body: &Value) -> String {
    let cleaned = remove_nulls(body);
    serde_json::to_string(&cleaned).unwrap()
}

pub fn sha256_hex(data: &str) -> String {
    let mut hasher = Sha256Hasher::new();
    hasher.update(data.as_bytes());
    hex::encode(hasher.finalize()).to_lowercase()
}

pub fn generate_timestamp() -> String {
    let wib = FixedOffset::east_opt(7 * 3600).unwrap();
    let now = Utc::now().with_timezone(&wib);
    format!("{}+07:00", now.format("%Y-%m-%dT%H:%M:%S"))
}

pub fn generate_request_id() -> String {
    let wib = FixedOffset::east_opt(7 * 3600).unwrap();
    let now = Utc::now().with_timezone(&wib);
    let date_str = now.format("%Y%m%d%H%M%S").to_string();
    let random_num: u32 = rand::thread_rng().gen_range(0..1000000);
    format!("{}{:06}", date_str, random_num)
}

pub fn generate_signature(
    method: &str,
    endpoint: &str,
    body: &Value,
    private_key_pem: &str,
) -> Result<(String, String), Box<dyn std::error::Error>> {
    let timestamp = generate_timestamp();

    let minified = minify_json(body);
    println!("{}", minified);

    let body_hash = sha256_hex(&minified);
    let string_to_sign = format!("{}:{}:{}:{}", method, endpoint, body_hash, timestamp);
    println!("String to Sign: {}", string_to_sign);

    let private_key_pem = private_key_pem.replace("\\n", "\n");
    let private_key = RsaPrivateKey::from_pkcs8_pem(&private_key_pem)?;

    let signing_key = Pkcs1v15Sign::new::<Sha256>();
    let mut hasher = Sha256Hasher::new();
    hasher.update(string_to_sign.as_bytes());
    let hashed = hasher.finalize();

    let signature = private_key.sign(signing_key, &hashed)?;
    let signature_base64 = STANDARD.encode(&signature);
    println!("Generated Signature: {}", signature_base64);

    Ok((signature_base64, timestamp))
}

pub async fn log_to_local_server(
    endpoint: &str,
    request_headers: serde_json::Map<String, Value>,
    request_body: &Value,
    response_body: &Value,
) {
    let log_data = json!({
        "type": "outbound",
        "endpoint": endpoint,
        "requestHeaders": request_headers,
        "requestBody": request_body,
        "responseBody": response_body,
    });

    let log_port = std::env::var("PORT").unwrap_or_else(|_| "3000".to_string());
    let client = reqwest::Client::new();
    let _ = client
        .post(format!("http://localhost:{}/log", log_port))
        .json(&log_data)
        .timeout(std::time::Duration::from_secs(2))
        .send()
        .await;
}

pub async fn create_transaction(
    endpoint: &str,
    body: &Value,
) -> Result<(), Box<dyn std::error::Error>> {
    dotenv::dotenv().ok();
    
    let base_url = std::env::var("PAYLABS_BASE_URL").unwrap_or_default();
    let merchant_id = std::env::var("MERCHANT_ID").unwrap_or_default();
    let private_key = std::env::var("MERCHANT_PRIVATE_KEY").unwrap_or_default();
    
    let (sig, timestamp) = generate_signature("POST", endpoint, body, &private_key)?;
    let request_id = body["requestId"].as_str().unwrap_or_default();
    
    let client = reqwest::Client::new();
    let response = client
        .post(format!("{}{}", base_url, endpoint))
        .header("Content-Type", "application/json")
        .header("X-PARTNER-ID", &merchant_id)
        .header("X-TIMESTAMP", &timestamp)
        .header("X-SIGNATURE", &sig)
        .header("X-REQUEST-ID", request_id)
        .json(&body)
        .send()
        .await?;

    let result: serde_json::Value = response.json().await?;
    
    // Log to local server
    let mut log_headers = serde_json::Map::new();
    log_headers.insert("Content-Type".to_string(), json!("application/json"));
    log_headers.insert("X-PARTNER-ID".to_string(), json!(merchant_id));
    log_headers.insert("X-TIMESTAMP".to_string(), json!(timestamp));
    log_headers.insert("X-SIGNATURE".to_string(), json!(sig));
    log_headers.insert("X-REQUEST-ID".to_string(), json!(request_id));
    log_to_local_server(endpoint, log_headers, body, &result).await;

    println!("{}", serde_json::to_string_pretty(&result)?);
    
    Ok(())
}

pub async fn get_public_ip() -> String {
    let client = reqwest::Client::new();
    match client.get("https://api.ipify.org?format=json").send().await {
        Ok(resp) => {
            if let Ok(json) = resp.json::<serde_json::Value>().await {
                if let Some(ip) = json["ip"].as_str() {
                    return ip.to_string();
                }
            }
            "127.0.0.1".to_string()
        }
        Err(_) => "127.0.0.1".to_string(),
    }
}

pub async fn create_transaction_snap(
    endpoint: &str,
    body: &Value,
) -> Result<(), Box<dyn std::error::Error>> {
    dotenv::dotenv().ok();
    
    let base_url = std::env::var("PAYLABS_BASE_URL").unwrap_or_default();
    let merchant_id = std::env::var("MERCHANT_ID").unwrap_or_default();
    let private_key = std::env::var("MERCHANT_PRIVATE_KEY").unwrap_or_default();
    
    let ip_address = get_public_ip().await;
    println!("Public IP: {}", ip_address);
    
    let mut body = body.clone();
    let external_id = body.as_object_mut().and_then(|m| {
        m.remove("externalId").or_else(|| m.remove("requestId"))
    }).and_then(|v| v.as_str().map(|s| s.to_string())).unwrap_or_else(generate_request_id);

    let mut signature_endpoint = endpoint.to_string();
    if endpoint.starts_with("/api/v1.0") {
        signature_endpoint = endpoint.replace("/api/v1.0", "");
    }

    let (sig, timestamp) = generate_signature("POST", &signature_endpoint, &body, &private_key)?;

    let client = reqwest::Client::new();
    let response = client
        .post(format!("{}{}", base_url, endpoint))
        .header("Content-Type", "application/json;charset=utf-8")
        .header("X-PARTNER-ID", &merchant_id)
        .header("X-TIMESTAMP", &timestamp)
        .header("X-SIGNATURE", &sig)
        .header("X-EXTERNAL-ID", &external_id)
        .header("X-IP-ADDRESS", &ip_address)
        .json(&body)
        .send()
        .await?;

    let result: serde_json::Value = response.json().await?;
    
    // Log to local server
    let mut log_headers = serde_json::Map::new();
    log_headers.insert("Content-Type".to_string(), json!("application/json;charset=utf-8"));
    log_headers.insert("X-PARTNER-ID".to_string(), json!(merchant_id));
    log_headers.insert("X-TIMESTAMP".to_string(), json!(timestamp));
    log_headers.insert("X-SIGNATURE".to_string(), json!(sig));
    log_headers.insert("X-EXTERNAL-ID".to_string(), json!(external_id));
    log_headers.insert("X-IP-ADDRESS".to_string(), json!(ip_address));
    log_to_local_server(endpoint, log_headers, &body, &result).await;

    println!("{}", serde_json::to_string_pretty(&result)?);
    
    Ok(())
}
