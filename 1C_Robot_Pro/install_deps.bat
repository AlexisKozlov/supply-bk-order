@echo off
cd /d "%~dp0"
echo Installing dependencies...
python -m pip install --upgrade pip
python -m pip install pandas openpyxl pyautogui keyboard pyperclip pyinstaller
echo.
echo Done.
pause
