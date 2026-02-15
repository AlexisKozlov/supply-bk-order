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

/* ================= CUSTOM CONFIRM ================= */
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