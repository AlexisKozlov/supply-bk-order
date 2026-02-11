/**
 * Модуль для работы с товарным запасом
 * Поддерживает двусторонний ввод: дни ↔ дата
 * ВАЖНО: Дни отсчитываются от ДАТЫ ПРИХОДА, а не от сегодня!
 */

export class SafetyStockManager {
  constructor(inputElement, buttonElement, onUpdate) {
    this.input = inputElement;
    this.button = buttonElement; // может быть null
    this.onUpdate = onUpdate;
    
    this.days = 0;
    this.endDate = null;
    this.deliveryDate = new Date();
    
    this.init();
  }
  
  init() {
    this.input.addEventListener('input', (e) => this.handleInput(e));
    this.input.addEventListener('blur', () => this.formatDisplay());
    
    // Кнопка календаря опциональна
    if (this.button) {
      this.button.addEventListener('click', () => this.openDatePicker());
    }
    
    this.input.addEventListener('focus', () => {
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
    tempInput.className = 'safety-date-picker';
    
    // Позиционируем РЯДОМ с кнопкой (absolute для showPicker)
    const buttonRect = this.button.getBoundingClientRect();
    tempInput.style.position = 'absolute';
    tempInput.style.left = (buttonRect.right + window.scrollX + 5) + 'px';
    tempInput.style.top = (buttonRect.top + window.scrollY) + 'px';
    tempInput.style.zIndex = '10000';
    
    // ВАЖНО: Устанавливаем минимальную дату = дата прихода
    if (this.deliveryDate) {
      tempInput.min = this.formatDateForInput(this.deliveryDate);
    }
    
    // Устанавливаем текущую дату
    if (this.endDate) {
      tempInput.value = this.formatDateForInput(this.endDate);
    } else {
      const defaultDate = new Date(this.deliveryDate);
      defaultDate.setDate(defaultDate.getDate() + this.days);
      tempInput.value = this.formatDateForInput(defaultDate);
    }
    
    document.body.appendChild(tempInput);
    
    // Обработчики
    const handleChange = () => {
      const selectedDate = new Date(tempInput.value);
      
      if (selectedDate < this.deliveryDate) {
        console.warn('Нельзя выбрать дату раньше даты прихода');
        cleanup();
        return;
      }
      
      this.endDate = selectedDate;
      this.calculateDays();
      this.formatDisplay();
      this.notifyUpdate();
      cleanup();
    };
    
    const cleanup = () => {
      if (tempInput.parentNode) tempInput.remove();
    };
    
    tempInput.addEventListener('change', handleChange);
    tempInput.addEventListener('blur', () => setTimeout(cleanup, 200));
    
    // Открываем календарь
    setTimeout(() => {
      tempInput.focus();
      if (tempInput.showPicker) {
        try {
          tempInput.showPicker();
        } catch (e) {
          tempInput.click();
        }
      }
    }, 50);
  }
  
  /**
   * Рассчитать дату окончания запаса по количеству дней
   */
  calculateEndDate() {
    const date = new Date(this.deliveryDate);
    date.setDate(date.getDate() + this.days);
    this.endDate = date;
  }
  
  /**
   * Рассчитать количество дней по дате окончания запаса
   */
  calculateDays() {
    if (!this.endDate || !this.deliveryDate) {
      this.days = 0;
      return;
    }
    
    const diffMs = this.endDate - this.deliveryDate;
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
   * Обновить дату ПРИХОДА заказа (от неё отсчитываются дни запаса)
   */
  setDeliveryDate(date) {
    this.deliveryDate = date;
    
    // Пересчитываем дату окончания при изменении даты прихода
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