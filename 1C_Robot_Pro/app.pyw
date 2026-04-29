# -*- coding: utf-8 -*-
"""
1C Robot Pro — GUI for loading order rows into 1C UT.
Run by double-clicking app.pyw or 1C_Robot.exe after building.
"""
from __future__ import annotations

import json
import os
import subprocess
import sys
import time
import threading
import traceback
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass
from datetime import datetime
from pathlib import Path

import pandas as pd
import pyautogui
import tkinter as tk
from tkinter import ttk, messagebox, filedialog


# ---------- paths ----------
def app_dir() -> Path:
    # In PyInstaller one-file mode, __file__ points to a temp _MEI folder.
    # sys.executable points to the actual exe. For normal .pyw, use this file's folder.
    if getattr(sys, "frozen", False):
        return Path(sys.executable).resolve().parent
    return Path(__file__).resolve().parent


BASE_DIR = app_dir()
OUTPUT_DIR = BASE_DIR / "output"
LOG_DIR = BASE_DIR / "logs"
SETTINGS_FILE = BASE_DIR / "settings.json"
DOWNLOADS_DIR = Path.home() / "Downloads"

DEFAULT_UPDATE_SETTINGS = {
    "version": "1.0.0",
    "update_url": "https://supply-department.online/version.json",
}

for p in [OUTPUT_DIR, LOG_DIR]:
    p.mkdir(exist_ok=True)


def load_update_settings() -> dict:
    if not SETTINGS_FILE.exists():
        try:
            SETTINGS_FILE.write_text(json.dumps(DEFAULT_UPDATE_SETTINGS, ensure_ascii=False, indent=2), encoding="utf-8")
        except Exception:
            pass
        return DEFAULT_UPDATE_SETTINGS.copy()
    try:
        data = json.loads(SETTINGS_FILE.read_text(encoding="utf-8"))
    except Exception:
        return DEFAULT_UPDATE_SETTINGS.copy()
    result = DEFAULT_UPDATE_SETTINGS.copy()
    result.update(data)
    return result


def version_tuple(value: str) -> tuple[int, ...]:
    result = []
    for part in str(value).split("."):
        try:
            result.append(int(part))
        except ValueError:
            result.append(0)
    return tuple(result)


def absolute_update_url(base_url: str, maybe_relative: str) -> str:
    if maybe_relative.startswith("http://") or maybe_relative.startswith("https://"):
        return maybe_relative
    return urllib.parse.urljoin(base_url, maybe_relative)


@dataclass
class RobotSettings:
    mode: str = "fast"  # fast / safe
    start_delay: int = 5
    # Stable version: article and quantity are typed, not pasted.
    # It is slower, but reliable for 1C fields that open selection dialogs on Ctrl+V.
    fast_type_interval: float = 0.02
    fast_enter_delay: float = 0.25
    fast_step_delay: float = 0.35
    fast_qty_wait: float = 0.50
    safe_type_interval: float = 0.04
    safe_enter_delay: float = 0.45
    safe_step_delay: float = 0.80
    safe_qty_wait: float = 1.20

    @property
    def type_interval(self) -> float:
        return self.safe_type_interval if self.mode == "safe" else self.fast_type_interval

    @property
    def enter_delay(self) -> float:
        return self.safe_enter_delay if self.mode == "safe" else self.fast_enter_delay

    @property
    def step_delay(self) -> float:
        return self.safe_step_delay if self.mode == "safe" else self.fast_step_delay

    @property
    def qty_wait(self) -> float:
        return self.safe_qty_wait if self.mode == "safe" else self.fast_qty_wait


class RobotApp:
    def __init__(self, root: tk.Tk):
        self.root = root
        self.root.title("1C Robot Pro — загрузка накладных")
        self.root.geometry("980x720")
        self.root.minsize(900, 650)

        self.settings = RobotSettings()
        self.update_settings = load_update_settings()
        self.files: list[Path] = []
        self.selected_path: Path | None = None
        self.stop_event = threading.Event()
        self.worker: threading.Thread | None = None
        self.is_running = False

        pyautogui.PAUSE = 0.08

        self.setup_style()
        self.build_ui()
        self.refresh_files(silent=True)

        # Auto-paste clipboard into search field if it looks useful.
        try:
            clip = self.root.clipboard_get().strip()
            if clip:
                self.search_var.set(clip)
                self.search_files()
        except Exception:
            pass

    def setup_style(self):
        style = ttk.Style()
        try:
            style.theme_use("clam")
        except Exception:
            pass

        style.configure("TFrame", background="#f5f7fb")
        style.configure("Card.TFrame", background="#ffffff", relief="flat")
        style.configure("TLabel", background="#f5f7fb", foreground="#1f2937", font=("Segoe UI", 10))
        style.configure("Title.TLabel", font=("Segoe UI", 18, "bold"), foreground="#111827", background="#f5f7fb")
        style.configure("Sub.TLabel", font=("Segoe UI", 10), foreground="#6b7280", background="#f5f7fb")
        style.configure("Status.TLabel", font=("Segoe UI", 11, "bold"), foreground="#111827", background="#f5f7fb")
        style.configure("TButton", font=("Segoe UI", 10), padding=8)
        style.configure("Accent.TButton", font=("Segoe UI", 10, "bold"), padding=8)
        style.configure("Danger.TButton", font=("Segoe UI", 10, "bold"), padding=8)
        style.configure("TEntry", padding=6)
        style.configure("TRadiobutton", background="#f5f7fb", font=("Segoe UI", 10))

    def build_ui(self):
        self.root.configure(bg="#f5f7fb")
        outer = ttk.Frame(self.root, padding=18)
        outer.pack(fill="both", expand=True)

        header = ttk.Frame(outer)
        header.pack(fill="x", pady=(0, 14))

        header_top = ttk.Frame(header)
        header_top.pack(fill="x")
        ttk.Label(header_top, text="Загрузка накладных в 1С", style="Title.TLabel").pack(side="left", anchor="w")
        ttk.Button(header_top, text="Проверить обновления", style="Accent.TButton", command=self.check_updates).pack(side="right")

        ttk.Label(
            header,
            text="Скачайте с сайта файл *_queue_ok.xlsx, положите его в папку output, откройте заявку в 1С и запустите робота.",
            style="Sub.TLabel",
        ).pack(anchor="w", pady=(4, 0))

        # Search card
        search_card = ttk.Frame(outer, style="Card.TFrame", padding=14)
        search_card.pack(fill="x", pady=(0, 12))

        line = ttk.Frame(search_card, style="Card.TFrame")
        line.pack(fill="x")

        ttk.Label(line, text="Номер накладной / часть номера:", background="#ffffff").pack(side="left")
        self.search_var = tk.StringVar()
        entry = ttk.Entry(line, textvariable=self.search_var, width=48)
        entry.pack(side="left", padx=10, fill="x", expand=True)
        entry.bind("<Return>", lambda _e: self.search_files())
        entry.bind("<Control-v>", self.paste_to_search)
        entry.bind("<Control-V>", self.paste_to_search)
        entry.bind("<Button-3>", self.paste_to_search)

        ttk.Button(line, text="Найти", style="Accent.TButton", command=self.search_files).pack(side="left", padx=(0, 6))
        ttk.Button(line, text="Обновить", command=self.refresh_files).pack(side="left")
        ttk.Button(line, text="Открыть output", command=self.open_output_dir).pack(side="left", padx=(6, 0))

        hint = ttk.Label(
            search_card,
            text=f"Папка для файлов накладных: {OUTPUT_DIR}",
            background="#ffffff",
            foreground="#6b7280",
        )
        hint.pack(anchor="w", pady=(8, 0))

        # Main area
        middle = ttk.Frame(outer)
        middle.pack(fill="both", expand=True)

        left = ttk.Frame(middle, style="Card.TFrame", padding=12)
        left.pack(side="left", fill="both", expand=True, padx=(0, 10))

        ttk.Label(left, text="Найденные файлы", background="#ffffff", font=("Segoe UI", 11, "bold")).pack(anchor="w")

        list_frame = ttk.Frame(left, style="Card.TFrame")
        list_frame.pack(fill="both", expand=True, pady=(8, 0))
        self.listbox = tk.Listbox(
            list_frame,
            height=12,
            font=("Consolas", 10),
            borderwidth=0,
            highlightthickness=1,
            highlightbackground="#e5e7eb",
            selectbackground="#2563eb",
            selectforeground="white",
        )
        self.listbox.pack(side="left", fill="both", expand=True)
        scrollbar = ttk.Scrollbar(list_frame, orient="vertical", command=self.listbox.yview)
        scrollbar.pack(side="right", fill="y")
        self.listbox.config(yscrollcommand=scrollbar.set)
        self.listbox.bind("<<ListboxSelect>>", self.on_select)

        right = ttk.Frame(middle, style="Card.TFrame", padding=12, width=270)
        right.pack(side="right", fill="y")
        right.pack_propagate(False)

        ttk.Label(right, text="Управление", background="#ffffff", font=("Segoe UI", 11, "bold")).pack(anchor="w")

        self.start_btn = ttk.Button(right, text="▶ Запустить робота", style="Accent.TButton", command=self.run_selected)
        self.start_btn.pack(fill="x", pady=(12, 6))

        self.stop_btn = ttk.Button(right, text="■ СТОП", style="Danger.TButton", command=self.stop_robot, state="disabled")
        self.stop_btn.pack(fill="x", pady=(0, 12))

        self.countdown_label = ttk.Label(right, text="", font=("Segoe UI", 22, "bold"), background="#ffffff", foreground="#2563eb")
        self.countdown_label.pack(pady=(4, 12))

        self.status_var = tk.StringVar(value="Статус: ожидание")
        ttk.Label(right, textvariable=self.status_var, style="Status.TLabel", background="#ffffff", wraplength=240).pack(anchor="w", pady=(0, 12))

        ttk.Label(right, text="Режим скорости", background="#ffffff", font=("Segoe UI", 10, "bold")).pack(anchor="w", pady=(8, 0))
        self.mode_var = tk.StringVar(value="fast")
        ttk.Radiobutton(right, text="Быстро", value="fast", variable=self.mode_var, command=self.change_mode).pack(anchor="w")
        ttk.Radiobutton(right, text="Надежно", value="safe", variable=self.mode_var, command=self.change_mode).pack(anchor="w")

        ttk.Separator(right).pack(fill="x", pady=12)
        ttk.Label(
            right,
            text=f"Версия программы: {self.update_settings.get('version', '1.0.0')}",
            background="#ffffff",
            foreground="#374151",
        ).pack(anchor="w")
        ttk.Button(right, text="Проверить обновления", command=self.check_updates).pack(fill="x", pady=(8, 0))

        ttk.Separator(right).pack(fill="x", pady=12)
        info = (
            "Перед стартом:\n"
            "1. Откройте 1С и нужную заявку.\n"
            "2. Поставьте курсор в Номенклатуру.\n"
            "3. Раскладка ENG, Caps Lock выкл.\n"
            "4. Не трогайте мышь во время работы."
        )
        ttk.Label(right, text=info, background="#ffffff", foreground="#374151", wraplength=240).pack(anchor="w")

        # Log panel
        log_card = ttk.Frame(outer, style="Card.TFrame", padding=12)
        log_card.pack(fill="both", expand=False, pady=(12, 0))
        ttk.Label(log_card, text="Лог работы", background="#ffffff", font=("Segoe UI", 11, "bold")).pack(anchor="w")
        self.log_box = tk.Text(
            log_card,
            height=12,
            font=("Consolas", 9),
            borderwidth=0,
            highlightthickness=1,
            highlightbackground="#e5e7eb",
            wrap="word",
        )
        self.log_box.pack(fill="both", expand=True, pady=(8, 0))
        self.log_box.tag_config("ok", foreground="#15803d")
        self.log_box.tag_config("error", foreground="#b91c1c")
        self.log_box.tag_config("warn", foreground="#c2410c")
        self.log_box.tag_config("start", foreground="#1d4ed8")
        self.log_box.tag_config("info", foreground="#111827")

    def paste_to_search(self, event=None):
        try:
            text = self.root.clipboard_get().strip()
            self.search_var.set(text)
            self.search_files()
        except Exception:
            pass
        return "break"

    def change_mode(self):
        self.settings.mode = self.mode_var.get()
        self.log(f"Режим скорости: {self.settings.mode}", "info")

    def log(self, text: str, tag: str | None = None):
        if tag is None:
            upper = text.upper()
            if "OK" in upper or "ГОТОВО" in upper or "УСПЕШНО" in upper:
                tag = "ok"
            elif "ERROR" in upper or "ОШИБКА" in upper:
                tag = "error"
            elif "SKIP" in upper or "ВНИМАНИЕ" in upper or "ОСТАНОВ" in upper:
                tag = "warn"
            elif "START" in upper or "ЗАПУСК" in upper or "СТАРТ" in upper:
                tag = "start"
            else:
                tag = "info"
        self.log_box.insert("end", text + "\n", tag)
        self.log_box.see("end")
        self.root.update_idletasks()

    def refresh_files(self, silent=False):
        OUTPUT_DIR.mkdir(exist_ok=True)
        self.all_files = sorted(OUTPUT_DIR.glob("*_queue_ok.xlsx"), key=lambda x: x.name)
        if not silent:
            self.log(f"Файлы обновлены. Найдено: {len(self.all_files)}", "info")
        self.search_files(show_message=False)

    def search_files(self, show_message=True):
        query = self.search_var.get().strip().lower()
        files = getattr(self, "all_files", sorted(OUTPUT_DIR.glob("*_queue_ok.xlsx"), key=lambda x: x.name))
        self.files = [f for f in files if query in f.name.lower()]
        self.listbox.delete(0, "end")
        for f in self.files:
            self.listbox.insert("end", f.name)
        if self.files:
            self.status_var.set(f"Статус: найдено файлов: {len(self.files)}")
            if len(self.files) == 1:
                self.listbox.selection_set(0)
                self.listbox.activate(0)
                self.selected_path = self.files[0]
            if show_message:
                self.log(f"Найдено файлов: {len(self.files)}", "info")
        else:
            self.selected_path = None
            self.status_var.set("Статус: ничего не найдено")
            if show_message:
                self.log("ОШИБКА: ничего не найдено", "error")

    def open_output_dir(self):
        OUTPUT_DIR.mkdir(exist_ok=True)
        try:
            if sys.platform.startswith("win"):
                os.startfile(str(OUTPUT_DIR))
            elif sys.platform == "darwin":
                subprocess.Popen(["open", str(OUTPUT_DIR)])
            else:
                subprocess.Popen(["xdg-open", str(OUTPUT_DIR)])
            self.log(f"Открыта папка output: {OUTPUT_DIR}", "info")
        except Exception as exc:
            self.log(f"ОШИБКА: не удалось открыть папку output: {exc}", "error")
            messagebox.showerror("Папка output", f"Не удалось открыть папку:\n{OUTPUT_DIR}\n\n{exc}")

    def on_select(self, _event=None):
        sel = self.listbox.curselection()
        if sel:
            self.selected_path = self.files[sel[0]]
            self.status_var.set(f"Выбрано: {self.selected_path.name}")

    def run_selected(self):
        if self.is_running:
            return
        sel = self.listbox.curselection()
        if sel:
            self.selected_path = self.files[sel[0]]
        if not self.selected_path or not self.selected_path.exists():
            messagebox.showwarning("Ошибка", "Выберите файл накладной из списка")
            return
        self.log_box.delete("1.0", "end")
        self.log(f"Выбрано: {self.selected_path.name}", "start")
        self.stop_event.clear()
        self.is_running = True
        self.start_btn.config(state="disabled")
        self.stop_btn.config(state="normal")
        self.start_countdown(self.settings.start_delay)

    def start_countdown(self, seconds: int):
        if self.stop_event.is_set():
            self.countdown_label.config(text="Остановлено")
            self.status_var.set("Статус: запуск отменен")
            self.log("ВНИМАНИЕ: запуск отменен пользователем", "warn")
            self.finish_run()
            return
        if seconds > 0:
            self.countdown_label.config(text=f"Старт через {seconds}")
            self.status_var.set("Статус: подготовьте 1С и поставьте курсор")
            self.root.after(1000, lambda: self.start_countdown(seconds - 1))
        else:
            self.countdown_label.config(text="Работает")
            self.status_var.set("Статус: робот работает")
            self.worker = threading.Thread(target=self.robot_worker, daemon=True)
            self.worker.start()

    def stop_robot(self):
        self.stop_event.set()
        self.status_var.set("Статус: остановка...")
        self.log("ВНИМАНИЕ: нажата кнопка СТОП", "warn")

    def finish_run(self):
        self.is_running = False
        self.start_btn.config(state="normal")
        self.stop_btn.config(state="disabled")

    def check_updates(self):
        self.log("Проверка обновлений...", "info")
        threading.Thread(target=self.check_updates_worker, daemon=True).start()

    def check_updates_worker(self):
        update_url = self.update_settings.get("update_url", DEFAULT_UPDATE_SETTINGS["update_url"])
        current_version = self.update_settings.get("version", DEFAULT_UPDATE_SETTINGS["version"])
        try:
            request = urllib.request.Request(update_url, headers={"User-Agent": "1C-Robot-Pro"})
            with urllib.request.urlopen(request, timeout=12) as response:
                remote = json.loads(response.read().decode("utf-8"))
        except (urllib.error.URLError, TimeoutError, json.JSONDecodeError, OSError) as exc:
            self.root.after(0, lambda: self.log(f"ОШИБКА: не удалось проверить обновления: {exc}", "error"))
            self.root.after(
                0,
                lambda: messagebox.showerror(
                    "Проверка обновлений",
                    f"Не удалось проверить обновления.\nПроверьте интернет или ссылку в settings.json.\n\n{exc}",
                ),
            )
            return

        latest_version = str(remote.get("version", "0.0.0"))
        installer_url = absolute_update_url(update_url, str(remote.get("installer_url", "")))
        notes = str(remote.get("notes", ""))

        if version_tuple(latest_version) > version_tuple(current_version):
            def show_update():
                self.log(f"Доступно обновление: {latest_version}", "warn")
                message = f"Доступна новая версия {latest_version}.\n\n{notes}\n\nСкачать установщик сейчас?"
                if messagebox.askyesno("Доступно обновление", message):
                    threading.Thread(
                        target=self.download_update_worker,
                        args=(installer_url, latest_version),
                        daemon=True,
                    ).start()

            self.root.after(0, show_update)
        else:
            self.root.after(0, lambda: self.log("Установлена актуальная версия", "ok"))
            self.root.after(0, lambda: messagebox.showinfo("Проверка обновлений", "Установлена актуальная версия"))

    def download_update_worker(self, installer_url: str, latest_version: str):
        try:
            target_dir = DOWNLOADS_DIR if DOWNLOADS_DIR.exists() else BASE_DIR
            target_path = target_dir / f"1C_Robot_Setup_{latest_version}.exe"
            self.root.after(0, lambda: self.log(f"Скачивание обновления: {target_path}", "info"))

            request = urllib.request.Request(installer_url, headers={"User-Agent": "1C-Robot-Pro"})
            with urllib.request.urlopen(request, timeout=60) as response, open(target_path, "wb") as fh:
                total = response.headers.get("Content-Length")
                total_size = int(total) if total and total.isdigit() else 0
                downloaded = 0
                next_report = 0
                while True:
                    chunk = response.read(1024 * 256)
                    if not chunk:
                        break
                    fh.write(chunk)
                    downloaded += len(chunk)
                    if total_size:
                        percent = int(downloaded * 100 / total_size)
                        if percent >= next_report:
                            self.root.after(0, lambda p=percent: self.log(f"Скачано: {p}%", "info"))
                            next_report += 20

            def ask_run():
                self.log(f"Обновление скачано: {target_path}", "ok")
                if messagebox.askyesno("Обновление скачано", f"Файл скачан:\n{target_path}\n\nЗапустить установщик?"):
                    os.startfile(str(target_path))

            self.root.after(0, ask_run)
        except Exception as exc:
            self.root.after(0, lambda: self.log(f"ОШИБКА: не удалось скачать обновление: {exc}", "error"))
            self.root.after(0, lambda: messagebox.showerror("Скачивание обновления", f"Не удалось скачать обновление.\n\n{exc}"))

    # ---------- robot engine integrated in app: no subprocess, no console ----------
    def cleanup_value(self, value) -> str:
        if pd.isna(value):
            return ""
        s = str(value).strip()
        if s.lower() == "nan":
            return ""
        if s.endswith(".0"):
            s = s[:-2]
        return s

    def press_enter(self, times: int):
        for _ in range(times):
            if self.stop_event.is_set():
                return
            pyautogui.press("enter")
            time.sleep(self.settings.enter_delay)

    def type_text(self, text: str, interval: float | None = None):
        interval = self.settings.type_interval if interval is None else interval
        for ch in str(text).strip():
            if self.stop_event.is_set():
                return
            pyautogui.press(ch)
            time.sleep(interval)

    def robot_worker(self):
        success_count = skip_count = error_count = 0
        log_file = LOG_DIR / f"robot_{datetime.now().strftime('%Y%m%d_%H%M%S')}.txt"

        def write_both(msg: str, tag: str | None = None):
            line = f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] {msg}"
            self.root.after(0, lambda: self.log(line, tag))
            try:
                with open(log_file, "a", encoding="utf-8") as f:
                    f.write(line + "\n")
            except Exception:
                pass

        try:
            df = pd.read_excel(self.selected_path)
            required = ["Артикул", "Количество"]
            for col in required:
                if col not in df.columns:
                    raise RuntimeError(f"В файле отсутствует колонка: {col}")
            if df.empty:
                raise RuntimeError("Файл загрузки пустой")

            write_both("==================================================", "start")
            write_both(f"Запуск робота. Режим: {self.settings.mode} / стабильный ввод печатью", "start")
            write_both(f"Файл загрузки: {self.selected_path}", "info")
            write_both(f"Всего строк: {len(df)}", "info")
            write_both("Перед стартом: 1С открыта, курсор в Номенклатуре, ENG, Caps Lock выкл.", "warn")

            for i, row in df.iterrows():
                if self.stop_event.is_set():
                    write_both("ВНИМАНИЕ: робот остановлен пользователем", "warn")
                    break

                row_num = i + 1
                article = self.cleanup_value(row["Артикул"])
                qty = self.cleanup_value(row["Количество"])

                try:
                    if not article:
                        skip_count += 1
                        write_both(f"SKIP | Строка {row_num} | Пустой артикул", "warn")
                        continue
                    if not qty:
                        skip_count += 1
                        write_both(f"SKIP | Строка {row_num} | Пустое количество | Артикул: {article}", "warn")
                        continue

                    write_both(f"START | Строка {row_num} | Артикул: {article} | Количество: {qty}", "start")

                    self.type_text(article, self.settings.type_interval)
                    time.sleep(self.settings.step_delay)
                    self.press_enter(4)
                    time.sleep(self.settings.step_delay)
                    self.type_text(qty, 0.02)
                    time.sleep(self.settings.qty_wait)
                    self.press_enter(2)
                    time.sleep(self.settings.step_delay)
                    if self.stop_event.is_set():
                        break
                    pyautogui.press("down")
                    time.sleep(self.settings.step_delay)

                    success_count += 1
                    write_both(f"OK    | Строка {row_num} | Артикул: {article} | Количество: {qty}", "ok")
                except Exception as e:
                    error_count += 1
                    write_both(f"ERROR | Строка {row_num} | Ошибка: {e}", "error")

            write_both("--------------- ИТОГ ---------------", "start")
            write_both(f"Успешно: {success_count}", "ok")
            write_both(f"Пропущено: {skip_count}", "warn" if skip_count else "info")
            write_both(f"Ошибок: {error_count}", "error" if error_count else "info")
            write_both(f"Лог сохранен: {log_file.name}", "info")

            if self.stop_event.is_set():
                self.root.after(0, lambda: self.countdown_label.config(text="Остановлено"))
                self.root.after(0, lambda: self.status_var.set("Статус: остановлено"))
            else:
                self.root.after(0, lambda: self.countdown_label.config(text="Готово"))
                self.root.after(0, lambda: self.status_var.set("Статус: готово"))

        except Exception as e:
            write_both(f"ОШИБКА: {e}", "error")
            write_both(traceback.format_exc(), "error")
            self.root.after(0, lambda: self.countdown_label.config(text="Ошибка"))
            self.root.after(0, lambda: self.status_var.set("Статус: ошибка"))
        finally:
            self.root.after(0, self.finish_run)


if __name__ == "__main__":
    root = tk.Tk()
    app = RobotApp(root)
    root.mainloop()
