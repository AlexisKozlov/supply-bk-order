import json
import queue
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.request
import webbrowser
from pathlib import Path
from tkinter import END, BOTH, LEFT, RIGHT, X, Button, Frame, Label, Listbox, StringVar, Tk, messagebox, ttk
from tkinter.scrolledtext import ScrolledText


def app_dir():
    if getattr(sys, "frozen", False):
        return Path(sys.executable).parent
    return Path(__file__).resolve().parent


BASE_DIR = app_dir()
OUTPUT_DIR = BASE_DIR / "output"
SETTINGS_FILE = BASE_DIR / "settings.json"


def load_settings():
    default = {"version": "1.0.0", "update_url": "https://example.com/version.json"}
    if not SETTINGS_FILE.exists():
        SETTINGS_FILE.write_text(json.dumps(default, ensure_ascii=False, indent=2), encoding="utf-8")
        return default
    try:
        data = json.loads(SETTINGS_FILE.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return default
    default.update(data)
    return default


def version_tuple(value):
    parts = []
    for part in str(value).split("."):
        try:
            parts.append(int(part))
        except ValueError:
            parts.append(0)
    return tuple(parts)


class RobotApp:
    def __init__(self, root):
        self.root = root
        self.root.title("1C Robot Pro")
        self.root.geometry("900x620")
        self.settings = load_settings()
        self.process = None
        self.log_queue = queue.Queue()
        self.mode_var = StringVar(value="safe")
        self.status_var = StringVar(value=f"Версия {self.settings['version']}")

        self.build_ui()
        self.refresh_files()
        self.root.after(200, self.consume_log_queue)

    def build_ui(self):
        top = Frame(self.root, padx=12, pady=12)
        top.pack(fill=X)

        Label(top, text="Файлы *_queue_ok.xlsx в папке output").pack(anchor="w")
        self.files_list = Listbox(top, height=9)
        self.files_list.pack(fill=X, pady=8)

        actions = Frame(top)
        actions.pack(fill=X)
        Button(actions, text="Обновить список", command=self.refresh_files).pack(side=LEFT)
        Button(actions, text="Запустить", command=self.start_robot).pack(side=LEFT, padx=6)
        Button(actions, text="СТОП", command=self.stop_robot).pack(side=LEFT)
        Button(actions, text="Проверить обновления", command=self.check_updates).pack(side=RIGHT)

        mode_box = Frame(top)
        mode_box.pack(fill=X, pady=10)
        Label(mode_box, text="Режим:").pack(side=LEFT)
        ttk.Radiobutton(mode_box, text="Безопасный", variable=self.mode_var, value="safe").pack(side=LEFT, padx=8)
        ttk.Radiobutton(mode_box, text="Быстрый", variable=self.mode_var, value="fast").pack(side=LEFT)
        Label(mode_box, textvariable=self.status_var).pack(side=RIGHT)

        log_frame = Frame(self.root, padx=12, pady=12)
        log_frame.pack(fill=BOTH, expand=True)
        self.log = ScrolledText(log_frame, height=20, state="disabled")
        self.log.pack(fill=BOTH, expand=True)
        self.log.tag_config("info", foreground="#1f4e79")
        self.log.tag_config("ok", foreground="#2e7d32")
        self.log.tag_config("error", foreground="#b00020")
        self.log.tag_config("warn", foreground="#9a6700")

    def write_log(self, text, tag="info"):
        self.log.configure(state="normal")
        self.log.insert(END, text + "\n", tag)
        self.log.see(END)
        self.log.configure(state="disabled")

    def consume_log_queue(self):
        while True:
            try:
                text, tag = self.log_queue.get_nowait()
            except queue.Empty:
                break
            self.write_log(text, tag)
        self.root.after(200, self.consume_log_queue)

    def refresh_files(self):
        OUTPUT_DIR.mkdir(exist_ok=True)
        self.files_list.delete(0, END)
        for path in sorted(OUTPUT_DIR.glob("*_queue_ok.xlsx")):
            self.files_list.insert(END, path.name)
        self.write_log("Список файлов обновлён", "ok")

    def selected_file(self):
        selection = self.files_list.curselection()
        if not selection:
            messagebox.showwarning("Файл не выбран", "Выберите файл queue_ok.xlsx")
            return None
        return OUTPUT_DIR / self.files_list.get(selection[0])

    def start_robot(self):
        if self.process and self.process.poll() is None:
            messagebox.showinfo("Уже запущено", "Робот уже работает")
            return
        file_path = self.selected_file()
        if not file_path:
            return
        threading.Thread(target=self.run_robot_thread, args=(file_path,), daemon=True).start()

    def run_robot_thread(self, file_path):
        for seconds in range(5, 0, -1):
            self.log_queue.put((f"Старт через {seconds}...", "warn"))
            time.sleep(1)

        worker_exe = BASE_DIR / "1C_Robot_Worker.exe"
        robot_path = BASE_DIR / "robot_1c.py"
        if worker_exe.exists():
            cmd = [str(worker_exe), str(file_path), "--mode", self.mode_var.get()]
        elif robot_path.exists():
            cmd = [sys.executable, str(robot_path), str(file_path), "--mode", self.mode_var.get()]
        else:
            self.log_queue.put(("Не найден robot_1c.py или 1C_Robot_Worker.exe рядом с программой", "error"))
            return
        creationflags = subprocess.CREATE_NO_WINDOW if sys.platform.startswith("win") else 0
        self.log_queue.put((f"Запуск: {file_path.name}", "info"))
        try:
            self.process = subprocess.Popen(
                cmd,
                cwd=str(BASE_DIR),
                stdout=subprocess.PIPE,
                stderr=subprocess.STDOUT,
                text=True,
                encoding="utf-8",
                errors="replace",
                creationflags=creationflags,
            )
            for line in self.process.stdout:
                self.log_queue.put((line.rstrip(), "info"))
            code = self.process.wait()
            tag = "ok" if code == 0 else "error"
            self.log_queue.put((f"Процесс завершён. Код: {code}", tag))
        except Exception as exc:
            self.log_queue.put((f"Ошибка запуска: {exc}", "error"))

    def stop_robot(self):
        if self.process and self.process.poll() is None:
            self.process.terminate()
            self.write_log("Отправлена команда остановки", "warn")
        else:
            self.write_log("Активного процесса нет", "warn")

    def check_updates(self):
        threading.Thread(target=self.check_updates_thread, daemon=True).start()

    def check_updates_thread(self):
        url = self.settings.get("update_url")
        try:
            with urllib.request.urlopen(url, timeout=10) as response:
                remote = json.loads(response.read().decode("utf-8"))
        except (urllib.error.URLError, TimeoutError, json.JSONDecodeError, OSError) as exc:
            self.root.after(0, lambda: messagebox.showerror("Ошибка обновления", f"Не удалось проверить обновления:\n{exc}"))
            return

        current = self.settings.get("version", "0.0.0")
        latest = remote.get("version", "0.0.0")
        installer_url = remote.get("installer_url", "")
        if version_tuple(latest) > version_tuple(current):
            def ask_open():
                if messagebox.askyesno("Доступно обновление", f"Доступна версия {latest}.\nОткрыть страницу скачивания?"):
                    webbrowser.open(installer_url)
            self.root.after(0, ask_open)
        else:
            self.root.after(0, lambda: messagebox.showinfo("Обновления", "Установлена актуальная версия"))


if __name__ == "__main__":
    root = Tk()
    RobotApp(root)
    root.mainloop()
