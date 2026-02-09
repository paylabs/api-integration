using QuickStart;

// Load environment variables from .env file
DotEnv.Load();

var cmdArgs = Environment.GetCommandLineArgs();

if (cmdArgs.Length > 1 && cmdArgs[1] == "callback")
{
    // Run callback server
    var builder = WebApplication.CreateBuilder(cmdArgs);
    var app = builder.Build();
    
    app.UseDefaultFiles();
    app.UseStaticFiles();

    VerifyCallback.MapCallbackEndpoint(app);
    
    var port = Environment.GetEnvironmentVariable("PORT") ?? "3000";
    Console.WriteLine($"Callback server listening on port {port}");
    app.Run($"http://localhost:{port}");
}
else
{
    // Run QRIS creation
    // await GenerateTransaction.CreateQRIS();
await GenerateTransaction.CreateSnapTransaction();
}

// Simple .env file loader
public static class DotEnv
{
    public static void Load(string filePath = ".env")
    {
        if (!File.Exists(filePath)) return;

        foreach (var line in File.ReadAllLines(filePath))
        {
            var trimmed = line.Trim();
            if (string.IsNullOrEmpty(trimmed) || trimmed.StartsWith("#")) continue;

            var separatorIndex = trimmed.IndexOf('=');
            if (separatorIndex == -1) continue;

            var key = trimmed[..separatorIndex].Trim();
            var value = trimmed[(separatorIndex + 1)..].Trim();
            
            // Remove surrounding quotes
            if ((value.StartsWith("\"") && value.EndsWith("\"")) ||
                (value.StartsWith("'") && value.EndsWith("'")))
            {
                value = value[1..^1];
            }

            Environment.SetEnvironmentVariable(key, value);
        }
    }
}
