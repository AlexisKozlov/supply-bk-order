import { createClient } from 'https://cdn.jsdelivr.net/npm/@supabase/supabase-js/+esm';

const SUPABASE_URL = 'https://obywcpilionribalfrbl.supabase.co';

const SUPABASE_KEY = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6Im9ieXdjcGlsaW9ucmliYWxmcmJsIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NjkxNjQ4NzYsImV4cCI6MjA4NDc0MDg3Nn0.gOo7_prJfR7kxw6Fe8ZrEEIbhEK02DCNYPr9Ln2EuYc';

// API ключ для RLS — замени на свой из таблицы api_keys после выполнения security-rls.sql
const API_KEY = localStorage.getItem('bk_api_key') || '';

export const supabase = createClient(
  SUPABASE_URL,
  SUPABASE_KEY,
  {
    global: {
      headers: {
        'x-api-key': API_KEY
      }
    }
  }
);

// Функция для установки API ключа (вызывается из настроек или при первом запуске)
export function setApiKey(key) {
  localStorage.setItem('bk_api_key', key);
  // Перезагрузка нужна чтобы клиент пересоздался с новым заголовком
  window.location.reload();
}