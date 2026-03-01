@echo off
REM Simple runner for Windows (cmd.exe)
SETLOCAL ENABLEDELAYEDEXPANSION

IF NOT EXIST venv (
  echo Creating virtual environment in venv...
  python -m venv venv
)

call venv\Scripts\activate

echo Installing requirements...
pip install -r requirements.txt

IF "%1"=="" (
  set CMD=generate
) ELSE (
  set CMD=%1
)

IF /I "%CMD%"=="generate" (
  echo Running generate_transaction.py...
  python generate_transaction.py
) ELSE IF /I "%CMD%"=="callback" (
  echo Running verify_callback.py...
  python verify_callback.py
) ELSE (
  echo Usage: run.bat [generate^|callback]
  exit /b 2
)
