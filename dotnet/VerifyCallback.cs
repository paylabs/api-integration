using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using Microsoft.AspNetCore.Mvc;

namespace QuickStart;

public static class VerifyCallback
{
    private static readonly List<StreamWriter> Clients = new();
    private static readonly SemaphoreSlim ClientsLock = new(1, 1);

    public static void MapCallbackEndpoint(WebApplication app)
    {
        app.MapGet("/events", async (HttpContext context) =>
        {
            context.Response.Headers.Add("Content-Type", "text/event-stream");
            context.Response.Headers.Add("Cache-Control", "no-cache");
            context.Response.Headers.Add("Connection", "keep-alive");

            var writer = new StreamWriter(context.Response.Body);
            await ClientsLock.WaitAsync();
            try
            {
                Clients.Add(writer);
            }
            finally
            {
                ClientsLock.Release();
            }

            try
            {
                while (!context.RequestAborted.IsCancellationRequested)
                {
                    await Task.Delay(1000);
                }
            }
            finally
            {
                await ClientsLock.WaitAsync();
                try
                {
                    Clients.Remove(writer);
                }
                finally
                {
                    ClientsLock.Release();
                }
            }
        });

        app.MapPost("/callback", async (HttpContext context) =>
        {
            var signature = context.Request.Headers["X-Signature"].FirstOrDefault() ?? "";
            var timestamp = context.Request.Headers["X-Timestamp"].FirstOrDefault() ?? "";
            var publicKey = Environment.GetEnvironmentVariable("PAYLABS_PUBLIC_KEY") ?? "";

            Console.WriteLine("Incoming Callback Headers:");
            var headers = new Dictionary<string, string>();
            foreach (var header in context.Request.Headers)
            {
                Console.WriteLine($"  {header.Key}: {header.Value}");
                headers[header.Key.ToLower()] = header.Value.ToString();
            }

            using var reader = new StreamReader(context.Request.Body);
            var rawBody = await reader.ReadToEndAsync();

            using var sha256 = SHA256.Create();
            var bodyHash = Convert.ToHexString(sha256.ComputeHash(Encoding.UTF8.GetBytes(rawBody))).ToLower();

            var stringToVerify = $"POST:/callback:{bodyHash}:{timestamp}";
            Console.WriteLine($"String to Verify: {stringToVerify}");

            var valid = PaylabsSignature.VerifySignature(stringToVerify, signature, publicKey);

            // Broadcast
            var bodyElement = JsonDocument.Parse(rawBody).RootElement;
            var jsonBody = JsonSerializer.Deserialize<object>(rawBody); // For simple object structure

            var sseData = new
            {
                headers,
                body = jsonBody,
                verificationStatus = valid ? "Valid" : "Invalid"
            };
            
            var sseMessage = $"data: {JsonSerializer.Serialize(sseData)}\n\n";

            await ClientsLock.WaitAsync();
            try
            {
                foreach (var client in Clients)
                {
                    try
                    {
                        await client.WriteAsync(sseMessage);
                        await client.FlushAsync();
                    }
                    catch
                    {
                        // Handle disconnected clients
                    }
                }
            }
            finally
            {
                ClientsLock.Release();
            }

            var status = bodyElement.TryGetProperty("status", out var s) ? s.GetString() : "";
            var requestId = bodyElement.TryGetProperty("requestId", out var r) ? r.GetString() : "";
            var merchantId = bodyElement.TryGetProperty("merchantId", out var m) ? m.GetString() : "";

            var responseData = status != "02" ? 
                (object)new
                {
                    requestId,
                    errCode = "1",
                    errCodeDes = "Payment not completed",
                    merchantId
                } : 
                new
                {
                    requestId,
                    errCode = "0",
                    errCodeDes = "Success",
                    merchantId
                };

            // Capture and broadcast again with responseBody
            var finalSseData = new
            {
                type = "inbound",
                headers,
                body = jsonBody,
                endpoint = "/callback",
                verificationStatus = valid ? "Valid" : "Invalid",
                responseBody = responseData
            };

            await BroadcastSse(JsonSerializer.Serialize(finalSseData));

            return Results.Ok(responseData);
        });

        app.MapPost("/api/v1.0/transfer-va/create-va", async (HttpContext context) =>
        {
            var signature = context.Request.Headers["X-Signature"].FirstOrDefault() ?? "";
            var timestamp = context.Request.Headers["X-Timestamp"].FirstOrDefault() ?? "";
            var publicKey = Environment.GetEnvironmentVariable("PAYLABS_PUBLIC_KEY") ?? "";

            Console.WriteLine("Incoming SNAP Create VA Headers:");
            var headers = new Dictionary<string, string>();
            foreach (var header in context.Request.Headers)
            {
                Console.WriteLine($"  {header.Key}: {header.Value}");
                headers[header.Key.ToLower()] = header.Value.ToString();
            }

            using var reader = new StreamReader(context.Request.Body);
            var rawBody = await reader.ReadToEndAsync();

            using var sha256 = SHA256.Create();
            var bodyHash = Convert.ToHexString(sha256.ComputeHash(Encoding.UTF8.GetBytes(rawBody))).ToLower();

            // Pattern: POST:/transfer-va/create-va:{bodyHash}:{timestamp}
            var stringToVerify = $"POST:/transfer-va/create-va:{bodyHash}:{timestamp}";
            Console.WriteLine($"SNAP Create VA String to Verify: {stringToVerify}");

            var valid = PaylabsSignature.VerifySignature(stringToVerify, signature, publicKey);
            var jsonBody = JsonSerializer.Deserialize<object>(rawBody);

            var responseCode = valid ? "2002700" : "4012701";
            var responseMessage = valid ? "Success" : "Invalid Signature";

            var responseData = new
            {
                responseCode,
                responseMessage
            };

            // Broadcast
            var sseData = new
            {
                type = "inbound",
                headers,
                body = jsonBody,
                endpoint = "/api/v1.0/transfer-va/create-va",
                verificationStatus = valid ? "Valid" : "Invalid",
                responseBody = responseData
            };
            
            await BroadcastSse(JsonSerializer.Serialize(sseData));

            if (!valid)
            {
                context.Response.StatusCode = 401;
                await context.Response.WriteAsJsonAsync(responseData);
                return;
            }

            Console.WriteLine("SNAP Create VA Signature is valid");
            await context.Response.WriteAsJsonAsync(responseData);
        });

        app.MapPost("/log", async (HttpContext context) =>
        {
            using var reader = new StreamReader(context.Request.Body);
            var rawBody = await reader.ReadToEndAsync();
            await BroadcastSse(rawBody);
            return Results.Ok();
        });

        app.MapPost("/transfer-va/payment", async (HttpContext context) =>
        {
            var signature = context.Request.Headers["X-Signature"].FirstOrDefault() ?? "";
            var timestamp = context.Request.Headers["X-Timestamp"].FirstOrDefault() ?? "";
            var publicKey = Environment.GetEnvironmentVariable("PAYLABS_PUBLIC_KEY") ?? "";

            Console.WriteLine("Incoming SNAP Callback Headers:");
            var headers = new Dictionary<string, string>();
            foreach (var header in context.Request.Headers)
            {
                Console.WriteLine($"  {header.Key}: {header.Value}");
                headers[header.Key.ToLower()] = header.Value.ToString();
            }

            using var reader = new StreamReader(context.Request.Body);
            var rawBody = await reader.ReadToEndAsync();

            using var sha256 = SHA256.Create();
            var bodyHash = Convert.ToHexString(sha256.ComputeHash(Encoding.UTF8.GetBytes(rawBody))).ToLower();

            var stringToVerify = $"POST:/transfer-va/payment:{bodyHash}:{timestamp}";
            Console.WriteLine($"SNAP String to Verify: {stringToVerify}");

            var valid = PaylabsSignature.VerifySignature(stringToVerify, signature, publicKey);

            // Broadcast
            var bodyElement = JsonDocument.Parse(rawBody).RootElement;
            var jsonBody = JsonSerializer.Deserialize<object>(rawBody);

            var sseData = new
            {
                headers,
                body = jsonBody,
                endpoint = "/transfer-va/payment",
                verificationStatus = valid ? "Valid" : "Invalid"
            };
            
            var sseMessage = $"data: {JsonSerializer.Serialize(sseData)}\n\n";

            await ClientsLock.WaitAsync();
            try
            {
                foreach (var client in Clients)
                {
                    try
                    {
                        await client.WriteAsync(sseMessage);
                        await client.FlushAsync();
                    }
                    catch
                    {
                        // Handle disconnected clients
                    }
                }
            }
            finally
            {
                ClientsLock.Release();
            }

            if (!valid)
            {
                context.Response.StatusCode = 401;
                await context.Response.WriteAsJsonAsync(new { responseCode = "4010000", responseMessage = "Unauthorized" });
                return;
            }

            Console.WriteLine("SNAP Signature is valid");

            var allowedFields = new[] {
                "paidBills", "virtualAccountNo", "paymentRequestId", "partnerServiceId",
                "virtualAccountPhone", "virtualAccountName", "journalNum", "flagAdvise",
                "trxId", "paymentFlagReason", "virtualAccountEmail", "billDetails",
                "totalAmount", "customerNo", "paymentType", "paidAmount", "referenceNo",
                "trxDateTime", "freeTexts", "paymentFlagStatus"
            };

            var filteredBody = new Dictionary<string, object>();
            foreach (var property in bodyElement.EnumerateObject())
            {
                if (allowedFields.Contains(property.Name))
                {
                    // Basic deserialization of property value
                    filteredBody[property.Name] = JsonSerializer.Deserialize<object>(property.Value.GetRawText());
                }
            }
            
            // paymentFlagStatus inside virtualAccountData
            filteredBody["paymentFlagStatus"] = "00";

            var responseData = new
            {
                responseCode = "2002500",
                responseMessage = "Success",
                virtualAccountData = filteredBody
            };

            // Update SSE with responseBody
            var finalSseData = new
            {
                type = "inbound",
                headers,
                body = jsonBody,
                endpoint = "/transfer-va/payment",
                verificationStatus = "Valid",
                responseBody = responseData
            };
            await BroadcastSse(JsonSerializer.Serialize(finalSseData));

            await context.Response.WriteAsJsonAsync(responseData);
        });
    }

    private static async Task BroadcastSse(string message)
    {
        var sseMessage = $"data: {message}\n\n";

        await ClientsLock.WaitAsync();
        try
        {
            foreach (var client in Clients)
            {
                try
                {
                    await client.WriteAsync(sseMessage);
                    await client.FlushAsync();
                }
                catch
                {
                    // Handle disconnected clients
                }
            }
        }
        finally
        {
            ClientsLock.Release();
        }
    }
}
