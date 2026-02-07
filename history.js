/**
 * История изменений для Undo/Redo
 */

class History {
  constructor(maxSize = 50) {
    this.states = [];
    this.currentIndex = -1;
    this.maxSize = maxSize;
  }

  // Сохранить текущее состояние
  push(state) {
    // Удаляем все состояния после текущего индекса
    this.states = this.states.slice(0, this.currentIndex + 1);
    
    // Добавляем новое состояние
    this.states.push(JSON.parse(JSON.stringify(state)));
    
    // Ограничиваем размер истории
    if (this.states.length > this.maxSize) {
      this.states.shift();
    } else {
      this.currentIndex++;
    }
  }

  // Отменить (вернуться назад)
  undo() {
    if (this.canUndo()) {
      this.currentIndex--;
      return JSON.parse(JSON.stringify(this.states[this.currentIndex]));
    }
    return null;
  }

  // Повторить (вернуться вперёд)
  redo() {
    if (this.canRedo()) {
      this.currentIndex++;
      return JSON.parse(JSON.stringify(this.states[this.currentIndex]));
    }
    return null;
  }

  // Можно ли отменить
  canUndo() {
    return this.currentIndex > 0;
  }

  // Можно ли повторить
  canRedo() {
    return this.currentIndex < this.states.length - 1;
  }

  // Очистить историю
  clear() {
    this.states = [];
    this.currentIndex = -1;
  }
}

export const history = new History();