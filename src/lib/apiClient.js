// REST API клиент для PHP + MariaDB бэкенда.
// Интерфейс .from().select()/.insert()/.update()/.delete()

import { ref } from 'vue';

const API_BASE = '/api';

// Глобальный флаг доступности сервера
export const serverDown = ref(false);
let _consecutiveErrors = 0;
let _recoveryTimer = null;

function trackServerStatus(ok) {
  if (ok) {
    if (_consecutiveErrors > 0) _consecutiveErrors = 0;
    if (serverDown.value) serverDown.value = false;
  } else {
    _consecutiveErrors++;
    if (_consecutiveErrors >= 3 && !serverDown.value) {
      serverDown.value = true;
      // Периодически пробуем восстановить
      if (!_recoveryTimer) {
        _recoveryTimer = setInterval(async () => {
          try {
            const r = await fetch(`${API_BASE}/rpc/health_check`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
            if (r.ok) { serverDown.value = false; _consecutiveErrors = 0; clearInterval(_recoveryTimer); _recoveryTimer = null; }
          } catch (e) { /* всё ещё недоступен */ }
        }, 15000);
      }
    }
  }
}
function getSessionToken() { return localStorage.getItem('bk_session_token') || ''; }
function buildHeaders() {
  const h = { 'Content-Type': 'application/json' };
  const t = getSessionToken();
  if (t) h['X-Session-Token'] = t;
  // Авторизация ресторана идёт через HttpOnly-cookie ro_session — браузер
  // прикладывает её сам, заголовок больше не нужен.
  return h;
}

let _onAuthError = null;
let _authErrorFired = false;
export function setAuthErrorHandler(fn) { _onAuthError = fn; }
function handleAuthError() {
  if (_authErrorFired) return;
  _authErrorFired = true;
  _onAuthError?.();
  setTimeout(() => { _authErrorFired = false; }, 5000);
}

async function fetchWithRetry(url, opts, maxRetries = 2) {
  for (let attempt = 0; ; attempt++) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 30000);
    try {
      const res = await fetch(url, { ...opts, signal: controller.signal });
      clearTimeout(timer);
      return res;
    } catch (e) {
      clearTimeout(timer);
      if (e.name === 'AbortError') throw new Error('Сервер не отвечает (таймаут 30 сек)');
      if (attempt >= maxRetries || !(e instanceof TypeError)) throw e;
      await new Promise(r => setTimeout(r, 1000));
    }
  }
}

// Дедупликация одинаковых GET-запросов, летящих одновременно
const _inflightGets = new Map();

class QueryBuilder {
  constructor(t) {
    this._t = t;
    this._sel = '*';
    this._f = {};
    this._or = null;
    this._ord = null;
    this._lim = null;
    this._off = 0;
    this._single = false;
    this._maybe = false;
    this._method = 'GET';
    this._body = null;
    this._head = false;
    this._countMode = null;
  }

  select(c, o) {
    this._sel = c ? c.replace(/\s+/g, ' ').replace(/(\w)\s+\(/g, '$1(').trim() : '*';
    if (o?.count) this._countMode = o.count;
    if (o?.head) this._head = true;
    return this;
  }

  insert(d) { this._method = 'POST'; this._body = Array.isArray(d) ? d : [d]; return this; }
  update(d) { this._method = 'PATCH'; this._body = d; return this; }
  upsert(d) { this._method = 'POST'; this._body = Array.isArray(d) ? d : [d]; this._upsert = true; return this; }
  delete() { this._method = 'DELETE'; return this; }

  eq(c, v)    { this._f[c] = `eq.${v}`;     return this; }
  neq(c, v)   { this._f[c] = `neq.${v}`;    return this; }
  gt(c, v)    { this._f[c] = `gt.${v}`;     return this; }
  gte(c, v)   { this._f[c] = `gte.${v}`;    return this; }
  lt(c, v)    { this._f[c] = `lt.${v}`;     return this; }
  lte(c, v)   { this._f[c] = `lte.${v}`;    return this; }
  between(c, from, to) { this._f[c] = `between.${from}.${to}`; return this; }
  in(c, v)    { if (!Array.isArray(v) || !v.length) { this._emptyIn = true; return this; } this._f[c] = `in.(${v.map(x => String(x).replace(/,/g, '\\,')).join(',')})`; return this; }
  is(c, v)    { this._f[c] = v === null ? 'is.null' : `eq.${v}`; return this; }
  // Примечание: несколько фильтров на одну и ту же колонку не поддерживаются —
  // каждый новый вызов .eq()/.neq()/etc. для той же колонки перезапишет предыдущий.
  // Для сложных условий на одну колонку используйте .or().
  not(c, op, v) {
    if (op === 'is' && v === null) { this._f[c] = 'not.is.null'; return this; }
    this._f[c] = `not.${op}.${v}`; return this;
  }
  ilike(c, v) { this._f[c] = `ilike.${v}`;  return this; }
  or(conditions) { this._or = conditions;    return this; }

  order(c, o) {
    const dir = o?.ascending === false ? 'desc' : 'asc';
    this._ord = `${c}.${dir}`;
    return this;
  }

  limit(n)       { this._lim = n;             return this; }
  offset(n)      { this._off = n;             return this; }
  single()       { this._single = true;        return this; }
  maybeSingle()  { this._single = true; this._maybe = true; return this; }

  then(res, rej) { this._run().then(res, rej); }

  rawParam(key, value) { this._rawParams = this._rawParams || {}; this._rawParams[key] = value; return this; }

  header(key, value) { this._headers = this._headers || {}; this._headers[key] = value; return this; }

  async _run() {
    const p = new URLSearchParams();
    for (const [k, v] of Object.entries(this._f)) p.set(k, v);
    if (this._sel !== '*') p.set('select', this._sel);
    if (this._ord) p.set('order', this._ord);
    if (this._lim != null) p.set('limit', String(this._lim));
    if (this._off) p.set('offset', String(this._off));
    if (this._upsert) p.set('upsert', 'true');
    if (this._rawParams) { for (const [k, v] of Object.entries(this._rawParams)) p.set(k, v); }
    let qs = p.toString();
    if (this._or) {
      const orEncoded = encodeURIComponent(this._or).replace(/%2C/gi, ',').replace(/%2A/gi, '*');
      qs += (qs ? '&' : '') + 'or=' + orEncoded;
    }
    const url = `${API_BASE}/${this._t}${qs ? '?' + qs : ''}`;

    // Дедупликация GET-запросов: ключ включает модификаторы результата
    if (this._method === 'GET') {
      const dedupKey = url + (this._single ? '|s' : '') + (this._maybe ? '|m' : '') + (this._head ? '|h' : '');
      const existing = _inflightGets.get(dedupKey);
      if (existing) return existing;
      const promise = this._exec(url);
      _inflightGets.set(dedupKey, promise);
      promise.finally(() => _inflightGets.delete(dedupKey));
      return promise;
    }

    const promise = this._exec(url);

    return promise;
  }

  async _exec(url) {
    if (this._emptyIn) return { data: [], error: null, status: 200 };
    try {
      const opts = { headers: { ...buildHeaders(), ...(this._headers || {}) } };
      if (this._method !== 'GET') {
        opts.method = this._method;
        if (this._method === 'POST') {
          const b = this._body.length === 1 ? this._body[0] : this._body;
          opts.body = JSON.stringify(b);
        } else if (this._method === 'PATCH') {
          opts.body = JSON.stringify(this._body);
        }
      }

      const r = await fetchWithRetry(url, opts);
      trackServerStatus(true);
      if (r.status === 401) { handleAuthError(); return { data: null, error: 'Session expired', status: r.status }; }
      if (!r.ok) {
        if (r.status >= 500) trackServerStatus(false);
        const e = await r.json().catch(() => ({}));
        return { data: null, error: e.error || r.statusText, status: r.status };
      }

      let d = await r.json().catch(() => null);
      if (d && typeof d === 'object') d = Array.isArray(d) ? d.map(parseJsonFields) : parseJsonFields(d);

      if (this._head) return { data: null, count: Array.isArray(d) ? d.length : 0, error: null, status: r.status };
      if (this._single) {
        if (Array.isArray(d)) d = d[0] || null;
        if (!d && !this._maybe) return { data: null, error: 'Row not found', status: r.status };
      }
      if (this._method === 'PATCH' && d && !Array.isArray(d)) d = [d];
      return { data: d, error: null, status: r.status };
    } catch (e) { trackServerStatus(false); return { data: null, error: e.message, status: 0 }; }
  }
}

function parseJsonFields(row) {
  if (!row || typeof row !== 'object') return row;
  for (const col in row) {
    const v = row[col];
    if (typeof v === 'string' && v.length >= 2) {
      const c = v[0];
      if (c === '[' || c === '{') {
        try { row[col] = JSON.parse(v); } catch (e) { /* keep string */ }
      }
    }
  }
  return row;
}

async function rpc(fn, params = {}) {
  try {
    const r = await fetchWithRetry(`${API_BASE}/rpc/${fn}`, { method: 'POST', headers: buildHeaders(), body: JSON.stringify(params) });
    trackServerStatus(true);
    if (r.status === 401) { handleAuthError(); return { data: null, error: 'Session expired' }; }
    if (!r.ok) {
      if (r.status >= 500) trackServerStatus(false);
      const e = await r.json().catch(() => ({}));
      return { data: null, error: e.error || r.statusText };
    }
    return { data: await r.json(), error: null };
  } catch (e) { trackServerStatus(false); return { data: null, error: e.message }; }
}

// Поиск товаров по названию/артикулу. Идёт в /api/search_products
// (отдельный эндпоинт, а не RPC), но использует общий buildHeaders/retry.
// Раньше 8 разных мест в коде дёргали fetch('/api/search_products') и
// вручную клеили X-Session-Token — это ломалось при любой смене схемы
// авторизации.
async function searchProducts(query, opts = {}) {
  const q = String(query || '').trim();
  if (q.length < 2) return { data: [], error: null };
  const params = new URLSearchParams({ q, limit: String(opts.limit || 15) });
  if (opts.legalEntity) params.set('legal_entity', opts.legalEntity);
  if (opts.supplier) params.set('supplier', opts.supplier);
  try {
    const r = await fetchWithRetry(`${API_BASE}/search_products?${params}`, { method: 'GET', headers: buildHeaders() });
    trackServerStatus(true);
    if (r.status === 401) { handleAuthError(); return { data: [], error: 'Session expired' }; }
    if (!r.ok) {
      if (r.status >= 500) trackServerStatus(false);
      return { data: [], error: r.statusText };
    }
    return { data: await r.json(), error: null };
  } catch (e) { trackServerStatus(false); return { data: [], error: e.message }; }
}

export const db = {
  from(t) { return new QueryBuilder(t); },
  rpc(fn, p) { return rpc(fn, p); },
  searchProducts(q, opts) { return searchProducts(q, opts); },
};

export function setSessionToken(t) { localStorage.setItem('bk_session_token', t); }

/**
 * Получает одноразовый download-токен для ссылки на файл (TTL 15 мин).
 * Заменяет небезопасную передачу session_token в ?token=. Кэширует токен
 * на 14 минут, чтобы не дёргать бэкенд при каждом ререндере списка файлов.
 *
 * Usage:
 *   const url = await getDownloadUrl('/api/uploads/protocols/abc.pdf');
 *   <a :href="url">скачать</a>
 */
const _downloadTokenCache = new Map(); // filePath → { token, expires }
export async function getDownloadUrl(filePath, opts = {}) {
  const path = String(filePath || '').replace(/^\/+api\/+/, '/api/');
  if (!path) return '';
  const cleanPath = path.replace(/^\/api\//, '');
  const cached = _downloadTokenCache.get(cleanPath);
  const now = Date.now();
  let token = cached && cached.expires > now ? cached.token : null;
  if (!token) {
    const { data } = await rpc('create_download_token', { file_path: cleanPath });
    if (!data?.token) return path; // fallback на старый URL без токена
    token = data.token;
    _downloadTokenCache.set(cleanPath, { token, expires: now + 14 * 60 * 1000 });
  }
  const sep = path.includes('?') ? '&' : '?';
  const dl = `${path}${sep}dl=${encodeURIComponent(token)}`;
  return opts.download ? `${dl}&download=1` : dl;
}

/**
 * Экранирует значение для использования в or()-условиях.
 * Запятые, скобки и обратные слэши в значении экранируются,
 * чтобы бэкенд не разбил строку в неправильном месте.
 */
export function orVal(col, op, val) {
  const safe = String(val).replace(/[\\,()]/g, c => '\\' + c);
  return `${col}.${op}.${safe}`;
}
