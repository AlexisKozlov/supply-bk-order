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
import ctypes
from ctypes import wintypes
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass
from datetime import datetime
from pathlib import Path

import pandas as pd
import pyautogui
import pyperclip
import tkinter as tk
from tkinter import ttk, messagebox, filedialog

from excel_service import MODE_SINGLE, MODE_SUMMARY, process_to_output

try:
    import keyboard
except ImportError:
    keyboard = None


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


INPUT_KEYBOARD = 1
KEYEVENTF_KEYUP = 0x0002
KEYEVENTF_UNICODE = 0x0004
ULONG_PTR = wintypes.WPARAM


class KEYBDINPUT(ctypes.Structure):
    _fields_ = [
        ("wVk", wintypes.WORD),
        ("wScan", wintypes.WORD),
        ("dwFlags", wintypes.DWORD),
        ("time", wintypes.DWORD),
        ("dwExtraInfo", ULONG_PTR),
    ]


class MOUSEINPUT(ctypes.Structure):
    _fields_ = [
        ("dx", wintypes.LONG),
        ("dy", wintypes.LONG),
        ("mouseData", wintypes.DWORD),
        ("dwFlags", wintypes.DWORD),
        ("time", wintypes.DWORD),
        ("dwExtraInfo", ULONG_PTR),
    ]


class HARDWAREINPUT(ctypes.Structure):
    _fields_ = [
        ("uMsg", wintypes.DWORD),
        ("wParamL", wintypes.WORD),
        ("wParamH", wintypes.WORD),
    ]


class INPUT_UNION(ctypes.Union):
    _fields_ = [
        ("mi", MOUSEINPUT),
        ("ki", KEYBDINPUT),
        ("hi", HARDWAREINPUT),
    ]


class INPUT(ctypes.Structure):
    _fields_ = [("type", wintypes.DWORD), ("union", INPUT_UNION)]


if sys.platform.startswith("win"):
    _user32 = ctypes.WinDLL("user32", use_last_error=True)
    _user32.SendInput.argtypes = (wintypes.UINT, ctypes.POINTER(INPUT), ctypes.c_int)
    _user32.SendInput.restype = wintypes.UINT
else:
    _user32 = None


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
    mode: str = "safe"  # fast / safe
    start_delay: int = 5
    # Text is inserted with Windows Unicode input because some 1C windows
    # ignore Latin letters typed as key names and Ctrl+V opens selection forms.
    fast_type_interval: float = 0.02
    fast_enter_delay: float = 0.25
    fast_step_delay: float = 0.35
    fast_qty_wait: float = 0.50
    safe_type_interval: float = 0.03
    safe_enter_delay: float = 0.35
    safe_step_delay: float = 0.60
    safe_qty_wait: float = 0.90

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
        self.root.geometry("1180x760")
        self.root.minsize(1080, 700)

        self.settings = RobotSettings()
        self.update_settings = load_update_settings()
        self.files: list[Path] = []
        self.selected_path: Path | None = None
        self.reference_path: Path | None = None
        self.invoice_path: Path | None = None
        self.stop_event = threading.Event()
        self.pause_event = threading.Event()
        self.worker: threading.Thread | None = None
        self.is_running = False
        self.overlay: tk.Toplevel | None = None
        self.overlay_vars: dict[str, tk.StringVar] = {}
        self.overlay_progress: ttk.Progressbar | None = None
        self.current_log_file: Path | None = None
        self.last_run_summary: dict | None = None
        self.run_started_at: float | None = None
        self.pause_started_at: float | None = None
        self.total_paused_seconds = 0.0

        pyautogui.PAUSE = 0.08

        self.setup_style()
        self.build_ui()
        self.setup_hotkeys()
        self.refresh_files(silent=True)
        self.root.after(2000, self.auto_check_updates)

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
        style.configure("TNotebook", background="#f5f7fb", borderwidth=0)
        style.configure("TNotebook.Tab", font=("Segoe UI", 10, "bold"), padding=(16, 9))

    def build_ui(self):
        self.root.configure(bg="#f5f7fb")
        outer = ttk.Frame(self.root, padding=18)
        outer.pack(fill="both", expand=True)

        header = ttk.Frame(outer)
        header.pack(fill="x", pady=(0, 14))

        header_top = ttk.Frame(header)
        header_top.pack(fill="x")
        ttk.Label(header_top, text="Загрузка накладных в 1С", style="Title.TLabel").pack(side="left", anchor="w")
        ttk.Label(
            header_top,
            text=f"Версия {self.update_settings.get('version', '1.0.0')}",
            style="Sub.TLabel",
        ).pack(side="left", padx=(14, 0))
        ttk.Button(header_top, text="Проверить обновления", style="Accent.TButton", command=self.check_updates).pack(side="right")

        ttk.Label(
            header,
            text="Подготовьте output, выберите файл накладной, откройте заявку в 1С и запустите робота. F8 останавливает работу.",
            style="Sub.TLabel",
        ).pack(anchor="w", pady=(4, 0))

        notebook = ttk.Notebook(outer)
        notebook.pack(fill="both", expand=True)

        robot_tab = ttk.Frame(notebook, padding=12)
        prepare_tab = ttk.Frame(notebook, padding=12)
        log_tab = ttk.Frame(notebook, padding=12)
        notebook.add(robot_tab, text="Загрузка в 1С")
        notebook.add(prepare_tab, text="Подготовка output")
        notebook.add(log_tab, text="Лог")

        # Robot tab
        robot_body = ttk.Frame(robot_tab)
        robot_body.pack(fill="both", expand=True)

        left = ttk.Frame(robot_body, style="Card.TFrame", padding=14)
        left.pack(side="left", fill="both", expand=True, padx=(0, 12))

        ttk.Label(left, text="Файлы для загрузки", background="#ffffff", font=("Segoe UI", 13, "bold")).pack(anchor="w")
        ttk.Label(
            left,
            text=f"Папка output: {OUTPUT_DIR}",
            background="#ffffff",
            foreground="#6b7280",
        ).pack(anchor="w", pady=(4, 10))

        search_card = ttk.Frame(left, style="Card.TFrame")
        search_card.pack(fill="x", pady=(0, 10))

        line = ttk.Frame(search_card, style="Card.TFrame")
        line.pack(fill="x")

        ttk.Label(line, text="Поиск:", background="#ffffff").pack(side="left")
        self.search_var = tk.StringVar()
        entry = ttk.Entry(line, textvariable=self.search_var, width=36)
        entry.pack(side="left", padx=10, fill="x", expand=True)
        entry.bind("<Return>", lambda _e: self.search_files())
        entry.bind("<Control-v>", self.paste_to_search)
        entry.bind("<Control-V>", self.paste_to_search)
        entry.bind("<Button-3>", self.paste_to_search)

        ttk.Button(line, text="Найти", style="Accent.TButton", command=self.search_files).pack(side="left", padx=(0, 6))
        ttk.Button(line, text="Обновить", command=self.refresh_files).pack(side="left")
        ttk.Button(line, text="Открыть output", command=self.open_output_dir).pack(side="left", padx=(6, 0))

        list_frame = ttk.Frame(left, style="Card.TFrame")
        list_frame.pack(fill="both", expand=True)
        self.listbox = tk.Listbox(
            list_frame,
            height=18,
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

        right = ttk.Frame(robot_body, style="Card.TFrame", padding=14, width=300)
        right.pack(side="right", fill="y")
        right.pack_propagate(False)

        ttk.Label(right, text="Управление роботом", background="#ffffff", font=("Segoe UI", 13, "bold")).pack(anchor="w")

        self.start_btn = ttk.Button(right, text="Запустить", style="Accent.TButton", command=self.run_selected)
        self.start_btn.pack(fill="x", pady=(12, 6))

        self.stop_btn = ttk.Button(right, text="СТОП  F8", style="Danger.TButton", command=self.stop_robot, state="disabled")
        self.stop_btn.pack(fill="x", pady=(0, 12))

        resume_frame = ttk.Frame(right, style="Card.TFrame")
        resume_frame.pack(fill="x", pady=(0, 10))
        ttk.Label(resume_frame, text="Начать со строки", background="#ffffff").pack(side="left")
        self.start_row_var = tk.StringVar(value="1")
        ttk.Entry(resume_frame, textvariable=self.start_row_var, width=7).pack(side="left", padx=(8, 0))

        self.countdown_label = ttk.Label(right, text="", font=("Segoe UI", 22, "bold"), background="#ffffff", foreground="#2563eb")
        self.countdown_label.pack(anchor="w", pady=(4, 10))

        self.status_var = tk.StringVar(value="Статус: ожидание")
        ttk.Label(right, textvariable=self.status_var, style="Status.TLabel", background="#ffffff", wraplength=240).pack(anchor="w", pady=(0, 12))

        ttk.Label(right, text="Режим скорости", background="#ffffff", font=("Segoe UI", 10, "bold")).pack(anchor="w", pady=(8, 0))
        self.mode_var = tk.StringVar(value="safe")
        ttk.Radiobutton(right, text="Быстро", value="fast", variable=self.mode_var, command=self.change_mode).pack(anchor="w")
        ttk.Radiobutton(right, text="Надежно", value="safe", variable=self.mode_var, command=self.change_mode).pack(anchor="w")

        ttk.Label(right, text="Защита от перегрузки 1С", background="#ffffff", font=("Segoe UI", 10, "bold")).pack(anchor="w", pady=(10, 0))
        throttle = ttk.Frame(right, style="Card.TFrame")
        throttle.pack(fill="x", pady=(6, 0))
        ttk.Label(throttle, text="Пауза каждые", background="#ffffff").grid(row=0, column=0, sticky="w")
        self.batch_size_var = tk.StringVar(value="10")
        ttk.Entry(throttle, textvariable=self.batch_size_var, width=5).grid(row=0, column=1, padx=4)
        ttk.Label(throttle, text="стр.", background="#ffffff").grid(row=0, column=2, sticky="w")
        ttk.Label(throttle, text="на", background="#ffffff").grid(row=1, column=0, sticky="w", pady=(4, 0))
        self.batch_pause_var = tk.StringVar(value="4")
        ttk.Entry(throttle, textvariable=self.batch_pause_var, width=5).grid(row=1, column=1, padx=4, pady=(4, 0))
        ttk.Label(throttle, text="сек.", background="#ffffff").grid(row=1, column=2, sticky="w", pady=(4, 0))

        limit_frame = ttk.Frame(right, style="Card.TFrame")
        limit_frame.pack(fill="x", pady=(8, 0))
        ttk.Label(limit_frame, text="Лимит строк", background="#ffffff").pack(side="left")
        self.max_rows_var = tk.StringVar(value="")
        ttk.Entry(limit_frame, textvariable=self.max_rows_var, width=7).pack(side="left", padx=(8, 0))

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
            "4. Не трогайте мышь во время работы.\n"
            "5. F8 — остановить робота."
        )
        ttk.Label(right, text=info, background="#ffffff", foreground="#374151", wraplength=240).pack(anchor="w")

        # Prepare tab
        prepare_card = ttk.Frame(prepare_tab, style="Card.TFrame", padding=18)
        prepare_card.pack(fill="both", expand=True)
        ttk.Label(
            prepare_card,
            text="Подготовить файлы output из Excel",
            background="#ffffff",
            font=("Segoe UI", 14, "bold"),
        ).pack(anchor="w")
        ttk.Label(
            prepare_card,
            text="Выберите справочник товаров и накладную. После обработки старые Excel-файлы в output будут заменены новыми.",
            background="#ffffff",
            foreground="#6b7280",
            wraplength=900,
        ).pack(anchor="w", pady=(4, 18))

        prep_actions = ttk.Frame(prepare_card, style="Card.TFrame")
        prep_actions.pack(fill="x")
        ttk.Button(prep_actions, text="Выбрать справочник", command=self.choose_reference_file).pack(side="left")
        ttk.Button(prep_actions, text="Выбрать накладную / сводную", command=self.choose_invoice_file).pack(side="left", padx=(8, 0))
        ttk.Button(prep_actions, text="Сформировать output", style="Accent.TButton", command=self.prepare_output_files).pack(side="right")

        prep_mode = ttk.Frame(prepare_card, style="Card.TFrame")
        prep_mode.pack(fill="x", pady=(18, 0))
        self.prepare_mode_var = tk.StringVar(value=MODE_SUMMARY)
        ttk.Label(prep_mode, text="Режим обработки:", background="#ffffff", font=("Segoe UI", 10, "bold")).pack(side="left")
        ttk.Radiobutton(prep_mode, text="Сводная ЭТТН", value=MODE_SUMMARY, variable=self.prepare_mode_var).pack(side="left", padx=(10, 0))
        ttk.Radiobutton(prep_mode, text="Одна СТТ", value=MODE_SINGLE, variable=self.prepare_mode_var).pack(side="left", padx=(10, 0))

        self.prepare_status_var = tk.StringVar(value="Выберите справочник и файл накладной.")
        ttk.Label(
            prepare_card,
            textvariable=self.prepare_status_var,
            background="#ffffff",
            foreground="#374151",
            wraplength=900,
        ).pack(anchor="w", pady=(18, 0))

        # Log tab
        log_card = ttk.Frame(log_tab, style="Card.TFrame", padding=14)
        log_card.pack(fill="both", expand=True)
        log_top = ttk.Frame(log_card, style="Card.TFrame")
        log_top.pack(fill="x")
        ttk.Label(log_top, text="Лог работы", background="#ffffff", font=("Segoe UI", 14, "bold")).pack(side="left")
        ttk.Button(log_top, text="Очистить лог", command=lambda: self.log_box.delete("1.0", "end")).pack(side="right")
        self.log_box = tk.Text(
            log_card,
            height=28,
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

    def create_progress_overlay(self, total_rows: int):
        if self.overlay and self.overlay.winfo_exists():
            self.overlay.destroy()

        overlay = tk.Toplevel(self.root)
        overlay.title("1C Robot")
        overlay.geometry("430x260+40+40")
        overlay.resizable(False, False)
        overlay.attributes("-topmost", True)
        overlay.configure(bg="#111827")
        try:
            overlay.attributes("-toolwindow", True)
        except Exception:
            pass

        self.overlay = overlay
        self.overlay_vars = {
            "title": tk.StringVar(value="1C Robot работает"),
            "progress": tk.StringVar(value=f"0 / {total_rows}"),
            "percent": tk.StringVar(value="0%"),
            "article": tk.StringVar(value="Артикул: —"),
            "qty": tk.StringVar(value="Кол-во: —"),
            "stats": tk.StringVar(value="OK: 0  Ошибок: 0  Пропусков: 0"),
            "time": tk.StringVar(value="Прошло: 00:00  Осталось: —"),
            "eta": tk.StringVar(value="Ожидаемо всего: —"),
            "pause": tk.StringVar(value="Пауза"),
        }

        frame = tk.Frame(overlay, bg="#111827", padx=14, pady=12)
        frame.pack(fill="both", expand=True)
        tk.Label(frame, textvariable=self.overlay_vars["title"], bg="#111827", fg="#ffffff", font=("Segoe UI", 12, "bold")).pack(anchor="w")
        line = tk.Frame(frame, bg="#111827")
        line.pack(fill="x", pady=(8, 4))
        tk.Label(line, textvariable=self.overlay_vars["progress"], bg="#111827", fg="#d1d5db", font=("Segoe UI", 10, "bold")).pack(side="left")
        tk.Label(line, textvariable=self.overlay_vars["percent"], bg="#111827", fg="#60a5fa", font=("Segoe UI", 10, "bold")).pack(side="right")
        self.overlay_progress = ttk.Progressbar(frame, maximum=max(total_rows, 1), value=0)
        self.overlay_progress.pack(fill="x", pady=(0, 10))
        tk.Label(frame, textvariable=self.overlay_vars["article"], bg="#111827", fg="#f9fafb", font=("Segoe UI", 9), anchor="w").pack(fill="x")
        tk.Label(frame, textvariable=self.overlay_vars["qty"], bg="#111827", fg="#f9fafb", font=("Segoe UI", 9), anchor="w").pack(fill="x", pady=(2, 0))
        tk.Label(frame, textvariable=self.overlay_vars["stats"], bg="#111827", fg="#d1d5db", font=("Segoe UI", 9), anchor="w").pack(fill="x", pady=(8, 2))
        tk.Label(frame, textvariable=self.overlay_vars["time"], bg="#111827", fg="#d1d5db", font=("Segoe UI", 9), anchor="w").pack(fill="x", pady=(0, 2))
        tk.Label(frame, textvariable=self.overlay_vars["eta"], bg="#111827", fg="#d1d5db", font=("Segoe UI", 9), anchor="w").pack(fill="x", pady=(0, 8))
        controls = tk.Frame(frame, bg="#111827")
        controls.pack(fill="x")
        tk.Button(
            controls,
            text="СТОП  F8",
            command=self.stop_robot,
            bg="#dc2626",
            fg="#ffffff",
            activebackground="#b91c1c",
            activeforeground="#ffffff",
            relief="flat",
            font=("Segoe UI", 10, "bold"),
            padx=12,
            pady=5,
        ).pack(side="left")
        tk.Button(
            controls,
            textvariable=self.overlay_vars["pause"],
            command=self.toggle_pause,
            bg="#f59e0b",
            fg="#111827",
            activebackground="#d97706",
            activeforeground="#111827",
            relief="flat",
            font=("Segoe UI", 10, "bold"),
            padx=12,
            pady=5,
        ).pack(side="left", padx=(8, 0))
        tk.Button(
            controls,
            text="Открыть окно",
            command=self.restore_main_window,
            bg="#374151",
            fg="#ffffff",
            activebackground="#4b5563",
            activeforeground="#ffffff",
            relief="flat",
            font=("Segoe UI", 9),
            padx=10,
            pady=5,
        ).pack(side="right")

        overlay.protocol("WM_DELETE_WINDOW", self.restore_main_window)

    def update_progress_overlay(self, current: int, total: int, article: str = "", qty: str = "", ok: int = 0, errors: int = 0, skipped: int = 0):
        if not self.overlay or not self.overlay.winfo_exists():
            return
        total = max(total, 1)
        percent = int(current * 100 / total)
        elapsed = self.active_elapsed_seconds()
        done_for_eta = max(ok + errors + skipped, 0)
        if done_for_eta > 0:
            seconds_per_row = elapsed / done_for_eta
            remaining_rows = max(0, total - current)
            expected_total = elapsed + seconds_per_row * remaining_rows
            remaining = max(0, expected_total - elapsed)
            remaining_text = self.format_duration(remaining)
            total_text = self.format_duration(expected_total)
        else:
            remaining_text = "—"
            total_text = "—"
        self.overlay_vars["progress"].set(f"{current} / {total}")
        self.overlay_vars["percent"].set(f"{percent}%")
        self.overlay_vars["article"].set(f"Артикул: {article or '—'}")
        self.overlay_vars["qty"].set(f"Кол-во: {qty or '—'}")
        self.overlay_vars["stats"].set(f"OK: {ok}  Ошибок: {errors}  Пропусков: {skipped}")
        self.overlay_vars["time"].set(f"Прошло: {self.format_duration(elapsed)}  Осталось: {remaining_text}")
        self.overlay_vars["eta"].set(f"Ожидаемо всего: {total_text}")
        self.overlay_vars["title"].set("1C Robot на паузе" if self.pause_event.is_set() else "1C Robot работает")
        self.overlay_vars["pause"].set("Продолжить" if self.pause_event.is_set() else "Пауза")
        if self.overlay_progress:
            self.overlay_progress.config(maximum=total, value=current)

    def format_duration(self, seconds: float | int | None) -> str:
        if seconds is None:
            return "—"
        seconds = max(0, int(seconds))
        hours, rem = divmod(seconds, 3600)
        minutes, secs = divmod(rem, 60)
        if hours:
            return f"{hours}:{minutes:02d}:{secs:02d}"
        return f"{minutes:02d}:{secs:02d}"

    def active_elapsed_seconds(self) -> float:
        if not self.run_started_at:
            return 0.0
        paused_now = 0.0
        if self.pause_event.is_set() and self.pause_started_at:
            paused_now = time.monotonic() - self.pause_started_at
        return max(0.0, time.monotonic() - self.run_started_at - self.total_paused_seconds - paused_now)

    def toggle_pause(self):
        if not self.is_running:
            return
        if self.pause_event.is_set():
            if self.pause_started_at:
                self.total_paused_seconds += time.monotonic() - self.pause_started_at
            self.pause_started_at = None
            self.pause_event.clear()
            self.status_var.set("Статус: робот работает")
            self.log("Пауза снята, робот продолжает работу", "warn")
        else:
            self.pause_started_at = time.monotonic()
            self.pause_event.set()
            self.status_var.set("Статус: пауза")
            self.log("ВНИМАНИЕ: робот поставлен на паузу", "warn")
        if self.overlay and self.overlay.winfo_exists():
            self.overlay_vars["title"].set("1C Robot на паузе" if self.pause_event.is_set() else "1C Robot работает")
            self.overlay_vars["pause"].set("Продолжить" if self.pause_event.is_set() else "Пауза")

    def wait_if_paused(self) -> bool:
        while self.pause_event.is_set() and not self.stop_event.is_set():
            time.sleep(0.15)
        return self.stop_event.is_set()

    def sleep_with_controls(self, seconds: float):
        end_at = time.monotonic() + max(0.0, seconds)
        while time.monotonic() < end_at:
            if self.stop_event.is_set() or self.wait_if_paused():
                return
            time.sleep(min(0.1, end_at - time.monotonic()))

    def restore_main_window(self):
        try:
            self.root.deiconify()
            self.root.lift()
            self.root.focus_force()
        except Exception:
            pass

    def close_progress_overlay(self):
        if self.overlay and self.overlay.winfo_exists():
            self.overlay.destroy()
        self.overlay = None
        self.overlay_vars = {}
        self.overlay_progress = None

    def open_current_log(self):
        if not self.current_log_file or not self.current_log_file.exists():
            messagebox.showinfo("Лог", "Файл лога ещё не создан.")
            return
        try:
            os.startfile(str(self.current_log_file))
        except Exception as exc:
            messagebox.showerror("Лог", f"Не удалось открыть лог:\n{self.current_log_file}\n\n{exc}")

    def show_run_report(self):
        summary = self.last_run_summary
        if not summary:
            return
        status = "остановлено" if summary.get("stopped") else ("завершено с ошибками" if summary.get("errors") else "завершено")
        message = (
            f"Загрузка {status}.\n\n"
            f"Всего строк: {summary.get('total', 0)}\n"
            f"Успешно: {summary.get('success', 0)}\n"
            f"Пропущено: {summary.get('skipped', 0)}\n"
            f"Ошибок: {summary.get('errors', 0)}\n\n"
            f"Лог: {summary.get('log_file', '')}"
        )
        if messagebox.askyesno("Отчёт загрузки", message + "\n\nОткрыть лог?"):
            self.open_current_log()

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

    def setup_hotkeys(self):
        self.root.bind("<F8>", lambda _event: self.stop_robot())
        if keyboard is None:
            self.log("Глобальная остановка F8 недоступна: не установлен модуль keyboard", "warn")
            return
        try:
            keyboard.add_hotkey("f8", self.stop_robot)
            self.log("Горячая клавиша остановки: F8", "info")
        except Exception as exc:
            self.log(f"Глобальная F8 недоступна, F8 работает только при активном окне программы: {exc}", "warn")

    def int_setting(self, var: tk.StringVar, default: int, minimum: int = 0) -> int:
        try:
            value = int(str(var.get()).strip())
        except ValueError:
            return default
        return max(minimum, value)

    def choose_reference_file(self):
        filename = filedialog.askopenfilename(
            title="Выберите справочник товаров",
            filetypes=[("Excel", "*.xlsx *.xls"), ("Все файлы", "*.*")],
        )
        if filename:
            self.reference_path = Path(filename)
            self.update_prepare_status()

    def choose_invoice_file(self):
        filename = filedialog.askopenfilename(
            title="Выберите накладную или сводную таблицу",
            filetypes=[("Excel", "*.xlsx *.xls"), ("Все файлы", "*.*")],
        )
        if filename:
            self.invoice_path = Path(filename)
            self.update_prepare_status()

    def update_prepare_status(self):
        ref = self.reference_path.name if self.reference_path else "справочник не выбран"
        invoice = self.invoice_path.name if self.invoice_path else "накладная не выбрана"
        self.prepare_status_var.set(f"Справочник: {ref} | Файл: {invoice}")

    def prepare_output_files(self):
        if not self.reference_path or not self.reference_path.exists():
            messagebox.showwarning("Нет справочника", "Выберите справочник товаров Excel.")
            return
        if not self.invoice_path or not self.invoice_path.exists():
            messagebox.showwarning("Нет накладной", "Выберите файл накладной или сводной таблицы.")
            return
        if not messagebox.askyesno(
            "Перезаписать output",
            "Старые Excel-файлы в папке output будут удалены и заменены новыми. Продолжить?",
        ):
            return
        threading.Thread(target=self.prepare_output_worker, daemon=True).start()

    def prepare_output_worker(self):
        try:
            self.root.after(0, lambda: self.log("Старт обработки Excel для output", "start"))
            result = process_to_output(
                self.reference_path,
                self.invoice_path,
                OUTPUT_DIR,
                self.prepare_mode_var.get(),
                clear_output=True,
            )
            stats = result["stats"]
            created_count = len(result["created"])
            message = (
                f"Output сформирован. Всего: {stats['total']}, OK: {stats['ok']}, "
                f"NOT_FOUND: {stats['not_found']}, DUPLICATE_GTIN: {stats['duplicate']}. "
                f"Файлов создано: {created_count}"
            )
            self.root.after(0, lambda: self.log(message, "ok"))
            self.root.after(0, lambda: self.prepare_status_var.set(message))
            self.root.after(0, self.refresh_files)
            self.root.after(0, lambda: messagebox.showinfo("Output готов", message))
        except Exception as exc:
            error_text = str(exc)
            self.root.after(0, lambda: self.log(f"ОШИБКА обработки Excel: {error_text}", "error"))
            self.root.after(0, lambda: messagebox.showerror("Ошибка обработки Excel", error_text))

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
        self.pause_event.clear()
        self.pause_started_at = None
        self.total_paused_seconds = 0.0
        self.run_started_at = None
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
            self.root.iconify()
            self.worker = threading.Thread(target=self.robot_worker, daemon=True)
            self.worker.start()

    def stop_robot(self):
        self.stop_event.set()
        if self.pause_event.is_set():
            self.pause_event.clear()
            self.pause_started_at = None
        self.status_var.set("Статус: остановка...")
        self.log("ВНИМАНИЕ: нажата кнопка СТОП", "warn")

    def finish_run(self):
        self.is_running = False
        self.pause_event.clear()
        self.pause_started_at = None
        self.start_btn.config(state="normal")
        self.stop_btn.config(state="disabled")
        self.restore_main_window()
        self.close_progress_overlay()
        if self.last_run_summary:
            self.root.after(200, self.show_run_report)

    def check_updates(self):
        self.log("Проверка обновлений...", "info")
        threading.Thread(target=self.check_updates_worker, daemon=True).start()

    def auto_check_updates(self):
        threading.Thread(target=self.check_updates_worker, args=(True,), daemon=True).start()

    def check_updates_worker(self, silent=False):
        update_url = self.update_settings.get("update_url", DEFAULT_UPDATE_SETTINGS["update_url"])
        current_version = self.update_settings.get("version", DEFAULT_UPDATE_SETTINGS["version"])
        try:
            request = urllib.request.Request(update_url, headers={"User-Agent": "1C-Robot-Pro"})
            with urllib.request.urlopen(request, timeout=12) as response:
                remote = json.loads(response.read().decode("utf-8"))
        except (urllib.error.URLError, TimeoutError, json.JSONDecodeError, OSError) as exc:
            error_text = str(exc)
            if not silent:
                self.root.after(0, lambda: self.log(f"ОШИБКА: не удалось проверить обновления: {error_text}", "error"))
                self.root.after(
                    0,
                    lambda: messagebox.showerror(
                        "Проверка обновлений",
                        f"Не удалось проверить обновления.\nПроверьте интернет или ссылку в settings.json.\n\n{error_text}",
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
            if not silent:
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
                if messagebox.askyesno(
                    "Обновление скачано",
                    "Файл обновления скачан.\n\n"
                    "Сейчас программа закроется, после этого запустится установщик.\n"
                    "Если в конце установщика будет галочка запуска программы, снимите её и запустите программу обычным ярлыком.\n\n"
                    "Продолжить?",
                ):
                    self.start_installer_and_exit(target_path)

            self.root.after(0, ask_run)
        except Exception as exc:
            error_text = str(exc)
            self.root.after(0, lambda: self.log(f"ОШИБКА: не удалось скачать обновление: {error_text}", "error"))
            self.root.after(0, lambda: messagebox.showerror("Скачивание обновления", f"Не удалось скачать обновление.\n\n{error_text}"))

    def start_installer_and_exit(self, installer_path: Path):
        if self.is_running:
            messagebox.showwarning("Робот работает", "Сначала остановите загрузку в 1С, затем запустите обновление.")
            return
        try:
            self.stop_event.set()
            self.close_progress_overlay()
            launcher_path = installer_path.with_name("start_1c_robot_update.bat")
            launcher_path.write_text(
                "@echo off\n"
                f'set "INSTALLER={installer_path}"\n'
                "ping 127.0.0.1 -n 3 >nul\n"
                'start "" "%INSTALLER%"\n'
                'del "%~f0" >nul 2>nul\n',
                encoding="utf-8",
            )
            flags = 0
            if sys.platform.startswith("win"):
                flags = subprocess.CREATE_NO_WINDOW | getattr(subprocess, "DETACHED_PROCESS", 0)
            subprocess.Popen(
                ["cmd", "/c", str(launcher_path)],
                creationflags=flags,
            )
            self.root.after(100, self.root.destroy)
        except Exception as exc:
            self.log(f"ОШИБКА: не удалось запустить установщик: {exc}", "error")
            messagebox.showerror("Запуск установщика", f"Не удалось запустить установщик.\n\n{exc}")

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
            if self.stop_event.is_set() or self.wait_if_paused():
                return
            pyautogui.press("enter")
            time.sleep(self.settings.enter_delay)

    def send_unicode_text(self, text: str, interval: float | None = None):
        interval = self.settings.type_interval if interval is None else interval
        if self.stop_event.is_set():
            return
        value = str(text).strip()
        if sys.platform.startswith("win"):
            for char in value:
                if self.stop_event.is_set() or self.wait_if_paused():
                    return
                code = ord(char)
                inputs = (INPUT * 2)(
                    INPUT(type=INPUT_KEYBOARD, union=INPUT_UNION(ki=KEYBDINPUT(0, code, KEYEVENTF_UNICODE, 0, 0))),
                    INPUT(type=INPUT_KEYBOARD, union=INPUT_UNION(ki=KEYBDINPUT(0, code, KEYEVENTF_UNICODE | KEYEVENTF_KEYUP, 0, 0))),
                )
                sent = _user32.SendInput(2, inputs, ctypes.sizeof(INPUT))
                if sent != 2:
                    err = ctypes.get_last_error()
                    raise RuntimeError(f"Windows не принял Unicode-ввод символа '{char}'. Код ошибки: {err}")
                time.sleep(interval)
        else:
            if self.wait_if_paused():
                return
            pyautogui.write(value, interval=interval)

    def type_digits_text(self, text: str, interval: float | None = None):
        interval = self.settings.type_interval if interval is None else interval
        if self.stop_event.is_set() or self.wait_if_paused():
            return
        cleaned = str(text).strip().replace(",", ".")
        pyautogui.write(cleaned, interval=interval)

    def robot_worker(self):
        success_count = skip_count = error_count = 0
        log_file = LOG_DIR / f"robot_{datetime.now().strftime('%Y%m%d_%H%M%S')}.txt"
        self.current_log_file = log_file
        total_rows = 0

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
            total_rows = len(df)
            start_row = self.int_setting(self.start_row_var, 1, 1)
            if start_row > total_rows:
                raise RuntimeError(f"Стартовая строка {start_row} больше количества строк в файле ({total_rows})")
            self.start_row_var.set(str(start_row))
            self.run_started_at = time.monotonic()
            self.total_paused_seconds = 0.0
            self.pause_started_at = None

            write_both("==================================================", "start")
            write_both(f"Запуск робота. Режим: {self.settings.mode} / ввод Unicode-символами", "start")
            write_both(f"Файл загрузки: {self.selected_path}", "info")
            write_both(f"Всего строк: {total_rows}", "info")
            if start_row > 1:
                write_both(f"Продолжение с строки: {start_row}", "warn")
            write_both("Перед стартом: 1С открыта, курсор в Номенклатуре, ENG, Caps Lock выкл.", "warn")
            self.root.after(0, lambda total=total_rows: self.create_progress_overlay(total))
            batch_size = self.int_setting(self.batch_size_var, 10, 0)
            batch_pause = self.int_setting(self.batch_pause_var, 8, 0)
            max_rows = self.int_setting(self.max_rows_var, 0, 0)
            if max_rows:
                write_both(f"Тестовый лимит строк: {max_rows}", "warn")
            if batch_size and batch_pause:
                write_both(f"Пауза защиты 1С: каждые {batch_size} строк на {batch_pause} сек.", "warn")

            for i, row in df.iloc[start_row - 1:].iterrows():
                if self.stop_event.is_set():
                    write_both("ВНИМАНИЕ: робот остановлен пользователем", "warn")
                    break
                if self.wait_if_paused():
                    write_both("ВНИМАНИЕ: робот остановлен пользователем", "warn")
                    break
                if max_rows and success_count >= max_rows:
                    write_both(f"ВНИМАНИЕ: достигнут лимит строк: {max_rows}", "warn")
                    break

                row_num = i + 1
                article = self.cleanup_value(row["Артикул"])
                qty = self.cleanup_value(row["Количество"])

                try:
                    self.root.after(
                        0,
                        lambda rn=row_num, total=total_rows, a=article, q=qty, ok=success_count, err=error_count, sk=skip_count:
                            self.update_progress_overlay(rn, total, a, q, ok, err, sk),
                    )
                    if not article:
                        skip_count += 1
                        write_both(f"SKIP | Строка {row_num} | Пустой артикул", "warn")
                        self.root.after(0, lambda rn=row_num, total=total_rows, ok=success_count, err=error_count, sk=skip_count: self.update_progress_overlay(rn, total, "", "", ok, err, sk))
                        continue
                    if not qty:
                        skip_count += 1
                        write_both(f"SKIP | Строка {row_num} | Пустое количество | Артикул: {article}", "warn")
                        self.root.after(0, lambda rn=row_num, total=total_rows, a=article, ok=success_count, err=error_count, sk=skip_count: self.update_progress_overlay(rn, total, a, "", ok, err, sk))
                        continue

                    write_both(f"START | Строка {row_num} | Артикул: {article} | Количество: {qty}", "start")

                    self.send_unicode_text(article, self.settings.type_interval)
                    self.sleep_with_controls(self.settings.step_delay)
                    self.press_enter(4)
                    self.sleep_with_controls(self.settings.step_delay)
                    self.type_digits_text(qty, 0.02)
                    self.sleep_with_controls(self.settings.qty_wait)
                    self.press_enter(2)
                    self.sleep_with_controls(self.settings.step_delay)
                    if self.stop_event.is_set():
                        break
                    pyautogui.press("down")
                    self.sleep_with_controls(self.settings.step_delay)

                    success_count += 1
                    write_both(f"OK    | Строка {row_num} | Артикул: {article} | Количество: {qty}", "ok")
                    self.root.after(0, lambda rn=row_num, total=total_rows, a=article, q=qty, ok=success_count, err=error_count, sk=skip_count: self.update_progress_overlay(rn, total, a, q, ok, err, sk))
                    self.sleep_with_controls(self.settings.step_delay)
                    if batch_size and batch_pause and success_count % batch_size == 0:
                        write_both(f"Пауза {batch_pause} сек. после {success_count} строк, чтобы 1С успела обработать данные", "warn")
                        for _ in range(batch_pause):
                            if self.stop_event.is_set():
                                break
                            self.sleep_with_controls(1)
                except Exception as e:
                    error_count += 1
                    write_both(f"ERROR | Строка {row_num} | Ошибка: {e}", "error")
                    self.root.after(0, lambda rn=row_num, total=total_rows, a=article, q=qty, ok=success_count, err=error_count, sk=skip_count: self.update_progress_overlay(rn, total, a, q, ok, err, sk))
                    self.stop_event.set()
                    write_both("ВНИМАНИЕ: робот остановлен после ошибки ввода, чтобы не прогонять накладную неверно", "warn")
                    break

            write_both("--------------- ИТОГ ---------------", "start")
            write_both(f"Успешно: {success_count}", "ok")
            write_both(f"Пропущено: {skip_count}", "warn" if skip_count else "info")
            write_both(f"Ошибок: {error_count}", "error" if error_count else "info")
            write_both(f"Лог сохранен: {log_file.name}", "info")
            self.last_run_summary = {
                "total": total_rows,
                "success": success_count,
                "skipped": skip_count,
                "errors": error_count,
                "stopped": self.stop_event.is_set(),
                "log_file": str(log_file),
            }

            if self.stop_event.is_set():
                self.root.after(0, lambda: self.countdown_label.config(text="Остановлено"))
                self.root.after(0, lambda: self.status_var.set("Статус: остановлено"))
            else:
                self.root.after(0, lambda: self.countdown_label.config(text="Готово"))
                self.root.after(0, lambda: self.status_var.set("Статус: готово"))

        except Exception as e:
            write_both(f"ОШИБКА: {e}", "error")
            write_both(traceback.format_exc(), "error")
            self.last_run_summary = {
                "total": total_rows,
                "success": success_count,
                "skipped": skip_count,
                "errors": error_count + 1,
                "stopped": self.stop_event.is_set(),
                "log_file": str(log_file),
            }
            self.root.after(0, lambda: self.countdown_label.config(text="Ошибка"))
            self.root.after(0, lambda: self.status_var.set("Статус: ошибка"))
        finally:
            self.root.after(0, self.finish_run)


if __name__ == "__main__":
    root = tk.Tk()
    app = RobotApp(root)
    root.mainloop()
