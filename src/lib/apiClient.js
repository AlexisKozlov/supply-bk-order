// REST API клиент для PHP + MariaDB бэкенда.
// Интерфейс .from().select()/.insert()/.update()/.delete()

const API_BASE = '/api';
function getApiKey() { return localStorage.getItem('bk_api_key') || ''; }
function getSessionToken() { return localStorage.getItem('bk_session_token') || ''; }
function buildHeaders() {
  const h = { 'Content-Type': 'application/json' };
  const k = getApiKey();
  if (k) h['X-API-Key'] = k;
  const t = getSessionToken();
  if (t) h['X-Session-Token'] = t;
  return h;
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
  upsert(d) { this._method = 'POST'; this._body = Array.isArray(d) ? d : [d]; return this; }
  delete() { this._method = 'DELETE'; return this; }

  eq(c, v)    { this._f[c] = `eq.${v}`;     return this; }
  neq(c, v)   { this._f[c] = `neq.${v}`;    return this; }
  gt(c, v)    { this._f[c] = `gt.${v}`;     return this; }
  gte(c, v)   { this._f[c] = `gte.${v}`;    return this; }
  lt(c, v)    { this._f[c] = `lt.${v}`;     return this; }
  lte(c, v)   { this._f[c] = `lte.${v}`;    return this; }
  in(c, v)    { this._f[c] = `in.(${v.join(',')})`; return this; }
  is(c, v)    { this._f[c] = v === null ? 'is.null' : `eq.${v}`; return this; }
  not(c, op, v) {
    if (op === 'is' && v === null) { this._f[c] = 'not.is.null'; return this; }
    this._f[c] = `neq.${v}`; return this;
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

  async _run() {
    const p = new URLSearchParams();
    for (const [k, v] of Object.entries(this._f)) p.set(k, v);
    if (this._sel !== '*') p.set('select', this._sel);
    if (this._ord) p.set('order', this._ord);
    if (this._lim) p.set('limit', String(this._lim));
    if (this._off) p.set('offset', String(this._off));
    if (this._rawParams) { for (const [k, v] of Object.entries(this._rawParams)) p.set(k, v); }
    let qs = p.toString();
    if (this._or) {
      const orEncoded = encodeURIComponent(this._or).replace(/%2C/gi, ',').replace(/%2A/gi, '*');
      qs += (qs ? '&' : '') + 'or=' + orEncoded;
    }
    const url = `${API_BASE}/${this._t}${qs ? '?' + qs : ''}`;

    try {
      if (this._method === 'GET') {
        const r = await fetchWithRetry(url, { headers: buildHeaders() });
        if (!r.ok) { const e = await r.json().catch(() => ({})); return { data: null, error: e.error || r.statusText }; }
        let d = await r.json();
        if (Array.isArray(d)) d = d.map(row => parseJsonFields(row));
        else if (d && typeof d === 'object') d = parseJsonFields(d);
        if (this._head) return { data: null, count: Array.isArray(d) ? d.length : 0, error: null };
        if (this._single) {
          if (Array.isArray(d)) d = d[0] || null;
          if (!d && !this._maybe) return { data: null, error: 'Row not found' };
        }
        return { data: d, error: null };
      }

      if (this._method === 'POST') {
        const b = this._body.length === 1 ? this._body[0] : this._body;
        const r = await fetchWithRetry(url, { method: 'POST', headers: buildHeaders(), body: JSON.stringify(b) });
        if (!r.ok) { const e = await r.json().catch(() => ({})); return { data: null, error: e.error || r.statusText }; }
        let d = await r.json();
        if (d && typeof d === 'object') d = Array.isArray(d) ? d.map(parseJsonFields) : parseJsonFields(d);
        return { data: d, error: null };
      }

      if (this._method === 'PATCH') {
        const r = await fetchWithRetry(url, { method: 'PATCH', headers: buildHeaders(), body: JSON.stringify(this._body) });
        if (!r.ok) { const e = await r.json().catch(() => ({})); return { data: null, error: e.error || r.statusText }; }
        let d = await r.json();
        if (Array.isArray(d)) d = d.map(parseJsonFields);
        return { data: Array.isArray(d) ? d : [d], error: null };
      }

      if (this._method === 'DELETE') {
        const r = await fetchWithRetry(url, { method: 'DELETE', headers: buildHeaders() });
        if (!r.ok) { const e = await r.json().catch(() => ({})); return { data: null, error: e.error || r.statusText }; }
        return { data: await r.json(), error: null };
      }
    } catch (e) { return { data: null, error: e.message }; }
  }
}

function parseJsonFields(row) {
  if (!row || typeof row !== 'object') return row;
  const jsonCols = ['items', 'legal_entities', 'details', 'sku_order', 'analogs', 'data', 'order_items'];
  for (const col of jsonCols) {
    if (col in row && typeof row[col] === 'string') {
      try { row[col] = JSON.parse(row[col]); } catch (e) { /* keep string */ }
    }
  }
  return row;
}

async function rpc(fn, params = {}) {
  try {
    const r = await fetchWithRetry(`${API_BASE}/rpc/${fn}`, { method: 'POST', headers: buildHeaders(), body: JSON.stringify(params) });
    if (!r.ok) { const e = await r.json().catch(() => ({})); return { data: null, error: e.error || r.statusText }; }
    return { data: await r.json(), error: null };
  } catch (e) { return { data: null, error: e.message }; }
}

export const db = {
  from(t) { return new QueryBuilder(t); },
  rpc(fn, p) { return rpc(fn, p); },
};

export function setApiKey(k) { localStorage.setItem('bk_api_key', k); }
export function setSessionToken(t) { localStorage.setItem('bk_session_token', t); }
