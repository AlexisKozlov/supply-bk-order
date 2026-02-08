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
  toast.innerHTML = `
    <div class="toast-icon">${icons[type]}</div>
    <div class="toast-content">
      <div class="toast-title">${title}</div>
      ${message ? `<div class="toast-message">${message}</div>` : ''}
    </div>
    <button class="toast-close"><img src="./icons/close.png" width="12" height="12" alt=""></button>
  `;

  const container = document.querySelector('.toast-container');
  container.appendChild(toast);

  toast.querySelector('.toast-close').addEventListener('click', () => {
    toast.remove();
  });

  setTimeout(() => {
    toast.remove();
  }, 4000);
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
      yesBtn.replaceWith(yesBtn.cloneNode(true));
      noBtn.replaceWith(noBtn.cloneNode(true));
      closeBtn.replaceWith(closeBtn.cloneNode(true));
      resolve(result);
    };

    document.getElementById('confirmYes').addEventListener('click', () => cleanup(true));
    document.getElementById('confirmNo').addEventListener('click', () => cleanup(false));
    document.getElementById('closeConfirm').addEventListener('click', () => cleanup(false));
  });
}