@echo off
setlocal
cd /d "%~dp0"

call build_exe.bat

if not exist installer_output mkdir installer_output
where ISCC.exe >nul 2>nul
if errorlevel 1 (
    if exist "%ProgramFiles(x86)%\Inno Setup 6\ISCC.exe" (
        "%ProgramFiles(x86)%\Inno Setup 6\ISCC.exe" installer.iss
    ) else if exist "%ProgramFiles%\Inno Setup 6\ISCC.exe" (
        "%ProgramFiles%\Inno Setup 6\ISCC.exe" installer.iss
    ) else (
        echo Не найден ISCC.exe. Установите Inno Setup 6.
        exit /b 1
    )
) else (
    ISCC.exe installer.iss
)

echo Установщик готов: installer_output\1C_Robot_Setup.exe
endlocal
