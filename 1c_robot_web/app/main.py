from pathlib import Path

from fastapi import FastAPI, File, Form, Request, UploadFile
from fastapi.responses import FileResponse, JSONResponse
from fastapi.staticfiles import StaticFiles
from fastapi.templating import Jinja2Templates

from app.version_service import get_version_info, installer_path


BASE_DIR = Path(__file__).resolve().parents[1]
OUTPUTS_DIR = BASE_DIR / "storage" / "outputs"

app = FastAPI(title="1C Robot Pro")
templates = Jinja2Templates(directory=str(BASE_DIR / "app" / "templates"))
app.mount("/static", StaticFiles(directory=str(BASE_DIR / "app" / "static")), name="static")


@app.get("/")
def index(request: Request):
    return templates.TemplateResponse(
        "index.html",
        {"request": request, "modes": {"single_stt": "Одна накладная СТТ", "summary_ettn": "Сводная таблица по ЭТТН"}},
    )


@app.post("/process")
def process(
    request: Request,
    reference_file: UploadFile = File(...),
    invoice_file: UploadFile = File(...),
    mode: str = Form(...),
):
    try:
        from app.excel_service import process_files, save_upload

        reference_path = save_upload(reference_file)
        invoice_path = save_upload(invoice_file)
        result = process_files(reference_path, invoice_path, mode)
        return templates.TemplateResponse("results.html", {"request": request, **result})
    except Exception as exc:
        return templates.TemplateResponse(
            "index.html",
            {
                "request": request,
                "error": str(exc),
                "modes": {"single_stt": "Одна накладная СТТ", "summary_ettn": "Сводная таблица по ЭТТН"},
            },
            status_code=400,
        )


@app.get("/outputs/{output_id}/{filename}")
def download_output(output_id: str, filename: str):
    path = OUTPUTS_DIR / output_id / filename
    if not path.exists() or not path.is_file():
        return JSONResponse({"error": "Файл не найден"}, status_code=404)
    return FileResponse(path, filename=filename)


@app.get("/download")
def download_page(request: Request):
    version = get_version_info()
    installer = installer_path()
    return templates.TemplateResponse(
        "download.html",
        {
            "request": request,
            "version": version,
            "installer_exists": installer.exists(),
        },
    )


@app.get("/version.json")
def version_json():
    return JSONResponse(get_version_info())


@app.get("/releases/1C_Robot_Setup.exe")
def release_installer():
    installer = installer_path()
    if not installer.exists():
        return JSONResponse({"error": "Установщик пока не загружен на сайт"}, status_code=404)
    return FileResponse(installer, filename="1C_Robot_Setup.exe")
