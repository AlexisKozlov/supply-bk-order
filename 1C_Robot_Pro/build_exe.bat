@echo off
setlocal
cd /d "%~dp0"

python -m pip install --upgrade pip
python -m pip install pyinstaller pandas openpyxl pyautogui keyboard pyperclip

pyinstaller --onefile --noconsole --name "1C_Robot" app.pyw

if not exist release mkdir release
if not exist release\output mkdir release\output
if not exist release\stt mkdir release\stt
if not exist release\done mkdir release\done
if not exist release\logs mkdir release\logs
if not exist release\reference mkdir release\reference

copy /Y dist\1C_Robot.exe release\1C_Robot.exe
copy /Y app.pyw release\app.pyw
copy /Y excel_service.py release\excel_service.py
copy /Y prepare_from_summary.py release\prepare_from_summary.py
copy /Y settings.json release\settings.json
copy /Y README.md release\README.md
xcopy /E /I /Y output release\output
xcopy /E /I /Y stt release\stt
xcopy /E /I /Y done release\done
xcopy /E /I /Y logs release\logs
xcopy /E /I /Y reference release\reference

echo Release готов: release
endlocal
