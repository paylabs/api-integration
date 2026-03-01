@echo off
REM Simple runner for .NET on Windows (cmd.exe)
SETLOCAL ENABLEDELAYEDEXPANSION

IF NOT DEFINED DOTNET_ROOT (
  where dotnet >nul 2>&1 || (
    echo .NET CLI not found. Install .NET SDK first.
    exit /b 1
  )
)

echo Restoring and building project...
dotnet restore QuickStart.csproj
dotnet build QuickStart.csproj

IF "%1"=="" (
  set CMD=run
) ELSE (
  set CMD=%1
)

IF /I "%CMD%"=="run" (
  echo Running project (generate)...
  dotnet run --project QuickStart.csproj
) ELSE IF /I "%CMD%"=="generate" (
  echo Running project (generate)...
  dotnet run --project QuickStart.csproj
) ELSE IF /I "%CMD%"=="callback" (
  echo Running project (callback)...
  dotnet run --project QuickStart.csproj -- callback
) ELSE (
  echo Usage: run.bat [run^|generate^|callback]
  exit /b 2
)
