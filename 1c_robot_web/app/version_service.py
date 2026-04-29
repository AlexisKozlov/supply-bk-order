import json
from pathlib import Path


BASE_DIR = Path(__file__).resolve().parents[1]
RELEASES_DIR = BASE_DIR / "storage" / "releases"
VERSION_FILE = RELEASES_DIR / "version.json"
INSTALLER_NAME = "1C_Robot_Setup.exe"

DEFAULT_VERSION = {
    "version": "1.0.0",
    "installer_url": f"/releases/{INSTALLER_NAME}",
    "notes": "Первая версия программы",
}


def ensure_version_file():
    RELEASES_DIR.mkdir(parents=True, exist_ok=True)
    if not VERSION_FILE.exists():
        VERSION_FILE.write_text(
            json.dumps(DEFAULT_VERSION, ensure_ascii=False, indent=2),
            encoding="utf-8",
        )


def get_version_info():
    ensure_version_file()
    try:
        data = json.loads(VERSION_FILE.read_text(encoding="utf-8"))
    except (json.JSONDecodeError, OSError):
        data = DEFAULT_VERSION.copy()

    data.setdefault("version", DEFAULT_VERSION["version"])
    data.setdefault("installer_url", DEFAULT_VERSION["installer_url"])
    data.setdefault("notes", DEFAULT_VERSION["notes"])
    return data


def installer_path():
    return RELEASES_DIR / INSTALLER_NAME
