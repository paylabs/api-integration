package id.paylabs.qris;

import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.util.Enumeration;
import java.util.HashMap;
import java.util.Map;
import java.util.List;
import java.util.concurrent.CopyOnWriteArrayList;

import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestHeader;
import org.springframework.web.bind.annotation.RestController;
import org.springframework.web.servlet.mvc.method.annotation.SseEmitter;

import io.github.cdimascio.dotenv.Dotenv;
import jakarta.servlet.http.HttpServletRequest;

@RestController
public class VerifyCallback {

    private final Dotenv dotenv = Dotenv.configure().ignoreIfMissing().load();
    private final List<SseEmitter> emitters = new CopyOnWriteArrayList<>();

    @GetMapping("/events")
    public SseEmitter handleEvents() {
        SseEmitter emitter = new SseEmitter(Long.MAX_VALUE);
        this.emitters.add(emitter);

        emitter.onCompletion(() -> this.emitters.remove(emitter));
        emitter.onTimeout(() -> this.emitters.remove(emitter));

        return emitter;
    }

    private void broadcast(Map<String, Object> data) {
        for (SseEmitter emitter : emitters) {
            try {
                emitter.send((Object)data);
            } catch (Exception e) {
                emitters.remove(emitter);
            }
        }
    }

    @PostMapping("/callback")
    public ResponseEntity<?> callback(
            HttpServletRequest servletRequest,
            @RequestHeader(value = "X-Signature", required = false) String signature,
            @RequestHeader(value = "X-Timestamp", required = false) String timestamp,
            @RequestBody String rawBody) {

        String publicKey = dotenv.get("PAYLABS_PUBLIC_KEY", "");

        // Log headers
        System.out.println("Incoming Callback Headers:");
        Map<String, String> headers = new HashMap<>();
        Enumeration<String> headerNames = servletRequest.getHeaderNames();
        while (headerNames.hasMoreElements()) {
            String name = headerNames.nextElement();
            String value = servletRequest.getHeader(name);
            System.out.println("  " + name + ": " + value);
            headers.put(name.toLowerCase(), value);
        }

        try {
            // Calculate SHA256 hash
            MessageDigest digest = MessageDigest.getInstance("SHA-256");
            byte[] hashBytes = digest.digest(rawBody.getBytes(StandardCharsets.UTF_8));
            StringBuilder hexString = new StringBuilder();
            for (byte b : hashBytes) {
                String hex = Integer.toHexString(0xff & b);
                if (hex.length() == 1) hexString.append('0');
                hexString.append(hex);
            }
            String shaJson = hexString.toString().toLowerCase();

            String stringToVerify = String.format("POST:/callback:%s:%s", shaJson, timestamp);
            System.out.println("String to Verify: " + stringToVerify);

            boolean valid = PaylabsSignature.verifySignature(stringToVerify, signature, publicKey);

            // Parse body for broadcasting
            com.fasterxml.jackson.databind.ObjectMapper mapper = new com.fasterxml.jackson.databind.ObjectMapper();
            Map<String, Object> body = mapper.readValue(rawBody, new com.fasterxml.jackson.core.type.TypeReference<Map<String, Object>>() {});

            Map<String, Object> sseData = new HashMap<>();
            sseData.put("headers", headers);
            sseData.put("body", body);
            sseData.put("verificationStatus", valid ? "Valid" : "Invalid");

            broadcast(sseData);

            if (!valid) {
                return ResponseEntity.badRequest().body("Invalid Signature");
            }

            System.out.println("Signature is valid");

            String requestId = (String) body.getOrDefault("requestId", "");
            String merchantId = (String) body.getOrDefault("merchantId", "");
            String status = (String) body.getOrDefault("status", "");

            Map<String, String> response = new HashMap<>();
            response.put("requestId", requestId);
            response.put("merchantId", merchantId);

            if (!"02".equals(status)) {
                response.put("errCode", "1");
                response.put("errCodeDes", "Payment not completed");
            } else {
                response.put("errCode", "0");
                response.put("errCodeDes", "Success");
            }

            // Capture and broadcast again with responseBody
            sseData.put("type", "inbound");
            sseData.put("endpoint", "/callback");
            sseData.put("responseBody", response);
            broadcast(sseData);

            return ResponseEntity.ok(response);

        } catch (Exception e) {
            System.out.println("Error: " + e.getMessage());
            return ResponseEntity.internalServerError().body("Internal error");
        }
    }

    @PostMapping("/transfer-va/payment")
    public ResponseEntity<?> snapCallback(
            HttpServletRequest servletRequest,
            @RequestHeader(value = "X-Signature", required = false) String signature,
            @RequestHeader(value = "X-Timestamp", required = false) String timestamp,
            @RequestBody String rawBody) {

        String publicKey = dotenv.get("PAYLABS_PUBLIC_KEY", "");

        // Log headers
        System.out.println("Incoming SNAP Callback Headers:");
        Map<String, String> headers = new HashMap<>();
        Enumeration<String> headerNames = servletRequest.getHeaderNames();
        while (headerNames.hasMoreElements()) {
            String name = headerNames.nextElement();
            String value = servletRequest.getHeader(name);
            System.out.println("  " + name + ": " + value);
            headers.put(name.toLowerCase(), value);
        }

        try {
            // Calculate SHA256 hash
            MessageDigest digest = MessageDigest.getInstance("SHA-256");
            byte[] hashBytes = digest.digest(rawBody.getBytes(StandardCharsets.UTF_8));
            StringBuilder hexString = new StringBuilder();
            for (byte b : hashBytes) {
                String hex = Integer.toHexString(0xff & b);
                if (hex.length() == 1) hexString.append('0');
                hexString.append(hex);
            }
            String shaJson = hexString.toString().toLowerCase();

            String stringToVerify = String.format("POST:/transfer-va/payment:%s:%s", shaJson, timestamp);
            System.out.println("SNAP String to Verify: " + stringToVerify);

            boolean valid = PaylabsSignature.verifySignature(stringToVerify, signature, publicKey);

            // Parse body for broadcasting
            com.fasterxml.jackson.databind.ObjectMapper mapper = new com.fasterxml.jackson.databind.ObjectMapper();
            Map<String, Object> body = mapper.readValue(rawBody, new com.fasterxml.jackson.core.type.TypeReference<Map<String, Object>>() {});

            Map<String, Object> sseData = new HashMap<>();
            sseData.put("headers", headers);
            sseData.put("body", body);
            sseData.put("endpoint", "/transfer-va/payment");
            sseData.put("verificationStatus", valid ? "Valid" : "Invalid");

            broadcast(sseData);

            if (!valid) {
                Map<String, String> err = new HashMap<>();
                err.put("responseCode", "4010000");
                err.put("responseMessage", "Unauthorized");
                return ResponseEntity.status(401).body(err);
            }

            System.out.println("SNAP Signature is valid");

            String[] allowedFields = {
                "paidBills", "virtualAccountNo", "paymentRequestId", "partnerServiceId",
                "virtualAccountPhone", "virtualAccountName", "journalNum", "flagAdvise",
                "trxId", "paymentFlagReason", "virtualAccountEmail", "billDetails",
                "totalAmount", "customerNo", "paymentType", "paidAmount", "referenceNo",
                "trxDateTime", "freeTexts", "paymentFlagStatus"
            };

            Map<String, Object> filteredBody = new HashMap<>();
            for (String field : allowedFields) {
                if (body.containsKey(field)) {
                    filteredBody.put(field, body.get(field));
                }
            }
            
            // paymentFlagStatus inside virtualAccountData
            filteredBody.put("paymentFlagStatus", "00");

            Map<String, Object> response = new HashMap<>();
            response.put("responseCode", "2002500");
            response.put("responseMessage", "Success");
            response.put("virtualAccountData", filteredBody);

            // Update SSE with responseBody
            sseData.put("type", "inbound");
            sseData.put("responseBody", response);
            broadcast(sseData);

            return ResponseEntity.ok(response);

        } catch (Exception e) {
            System.out.println("Error: " + e.getMessage());
            return ResponseEntity.internalServerError().body("Internal error");
        }
    }
    @PostMapping("/log")
    public ResponseEntity<?> log(@RequestBody Map<String, Object> logData) {
        broadcast(logData);
        return ResponseEntity.ok().build();
    }
}
