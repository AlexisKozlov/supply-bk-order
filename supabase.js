import { createClient } from 'https://cdn.jsdelivr.net/npm/@supabase/supabase-js/+esm';

const SUPABASE_URL = 'https://obywcpilionribalfrbl.supabase.co';

const SUPABASE_KEY = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6Im9ieXdjcGlsaW9ucmliYWxmcmJsIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NjkxNjQ4NzYsImV4cCI6MjA4NDc0MDg3Nn0.gOo7_prJfR7kxw6Fe8ZrEEIbhEK02DCNYPr9Ln2EuYc';

function getApiKey() {
  return localStorage.getItem('bk_api_key') || '';
}

function buildClient(apiKey) {
  const opts = apiKey
    ? { global: { headers: { 'x-api-key': apiKey } } }
    : {};
  return createClient(SUPABASE_URL, SUPABASE_KEY, opts);
}

// Начальный клиент (с ключом из localStorage если есть)
export let supabase = buildClient(getApiKey());

// Пересоздать клиент с новым API ключом (вызывается после логина)
export function setApiKey(key) {
  localStorage.setItem('bk_api_key', key);
  supabase = buildClient(key);
}