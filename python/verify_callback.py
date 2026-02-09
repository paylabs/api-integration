import os
import hashlib
import json
import queue
from flask import Flask, request, jsonify, Response, send_from_directory, current_app
from dotenv import load_dotenv
from paylabs_signature import verify_signature

load_dotenv()

# We need to explicitly set static/template folders to 'views'
app = Flask(__name__, static_folder='views', template_folder='views')

# List to hold SSE clients
clients = []

def broadcast(data):
    """Send data to all connected SSE clients."""
    msg = f"data: {json.dumps(data)}\n\n"
    for q in clients:
        q.put(msg)

@app.route('/')
def index():
    return send_from_directory('views', 'index.html')

@app.route('/css/<path:filename>')
def serve_css(filename):
    return send_from_directory('views/css', filename)

@app.route('/js/<path:filename>')
def serve_js(filename):
    return send_from_directory('views/js', filename)

@app.route('/events')
def events():
    def stream():
        q = queue.Queue()
        clients.append(q)
        try:
            while True:
                msg = q.get()
                yield msg
        except GeneratorExit:
            clients.remove(q)

    return Response(stream(), mimetype='text/event-stream')


@app.route('/callback', methods=['POST'])
def callback():
    """Handle Paylabs callback with signature verification."""
    signature = request.headers.get('X-Signature')
    timestamp = request.headers.get('X-Timestamp')
    public_key = os.getenv('PAYLABS_PUBLIC_KEY')
    
    print(f'Incoming Callback Headers: {dict(request.headers)}')
    
    data_to_sign = request.get_data(as_text=True)
    sha_json = hashlib.sha256(data_to_sign.encode()).hexdigest().lower()
    
    string_to_verify = f'POST:/callback:{sha_json}:{timestamp}'
    print(f'String to Verify: {string_to_verify}')
    
    valid = verify_signature(string_to_verify, signature, public_key)
    
    body = request.get_json()
    
    resp_data = None
    if status != '02':
        resp_data = {
            'requestId': request_id,
            'errCode': '1',
            'errCodeDes': 'Payment not completed',
            'merchantId': merchant_id,
        }
    else:
        resp_data = {
            'requestId': request_id,
            'errCode': '0',
            'errCodeDes': 'Success',
            'merchantId': merchant_id,
        }
    
    # Broadcast to SSE with responseBody
    sse_data['type'] = 'inbound'
    sse_data['endpoint'] = '/callback'
    sse_data['responseBody'] = resp_data
    broadcast(sse_data)
    
    if not valid:
        return jsonify({'error': 'Invalid Signature'}), 400
    
    print('Signature is valid')
    return jsonify(resp_data)


@app.route('/transfer-va/payment', methods=['POST'])
def snap_callback():
    """Handle SNAP callback."""
    signature = request.headers.get('X-Signature')
    timestamp = request.headers.get('X-Timestamp')
    public_key = os.getenv('PAYLABS_PUBLIC_KEY')
    
    print(f'Incoming SNAP Callback Headers: {dict(request.headers)}')
    
    data_to_sign = request.get_data(as_text=True)
    sha_json = hashlib.sha256(data_to_sign.encode()).hexdigest().lower()
    
    string_to_verify = f'POST:/transfer-va/payment:{sha_json}:{timestamp}'
    print(f'SNAP String to Verify: {string_to_verify}')
    
    valid = verify_signature(string_to_verify, signature, public_key)
    
    body = request.get_json()
    
    resp_data = {
        'responseCode': '2002500',
        'responseMessage': 'Success',
        'virtualAccountData': filtered_body
    }
    
    # Broadcast to SSE with responseBody
    sse_data['type'] = 'inbound'
    sse_data['responseBody'] = resp_data
    broadcast(sse_data)
    
    if not valid:
        return jsonify({
            'responseCode': '4010000',
            'responseMessage': 'Unauthorized'
        }), 401
    
    print('SNAP Signature is valid')
    return jsonify(resp_data)

@app.route('/api/v1.0/transfer-va/create-va', methods=['POST'])
def snap_create_va():
    """Handle SNAP VA creation/inquiry callback."""
    signature = request.headers.get('X-Signature')
    timestamp = request.headers.get('X-Timestamp')
    public_key = os.getenv('PAYLABS_PUBLIC_KEY')

    print(f'Incoming SNAP Create VA Headers: {dict(request.headers)}')

    data_to_sign = request.get_data(as_text=True)
    sha_json = hashlib.sha256(data_to_sign.encode()).hexdigest().lower()

    # Pattern: POST:/transfer-va/create-va:{bodyHash}:{timestamp}
    string_to_verify = f'POST:/transfer-va/create-va:{sha_json}:{timestamp}'
    print(f'SNAP Create VA String to Verify: {string_to_verify}')

    valid = verify_signature(string_to_verify, signature, public_key)
    body = request.get_json()

    response_code = '2002700' if valid else '4012701'
    response_message = 'Success' if valid else 'Invalid Signature'

    resp_data = {
        'responseCode': response_code,
        'responseMessage': response_message
    }

    # Broadcast to SSE
    sse_data = {
        'type': 'inbound',
        'headers': dict(request.headers),
        'body': body,
        'endpoint': '/api/v1.0/transfer-va/create-va',
        'verificationStatus': 'Valid' if valid else 'Invalid',
        'responseBody': resp_data
    }
    broadcast(sse_data)

    if not valid:
        return jsonify(resp_data), 401

    print('SNAP Create VA Signature is valid')
    return jsonify(resp_data)

@app.route('/log', methods=['POST'])
def log_route():
    """Receive logs from outbound requests and broadcast via SSE."""
    log_data = request.get_json()
    broadcast(log_data)
    return '', 200


if __name__ == '__main__':
    port = int(os.getenv('PORT', 3000))
    print(f'Callback server listening on port {port}')
    print(f'Open http://localhost:{port} to visualize callbacks')
    app.run(host='0.0.0.0', port=port, threaded=True)
