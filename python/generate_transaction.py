import os
from dotenv import load_dotenv
from paylabs_signature import generate_signature, generate_request_id, create_transaction

load_dotenv()


def create_qris():
    """Create QRIS payment request."""
    endpoint = os.getenv('QRIS_CREATE_ENDPOINT')
    merchant_id = os.getenv('MERCHANT_ID')
    
    request_id = generate_request_id()
    
    body = {
        'merchantId': merchant_id,
        'merchantTradeNo': f'QRIS-{request_id}',
        'requestId': request_id,
        'paymentType': 'QRIS',
        'amount': '10000.00',
        'productName': 'QRIS Payment',
        'expire': 3600,
        'feeType': 'OUR',
        'payer': 'John Doe',
        'notifyUrl': 'https://ungirlishly-unmoralistic-antony.ngrok-free.dev/callback',
    }
    
    print("Creating QRIS Transaction...")
    create_transaction(endpoint, body)


def create_general_transaction():
    """Create General payment request."""
    endpoint = "/payment/v2/transaction/create"  # Adjust endpoint
    merchant_id = os.getenv('MERCHANT_ID')
    
    request_id = generate_request_id()
    
    body = {
        'merchantId': merchant_id,
        'merchantTradeNo': f'TRX-{request_id}',
        'requestId': request_id,
        'paymentType': 'General',
        'amount': '50000.00',
        'productName': 'General Payment',
        'notifyUrl': 'https://your-domain.ngrok-free.dev/callback',
    }
    
    print("Creating General Transaction...")
    create_transaction(endpoint, body)


def create_snap_transaction():
    """Create SNAP payment request."""
    # Note: paylabs_signature module must have create_transaction_snap imported or available
    from paylabs_signature import create_transaction_snap
    
    endpoint = '/api/v1.0/transfer-va/create-va'
    merchant_id = os.getenv('MERCHANT_ID')
    
    request_id = generate_request_id()
    
    body = {
        'partnerServiceId': f"00{merchant_id}",
        'customerNo': "00000000000000000000",
        'virtualAccountNo': "000105796289500005539",
        'virtualAccountName': "SUCCESS John - shoes**",
        'virtualAccountPhone': "+6281234567890",
        'trxId': f"PYL{request_id}",
        'totalAmount': {
            'value': "10000.00",
            'currency': "IDR",
        },
        'billDetails': [
            {
                'billCode': "1",
                'billName': "Produk John",
                'billAmount': {
                    'value': "10000.00",
                    'currency': "IDR",
                },
            },
        ],
        'expiredDate': "2026-12-25T15:52:34+07:00",
        'virtualAccountTrxType': "1",
        'additionalInfo': {
            'paymentType': "MuamalatVA",
        },
        # Pass requestId for externalId usage
        # 'requestId': request_id 
    }
    
    print("Creating SNAP Transaction...")
    create_transaction_snap(endpoint, body)


if __name__ == '__main__':
    # create_qris()
    create_snap_transaction()
