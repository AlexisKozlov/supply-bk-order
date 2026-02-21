/**
 * Модуль для работы с модальными окнами и уведомлениями
 */

/* ================= TOAST NOTIFICATIONS ================= */
function createToastContainer() {
  if (!document.querySelector('.toast-container')) {
    const container = document.createElement('div');
    container.className = 'toast-container';
    document.body.appendChild(container);
  }
}

export function showToast(title, message, type = 'info') {
  createToastContainer();
  
  const icons = {
    success: '<img src="./icons/check.png" width="20" height="20" alt="">',
    error: '<img src="./icons/error.png" width="20" height="20" alt="">',
    info: '<img src="./icons/info.png" width="20" height="20" alt="">'
  };

  const toast = document.createElement('div');
  toast.className = `toast toast-${type}`;
  
  const iconDiv = document.createElement('div');
  iconDiv.className = 'toast-icon';
  iconDiv.innerHTML = icons[type] || icons.info;
  
  const contentDiv = document.createElement('div');
  contentDiv.className = 'toast-content';
  
  const titleDiv = document.createElement('div');
  titleDiv.className = 'toast-title';
  titleDiv.textContent = title;
  contentDiv.appendChild(titleDiv);
  
  if (message) {
    const msgDiv = document.createElement('div');
    msgDiv.className = 'toast-message';
    msgDiv.textContent = message;
    contentDiv.appendChild(msgDiv);
  }
  
  const closeBtn = document.createElement('button');
  closeBtn.className = 'toast-close';
  closeBtn.textContent = '✕';
  
  toast.appendChild(iconDiv);
  toast.appendChild(contentDiv);
  toast.appendChild(closeBtn);

  const container = document.querySelector('.toast-container');
  container.appendChild(toast);

  closeBtn.addEventListener('click', () => toast.remove());
  setTimeout(() => toast.remove(), 4000);
}

/* ================= CUSTOM PROMPT ================= */
export function customPrompt(title, placeholder = '') {
  return new Promise((resolve) => {
    // Создаём модалку динамически
    const overlay = document.createElement('div');
    overlay.className = 'modal';
    overlay.style.cssText = 'z-index:10001;display:flex;align-items:center;justify-content:center;position:fixed;inset:0;background:rgba(0,0,0,0.4);';
    
    const box = document.createElement('div');
    box.style.cssText = 'background:white;border-radius:12px;padding:20px;width:400px;max-width:90vw;box-shadow:0 8px 32px rgba(0,0,0,0.2);';
    box.innerHTML = `
      <div style="font-weight:700;font-size:15px;margin-bottom:12px;">${title}</div>
      <textarea style="width:100%;min-height:60px;border:1px solid #ddd;border-radius:8px;padding:8px 10px;font-size:13px;resize:vertical;font-family:inherit;" placeholder="${placeholder}"></textarea>
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px;">
        <button class="btn" style="background:#f5f5f5;color:#333;padding:6px 16px;border-radius:8px;font-size:13px;">Пропустить</button>
        <button class="btn" style="background:var(--accent,#E65100);color:white;padding:6px 16px;border-radius:8px;font-size:13px;">Сохранить</button>
      </div>
    `;
    overlay.appendChild(box);
    document.body.appendChild(overlay);
    
    const textarea = box.querySelector('textarea');
    const [skipBtn, saveBtn] = box.querySelectorAll('button');
    textarea.focus();
    
    const cleanup = (val) => { overlay.remove(); resolve(val); };
    
    skipBtn.onclick = () => cleanup('');
    saveBtn.onclick = () => cleanup(textarea.value.trim());
    overlay.onclick = (e) => { if (e.target === overlay) cleanup(''); };
    textarea.onkeydown = (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); cleanup(textarea.value.trim()); } };
  });
}
export function customConfirm(title, message) {
  return new Promise((resolve) => {
    const modal = document.getElementById('confirmModal');
    const titleEl = document.getElementById('confirmTitle');
    const messageEl = document.getElementById('confirmMessage');
    const yesBtn = document.getElementById('confirmYes');
    const noBtn = document.getElementById('confirmNo');
    const closeBtn = document.getElementById('closeConfirm');

    titleEl.textContent = title;
    messageEl.textContent = message;
    modal.classList.remove('hidden');

    const cleanup = (result) => {
      modal.classList.add('hidden');
      modal.removeEventListener('click', handleBackdrop);
      yesBtn.replaceWith(yesBtn.cloneNode(true));
      noBtn.replaceWith(noBtn.cloneNode(true));
      closeBtn.replaceWith(closeBtn.cloneNode(true));
      resolve(result);
    };

    const handleBackdrop = (e) => {
      if (e.target === modal) cleanup(false);
    };

    modal.addEventListener('click', handleBackdrop);
    document.getElementById('confirmYes').addEventListener('click', () => cleanup(true));
    document.getElementById('confirmNo').addEventListener('click', () => cleanup(false));
    document.getElementById('closeConfirm').addEventListener('click', () => cleanup(false));
  });
}