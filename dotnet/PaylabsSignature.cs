using System.Security.Cryptography;
using System.Text;
using System.Text.Json;

namespace QuickStart;

public static class PaylabsSignature
{
    private static readonly TimeZoneInfo WibTimeZone = TimeZoneInfo.CreateCustomTimeZone("WIB", TimeSpan.FromHours(7), "WIB", "WIB");

    public static string RemoveNulls(JsonElement element)
    {
        using var stream = new MemoryStream();
        using var writer = new Utf8JsonWriter(stream, new JsonWriterOptions { Encoder = System.Text.Encodings.Web.JavaScriptEncoder.UnsafeRelaxedJsonEscaping });
        WriteWithoutNulls(writer, element);
        writer.Flush();
        return Encoding.UTF8.GetString(stream.ToArray());
    }

    private static void WriteWithoutNulls(Utf8JsonWriter writer, JsonElement element)
    {
        switch (element.ValueKind)
        {
            case JsonValueKind.Object:
                writer.WriteStartObject();
                foreach (var property in element.EnumerateObject())
                {
                    if (property.Value.ValueKind != JsonValueKind.Null)
                    {
                        writer.WritePropertyName(property.Name);
                        WriteWithoutNulls(writer, property.Value);
                    }
                }
                writer.WriteEndObject();
                break;
            case JsonValueKind.Array:
                writer.WriteStartArray();
                foreach (var item in element.EnumerateArray())
                {
                    WriteWithoutNulls(writer, item);
                }
                writer.WriteEndArray();
                break;
            default:
                element.WriteTo(writer);
                break;
        }
    }

    public static string Sha256Hex(string data)
    {
        using var sha256 = SHA256.Create();
        var bytes = sha256.ComputeHash(Encoding.UTF8.GetBytes(data));
        return Convert.ToHexString(bytes).ToLower();
    }

    public static string GenerateTimestamp()
    {
        var now = TimeZoneInfo.ConvertTimeFromUtc(DateTime.UtcNow, WibTimeZone);
        return now.ToString("yyyy-MM-ddTHH:mm:ss") + "+07:00";
    }

    public static string GenerateRequestId()
    {
        var now = TimeZoneInfo.ConvertTimeFromUtc(DateTime.UtcNow, WibTimeZone);
        var dateStr = now.ToString("yyyyMMddHHmmss");
        var randomStr = Random.Shared.Next(0, 1000000).ToString("D6");
        return dateStr + randomStr;
    }

    public static (string Signature, string Timestamp) GenerateSignature(string method, string endpoint, string bodyJson, string privateKeyPem)
    {
        var timestamp = GenerateTimestamp();

        var bodyElement = JsonDocument.Parse(bodyJson).RootElement;
        var minifiedBody = RemoveNulls(bodyElement);
        Console.WriteLine(minifiedBody);

        var bodyHash = Sha256Hex(minifiedBody);
        var stringToSign = $"{method}:{endpoint}:{bodyHash}:{timestamp}";
        Console.WriteLine($"String to Sign: {stringToSign}");

        privateKeyPem = privateKeyPem.Replace("\\n", "\n");
        using var rsa = RSA.Create();
        rsa.ImportFromPem(privateKeyPem.ToCharArray());

        var signatureBytes = rsa.SignData(Encoding.UTF8.GetBytes(stringToSign), HashAlgorithmName.SHA256, RSASignaturePadding.Pkcs1);
        var signature = Convert.ToBase64String(signatureBytes);
        Console.WriteLine($"Generated Signature: {signature}");

        return (signature, timestamp);
    }

    public static async Task LogToLocalServer(string endpoint, IDictionary<string, string> requestHeaders, object requestBody, object responseBody)
    {
        try
        {
            var logData = new
            {
                type = "outbound",
                endpoint = endpoint,
                requestHeaders = requestHeaders,
                requestBody = requestBody,
                responseBody = responseBody
            };

            var logPort = Environment.GetEnvironmentVariable("PORT") ?? "3000";
            using var client = new HttpClient();
            var content = new StringContent(JsonSerializer.Serialize(logData), Encoding.UTF8, "application/json");
            await client.PostAsync($"http://localhost:{logPort}/log", content);
        }
        catch
        {
            // Silently fail if visualizer is not running
        }
    }

    public static bool VerifySignature(string stringToVerify, string signatureBase64, string publicKeyPem)
    {
        try
        {
            publicKeyPem = publicKeyPem.Replace("\\n", "\n");
            using var rsa = RSA.Create();
            rsa.ImportFromPem(publicKeyPem.ToCharArray());

            var signatureBytes = Convert.FromBase64String(signatureBase64);
            return rsa.VerifyData(Encoding.UTF8.GetBytes(stringToVerify), signatureBytes, HashAlgorithmName.SHA256, RSASignaturePadding.Pkcs1);
        }
        catch (Exception ex)
        {
            Console.WriteLine($"Verification failed: {ex.Message}");
            return false;
        }
    }

    public static async Task<string> GetPublicIp()
    {
        try
        {
            using var client = new HttpClient();
            var response = await client.GetStringAsync("https://api.ipify.org?format=json");
            var json = JsonDocument.Parse(response);
            return json.RootElement.GetProperty("ip").GetString() ?? "127.0.0.1";
        }
        catch
        {
            return "127.0.0.1";
        }
    }

    public static async Task CreateTransactionSnap(string endpoint, object body)
    {
        // Use JsonNode to manipulate the body
        var jsonNode = System.Text.Json.Nodes.JsonNode.Parse(JsonSerializer.Serialize(body));
        
        // Load env variables manually or assume loaded in Program.cs
        var baseUrl = Environment.GetEnvironmentVariable("PAYLABS_BASE_URL");
        var merchantId = Environment.GetEnvironmentVariable("MERCHANT_ID");
        var privateKey = Environment.GetEnvironmentVariable("MERCHANT_PRIVATE_KEY");

        var ipAddress = await GetPublicIp();
        Console.WriteLine($"Public IP: {ipAddress}");

        var signatureEndpoint = endpoint;
        if (endpoint.StartsWith("/api/v1.0"))
        {
            signatureEndpoint = endpoint.Replace("/api/v1.0", "");
        }

        var externalId = "";
        if (jsonNode["externalId"] != null)
        {
            externalId = jsonNode["externalId"].ToString();
            jsonNode.AsObject().Remove("externalId");
        }
        
        if (jsonNode["requestId"] != null)
        {
            if (string.IsNullOrEmpty(externalId))
            {
                externalId = jsonNode["requestId"].ToString();
            }
            jsonNode.AsObject().Remove("requestId");
        }
        
        if (string.IsNullOrEmpty(externalId))
        {
             externalId = GenerateRequestId();
        }

        // Minify and remove nulls (although SerializeToNode might keep nulls, RemoveNulls handles JsonElement)
        // Convert back to JsonElement to use existing RemoveNulls
        var finalBodyJson = RemoveNulls(JsonSerializer.Deserialize<JsonElement>(jsonNode.ToJsonString()));

        var (signature, timestamp) = GenerateSignature("POST", signatureEndpoint, finalBodyJson, privateKey);

        using var client = new HttpClient();
        var request = new HttpRequestMessage(HttpMethod.Post, baseUrl + endpoint);
        request.Content = new StringContent(finalBodyJson, Encoding.UTF8, "application/json");
        
        request.Headers.Add("X-PARTNER-ID", merchantId);
        request.Headers.Add("X-TIMESTAMP", timestamp);
        request.Headers.Add("X-SIGNATURE", signature);
        request.Headers.Add("X-EXTERNAL-ID", externalId);
        request.Headers.Add("X-IP-ADDRESS", ipAddress);

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
            await LogToLocalServer(endpoint, logHeaders, body, json);

            Console.WriteLine("Response: " + JsonSerializer.Serialize(json, new JsonSerializerOptions { WriteIndented = true }));
        }
        catch (Exception ex)
        {
            Console.WriteLine($"Error: {ex.Message}");
        }
    }
}
