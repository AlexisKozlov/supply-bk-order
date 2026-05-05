# -*- coding: utf-8 -*-
"""Проверки на сохранение ведущих нулей у артикулов.

Запуск:
    python 1C_Robot_Pro/tests/test_articles.py
"""
from __future__ import annotations

import io
import sys
from pathlib import Path

import pandas as pd

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from excel_service import first_product_token, normalize_gtin, write_xlsx_with_text_columns


def test_first_product_token_keeps_leading_zeros():
    cases = [
        ("001920 Мороженое", "001920"),
        ("000053 Фондант", "000053"),
        ("000568 Тортильи", "000568"),
        ("3375 Колбаски", "3375"),
        ("FMS9 Товар", "FMS9"),
    ]
    for raw, expected in cases:
        actual = first_product_token(raw)
        assert actual == expected, f"first_product_token({raw!r}) = {actual!r}, ожидали {expected!r}"


def test_read_excel_dtype_str_keeps_leading_zeros():
    df = pd.DataFrame({"Артикул": ["001920", "000053", "3375", "FMS9"], "Количество": ["1", "2", "3", "4"]})
    buf = io.BytesIO()
    df.to_excel(buf, index=False)
    buf.seek(0)
    loaded = pd.read_excel(buf, dtype=str).fillna("")
    loaded["Артикул"] = loaded["Артикул"].astype(str).str.strip()
    assert loaded["Артикул"].tolist() == ["001920", "000053", "3375", "FMS9"]


def test_read_excel_without_dtype_breaks_zeros():
    """Контрольный тест: показывает, что без dtype=str ведущие нули теряются.
    Это и был исходный баг."""
    df = pd.DataFrame({"Артикул": ["001920", "000053"], "Количество": ["1", "2"]})
    buf = io.BytesIO()
    df.to_excel(buf, index=False)
    buf.seek(0)
    loaded = pd.read_excel(buf)
    values = [str(value) for value in loaded["Артикул"].tolist()]
    assert "001920" not in values, "Если этот тест упал — pandas сохранил нули, тест устарел."


def test_write_xlsx_keeps_text_articles(tmp_path: Path):
    df = pd.DataFrame({"Артикул": ["001920", "000053", "FMS9"], "Количество": [1, 2, 3]})
    target = tmp_path / "queue_ok.xlsx"
    write_xlsx_with_text_columns(df, target)
    loaded = pd.read_excel(target, dtype=str).fillna("")
    assert loaded["Артикул"].tolist() == ["001920", "000053", "FMS9"]


def test_normalize_gtin_strips_dot_zero():
    assert normalize_gtin("4607034870317.0") == "4607034870317"
    assert normalize_gtin("4607034870317") == "4607034870317"
    assert normalize_gtin("") == ""


def _run_all():
    import tempfile

    failures = []
    for name, fn in list(globals().items()):
        if not name.startswith("test_") or not callable(fn):
            continue
        try:
            if "tmp_path" in fn.__code__.co_varnames:
                with tempfile.TemporaryDirectory() as tmp:
                    fn(Path(tmp))
            else:
                fn()
            print(f"OK  {name}")
        except AssertionError as exc:
            failures.append((name, exc))
            print(f"FAIL {name}: {exc}")
        except Exception as exc:
            failures.append((name, exc))
            print(f"ERR  {name}: {exc!r}")
    if failures:
        sys.exit(1)


if __name__ == "__main__":
    _run_all()
