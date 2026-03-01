#!/usr/bin/env bash
set -e

# Simple runner for development on Unix/macOS
VENV_DIR=venv
PY=python3

if [ ! -d "$VENV_DIR" ]; then
  echo "Creating virtual environment in $VENV_DIR..."
  $PY -m venv "$VENV_DIR"
fi

echo "Activating virtual environment..."
source "$VENV_DIR/bin/activate"

echo "Installing requirements..."
pip install -r requirements.txt

CMD=${1:-generate}

if [ "$CMD" = "generate" ]; then
  echo "Running generate_transaction.py..."
  python generate_transaction.py
elif [ "$CMD" = "callback" ]; then
  echo "Running verify_callback.py..."
  python verify_callback.py
else
  echo "Unknown command: $CMD"
  echo "Usage: ./run.sh [generate|callback]"
  exit 2
fi
