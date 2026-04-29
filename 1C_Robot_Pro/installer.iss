[Setup]
AppName=1C Robot Pro
AppVersion=1.0.0
DefaultDirName={localappdata}\1C_Robot_Pro
DefaultGroupName=1C Robot Pro
OutputDir=installer_output
OutputBaseFilename=1C_Robot_Setup
Compression=lzma
SolidCompression=yes
PrivilegesRequired=lowest
DisableProgramGroupPage=yes

[Files]
Source: "release\1C_Robot.exe"; DestDir: "{app}"; Flags: ignoreversion
Source: "release\app.pyw"; DestDir: "{app}"; Flags: ignoreversion
Source: "release\prepare_from_summary.py"; DestDir: "{app}"; Flags: ignoreversion
Source: "release\settings.json"; DestDir: "{app}"; Flags: ignoreversion
Source: "release\README.md"; DestDir: "{app}"; Flags: ignoreversion
Source: "release\output\*"; DestDir: "{app}\output"; Flags: ignoreversion recursesubdirs createallsubdirs onlyifdoesntexist skipifsourcedoesntexist
Source: "release\stt\*"; DestDir: "{app}\stt"; Flags: ignoreversion recursesubdirs createallsubdirs onlyifdoesntexist skipifsourcedoesntexist
Source: "release\done\*"; DestDir: "{app}\done"; Flags: ignoreversion recursesubdirs createallsubdirs onlyifdoesntexist skipifsourcedoesntexist
Source: "release\logs\*"; DestDir: "{app}\logs"; Flags: ignoreversion recursesubdirs createallsubdirs onlyifdoesntexist skipifsourcedoesntexist
Source: "release\reference\*"; DestDir: "{app}\reference"; Flags: ignoreversion recursesubdirs createallsubdirs onlyifdoesntexist skipifsourcedoesntexist

[Dirs]
Name: "{app}\output"
Name: "{app}\stt"
Name: "{app}\done"
Name: "{app}\logs"
Name: "{app}\reference"

[Icons]
Name: "{autodesktop}\1C Robot Pro"; Filename: "{app}\1C_Robot.exe"
Name: "{group}\1C Robot Pro"; Filename: "{app}\1C_Robot.exe"

[Run]
Filename: "{app}\1C_Robot.exe"; Description: "Запустить 1C Robot Pro"; Flags: nowait postinstall skipifsilent
