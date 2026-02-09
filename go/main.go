package main

import (
	"bytes"
	"crypto"
	"crypto/rand"
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
	"math/big"
	"net/http"
	"os"
	"strings"
	"time"

	"github.com/joho/godotenv"
)

func removeNulls(data interface{}) interface{} {
	switch v := data.(type) {
	case map[string]interface{}:
		result := make(map[string]interface{})
		for key, value := range v {
			if value != nil {
				result[key] = removeNulls(value)
			}
		}
		return result
	case []interface{}:
		result := make([]interface{}, len(v))
		for i, value := range v {
			result[i] = removeNulls(value)
		}
		return result
	default:
		return data
	}
}

func minifyJSON(body map[string]interface{}) string {
	cleaned := removeNulls(body)
	jsonBytes, _ := json.Marshal(cleaned)
	return string(jsonBytes)
}

func sha256Hex(data string) string {
	hash := sha256.Sum256([]byte(data))
	return strings.ToLower(hex.EncodeToString(hash[:]))
}

func generateTimestamp() string {
	loc := time.FixedZone("WIB", 7*60*60)
	now := time.Now().In(loc)
	return now.Format("2006-01-02T15:04:05") + "+07:00"
}

func generateRequestId() string {
	loc := time.FixedZone("WIB", 7*60*60)
	now := time.Now().In(loc)
	dateStr := now.Format("20060102150405")

	randomNum, _ := rand.Int(rand.Reader, big.NewInt(1000000))
	randomStr := fmt.Sprintf("%06d", randomNum.Int64())

	return dateStr + randomStr
}

func parsePrivateKey(pemStr string) (*rsa.PrivateKey, error) {
	pemStr = strings.ReplaceAll(pemStr, "\\n", "\n")
	block, _ := pem.Decode([]byte(pemStr))
	if block == nil {
		return nil, fmt.Errorf("failed to parse PEM block")
	}

	key, err := x509.ParsePKCS8PrivateKey(block.Bytes)
	if err != nil {
		return nil, err
	}

	rsaKey, ok := key.(*rsa.PrivateKey)
	if !ok {
		return nil, fmt.Errorf("not an RSA private key")
	}

	return rsaKey, nil
}

func generateSignature(method, endpoint string, body map[string]interface{}, privateKeyPEM string) (string, string, error) {
	timestamp := generateTimestamp()

	bodyHash := sha256Hex(minifyJSON(body))
	fmt.Println(minifyJSON(body))

	stringToSign := fmt.Sprintf("%s:%s:%s:%s", method, endpoint, bodyHash, timestamp)
	fmt.Println("String to Sign:", stringToSign)

	privateKey, err := parsePrivateKey(privateKeyPEM)
	if err != nil {
		return "", "", err
	}

	hashed := sha256.Sum256([]byte(stringToSign))
	signature, err := rsa.SignPKCS1v15(rand.Reader, privateKey, crypto.SHA256, hashed[:])
	if err != nil {
		return "", "", err
	}

	signatureBase64 := base64.StdEncoding.EncodeToString(signature)
	fmt.Println("Generated Signature:", signatureBase64)

	return signatureBase64, timestamp, nil
}

func logToLocalServer(endpoint string, requestHeaders http.Header, requestBody interface{}, responseBody interface{}) {
	logPort := os.Getenv("PORT")
	if logPort == "" {
		logPort = "3000"
	}

	logData := map[string]interface{}{
		"type":           "outbound",
		"endpoint":       endpoint,
		"requestHeaders": requestHeaders,
		"requestBody":    requestBody,
		"responseBody":   responseBody,
	}

	jsonBytes, _ := json.Marshal(logData)
	http.Post(fmt.Sprintf("http://localhost:%s/log", logPort), "application/json", bytes.NewBuffer(jsonBytes))
}

func createTransaction(endpoint string, body map[string]interface{}) {
	err := godotenv.Load()
	if err != nil {
		log.Println("Warning: .env file not found")
	}

	baseURL := os.Getenv("PAYLABS_BASE_URL")
	merchantId := os.Getenv("MERCHANT_ID")
	privateKey := os.Getenv("MERCHANT_PRIVATE_KEY")

	signature, timestamp, err := generateSignature("POST", endpoint, body, privateKey)
	if err != nil {
		log.Fatal("Failed to generate signature:", err)
	}

	jsonBody, _ := json.Marshal(body)

	req, err := http.NewRequest("POST", baseURL+endpoint, bytes.NewBuffer(jsonBody))
	if err != nil {
		log.Fatal("Failed to create request:", err)
	}

	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-PARTNER-ID", merchantId)
	req.Header.Set("X-TIMESTAMP", timestamp)
	req.Header.Set("X-SIGNATURE", signature)
	req.Header.Set("X-REQUEST-ID", body["requestId"].(string))

	client := &http.Client{}
	resp, err := client.Do(req)
	if err != nil {
		log.Fatal("Request failed:", err)
	}
	defer resp.Body.Close()

	respBody, _ := io.ReadAll(resp.Body)

	var result map[string]interface{}
	json.Unmarshal(respBody, &result)

	// Log to local server
	logToLocalServer(endpoint, req.Header, body, result)

	prettyJSON, _ := json.MarshalIndent(result, "", "  ")
	fmt.Println(string(prettyJSON))
}

func createQRIS() {
	endpoint := os.Getenv("QRIS_CREATE_ENDPOINT")
	merchantId := os.Getenv("MERCHANT_ID")
	requestId := generateRequestId()

	body := map[string]interface{}{
		"merchantId":      merchantId,
		"merchantTradeNo": "QRIS-" + requestId,
		"requestId":       requestId,
		"paymentType":     "QRIS",
		"amount":          "10000.00",
		"productName":     "QRIS Payment",
		"expire":          3600,
		"feeType":         "OUR",
		"payer":           "John Doe",
		"notifyUrl":       "https://ungirlishly-unmoralistic-antony.ngrok-free.dev/callback",
	}

	fmt.Println("Creating QRIS Transaction...")
	createTransaction(endpoint, body)
}

// Get Public IP
func getPublicIp() string {
	resp, err := http.Get("https://api.ipify.org?format=json")
	if err != nil {
		return "127.0.0.1"
	}
	defer resp.Body.Close()

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return "127.0.0.1"
	}

	var result map[string]interface{}
	json.Unmarshal(body, &result)
	if ip, ok := result["ip"].(string); ok {
		return ip
	}
	return "127.0.0.1"
}

// Create SNAP Transaction
func createTransactionSnap(endpoint string, body map[string]interface{}) {
	err := godotenv.Load()
	if err != nil {
		log.Println("Warning: .env file not found")
	}

	baseURL := os.Getenv("PAYLABS_BASE_URL")
	merchantId := os.Getenv("MERCHANT_ID")
	privateKey := os.Getenv("MERCHANT_PRIVATE_KEY")

	// SNAP signature uses a modified endpoint logic if needed, but for now assuming same pattern or passed correct one
	// Actually SNAP usually removes /api/v1.0 prefix for signature.
	signatureEndpoint := endpoint
	if strings.HasPrefix(endpoint, "/api/v1.0") {
		signatureEndpoint = strings.Replace(endpoint, "/api/v1.0", "", 1)
	} else if strings.HasPrefix(endpoint, "/api/v2.1") { // Example adjustment
		signatureEndpoint = strings.Replace(endpoint, "/api/v2.1", "", 1)
	}
    // Using regex replacement similar to PHP: preg_replace('#^/api/v\d+\.\d+#', '', $endpoint)
    // Go regex:
    // re := regexp.MustCompile(`^/api/v\d+\.\d+`)
    // signatureEndpoint = re.ReplaceAllString(endpoint, "")
    // But let's stick to simple replacement or the user provided endpoint is already full? 
    // In PHP we did: preg_replace('#^/api/v\d+\.\d+#', '', $endpoint);
    // Let's replicate that simply.
    
    // For this specific endpoint /api/v1.0/transfer-va/create-va -> /transfer-va/create-va
    if strings.HasPrefix(endpoint, "/api/") {
         parts := strings.SplitN(endpoint, "/", 4) 
         // "", "api", "v1.0", "transfer-va/create-va"
         if len(parts) >= 4 {
             signatureEndpoint = "/" + parts[3]
         }
    }

    var externalId string
    if val, ok := body["externalId"].(string); ok {
        externalId = val
    } else if val, ok := body["requestId"].(string); ok {
        externalId = val
    } else {
        externalId = generateRequestId()
    }

    // SNAP API does not allow requestId/externalId in the body
    delete(body, "requestId")
    delete(body, "externalId")

	signature, timestamp, err := generateSignature("POST", signatureEndpoint, body, privateKey)
	if err != nil {
		log.Fatal("Failed to generate signature:", err)
	}
    
	ipAddress := getPublicIp()
	fmt.Println("Public IP:", ipAddress)

	jsonBody, _ := json.Marshal(body)

	req, err := http.NewRequest("POST", baseURL+endpoint, bytes.NewBuffer(jsonBody))
	if err != nil {
		log.Fatal("Failed to create request:", err)
	}

	req.Header.Set("Content-Type", "application/json;charset=utf-8")
	req.Header.Set("X-PARTNER-ID", merchantId)
	req.Header.Set("X-TIMESTAMP", timestamp)
	req.Header.Set("X-SIGNATURE", signature)
	req.Header.Set("X-EXTERNAL-ID", externalId)
	req.Header.Set("X-IP-ADDRESS", ipAddress)

	client := &http.Client{}
	resp, err := client.Do(req)
	if err != nil {
		log.Fatal("Request failed:", err)
	}
	defer resp.Body.Close()

	respBody, _ := io.ReadAll(resp.Body)

	var result map[string]interface{}
	json.Unmarshal(respBody, &result)

	// Log to local server
	headersToLog := req.Header.Clone()
	headersToLog.Set("X-EXTERNAL-ID", externalId) // Ensure visibility
	logToLocalServer(endpoint, headersToLog, body, result)

	prettyJSON, _ := json.MarshalIndent(result, "", "  ")
	fmt.Println("Response:", string(prettyJSON))
}


func createSnapTransaction() {
	endpoint := "/api/v1.0/transfer-va/create-va"
	merchantId := os.Getenv("MERCHANT_ID")
	requestId := generateRequestId()

	body := map[string]interface{}{
		"partnerServiceId":      "00" + merchantId,
		"customerNo":            "00000000000000000000",
		"virtualAccountNo":      "000105796289500005539",
		"virtualAccountName":    "SUCCESS John - shoes**",
		"virtualAccountPhone":   "+6281234567890",
		"trxId":                 "PYL" + requestId,
		"totalAmount": map[string]string{
			"value":    "10000.00",
			"currency": "IDR",
		},
		"billDetails": []map[string]interface{}{
			{
				"billCode":   "1",
				"billName":   "Produk John",
				"billAmount": map[string]string{
					"value":    "10000.00",
					"currency": "IDR",
				},
			},
		},
		"expiredDate":           "2026-12-25T15:52:34+07:00",
		"virtualAccountTrxType": "1",
		"additionalInfo": map[string]string{
			"paymentType": "MuamalatVA",
		},
        // We need to pass requestId for externalId usage in the wrapper
        "requestId": requestId, 
	}

	fmt.Println("Creating SNAP Transaction...")
	createTransactionSnap(endpoint, body)
}

// Example: Create General Transaction
func createGeneralTransaction() {
	endpoint := "/payment/v2/transaction/create" // Adjust endpoint
	merchantId := os.Getenv("MERCHANT_ID")
	requestId := generateRequestId()

	body := map[string]interface{}{
		"merchantId":      merchantId,
		"merchantTradeNo": "TRX-" + requestId,
		"requestId":       requestId,
		"paymentType":     "General",
		"amount":          "50000.00",
		"productName":     "General Payment",
		"notifyUrl":       "https://your-domain.ngrok-free.dev/callback",
	}

	fmt.Println("Creating General Transaction...")
	createTransaction(endpoint, body)
}

func main() {
    godotenv.Load()
	// createQRIS()
    createSnapTransaction()
}
