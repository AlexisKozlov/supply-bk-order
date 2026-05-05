# -*- coding: utf-8 -*-
from __future__ import annotations
import re
import pandas as pd
from pathlib import Path

from excel_service import write_xlsx_with_text_columns

BASE_DIR = Path(__file__).resolve().parent
OUTPUT_DIR = BASE_DIR / "output"
SPRAV_FILE = BASE_DIR / "Карточки_ООО Бургер БК_15.04.2026.xlsx"


def normalize(value):
    if pd.isna(value):
        return ""
    s = str(value).strip()
    if s.endswith(".0"):
        s = s[:-2]
    return s


def safe_filename(name):
    return re.sub(r'[\\/*?:"<>|]', "_", str(name).strip())


def main():
    OUTPUT_DIR.mkdir(exist_ok=True)
    summary_files = [f for f in BASE_DIR.glob("*.xlsx") if "свод" in f.name.lower() or "svod" in f.name.lower()]

    if not SPRAV_FILE.exists():
        raise SystemExit(f"ОШИБКА: не найден справочник товаров: {SPRAV_FILE}")
    if not summary_files:
        raise SystemExit("ОШИБКА: не найден файл сводной таблицы. В названии должно быть слово 'Свод'.")

    input_file = summary_files[0]
    if len(summary_files) > 1:
        print("Найдено несколько сводных файлов:")
        for i, f in enumerate(summary_files, 1):
            print(f"{i}. {f.name}")
        choice = int(input("Введите номер нужного файла: ").strip())
        input_file = summary_files[choice - 1]

    print(f"Сводный файл: {input_file.name}")

    sprav = pd.read_excel(SPRAV_FILE, dtype=str).fillna("")
    for col in ["Штрихкод", "Артикул"]:
        if col not in sprav.columns:
            raise SystemExit(f"ОШИБКА: в справочнике нет колонки: {col}")

    sprav["Штрихкод"] = sprav["Штрихкод"].apply(normalize)
    sprav["Артикул"] = sprav["Артикул"].astype(str).str.strip()
    gtin_map = sprav.groupby("Штрихкод")["Артикул"].apply(list).to_dict()

    excel = pd.ExcelFile(input_file)
    all_rows = []
    errors = []

    for sheet in excel.sheet_names:
        print(f"Лист: {sheet}")
        df = excel.parse(sheet, dtype=str).fillna("")
        required = ["GTIN", "Количество", "Номер ЭТТН"]
        if not all(col in df.columns for col in required):
            print(f"Пропуск листа {sheet}: нет нужных колонок.")
            continue

        for _, row in df.iterrows():
            gtin = normalize(row.get("GTIN", ""))
            qty = row.get("Количество", "")
            ettn = normalize(row.get("Номер ЭТТН", ""))
            if not gtin or not ettn:
                continue

            status = "OK"
            article = ""
            if gtin not in gtin_map:
                status = "NOT_FOUND"
            elif len(gtin_map[gtin]) > 1:
                status = "DUPLICATE_GTIN"
                article = gtin_map[gtin][0]
            else:
                article = gtin_map[gtin][0]

            item = {"Артикул": article, "Количество": qty, "GTIN": gtin, "ЭТТН": ettn, "Статус": status, "Лист": sheet}
            all_rows.append(item)
            if status != "OK":
                errors.append(item)

    df_all = pd.DataFrame(all_rows)
    if df_all.empty:
        raise SystemExit("ОШИБКА: нет данных для обработки.")

    write_xlsx_with_text_columns(df_all, OUTPUT_DIR / "summary_all.xlsx")
    if errors:
        write_xlsx_with_text_columns(pd.DataFrame(errors), OUTPUT_DIR / "summary_errors.xlsx")
        print(f"ВНИМАНИЕ: есть проблемные строки: {len(errors)}")

    df_ok = df_all[df_all["Статус"] == "OK"].copy()
    if df_ok.empty:
        raise SystemExit("ОШИБКА: нет строк со статусом OK.")

    count_files = 0
    for ettn, part in df_ok.groupby("ЭТТН"):
        path = OUTPUT_DIR / f"{safe_filename(ettn)}_queue_ok.xlsx"
        write_xlsx_with_text_columns(part[["Артикул", "Количество", "GTIN", "ЭТТН", "Лист"]], path)
        count_files += 1
        print(f"Создан файл: {path.name} | строк: {len(part)}")

    print(f"Готово. Отдельных файлов создано: {count_files}")


if __name__ == "__main__":
    main()
