package main

import (
	"crypto"
	"crypto/rsa"
	"crypto/sha256"
	"crypto/x509"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"encoding/pem"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"strings"

	"sync"

	"github.com/joho/godotenv"
)

var (
	clients   = make(map[chan string]bool)
	clientsMu sync.Mutex
)

func eventsHandler(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "text/event-stream")
	w.Header().Set("Cache-Control", "no-cache")
	w.Header().Set("Connection", "keep-alive")

	clientChan := make(chan string)
	clientsMu.Lock()
	clients[clientChan] = true
	clientsMu.Unlock()

	defer func() {
		clientsMu.Lock()
		delete(clients, clientChan)
		clientsMu.Unlock()
		close(clientChan)
	}()

	notify := r.Context().Done()

	for {
		select {
		case msg := <-clientChan:
			fmt.Fprintf(w, "data: %s\n\n", msg)
			w.(http.Flusher).Flush()
		case <-notify:
			return
		}
	}
}

func parsePublicKey(pemStr string) (*rsa.PublicKey, error) {
	pemStr = strings.Trim(pemStr, "\"")
	pemStr = strings.ReplaceAll(pemStr, "\\n", "\n")
	block, _ := pem.Decode([]byte(pemStr))
	if block == nil {
		return nil, fmt.Errorf("failed to parse PEM block (key length: %d)", len(pemStr))
	}

	pub, err := x509.ParsePKIXPublicKey(block.Bytes)
	if err != nil {
		return nil, err
	}

	rsaPub, ok := pub.(*rsa.PublicKey)
	if !ok {
		return nil, fmt.Errorf("not an RSA public key")
	}

	return rsaPub, nil
}

func verifySignature(stringToVerify, signatureBase64, publicKeyPEM string) bool {
	if publicKeyPEM == "" {
		log.Println("Error: PAYLABS_PUBLIC_KEY is not set in environment or .env file")
		return false
	}
	publicKey, err := parsePublicKey(publicKeyPEM)
	if err != nil {
		log.Println("Failed to parse public key:", err)
		return false
	}

	signature, err := base64.StdEncoding.DecodeString(signatureBase64)
	if err != nil {
		log.Println("Failed to decode signature:", err)
		return false
	}

	hashed := sha256.Sum256([]byte(stringToVerify))
	err = rsa.VerifyPKCS1v15(publicKey, crypto.SHA256, hashed[:], signature)

	return err == nil
}

func callbackHandler(w http.ResponseWriter, r *http.Request) {
	if r.Method != "POST" {
		http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
		return
	}

	signature := r.Header.Get("X-Signature")
	timestamp := r.Header.Get("X-Timestamp")
	publicKey := os.Getenv("PAYLABS_PUBLIC_KEY")

	log.Println("Incoming Callback Headers:")
	headers := make(map[string]interface{})
	for name, values := range r.Header {
		for _, value := range values {
			log.Printf("  %s: %s\n", name, value)
			headers[strings.ToLower(name)] = value
		}
	}

	bodyBytes, err := io.ReadAll(r.Body)
	if err != nil {
		http.Error(w, "Failed to read body", http.StatusBadRequest)
		return
	}

	var body map[string]interface{}
	json.Unmarshal(bodyBytes, &body)

	status, _ := body["status"].(string)
	requestId, _ := body["requestId"].(string)
	merchantId, _ := body["merchantId"].(string)

	dataToSign := string(bodyBytes)
	hash := sha256.Sum256([]byte(dataToSign))
	shaJson := strings.ToLower(hex.EncodeToString(hash[:]))

	stringToVerify := fmt.Sprintf("POST:/callback:%s:%s", shaJson, timestamp)
	log.Println("String to Verify:", stringToVerify)

	valid := verifySignature(stringToVerify, signature, publicKey)

	// Capture response body for later broadcast
	var responseData map[string]interface{}
	if status != "02" {
		responseData = map[string]interface{}{
			"requestId":  requestId,
			"errCode":    "1",
			"errCodeDes": "Payment not completed",
			"merchantId": merchantId,
		}
	} else {
		responseData = map[string]interface{}{
			"requestId":  requestId,
			"errCode":    "0",
			"errCodeDes": "Success",
			"merchantId": merchantId,
		}
	}

	// Broadcast to SSE clients
	sseData := map[string]interface{}{
		"type":               "inbound",
		"headers":            headers,
		"body":               body,
		"endpoint":           "/callback",
		"verificationStatus": "Invalid",
		"responseBody":       responseData,
	}
	if valid {
		sseData["verificationStatus"] = "Valid"
	}

	sseJson, _ := json.Marshal(sseData)
	clientsMu.Lock()
	for clientChan := range clients {
		clientChan <- string(sseJson)
	}
	clientsMu.Unlock()

	if !valid {
		http.Error(w, "Invalid Signature", http.StatusBadRequest)
		return
	}

	log.Println("Signature is valid")

	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(responseData)
}

func snapCallbackHandler(w http.ResponseWriter, r *http.Request) {
	if r.Method != "POST" {
		http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
		return
	}

	signature := r.Header.Get("X-Signature")
	timestamp := r.Header.Get("X-Timestamp")
	publicKey := os.Getenv("PAYLABS_PUBLIC_KEY")

	log.Println("Incoming SNAP Callback Headers:")
	headers := make(map[string]interface{})
    // Copy headers logic...
	for name, values := range r.Header {
		for _, value := range values {
			log.Printf("  %s: %s\n", name, value)
			headers[strings.ToLower(name)] = value
		}
	}

	bodyBytes, err := io.ReadAll(r.Body)
	if err != nil {
		http.Error(w, "Failed to read body", http.StatusBadRequest)
		return
	}

	dataToSign := string(bodyBytes)
	hash := sha256.Sum256([]byte(dataToSign))
	shaJson := strings.ToLower(hex.EncodeToString(hash[:]))

	stringToVerify := fmt.Sprintf("POST:/transfer-va/payment:%s:%s", shaJson, timestamp)
	log.Println("SNAP String to Verify:", stringToVerify)

	valid := verifySignature(stringToVerify, signature, publicKey)

	var body map[string]interface{}
	json.Unmarshal(bodyBytes, &body)

    // Filter Allowed Fields
    allowedFields := []string{
        "paidBills", "virtualAccountNo", "paymentRequestId", "partnerServiceId",
        "virtualAccountPhone", "virtualAccountName", "journalNum", "flagAdvise",
        "trxId", "paymentFlagReason", "virtualAccountEmail", "billDetails",
        "totalAmount", "customerNo", "paymentType", "paidAmount", "referenceNo",
        "trxDateTime", "freeTexts", "paymentFlagStatus",
    }
    
    filteredBody := make(map[string]interface{})
    for _, field := range allowedFields {
        if val, ok := body[field]; ok {
            filteredBody[field] = val
        }
    }
    
    // paymentFlagStatus inside virtualAccountData
    filteredBody["paymentFlagStatus"] = "00"

	response := map[string]interface{}{
		"responseCode":    "2002500",
		"responseMessage": "Success",
		"virtualAccountData": filteredBody,
	}

	// Broadcast to SSE clients
	sseData := map[string]interface{}{
		"type":               "inbound",
		"headers":            headers,
		"body":               body,
		"endpoint":           "/transfer-va/payment",
		"verificationStatus": "Valid",
		"responseBody":       response,
	}

	sseJson, _ := json.Marshal(sseData)
	clientsMu.Lock()
	for clientChan := range clients {
		clientChan <- string(sseJson)
	}
	clientsMu.Unlock()

	if !valid {
        w.WriteHeader(http.StatusUnauthorized)
		response := map[string]string{
            "responseCode": "4010000",
            "responseMessage": "Unauthorized",
        }
        json.NewEncoder(w).Encode(response)
		return
	}

	log.Println("SNAP Signature is valid")

	w.Header().Set("Content-Type", "application/json")
    
	json.NewEncoder(w).Encode(response)
}

func snapCreateVaHandler(w http.ResponseWriter, r *http.Request) {
	if r.Method != "POST" {
		http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
		return
	}

	signature := r.Header.Get("X-Signature")
	timestamp := r.Header.Get("X-Timestamp")
	publicKey := os.Getenv("PAYLABS_PUBLIC_KEY")

	log.Println("Incoming SNAP Create VA Headers:")
	headers := make(map[string]interface{})
	for name, values := range r.Header {
		for _, value := range values {
			log.Printf("  %s: %s\n", name, value)
			headers[strings.ToLower(name)] = value
		}
	}

	bodyBytes, err := io.ReadAll(r.Body)
	if err != nil {
		http.Error(w, "Failed to read body", http.StatusBadRequest)
		return
	}

	var body map[string]interface{}
	json.Unmarshal(bodyBytes, &body)

	dataToSign := string(bodyBytes)
	hash := sha256.Sum256([]byte(dataToSign))
	shaJson := strings.ToLower(hex.EncodeToString(hash[:]))

	stringToVerify := fmt.Sprintf("POST:/transfer-va/create-va:%s:%s", shaJson, timestamp)
	log.Println("SNAP Create VA String to Verify:", stringToVerify)

	valid := verifySignature(stringToVerify, signature, publicKey)

	responseCode := "2002700"
	responseMessage := "Success"
	if !valid {
		responseCode = "4012701"
		responseMessage = "Invalid Signature"
	}

	responseData := map[string]interface{}{
		"responseCode":    responseCode,
		"responseMessage": responseMessage,
	}

	// Broadcast to SSE clients
	sseData := map[string]interface{}{
		"type":               "inbound",
		"headers":            headers,
		"body":               body,
		"endpoint":           "/api/v1.0/transfer-va/create-va",
		"verificationStatus": "Invalid",
		"responseBody":       responseData,
	}
	if valid {
		sseData["verificationStatus"] = "Valid"
	}

	sseJson, _ := json.Marshal(sseData)
	clientsMu.Lock()
	for clientChan := range clients {
		clientChan <- string(sseJson)
	}
	clientsMu.Unlock()

	if !valid {
		w.WriteHeader(http.StatusUnauthorized)
		json.NewEncoder(w).Encode(responseData)
		return
	}

	log.Println("SNAP Create VA Signature is valid")
	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(responseData)
}

func logHandler(w http.ResponseWriter, r *http.Request) {
	if r.Method != "POST" {
		http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
		return
	}

	bodyBytes, _ := io.ReadAll(r.Body)
	clientsMu.Lock()
	for clientChan := range clients {
		clientChan <- string(bodyBytes)
	}
	clientsMu.Unlock()
	w.WriteHeader(http.StatusOK)
}

func main() {
	// Try loading .env from current and parent directory
	_ = godotenv.Load()           // try ./.env
	_ = godotenv.Load("../.env")  // try ../.env

	port := os.Getenv("PORT")
	if port == "" {
		port = "3000"
	}

	// Serve static files
	fs := http.FileServer(http.Dir("./views"))
	http.Handle("/", fs)

	http.HandleFunc("/events", eventsHandler)
	http.HandleFunc("/log", logHandler)
	http.HandleFunc("/callback", callbackHandler)
	http.HandleFunc("/transfer-va/payment", snapCallbackHandler)
	http.HandleFunc("/api/v1.0/transfer-va/create-va", snapCreateVaHandler)

	log.Printf("Callback server listening on port %s\n", port)
	log.Printf("Open http://localhost:%s to visualize callbacks\n", port)
	log.Fatal(http.ListenAndServe(":"+port, nil))
}
