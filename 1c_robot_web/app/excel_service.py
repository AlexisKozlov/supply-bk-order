from datetime import datetime
from pathlib import Path
from uuid import uuid4

import pandas as pd


BASE_DIR = Path(__file__).resolve().parents[1]
UPLOADS_DIR = BASE_DIR / "storage" / "uploads"
OUTPUTS_DIR = BASE_DIR / "storage" / "outputs"

MODE_SINGLE = "single_stt"
MODE_SUMMARY = "summary_ettn"


TEXT_COLUMNS_DEFAULT = ("Артикул", "GTIN", "Штрихкод", "ЭТТН", "Номер ЭТТН", "№ ресторана")


def write_xlsx_with_text_columns(df, path, text_columns=TEXT_COLUMNS_DEFAULT):
    """Сохранить DataFrame в xlsx и пометить колонки с кодами как текст,
    чтобы Excel не отбрасывал ведущие нули у артикулов/GTIN."""
    path = Path(path)
    with pd.ExcelWriter(path, engine="openpyxl") as writer:
        df.to_excel(writer, index=False)
        worksheet = writer.sheets[list(writer.sheets.keys())[0]]
        column_indexes = {}
        for idx, column_name in enumerate(df.columns, start=1):
            if column_name in text_columns:
                column_indexes[idx] = column_name
        if not column_indexes:
            return
        for row in worksheet.iter_rows(min_row=2, max_row=worksheet.max_row):
            for cell in row:
                if cell.column in column_indexes:
                    if cell.value is None:
                        continue
                    cell.value = str(cell.value)
                    cell.number_format = "@"


def normalize_gtin(value):
    if pd.isna(value):
        return ""
    text = str(value).strip()
    if text.endswith(".0"):
        text = text[:-2]
    return text


def safe_filename(value):
    text = str(value).strip() or "empty"
    allowed = []
    for char in text:
        allowed.append(char if char.isalnum() or char in ("-", "_") else "_")
    return "".join(allowed)[:120]


def save_upload(upload_file):
    UPLOADS_DIR.mkdir(parents=True, exist_ok=True)
    suffix = Path(upload_file.filename or "upload.xlsx").suffix or ".xlsx"
    target = UPLOADS_DIR / f"{datetime.now().strftime('%Y%m%d_%H%M%S')}_{uuid4().hex}{suffix}"
    with target.open("wb") as fh:
        fh.write(upload_file.file.read())
    return target


def load_reference(reference_path):
    df = pd.read_excel(reference_path, dtype=str)
    required = {"Штрихкод", "Артикул"}
    missing = required - set(df.columns)
    if missing:
        raise ValueError(f"В справочнике нет колонок: {', '.join(sorted(missing))}")

    df = df.copy()
    df["GTIN"] = df["Штрихкод"].map(normalize_gtin)
    df["Артикул"] = df["Артикул"].astype(str).str.strip()
    df = df[df["GTIN"] != ""]

    mapping = {}
    for gtin, group in df.groupby("GTIN"):
        articles = sorted(set(item for item in group["Артикул"].tolist() if item))
        mapping[gtin] = articles
    return mapping


def resolve_articles(rows, reference_map):
    result = rows.copy()
    statuses = []
    articles = []

    for gtin in result["GTIN"].map(normalize_gtin):
        matches = reference_map.get(gtin, [])
        if len(matches) == 1:
            statuses.append("OK")
            articles.append(matches[0])
        elif len(matches) > 1:
            statuses.append("DUPLICATE_GTIN")
            articles.append(", ".join(matches))
        else:
            statuses.append("NOT_FOUND")
            articles.append("")

    result["Артикул"] = articles
    result["Статус"] = statuses
    return result


def write_common_files(result_df, output_dir):
    queue_path = output_dir / "queue.xlsx"
    ok_path = output_dir / "queue_ok.xlsx"
    errors_path = output_dir / "queue_errors.xlsx"

    write_xlsx_with_text_columns(result_df, queue_path)
    write_xlsx_with_text_columns(result_df[result_df["Статус"] == "OK"], ok_path)
    write_xlsx_with_text_columns(result_df[result_df["Статус"] != "OK"], errors_path)

    return [
        {"name": "queue.xlsx", "path": queue_path.name},
        {"name": "queue_ok.xlsx", "path": ok_path.name},
        {"name": "queue_errors.xlsx", "path": errors_path.name},
    ]


def stats_for(result_df):
    return {
        "total": int(len(result_df)),
        "ok": int((result_df["Статус"] == "OK").sum()),
        "not_found": int((result_df["Статус"] == "NOT_FOUND").sum()),
        "duplicate": int((result_df["Статус"] == "DUPLICATE_GTIN").sum()),
    }


def find_header_row(raw_df, marker="GTIN"):
    for idx, row in raw_df.iterrows():
        values = [str(value).strip() for value in row.tolist() if not pd.isna(value)]
        if marker in values:
            return idx
    raise ValueError("Не найдена строка с заголовком GTIN")


def parse_single_stt(invoice_path):
    raw = pd.read_excel(invoice_path, header=None, dtype=str)
    header_row = find_header_row(raw, "GTIN")
    data = raw.iloc[header_row + 2 :].copy()

    rows = pd.DataFrame(
        {
            "GTIN": data.iloc[:, 1].map(normalize_gtin),
            "Наименование товара": data.iloc[:, 4].fillna("").astype(str).str.strip(),
            "Количество": data.iloc[:, 12].fillna("").astype(str).str.strip(),
        }
    )
    rows = rows[rows["GTIN"] != ""].reset_index(drop=True)
    return rows


def find_column(columns, expected):
    normalized = {str(column).strip().lower(): column for column in columns}
    key = expected.strip().lower()
    if key not in normalized:
        raise ValueError(f"Не найдена колонка: {expected}")
    return normalized[key]


def parse_summary_ettn(invoice_path):
    sheets = pd.read_excel(invoice_path, sheet_name=None, dtype=str)
    frames = []

    for sheet_name, df in sheets.items():
        if df.empty:
            continue

        gtin_col = find_column(df.columns, "GTIN")
        qty_col = find_column(df.columns, "Количество")
        ettn_col = find_column(df.columns, "Номер ЭТТН")

        optional = {}
        for name in ("Товар", "№ ресторана", "Адрес ресторана"):
            try:
                optional[name] = find_column(df.columns, name)
            except ValueError:
                pass

        frame = pd.DataFrame(
            {
                "Лист": sheet_name,
                "Номер ЭТТН": df[ettn_col].fillna("").astype(str).str.strip(),
                "GTIN": df[gtin_col].map(normalize_gtin),
                "Количество": df[qty_col].fillna("").astype(str).str.strip(),
            }
        )
        for output_name, source_col in optional.items():
            frame[output_name] = df[source_col].fillna("").astype(str).str.strip()
        frames.append(frame)

    if not frames:
        raise ValueError("В файле не найдено подходящих листов")

    rows = pd.concat(frames, ignore_index=True)
    rows = rows[(rows["GTIN"] != "") & (rows["Номер ЭТТН"] != "")].reset_index(drop=True)
    return rows


def process_files(reference_path, invoice_path, mode):
    timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    output_dir = OUTPUTS_DIR / timestamp
    output_dir.mkdir(parents=True, exist_ok=True)

    reference_map = load_reference(reference_path)
    if mode == MODE_SINGLE:
        source_rows = parse_single_stt(invoice_path)
    elif mode == MODE_SUMMARY:
        source_rows = parse_summary_ettn(invoice_path)
    else:
        raise ValueError("Неизвестный режим обработки")

    result_df = resolve_articles(source_rows, reference_map)
    files = write_common_files(result_df, output_dir)

    if mode == MODE_SUMMARY and "Номер ЭТТН" in result_df.columns:
        for ettn, group in result_df[result_df["Статус"] == "OK"].groupby("Номер ЭТТН"):
            filename = f"{safe_filename(ettn)}_queue_ok.xlsx"
            write_xlsx_with_text_columns(group, output_dir / filename)
            files.append({"name": filename, "path": filename})

    return {
        "output_id": output_dir.name,
        "stats": stats_for(result_df),
        "preview": result_df.head(100).fillna("").to_dict(orient="records"),
        "columns": list(result_df.columns),
        "files": files,
    }
