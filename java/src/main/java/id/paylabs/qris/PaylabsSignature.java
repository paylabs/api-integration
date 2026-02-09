package id.paylabs.qris;

import java.nio.charset.StandardCharsets;
import java.security.KeyFactory;
import java.security.MessageDigest;
import java.security.PrivateKey;
import java.security.PublicKey;
import java.security.Signature;
import java.security.spec.PKCS8EncodedKeySpec;
import java.security.spec.X509EncodedKeySpec;
import java.time.ZoneId;
import java.time.ZonedDateTime;
import java.time.format.DateTimeFormatter;
import java.util.Base64;
import java.util.Iterator;
import java.util.Random;

import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;
import com.fasterxml.jackson.databind.node.ArrayNode;
import com.fasterxml.jackson.databind.node.ObjectNode;

import java.util.HashMap;
import java.util.Map;

public class PaylabsSignature {

    private static final ZoneId WIB = ZoneId.of("Asia/Jakarta");
    private static final ObjectMapper mapper = new ObjectMapper();

    public static JsonNode removeNulls(JsonNode node) {
        if (node.isObject()) {
            ObjectNode result = mapper.createObjectNode();
            Iterator<String> fieldNames = node.fieldNames();
            while (fieldNames.hasNext()) {
                String fieldName = fieldNames.next();
                JsonNode value = node.get(fieldName);
                if (!value.isNull()) {
                    result.set(fieldName, removeNulls(value));
                }
            }
            return result;
        } else if (node.isArray()) {
            ArrayNode result = mapper.createArrayNode();
            for (JsonNode item : node) {
                result.add(removeNulls(item));
            }
            return result;
        }
        return node;
    }

    public static String minifyJson(Object body) throws Exception {
        JsonNode node = mapper.valueToTree(body);
        JsonNode cleaned = removeNulls(node);
        return mapper.writeValueAsString(cleaned);
    }

    public static String sha256Hex(String data) throws Exception {
        MessageDigest digest = MessageDigest.getInstance("SHA-256");
        byte[] hash = digest.digest(data.getBytes(StandardCharsets.UTF_8));
        StringBuilder hexString = new StringBuilder();
        for (byte b : hash) {
            String hex = Integer.toHexString(0xff & b);
            if (hex.length() == 1) hexString.append('0');
            hexString.append(hex);
        }
        return hexString.toString().toLowerCase();
    }

    public static String generateTimestamp() {
        ZonedDateTime now = ZonedDateTime.now(WIB);
        return now.format(DateTimeFormatter.ofPattern("yyyy-MM-dd'T'HH:mm:ss")) + "+07:00";
    }

    public static String generateRequestId() {
        ZonedDateTime now = ZonedDateTime.now(WIB);
        String dateStr = now.format(DateTimeFormatter.ofPattern("yyyyMMddHHmmss"));
        int randomNum = new Random().nextInt(1000000);
        return String.format("%s%06d", dateStr, randomNum);
    }

    public static String[] generateSignature(String method, String endpoint, Object body, String privateKeyPem) throws Exception {
        String timestamp = generateTimestamp();
        
        String minified = minifyJson(body);
        System.out.println(minified);
        
        String bodyHash = sha256Hex(minified);
        String stringToSign = String.format("%s:%s:%s:%s", method, endpoint, bodyHash, timestamp);
        System.out.println("String to Sign: " + stringToSign);

        privateKeyPem = privateKeyPem.replace("\\n", "\n");
        String privateKeyContent = privateKeyPem
            .replace("-----BEGIN PRIVATE KEY-----", "")
            .replace("-----END PRIVATE KEY-----", "")
            .replaceAll("\\s", "");
        
        byte[] keyBytes = Base64.getDecoder().decode(privateKeyContent);
        PKCS8EncodedKeySpec keySpec = new PKCS8EncodedKeySpec(keyBytes);
        KeyFactory keyFactory = KeyFactory.getInstance("RSA");
        PrivateKey privateKey = keyFactory.generatePrivate(keySpec);

        Signature signer = Signature.getInstance("SHA256withRSA");
        signer.initSign(privateKey);
        signer.update(stringToSign.getBytes(StandardCharsets.UTF_8));
        byte[] signatureBytes = signer.sign();
        
        String signature = Base64.getEncoder().encodeToString(signatureBytes);
        System.out.println("Generated Signature: " + signature);

        return new String[] { signature, timestamp };
    }

    public static void logToLocalServer(String endpoint, Map<String, String> requestHeaders, Object requestBody, Object responseBody) {
        try {
            Map<String, Object> logData = new HashMap<>();
            logData.put("type", "outbound");
            logData.put("endpoint", endpoint);
            logData.put("requestHeaders", requestHeaders);
            logData.put("requestBody", requestBody);
            logData.put("responseBody", responseBody);

            String logJson = mapper.writeValueAsString(logData);

            String logPort = System.getenv("PORT");
            if (logPort == null) logPort = "3000";

            java.net.http.HttpClient client = java.net.http.HttpClient.newHttpClient();
            java.net.http.HttpRequest request = java.net.http.HttpRequest.newBuilder()
                .uri(java.net.URI.create("http://localhost:" + logPort + "/log"))
                .header("Content-Type", "application/json")
                .POST(java.net.http.HttpRequest.BodyPublishers.ofString(logJson))
                .timeout(java.time.Duration.ofSeconds(2))
                .build();

            client.send(request, java.net.http.HttpResponse.BodyHandlers.discarding());
        } catch (Exception e) {
            // Silently fail if visualizer is not running
        }
    }

    public static void verifySignatureTest(String stringToVerify, String signatureBase64, String publicKeyPem) {
        // ... existing or helper if needed
    }

    public static boolean verifySignature(String stringToVerify, String signatureBase64, String publicKeyPem) {
        try {
            publicKeyPem = publicKeyPem.replace("\\n", "\n");
            String publicKeyContent = publicKeyPem
                .replace("-----BEGIN PUBLIC KEY-----", "")
                .replace("-----END PUBLIC KEY-----", "")
                .replaceAll("\\s", "");
            
            byte[] keyBytes = Base64.getDecoder().decode(publicKeyContent);
            X509EncodedKeySpec keySpec = new X509EncodedKeySpec(keyBytes);
            KeyFactory keyFactory = KeyFactory.getInstance("RSA");
            PublicKey publicKey = keyFactory.generatePublic(keySpec);

            Signature verifier = Signature.getInstance("SHA256withRSA");
            verifier.initVerify(publicKey);
            verifier.update(stringToVerify.getBytes(StandardCharsets.UTF_8));
            
            byte[] signatureBytes = Base64.getDecoder().decode(signatureBase64);
            return verifier.verify(signatureBytes);
        } catch (Exception e) {
            System.out.println("Verification failed: " + e.getMessage());
            return false;
        }
    }

    public static void createTransaction(String endpoint, Map<String, Object> body) {
        io.github.cdimascio.dotenv.Dotenv dotenv = io.github.cdimascio.dotenv.Dotenv.configure().ignoreIfMissing().load();

        String baseUrl = dotenv.get("PAYLABS_BASE_URL", "");
        String merchantId = dotenv.get("MERCHANT_ID", "");
        String privateKey = dotenv.get("MERCHANT_PRIVATE_KEY", "");

        try {
            String[] signatureResult = generateSignature("POST", endpoint, body, privateKey);
            String signature = signatureResult[0];
            String timestamp = signatureResult[1];

            String bodyJson = minifyJson(body);

            java.net.http.HttpClient client = java.net.http.HttpClient.newHttpClient();
            java.net.http.HttpRequest request = java.net.http.HttpRequest.newBuilder()
                .uri(java.net.URI.create(baseUrl + endpoint))
                .header("Content-Type", "application/json")
                .header("X-PARTNER-ID", merchantId)
                .header("X-TIMESTAMP", timestamp)
                .header("X-SIGNATURE", signature)
                .header("X-REQUEST-ID", (String) body.get("requestId"))
                .POST(java.net.http.HttpRequest.BodyPublishers.ofString(bodyJson))
                .build();

            java.net.http.HttpResponse<String> response = client.send(request, java.net.http.HttpResponse.BodyHandlers.ofString());

            JsonNode json = mapper.readTree(response.body());
            
            // Log to local server
            Map<String, String> logHeaders = new HashMap<>();
            logHeaders.put("Content-Type", "application/json");
            logHeaders.put("X-PARTNER-ID", merchantId);
            logHeaders.put("X-TIMESTAMP", timestamp);
            logHeaders.put("X-SIGNATURE", signature);
            logHeaders.put("X-REQUEST-ID", (String) body.get("requestId"));
            logToLocalServer(endpoint, logHeaders, body, json);

            String prettyJson = mapper.writerWithDefaultPrettyPrinter().writeValueAsString(json);
            System.out.println(prettyJson);

        } catch (Exception e) {
            System.out.println("Error: " + e.getMessage());
            e.printStackTrace();
        }
    }

    public static String getPublicIp() {
        try {
            java.net.http.HttpClient client = java.net.http.HttpClient.newHttpClient();
            java.net.http.HttpRequest request = java.net.http.HttpRequest.newBuilder()
                .uri(java.net.URI.create("https://api.ipify.org?format=json"))
                .GET()
                .build();
            java.net.http.HttpResponse<String> response = client.send(request, java.net.http.HttpResponse.BodyHandlers.ofString());
            JsonNode json = mapper.readTree(response.body());
            return json.get("ip").asText();
        } catch (Exception e) {
            return "127.0.0.1";
        }
    }

    public static void createTransactionSnap(String endpoint, Map<String, Object> body) {
        io.github.cdimascio.dotenv.Dotenv dotenv = io.github.cdimascio.dotenv.Dotenv.configure().ignoreIfMissing().load();

        String baseUrl = dotenv.get("PAYLABS_BASE_URL", "");
        String merchantId = dotenv.get("MERCHANT_ID", "");
        String privateKey = dotenv.get("MERCHANT_PRIVATE_KEY", "");

        try {
            String ipAddress = getPublicIp();
            System.out.println("Public IP: " + ipAddress);

            String externalId = (String) body.remove("externalId");
            if (externalId == null) {
                externalId = (String) body.remove("requestId");
            }
            if (externalId == null) {
                externalId = generateRequestId();
            }

            String signatureEndpoint = endpoint;
            if (endpoint.startsWith("/api/v1.0")) {
                signatureEndpoint = endpoint.replace("/api/v1.0", "");
            }

            String[] signatureResult = generateSignature("POST", signatureEndpoint, body, privateKey);
            String signature = signatureResult[0];
            String timestamp = signatureResult[1];

            String bodyJson = minifyJson(body);

            java.net.http.HttpClient client = java.net.http.HttpClient.newHttpClient();
            java.net.http.HttpRequest request = java.net.http.HttpRequest.newBuilder()
                .uri(java.net.URI.create(baseUrl + endpoint))
                .header("Content-Type", "application/json;charset=utf-8")
                .header("X-PARTNER-ID", merchantId)
                .header("X-TIMESTAMP", timestamp)
                .header("X-SIGNATURE", signature)
                .header("X-EXTERNAL-ID", externalId)
                .header("X-IP-ADDRESS", ipAddress)
                .POST(java.net.http.HttpRequest.BodyPublishers.ofString(bodyJson))
                .build();

            java.net.http.HttpResponse<String> response = client.send(request, java.net.http.HttpResponse.BodyHandlers.ofString());

            JsonNode json = mapper.readTree(response.body());

            // Log to local server
            Map<String, String> logHeaders = new HashMap<>();
            logHeaders.put("Content-Type", "application/json;charset=utf-8");
            logHeaders.put("X-PARTNER-ID", merchantId);
            logHeaders.put("X-TIMESTAMP", timestamp);
            logHeaders.put("X-SIGNATURE", signature);
            logHeaders.put("X-EXTERNAL-ID", externalId);
            logHeaders.put("X-IP-ADDRESS", ipAddress);
            logToLocalServer(endpoint, logHeaders, body, json);

            String prettyJson = mapper.writerWithDefaultPrettyPrinter().writeValueAsString(json);
            System.out.println(prettyJson);

        } catch (Exception e) {
            System.out.println("Error: " + e.getMessage());
            e.printStackTrace();
        }
    }
}
