@echo off
cd /d "%~dp0"
echo Starting 1C Robot...
pythonw app.pyw
if errorlevel 1 (
    echo Failed to start with pythonw. Trying python...
    python app.pyw
)
pause
