package id.paylabs.qris;

import java.util.HashMap;
import java.util.Map;

import io.github.cdimascio.dotenv.Dotenv;

public class GenerateTransaction {

    public static void createQRIS() {
        Dotenv dotenv = Dotenv.configure().ignoreIfMissing().load();
        String endpoint = dotenv.get("QRIS_CREATE_ENDPOINT", "");
        String merchantId = dotenv.get("MERCHANT_ID", "");

        String requestId = PaylabsSignature.generateRequestId();

        Map<String, Object> body = new HashMap<>();
        body.put("merchantId", merchantId);
        body.put("merchantTradeNo", "QRIS-" + requestId);
        body.put("requestId", requestId);
        body.put("paymentType", "QRIS");
        body.put("amount", "10000.00");
        body.put("productName", "QRIS Payment");
        body.put("expire", 3600);
        body.put("feeType", "OUR");
        body.put("payer", "John Doe");
        body.put("notifyUrl", "https://ungirlishly-unmoralistic-antony.ngrok-free.dev/callback");

        System.out.println("Creating QRIS Transaction...");
        PaylabsSignature.createTransaction(endpoint, body);
    }

    // Example: Create General Transaction
    public static void createGeneralTransaction() {
        Dotenv dotenv = Dotenv.configure().ignoreIfMissing().load();
        String endpoint = "/payment/v2/transaction/create"; // Adjust endpoint
        String merchantId = dotenv.get("MERCHANT_ID", "");

        String requestId = PaylabsSignature.generateRequestId();

        Map<String, Object> body = new HashMap<>();
        body.put("merchantId", merchantId);
        body.put("merchantTradeNo", "TRX-" + requestId);
        body.put("requestId", requestId);
        body.put("paymentType", "General");
        body.put("amount", "50000.00");
        body.put("productName", "General Payment");
        body.put("notifyUrl", "https://your-domain.ngrok-free.dev/callback");

        System.out.println("Creating General Transaction...");
        PaylabsSignature.createTransaction(endpoint, body);
    }

    // Example: Create SNAP Transaction
    public static void createSnapTransaction() {
        Dotenv dotenv = Dotenv.configure().ignoreIfMissing().load();
        String endpoint = "/api/v1.0/transfer-va/create-va";
        String merchantId = dotenv.get("MERCHANT_ID", "");
        String requestId = PaylabsSignature.generateRequestId();

        Map<String, Object> body = new HashMap<>();
        body.put("partnerServiceId", "00" + merchantId);
        body.put("customerNo", "00000000000000000000");
        body.put("virtualAccountNo", "000105796289500005539");
        body.put("virtualAccountName", "SUCCESS John - shoes**");
        body.put("virtualAccountPhone", "+6281234567890");
        body.put("trxId", "PYL" + requestId);
        
        Map<String, String> totalAmount = new HashMap<>();
        totalAmount.put("value", "10000.00");
        totalAmount.put("currency", "IDR");
        body.put("totalAmount", totalAmount);

        Map<String, Object> billDetail = new HashMap<>();
        billDetail.put("billCode", "1");
        billDetail.put("billName", "Produk John");
        billDetail.put("billAmount", totalAmount);
        
        body.put("billDetails", new Object[] { billDetail });
        body.put("expiredDate", "2026-12-25T15:52:34+07:00");
        body.put("virtualAccountTrxType", "1");
        
        Map<String, String> additionalInfo = new HashMap<>();
        additionalInfo.put("paymentType", "MuamalatVA");
        body.put("additionalInfo", additionalInfo);

        // Pass requestId for externalId usage
        body.put("requestId", requestId);

        System.out.println("Creating SNAP Transaction...");
        PaylabsSignature.createTransactionSnap(endpoint, body);
    }

    public static void main(String[] args) {
        createQRIS();
        // createGeneralTransaction();
        // createSnapTransaction();
    }
}
