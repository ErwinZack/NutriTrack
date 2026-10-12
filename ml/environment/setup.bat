@echo off
echo ============================================================
echo           NutriTrack ML Environment Setup
echo ============================================================
echo.

REM Check Python installation
echo [Step 1/5] Checking Python installation...
python --version >nul 2>&1
if %errorlevel% neq 0 (
    echo ERROR: Python not found!
    echo Please install Python 3.9 or higher from python.org
    echo.
    pause
    exit /b 1
)
python --version
echo.

REM Create virtual environment
echo [Step 2/5] Creating virtual environment...
if exist venv (
    echo Virtual environment already exists. Skipping...
) else (
    python -m venv venv
    if %errorlevel% neq 0 (
        echo ERROR: Failed to create virtual environment
        pause
        exit /b 1
    )
    echo Virtual environment created successfully!
)
echo.

REM Activate virtual environment
echo [Step 3/5] Activating virtual environment...
call venv\Scripts\activate
if %errorlevel% neq 0 (
    echo ERROR: Failed to activate virtual environment
    pause
    exit /b 1
)
echo Virtual environment activated!
echo.

REM Upgrade pip
echo [Step 4/5] Upgrading pip...
python -m pip install --upgrade pip
echo.

REM Install requirements
echo [Step 5/5] Installing Python packages...
echo This may take a few minutes...
echo.
pip install -r requirements.txt
if %errorlevel% neq 0 (
    echo ERROR: Failed to install packages
    pause
    exit /b 1
)
echo.

echo ============================================================
echo                Setup Complete!
echo ============================================================
echo.
echo Virtual environment created at: venv\
echo.
echo To activate the environment in the future, run:
echo   venv\Scripts\activate
echo.
echo To deactivate, run:
echo   deactivate
echo.
echo Next steps:
echo   1. cd ..\scripts
echo   2. python train_model.py
echo   3. python predict.py 22.5
echo.
pause