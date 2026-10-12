#!/bin/bash

echo "============================================================"
echo "           NutriTrack ML Environment Setup"
echo "============================================================"
echo ""

# Check Python installation
echo "[Step 1/5] Checking Python installation..."
if ! command -v python3 &> /dev/null; then
    echo "ERROR: Python3 not found!"
    echo "Please install Python 3.9 or higher"
    exit 1
fi
python3 --version
echo ""

# Create virtual environment
echo "[Step 2/5] Creating virtual environment..."
if [ -d "venv" ]; then
    echo "Virtual environment already exists. Skipping..."
else
    python3 -m venv venv
    if [ $? -ne 0 ]; then
        echo "ERROR: Failed to create virtual environment"
        exit 1
    fi
    echo "✓ Virtual environment created!"
fi
echo ""

# Activate virtual environment
echo "[Step 3/5] Activating virtual environment..."
source venv/bin/activate
echo "✓ Virtual environment activated!"
echo ""

# Upgrade pip
echo "[Step 4/5] Upgrading pip..."
pip install --upgrade pip
echo ""

# Install requirements
echo "[Step 5/5] Installing Python packages..."
echo "This may take a few minutes..."
pip install -r requirements.txt
if [ $? -ne 0 ]; then
    echo "ERROR: Failed to install packages"
    exit 1
fi
echo ""

echo "============================================================"
echo "                Setup Complete!"
echo "============================================================"
echo ""
echo "Virtual environment created at: venv/"
echo ""
echo "To activate the environment in the future, run:"
echo "  source venv/bin/activate"
echo ""
echo "To deactivate, run:"
echo "  deactivate"
echo ""
echo "Next steps:"
echo "  1. cd ../scripts/"
echo "  2. python train_model.py"
echo "  3. python predict.py 22.5"
echo ""