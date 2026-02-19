/**
 * supabase.js — API клиент
 * Заменяет Supabase SDK, сохраняя тот же интерфейс:
 *   supabase.from('table').select('*').eq('col', val)...
 *
 * Все вызовы идут на /api/{table}
 */

const API_BASE = '/api';

function getApiKey() {
  return localStorage.getItem('bk_api_key') || '';
}

function buildHeaders() {
  const h = { 'Content-Type': 'application/json' };
  const key = getApiKey();
  if (key) h['X-API-Key'] = key;
  return h;
}

// ═══════ QUERY BUILDER ═══════

class QueryBuilder {
  constructor(table) {
    this._table = table;
    this._select = '*';
    this._filters = {};
    this._order = null;
    this._limit = null;
    this._single = false;
    this._maybeSingle = false;
    this._method = 'GET';
    this._body = null;
    this._countMode = null;
    this._head = false;
  }

  select(cols, opts) {
    this._select = cols || '*';
    if (opts?.count) this._countMode = opts.count;
    if (opts?.head) this._head = true;
    return this;
  }

  insert(data) {
    this._method = 'POST';
    this._body = Array.isArray(data) ? data : [data];
    return this;
  }

  update(data) {
    this._method = 'PATCH';
    this._body = data;
    return this;
  }

  upsert(data) {
    this._method = 'POST';
    this._body = Array.isArray(data) ? data : [data];
    this._upsert = true;
    return this;
  }

  delete() {
    this._method = 'DELETE';
    return this;
  }

  eq(col, val) {
    this._filters[col] = `eq.${val}`;
    return this;
  }

  neq(col, val) {
    this._filters[col] = `neq.${val}`;
    return this;
  }

  gte(col, val) {
    this._filters[col] = `gte.${val}`;
    return this;
  }

  lte(col, val) {
    this._filters[col] = `lte.${val}`;
    return this;
  }

  in(col, values) {
    this._filters[col] = `in.(${values.join(',')})`;
    return this;
  }

  order(col, opts) {
    const dir = opts?.ascending === false ? 'desc' : 'asc';
    this._order = `${col}.${dir}`;
    return this;
  }

  limit(n) {
    this._limit = n;
    return this;
  }

  single() {
    this._single = true;
    return this;
  }

  maybeSingle() {
    this._single = true;
    this._maybeSingle = true;
    return this;
  }

  // Thenable — позволяет использовать await
  then(resolve, reject) {
    this._execute().then(resolve, reject);
  }

  async _execute() {
    let url = `${API_BASE}/${this._table}`;
    const params = new URLSearchParams();

    for (const [col, val] of Object.entries(this._filters)) {
      params.set(col, val);
    }

    if (this._select !== '*') params.set('select', this._select);
    if (this._order) params.set('order', this._order);
    if (this._limit) params.set('limit', String(this._limit));

    const qs = params.toString();
    const fullUrl = `${url}${qs ? '?' + qs : ''}`;

    try {
      if (this._method === 'GET') {
        const resp = await fetch(fullUrl, { headers: buildHeaders() });

        if (!resp.ok) {
          const err = await resp.json().catch(() => ({}));
          return { data: null, error: err.error || resp.statusText };
        }

        let data = await resp.json();

        if (this._head) {
          return { data: null, count: Array.isArray(data) ? data.length : 0, error: null };
        }

        if (this._single) {
          if (Array.isArray(data)) data = data[0] || null;
          if (!data && !this._maybeSingle) return { data: null, error: 'Row not found' };
        }

        return { data, error: null };
      }

      if (this._method === 'POST') {
        const sendBody = this._body.length === 1 ? this._body[0] : this._body;
        const resp = await fetch(fullUrl, {
          method: 'POST',
          headers: buildHeaders(),
          body: JSON.stringify(sendBody)
        });

        if (!resp.ok) {
          const err = await resp.json().catch(() => ({}));
          return { data: null, error: err.error || resp.statusText };
        }

        const data = await resp.json();
        return { data, error: null };
      }

      if (this._method === 'PATCH' || this._method === 'PUT') {
        const resp = await fetch(fullUrl, {
          method: 'PATCH',
          headers: buildHeaders(),
          body: JSON.stringify(this._body)
        });

        if (!resp.ok) {
          const err = await resp.json().catch(() => ({}));
          return { data: null, error: err.error || resp.statusText };
        }

        const data = await resp.json();
        return { data: Array.isArray(data) ? data : [data], error: null };
      }

      if (this._method === 'DELETE') {
        const resp = await fetch(fullUrl, {
          method: 'DELETE',
          headers: buildHeaders()
        });

        if (!resp.ok) {
          const err = await resp.json().catch(() => ({}));
          return { data: null, error: err.error || resp.statusText };
        }

        const data = await resp.json();
        return { data, error: null };
      }

    } catch (e) {
      return { data: null, error: e.message };
    }
  }
}

// ═══════ RPC ═══════
async function rpc(fnName, params = {}) {
  try {
    const resp = await fetch(`${API_BASE}/rpc/${fnName}`, {
      method: 'POST',
      headers: buildHeaders(),
      body: JSON.stringify(params)
    });

    if (!resp.ok) {
      const err = await resp.json().catch(() => ({}));
      return { data: null, error: err.error || resp.statusText };
    }

    const data = await resp.json();
    return { data, error: null };
  } catch (e) {
    return { data: null, error: e.message };
  }
}

// ═══════ EXPORT ═══════

export const supabase = {
  from(table) {
    return new QueryBuilder(table);
  },
  rpc(fnName, params) {
    return rpc(fnName, params);
  }
};

export function setApiKey(key) {
  localStorage.setItem('bk_api_key', key);
}