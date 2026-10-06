export class ApiError extends Error {
  constructor(message, status = 0, uncertain = false) {
    super(message); this.status = status; this.uncertain = uncertain;
  }
}
export class TokenApi {
  constructor(endpoint, {fetcher = globalThis.fetch, storage = null} = {}) {
    this.endpoint = new URL(endpoint); this.fetcher = fetcher; this.storage = storage;
    try { this.token = storage?.getItem('divan_admin_token') || ''; } catch { this.token = ''; }
  }
  setToken(token) {
    this.token = token;
    try {
      if (token) this.storage?.setItem('divan_admin_token', token);
      else this.storage?.removeItem('divan_admin_token');
    } catch { /* Private browsers may disable storage; use memory. */ }
  }
  async call(action, {method = 'GET', fields = {}, query = {}, authenticated = true} = {}) {
    const url = new URL(this.endpoint);
    if (action) url.searchParams.set('action', action);
    for (const [key, value] of Object.entries(query)) url.searchParams.set(key, String(value));
    const currentToken = this.token;
    const headers = {Accept: 'application/json'};
    if (authenticated && this.token) headers.Authorization = `Bearer ${this.token}`;
    const mutation = method === 'POST' && !['login', 'logout'].includes(action);
    let response;
    try {
      response = await this.fetcher(url, {
        method, headers, credentials: 'omit', cache: 'no-store', redirect: 'error',
        ...(method === 'POST' ? {body: fields instanceof FormData ? fields : new URLSearchParams(fields)} : {}),
      });
    } catch {
      throw new ApiError(mutation ? 'نتیجهٔ ارسال مشخص نیست؛ ابتدا نتیجه را بررسی کنید.' : 'ارتباط برقرار نشد. دوباره تلاش کنید.', 0, mutation);
    }
    let data;
    try { data = await response.json(); } catch {
      throw new ApiError('پاسخ معتبر از سرور دریافت نشد.', response.status, mutation);
    }
    if (response.status === 401) {
      if (this.token !== currentToken) throw new ApiError('پاسخ مربوط به ورود قبلی است.', 409);
      this.setToken('');
      throw new ApiError(action === 'login' ? 'نام کاربری یا رمز پذیرفته نشد.' : 'ورود منقضی شده است؛ دوباره وارد شوید.', 401);
    }
    if (!response.ok || (action && data?.ok !== true)) {
      throw new ApiError(data?.message || 'درخواست انجام نشد.', response.status, mutation && response.status >= 500);
    }
    if (!action) {
      if (Array.isArray(data) && data.length === 0) return [];
      if (!Array.isArray(data?.AndroidEbookApp)) throw new ApiError('پاسخ خواندن معتبر نیست.');
      return data.AndroidEbookApp;
    }
    return data;
  }
  async login(username, password) {
    this.setToken('');
    const data = await this.call('login', {method: 'POST', fields: {username, password}, authenticated: false});
    if (data.token_type !== 'Bearer' || !/^[a-f0-9]{64}$/.test(data.access_token || '')) throw new ApiError('پاسخ ورود معتبر نیست.');
    // Do not persist/enable the panel until the token is verified by the server.
    this.token = data.access_token;
    try { const me = await this.call('me'); this.setToken(this.token); return me; }
    catch (error) { this.setToken(''); throw error; }
  }
  async logout() {
    try { if (this.token) await this.call('logout', {method: 'POST'}); }
    finally { this.setToken(''); }
  }
}
export const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'}[c]));
export function imageUrl(value, base) {
  if (!value || (/^[a-z][a-z0-9+.-]*:/i.test(value) && !/^https?:/i.test(value))) return '';
  const path = /^(https?:|\/|upload\/)/i.test(value) ? value : `upload/category/${value}`;
  try { const url = new URL(path, base); return ['http:', 'https:'].includes(url.protocol) ? url.href : ''; } catch { return ''; }
}
export function matchingWrite(rows, target, baseline = []) {
  return rows.filter(row =>
    (target.id ? String(row.nid) === String(target.id) : !baseline.includes(String(row.nid))) &&
    String(row.cat_id) === String(target.cid) && row.news_heading === target.news_heading &&
    row.news_date === target.news_date && row.news_description === target.news_description);
}
