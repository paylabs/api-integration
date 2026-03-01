#!/usr/bin/env bash
set -euo pipefail

# Simple runner for .NET on Unix/macOS
PROJECT_FILE="QuickStart.csproj"

if ! command -v dotnet >/dev/null 2>&1; then
  echo "dotnet CLI not found. Install .NET SDK first." >&2
  exit 1
fi

echo "Restoring and building project..."
dotnet restore "$PROJECT_FILE"
dotnet build "$PROJECT_FILE"

CMD=${1:-run}

if [ "$CMD" = "run" ] || [ "$CMD" = "generate" ]; then
  echo "Running project (generate)..."
  dotnet run --project "$PROJECT_FILE"
elif [ "$CMD" = "callback" ]; then
  echo "Running project (callback)..."
  dotnet run --project "$PROJECT_FILE" -- callback
else
  echo "Unknown command: $CMD"
  echo "Usage: ./run.sh [run|generate|callback]"
  exit 2
fi
