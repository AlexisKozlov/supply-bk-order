/**
 * Модуль для работы с товарным запасом
 * Поддерживает двусторонний ввод: дни ↔ дата
 */

export class SafetyStockManager {
  constructor(inputElement, buttonElement, onUpdate) {
    this.input = inputElement;
    this.button = buttonElement;
    this.onUpdate = onUpdate;
    
    this.days = 0;
    this.endDate = null;
    this.todayDate = new Date();
    
    this.init();
  }
  
  init() {
    // Обработчик ввода в поле
    this.input.addEventListener('input', (e) => this.handleInput(e));
    this.input.addEventListener('blur', () => this.formatDisplay());
    
    // Обработчик кнопки календаря
    this.button.addEventListener('click', () => this.openDatePicker());
    
    // Форматируем при фокусе
    this.input.addEventListener('focus', () => {
      // Выделяем только число дней для удобного редактирования
      const value = this.input.value;
      const match = value.match(/^(\d+)/);
      if (match) {
        setTimeout(() => {
          this.input.setSelectionRange(0, match[1].length);
        }, 0);
      }
    });
  }
  
  /**
   * Обработка ввода в поле
   */
  handleInput(e) {
    const value = e.target.value.trim();
    
    // Извлекаем число в начале строки
    const match = value.match(/^(\d+)/);
    if (match) {
      this.days = parseInt(match[1], 10);
      this.calculateEndDate();
      this.notifyUpdate();
    }
  }
  
  /**
   * Открытие датапикера для выбора даты окончания запаса
   */
  openDatePicker() {
    // Создаем временный input type="date"
    const tempInput = document.createElement('input');
    tempInput.type = 'date';
    tempInput.style.position = 'absolute';
    tempInput.style.opacity = '0';
    tempInput.style.pointerEvents = 'none';
    
    // Устанавливаем текущую дату окончания (если есть)
    if (this.endDate) {
      tempInput.value = this.formatDateForInput(this.endDate);
    } else {
      // Или дату через N дней от сегодня
      const defaultDate = new Date(this.todayDate);
      defaultDate.setDate(defaultDate.getDate() + this.days);
      tempInput.value = this.formatDateForInput(defaultDate);
    }
    
    document.body.appendChild(tempInput);
    
    // Обработчик выбора даты
    tempInput.addEventListener('change', () => {
      const selectedDate = new Date(tempInput.value);
      this.endDate = selectedDate;
      this.calculateDays();
      this.formatDisplay();
      this.notifyUpdate();
      tempInput.remove();
    });
    
    // Открываем датапикер
    tempInput.showPicker();
    
    // Удаляем при отмене (клик вне или ESC)
    tempInput.addEventListener('blur', () => {
      setTimeout(() => tempInput.remove(), 100);
    });
  }
  
  /**
   * Рассчитать дату окончания запаса по количеству дней
   */
  calculateEndDate() {
    const date = new Date(this.todayDate);
    date.setDate(date.getDate() + this.days);
    this.endDate = date;
  }
  
  /**
   * Рассчитать количество дней по дате окончания запаса
   */
  calculateDays() {
    if (!this.endDate || !this.todayDate) {
      this.days = 0;
      return;
    }
    
    const diffMs = this.endDate - this.todayDate;
    this.days = Math.max(0, Math.ceil(diffMs / 86400000));
  }
  
  /**
   * Форматировать отображение в поле
   */
  formatDisplay() {
    if (this.days === 0) {
      this.input.value = '0';
      return;
    }
    
    const dateStr = this.formatDateForDisplay(this.endDate);
    this.input.value = `${this.days} / ${dateStr}`;
  }
  
  /**
   * Обновить дату "сегодня"
   */
  setTodayDate(date) {
    this.todayDate = date;
    
    // Пересчитываем дату окончания при изменении "сегодня"
    if (this.days > 0) {
      this.calculateEndDate();
      this.formatDisplay();
      this.notifyUpdate();
    }
  }
  
  /**
   * Установить значение (дни)
   */
  setDays(days) {
    this.days = days || 0;
    this.calculateEndDate();
    this.formatDisplay();
  }
  
  /**
   * Получить количество дней
   */
  getDays() {
    return this.days;
  }
  
  /**
   * Получить дату окончания
   */
  getEndDate() {
    return this.endDate;
  }
  
  /**
   * Уведомить об изменениях
   */
  notifyUpdate() {
    if (this.onUpdate) {
      this.onUpdate({
        days: this.days,
        endDate: this.endDate
      });
    }
  }
  
  /**
   * Форматировать дату для отображения (DD.MM.YY)
   */
  formatDateForDisplay(date) {
    if (!date) return '—';
    
    const day = String(date.getDate()).padStart(2, '0');
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const year = String(date.getFullYear()).slice(-2);
    
    return `${day}.${month}.${year}`;
  }
  
  /**
   * Форматировать дату для input type="date" (YYYY-MM-DD)
   */
  formatDateForInput(date) {
    if (!date) return '';
    
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    
    return `${year}-${month}-${day}`;
  }
}