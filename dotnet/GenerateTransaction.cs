using System.Text;
using System.Text.Json;

namespace QuickStart;

public static class GenerateTransaction
{
    public static async Task CreateQRIS()
    {
        var endpoint = Environment.GetEnvironmentVariable("QRIS_CREATE_ENDPOINT") ?? "";
        var merchantId = Environment.GetEnvironmentVariable("MERCHANT_ID") ?? "";
        
        var requestId = PaylabsSignature.GenerateRequestId();

        var body = new
        {
            merchantId = merchantId,
            merchantTradeNo = $"QRIS-{requestId}",
            requestId = requestId,
            paymentType = "QRIS",
            amount = "10000.00",
            productName = "QRIS Payment",
            expire = 3600,
            feeType = "OUR",
            payer = "John Doe",
            notifyUrl = "https://ungirlishly-unmoralistic-antony.ngrok-free.dev/callback"
        };
        
        Console.WriteLine("Creating QRIS Transaction...");
        await CreateTransaction(endpoint, body);
    }

    public static async Task CreateGeneralTransaction()
    {
        var endpoint = "/payment/v2/transaction/create"; // Adjust endpoint
        var merchantId = Environment.GetEnvironmentVariable("MERCHANT_ID") ?? "";
        
        var requestId = PaylabsSignature.GenerateRequestId();

        var body = new
        {
            merchantId = merchantId,
            merchantTradeNo = $"TRX-{requestId}",
            requestId = requestId,
            paymentType = "General",
            amount = "50000.00",
            productName = "General Payment",
            notifyUrl = "https://your-domain.ngrok-free.dev/callback"
        };
        
        Console.WriteLine("Creating General Transaction...");
        await CreateTransaction(endpoint, body);
    }

    public static async Task CreateSnapTransaction()
    {
        var endpoint = "/api/v1.0/transfer-va/create-va";
        var merchantId = Environment.GetEnvironmentVariable("MERCHANT_ID");
        var requestId = PaylabsSignature.GenerateRequestId();

        var body = new
        {
            partnerServiceId = "00" + merchantId,
            customerNo = "00000000000000000000",
            virtualAccountNo = "000105796289500005539",
            virtualAccountName = "SUCCESS John - shoes**",
            virtualAccountPhone = "+6281234567890",
            trxId = "PYL" + requestId,
            totalAmount = new
            {
                value = "10000.00",
                currency = "IDR"
            },
            billDetails = new[]
            {
                new
                {
                    billCode = "1",
                    billName = "Produk John",
                    billAmount = new
                    {
                        value = "10000.00",
                        currency = "IDR"
                    }
                }
            },
            expiredDate = "2026-12-25T15:52:34+07:00",
            virtualAccountTrxType = "1",
            additionalInfo = new
            {
                paymentType = "MuamalatVA"
            },
            requestId = requestId
        };

        Console.WriteLine("Creating SNAP Transaction...");
        await PaylabsSignature.CreateTransactionSnap(endpoint, body);
    }

    private static async Task CreateTransaction(string endpoint, object body)
    {
        var bodyJson = JsonSerializer.Serialize(body);
        
        var baseUrl = Environment.GetEnvironmentVariable("PAYLABS_BASE_URL");
        var merchantId = Environment.GetEnvironmentVariable("MERCHANT_ID");
        var privateKey = Environment.GetEnvironmentVariable("MERCHANT_PRIVATE_KEY");

        var (signature, timestamp) = PaylabsSignature.GenerateSignature("POST", endpoint, bodyJson, privateKey);
        
        var doc = JsonDocument.Parse(bodyJson);
        var reqId = doc.RootElement.GetProperty("requestId").GetString();

        using var client = new HttpClient();
        var request = new HttpRequestMessage(HttpMethod.Post, baseUrl + endpoint);
        request.Content = new StringContent(bodyJson, Encoding.UTF8, "application/json");
        
        request.Headers.Add("X-PARTNER-ID", merchantId);
        request.Headers.Add("X-TIMESTAMP", timestamp);
        request.Headers.Add("X-SIGNATURE", signature);
        request.Headers.Add("X-REQUEST-ID", reqId);

        try
        {
            var response = await client.SendAsync(request);
            var responseBody = await response.Content.ReadAsStringAsync();
            
            var json = JsonSerializer.Deserialize<object>(responseBody);

            // Log to local server
            var logHeaders = new Dictionary<string, string>();
            foreach (var header in request.Headers)
            {
                logHeaders[header.Key] = string.Join(", ", header.Value);
            }
            await PaylabsSignature.LogToLocalServer(endpoint, logHeaders, body, json);

            Console.WriteLine(JsonSerializer.Serialize(json, new JsonSerializerOptions { WriteIndented = true }));
        }
        catch (Exception ex)
        {
            Console.WriteLine($"Error: {ex.Message}");
        }
    }
}
