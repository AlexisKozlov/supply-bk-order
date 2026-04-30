# -*- coding: utf-8 -*-
from __future__ import annotations

from pathlib import Path

import pandas as pd


MODE_SINGLE = "single_stt"
MODE_SUMMARY = "summary_ettn"
MODE_MERCURY_DODO = "mercury_dodo"

MERCURY_STOCK_SHEETS = {
    "dry": "СУХОЙ",
    "cold": "ХОЛОД",
    "frozen": "МОРОЗ",
    "сухой": "СУХОЙ",
    "холод": "ХОЛОД",
    "мороз": "МОРОЗ",
}


def normalize_gtin(value):
    if pd.isna(value):
        return ""
    text = str(value).strip()
    if text.endswith(".0"):
        text = text[:-2]
    return text


def safe_filename(value):
    text = str(value).strip() or "empty"
    result = []
    for char in text:
        result.append(char if char.isalnum() or char in ("-", "_") else "_")
    return "".join(result)[:120]


def normalize_text(value):
    if pd.isna(value):
        return ""
    return " ".join(str(value).strip().lower().split())


def first_product_token(value):
    if pd.isna(value):
        return ""
    text = str(value).strip()
    if not text:
        return ""
    return text.split()[0].strip()


def load_reference(reference_path: Path):
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


def find_header_row(raw_df, marker="GTIN"):
    for idx, row in raw_df.iterrows():
        values = [str(value).strip() for value in row.tolist() if not pd.isna(value)]
        if marker in values:
            return idx
    raise ValueError("Не найдена строка с заголовком GTIN")


def parse_single_stt(invoice_path: Path):
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


def parse_summary_ettn(invoice_path: Path):
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


def parse_mercury_dodo_all(invoice_path: Path):
    sheets = pd.read_excel(invoice_path, sheet_name=None, dtype=str)
    frames = []

    for sheet_name in ("СУХОЙ", "ХОЛОД", "МОРОЗ"):
        matching_sheet = None
        for name, df in sheets.items():
            if normalize_text(name) == normalize_text(sheet_name):
                matching_sheet = df
                break
        if matching_sheet is None:
            continue

        df = matching_sheet.copy()
        required = ["№ ресторана", "Адрес ресторана", "Товар", "Количество"]
        missing = [name for name in required if name not in df.columns]
        if missing:
            raise ValueError(f"На листе {sheet_name} нет колонок: {', '.join(missing)}")

        df["№ ресторана"] = df["№ ресторана"].fillna("").astype(str).str.strip()
        df["Адрес ресторана"] = df["Адрес ресторана"].fillna("").astype(str).str.strip()
        df["Товар"] = df["Товар"].fillna("").astype(str).str.strip()
        df["Количество"] = df["Количество"].fillna("").astype(str).str.strip()
        df = df[(df["№ ресторана"] != "") & (df["Адрес ресторана"] != "") & (df["Товар"] != "") & (df["Количество"] != "")]

        frame = pd.DataFrame(
            {
                "№ ресторана": df["№ ресторана"],
                "Адрес ресторана": df["Адрес ресторана"],
                "Артикул": df["Товар"].map(first_product_token),
                "Количество": df["Количество"],
                "Склад": sheet_name,
                "Товар": df["Товар"],
            }
        )
        frame = frame[(frame["Артикул"] != "") & (frame["Артикул"].map(normalize_text) != "товар")]
        frames.append(frame)

    if not frames:
        raise ValueError("В файле не найдены листы СУХОЙ, ХОЛОД или МОРОЗ")

    result = pd.concat(frames, ignore_index=True)
    result = result[(result["Артикул"] != "") & (result["Количество"] != "")].reset_index(drop=True)
    if result.empty:
        raise ValueError("В файле Меркурия нет строк для загрузки")
    return result


def clear_output_xlsx(output_dir: Path):
    output_dir.mkdir(exist_ok=True)
    for path in output_dir.glob("*.xlsx"):
        path.unlink()


def write_common_files(result_df, output_dir: Path):
    result_df.to_excel(output_dir / "queue.xlsx", index=False)
    result_df[result_df["Статус"] == "OK"].to_excel(output_dir / "queue_ok.xlsx", index=False)
    result_df[result_df["Статус"] != "OK"].to_excel(output_dir / "queue_errors.xlsx", index=False)


def stats_for(result_df):
    return {
        "total": int(len(result_df)),
        "ok": int((result_df["Статус"] == "OK").sum()),
        "not_found": int((result_df["Статус"] == "NOT_FOUND").sum()),
        "duplicate": int((result_df["Статус"] == "DUPLICATE_GTIN").sum()),
    }


def simple_stats_for(result_df):
    return {
        "total": int(len(result_df)),
        "ok": int(len(result_df)),
        "not_found": 0,
        "duplicate": 0,
    }


def process_mercury_to_output(invoice_path: Path, output_dir: Path, clear_output=True):
    result_df = parse_mercury_dodo_all(invoice_path)
    if clear_output:
        clear_output_xlsx(output_dir)
    else:
        output_dir.mkdir(exist_ok=True)

    result_df.to_excel(output_dir / "mercury_queue.xlsx", index=False)
    created = ["mercury_queue.xlsx"]

    for (rest_number, rest_address, stock_name), group in result_df.groupby(["№ ресторана", "Адрес ресторана", "Склад"], sort=True):
        load_df = group[["Артикул", "Количество"]].copy()
        output_name = f"{safe_filename(rest_number)}_{safe_filename(rest_address)}_{stock_name}_mercury_queue_ok.xlsx"
        load_df.to_excel(output_dir / output_name, index=False)
        created.append(output_name)

    return {
        "created": created,
        "stats": simple_stats_for(result_df),
    }


def process_to_output(reference_path: Path, invoice_path: Path, output_dir: Path, mode: str, clear_output=True):
    reference_map = load_reference(reference_path)
    if mode == MODE_SINGLE:
        source_rows = parse_single_stt(invoice_path)
    elif mode == MODE_SUMMARY:
        source_rows = parse_summary_ettn(invoice_path)
    else:
        raise ValueError("Неизвестный режим обработки")

    result_df = resolve_articles(source_rows, reference_map)
    if clear_output:
        clear_output_xlsx(output_dir)
    else:
        output_dir.mkdir(exist_ok=True)

    write_common_files(result_df, output_dir)
    created = ["queue.xlsx", "queue_ok.xlsx", "queue_errors.xlsx"]

    if mode == MODE_SUMMARY and "Номер ЭТТН" in result_df.columns:
        ok_rows = result_df[result_df["Статус"] == "OK"]
        for ettn, group in ok_rows.groupby("Номер ЭТТН"):
            filename = f"{safe_filename(ettn)}_queue_ok.xlsx"
            group.to_excel(output_dir / filename, index=False)
            created.append(filename)

    return {
        "created": created,
        "stats": stats_for(result_df),
    }
