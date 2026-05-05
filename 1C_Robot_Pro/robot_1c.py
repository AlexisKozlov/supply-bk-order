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


# Предустановленные скорости
SPEED_PRESETS = {
    "slow": {
        "pause_between_keys": 0.3,
        "pause_between_rows": 0.5,
    },
    "medium": {
        "pause_between_keys": 0.15,
        "pause_between_rows": 0.3,
    },
    "fast": {
        "pause_between_keys": 0.05,
        "pause_between_rows": 0.1,
    },
}


def get_pause_settings(args):
    """Возвращает словарь с паузами на основе аргументов командной строки."""
    if args.speed:
        return SPEED_PRESETS[args.speed].copy()
    # Если скорость не указана, используем явные паузы или medium по умолчанию
    return {
        "pause_between_keys": args.pause_between_keys or SPEED_PRESETS["medium"]["pause_between_keys"],
        "pause_between_rows": args.pause_between_rows or SPEED_PRESETS["medium"]["pause_between_rows"],
    }


def type_value(value, pause):
    pyautogui.write(str(value), interval=pause)


def press_enter(times, pause):
    for _ in range(times):
        pyautogui.press("enter")
        time.sleep(pause)


def process_row(article, quantity, pause_keys, pause_rows):
    type_value(article, pause_keys)
    press_enter(4, pause_keys)
    type_value(quantity, pause_keys)
    press_enter(2, pause_keys)
    pyautogui.press("down")
    time.sleep(pause_rows)


def process_mercury_row(article, quantity, pause_keys, pause_rows):
    type_value(article, pause_keys)
    pyautogui.press("down")
    time.sleep(pause_keys)
    press_enter(1, pause_keys)
    type_value(quantity, pause_keys)
    press_enter(1, pause_keys)
    time.sleep(pause_rows)


def run(queue_file, mode, target, pause_keys, pause_rows):
    if pyautogui is None:
        raise RuntimeError("Не установлен пакет pyautogui")

    df = pd.read_excel(queue_file, dtype=str).fillna("")
    if "Артикул" not in df.columns or "Количество" not in df.columns:
        raise ValueError("В файле должны быть колонки 'Артикул' и 'Количество'")

    logging.info("Файл: %s", queue_file)
    logging.info("Режим: %s", mode)
    logging.info("Система загрузки: %s", target)
    logging.info("Пауза между нажатиями: %.3f с", pause_keys)
    logging.info("Пауза между строками: %.3f с", pause_rows)
    logging.info("Строк: %s", len(df))

    for index, row in df.iterrows():
        article = str(row["Артикул"]).strip()
        quantity = str(row["Количество"]).strip()
        if not article or not quantity:
            logging.warning("Строка %s пропущена: нет артикула или количества", index + 1)
            continue
        logging.info("Строка %s: артикул %s, количество %s", index + 1, article, quantity)
        if target == "mercury":
            process_mercury_row(article, quantity, pause_keys, pause_rows)
        else:
            process_row(article, quantity, pause_keys, pause_rows)

    logging.info("Готово")


def main():
    parser = argparse.ArgumentParser(description="Ввод queue_ok.xlsx в 1С или Меркурий")
    parser.add_argument("queue_file", help="Путь к файлу queue_ok.xlsx")
    parser.add_argument("--mode", choices=["fast", "safe"], default="safe",
                        help="Режим работы (устарел, используйте --speed)")
    parser.add_argument("--target", choices=["1c", "mercury"], default="1c")
    parser.add_argument("--speed", choices=["slow", "medium", "fast"], default=None,
                        help="Предустановленная скорость ввода")
    parser.add_argument("--pause-between-keys", type=float, default=None,
                        help="Пауза между нажатиями клавиш (секунды)")
    parser.add_argument("--pause-between-rows", type=float, default=None,
                        help="Пауза между строками (секунды)")
    args = parser.parse_args()

    setup_logging()
    queue_file = Path(args.queue_file)
    if not queue_file.exists():
        raise FileNotFoundError(f"Файл не найден: {queue_file}")

    pause_settings = get_pause_settings(args)
    run(queue_file, args.mode, args.target,
        pause_settings["pause_between_keys"],
        pause_settings["pause_between_rows"])


if __name__ == "__main__":
    main()
