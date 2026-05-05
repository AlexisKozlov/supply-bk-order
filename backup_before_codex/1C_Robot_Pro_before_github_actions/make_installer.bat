@echo off
setlocal
cd /d "%~dp0"

call build_exe.bat

if not exist installer_output mkdir installer_output
ISCC.exe installer.iss

echo Установщик готов: installer_output\1C_Robot_Setup.exe
endlocal
