import hashlib
import base64
import json
import os
from datetime import datetime, timezone, timedelta
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import padding


def remove_nulls(obj):
    """Recursively remove null values from dict/list."""
    if isinstance(obj, dict):
        return {k: remove_nulls(v) for k, v in obj.items() if v is not None}
    elif isinstance(obj, list):
        return [remove_nulls(item) for item in obj]
    return obj


def minify_json(body):
    """Minify JSON without whitespace."""
    cleaned = remove_nulls(body)
    return json.dumps(cleaned, separators=(',', ':'))


def sha256_hex(data):
    """Generate SHA256 hash in lowercase hex."""
    return hashlib.sha256(data.encode()).hexdigest().lower()


def generate_timestamp():
    """Generate timestamp in WIB timezone (UTC+7)."""
    wib = timezone(timedelta(hours=7))
    now = datetime.now(wib)
    return now.strftime('%Y-%m-%dT%H:%M:%S') + '+07:00'


def generate_request_id():
    """Generate unique request ID with timestamp and random number."""
    import random
    wib = timezone(timedelta(hours=7))
    now = datetime.now(wib)
    date_str = now.strftime('%Y%m%d%H%M%S')
    random_str = str(random.randint(0, 999999)).zfill(6)
    return f"{date_str}{random_str}"


def parse_private_key(pem_str):
    """Parse PEM private key string."""
    pem_str = pem_str.replace('\\n', '\n')
    return serialization.load_pem_private_key(pem_str.encode(), password=None)


def parse_public_key(pem_str):
    """Parse PEM public key string."""
    pem_str = pem_str.replace('\\n', '\n')
    return serialization.load_pem_public_key(pem_str.encode())


def generate_signature(method, endpoint, body, private_key_pem):
    """Generate RSA-SHA256 signature for Paylabs API."""
    timestamp = generate_timestamp()
    
    body_hash = sha256_hex(minify_json(body))
    print(minify_json(body))
    
    string_to_sign = f"{method}:{endpoint}:{body_hash}:{timestamp}"
    print(f"String to Sign: {string_to_sign}")
    
    private_key = parse_private_key(private_key_pem)
    
    signature_bytes = private_key.sign(
        string_to_sign.encode(),
        padding.PKCS1v15(),
        hashes.SHA256()
    )
    
    signature = base64.b64encode(signature_bytes).decode()
    print(f"Generated Signature: {signature}")
    
    return signature, timestamp


def verify_signature(string_to_verify, signature_base64, public_key_pem):
    """Verify RSA-SHA256 signature from Paylabs callback."""
    try:
        public_key = parse_public_key(public_key_pem)
        signature = base64.b64decode(signature_base64)
        
        public_key.verify(
            signature,
            string_to_verify.encode(),
            padding.PKCS1v15(),
            hashes.SHA256()
        )
        return True
    except Exception as e:
        print(f"Verification failed: {e}")
        return False


def log_to_local_server(endpoint, request_headers, request_body, response_body):
    """Log outbound request/response to the visualizer local server."""
    import requests
    try:
        log_data = {
            'type': 'outbound',
            'endpoint': endpoint,
            'requestHeaders': request_headers,
            'requestBody': request_body,
            'responseBody': response_body
        }
        log_port = os.getenv('PORT', '3000')
        requests.post(f'http://localhost:{log_port}/log', json=log_data, timeout=2)
    except Exception:
        # Silently fail if visualizer is not running
        pass


def create_transaction(endpoint, body):
    """Generic function to create a transaction."""
    import requests
    from dotenv import load_dotenv
    
    load_dotenv()
    
    base_url = os.getenv('PAYLABS_BASE_URL')
    merchant_id = os.getenv('MERCHANT_ID')
    private_key = os.getenv('MERCHANT_PRIVATE_KEY')
    
    signature, timestamp = generate_signature('POST', endpoint, body, private_key)
    
    headers = {
        'Content-Type': 'application/json',
        'X-PARTNER-ID': merchant_id,
        'X-TIMESTAMP': timestamp,
        'X-SIGNATURE': signature,
        'X-REQUEST-ID': body.get('requestId'),
    }
    
    try:
        minified_body = minify_json(body)
        response = requests.post(
            f'{base_url}{endpoint}',
            data=minified_body,
            headers=headers
        )
        print(json.dumps(response.json(), indent=2))
        return response.json()
    except Exception as e:
        print(f'Error: {e}')


def get_public_ip():
    """Get public IP address."""
    import requests
    try:
        response = requests.get('https://api.ipify.org?format=json', timeout=5)
        return response.json().get('ip', '127.0.0.1')
    except Exception:
        return '127.0.0.1'


def create_transaction_snap(endpoint, body):
    """Create SNAP Transaction."""
    import requests
    from dotenv import load_dotenv
    
    load_dotenv()
    
    base_url = os.getenv('PAYLABS_BASE_URL')
    merchant_id = os.getenv('MERCHANT_ID')
    private_key = os.getenv('MERCHANT_PRIVATE_KEY')
    
    ip_address = get_public_ip()
    print(f"Public IP: {ip_address}")
    
    # SNAP signature uses a modified endpoint logic if needed
    signature_endpoint = endpoint
    if endpoint.startswith("/api/v1.0"):
        signature_endpoint = endpoint.replace("/api/v1.0", "")
        
    # Remove requestId/externalId from body before signing/sending for SNAP
    external_id = body.pop('externalId', None)
    if not external_id:
        external_id = body.pop('requestId', None)
    
    if not external_id:
        external_id = generate_request_id()
        
    signature, timestamp = generate_signature('POST', signature_endpoint, body, private_key)
    
    headers = {
        'Content-Type': 'application/json;charset=utf-8',
        'X-PARTNER-ID': merchant_id,
        'X-TIMESTAMP': timestamp,
        'X-SIGNATURE': signature,
        'X-EXTERNAL-ID': external_id,
        'X-IP-ADDRESS': ip_address,
    }
    
    try:
        minified_body = minify_json(body)
        response = requests.post(
            f'{base_url}{endpoint}',
            data=minified_body,
            headers=headers
        )
        print(json.dumps(response.json(), indent=2))
        return response.json()
    except Exception as e:
        print(f'Error: {e}')
