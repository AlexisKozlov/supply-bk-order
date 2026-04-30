import argparse
import logging
import sys
import time
from pathlib import Path

import pandas as pd

try:
    import pyautogui
except ImportError:
    pyautogui = None


def app_dir():
    if getattr(sys, "frozen", False):
        return Path(sys.executable).parent
    return Path(__file__).resolve().parent


BASE_DIR = app_dir()
LOG_DIR = BASE_DIR / "logs"


def setup_logging():
    LOG_DIR.mkdir(exist_ok=True)
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(message)s",
        handlers=[
            logging.FileHandler(LOG_DIR / "robot_1c.log", encoding="utf-8"),
            logging.StreamHandler(sys.stdout),
        ],
    )


def delay_for_mode(mode):
    return 0.05 if mode == "fast" else 0.2


def type_value(value, pause):
    pyautogui.write(str(value), interval=pause)


def press_enter(times, pause):
    for _ in range(times):
        pyautogui.press("enter")
        time.sleep(pause)


def process_row(article, quantity, pause):
    type_value(article, pause)
    press_enter(4, pause)
    type_value(quantity, pause)
    press_enter(2, pause)
    pyautogui.press("down")
    time.sleep(pause)


def process_mercury_row(article, quantity, pause):
    type_value(article, pause)
    pyautogui.press("down")
    time.sleep(pause)
    press_enter(1, pause)
    type_value(quantity, pause)
    press_enter(1, pause)


def run(queue_file, mode, target):
    if pyautogui is None:
        raise RuntimeError("Не установлен пакет pyautogui")

    df = pd.read_excel(queue_file, dtype=str).fillna("")
    if "Артикул" not in df.columns or "Количество" not in df.columns:
        raise ValueError("В файле должны быть колонки 'Артикул' и 'Количество'")

    pause = delay_for_mode(mode)
    logging.info("Файл: %s", queue_file)
    logging.info("Режим: %s", mode)
    logging.info("Система загрузки: %s", target)
    logging.info("Строк: %s", len(df))

    for index, row in df.iterrows():
        article = str(row["Артикул"]).strip()
        quantity = str(row["Количество"]).strip()
        if not article or not quantity:
            logging.warning("Строка %s пропущена: нет артикула или количества", index + 1)
            continue
        logging.info("Строка %s: артикул %s, количество %s", index + 1, article, quantity)
        if target == "mercury":
            process_mercury_row(article, quantity, pause)
        else:
            process_row(article, quantity, pause)

    logging.info("Готово")


def main():
    parser = argparse.ArgumentParser(description="Ввод queue_ok.xlsx в 1С или Меркурий")
    parser.add_argument("queue_file", help="Путь к файлу queue_ok.xlsx")
    parser.add_argument("--mode", choices=["fast", "safe"], default="safe")
    parser.add_argument("--target", choices=["1c", "mercury"], default="1c")
    args = parser.parse_args()

    setup_logging()
    queue_file = Path(args.queue_file)
    if not queue_file.exists():
        raise FileNotFoundError(f"Файл не найден: {queue_file}")
    run(queue_file, args.mode, args.target)


if __name__ == "__main__":
    main()
