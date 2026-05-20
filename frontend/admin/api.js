// frontend/admin/api.js
// Shared fetch wrapper for the admin panel.

const BASE = location.origin + '/api';

const _listeners = { logout: [] };
export function on(event, fn) {
  if (_listeners[event]) _listeners[event].push(fn);
}

export function getToken()  { return localStorage.getItem('ap_admin_token'); }
export function setToken(t) { localStorage.setItem('ap_admin_token', t); }
export function clearToken() { localStorage.removeItem('ap_admin_token'); }

async function request(method, path, body = null) {
  const headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
  const tok = getToken();
  if (tok) headers.Authorization = 'Bearer ' + tok;

  const init = { method, headers };
  if (body !== null) init.body = JSON.stringify(body);

  let res;
  try {
    res = await fetch(BASE + path, init);
  } catch (e) {
    const err = new Error('Network error'); err.code = 'network'; err.status = 0;
    throw err;
  }

  let data;
  try { data = await res.json(); } catch { data = null; }

  if (!res.ok) {
    if (res.status === 401) {
      clearToken();
      for (const fn of _listeners.logout) try { fn(); } catch {}
    }
    const err = new Error(data?.error || data?.message || ('HTTP ' + res.status));
    err.status = res.status; err.code = data?.code || null; err.data = data;
    throw err;
  }
  return data;
}

export const get   = (p)    => request('GET',    p);
export const post  = (p, b) => request('POST',   p, b ?? {});
export const put   = (p, b) => request('PUT',    p, b ?? {});
export const patch = (p, b) => request('PATCH',  p, b ?? {});
export const del   = (p)    => request('DELETE', p);

/**
 * Download a file from the API and trigger a browser save.
 * Used for CSV/PDF exports — we can't use a plain <a href> because the
 * endpoint requires the Authorization header, which an anchor click won't send.
 *
 * Returns a Promise that resolves once the download has started, rejects if
 * the request fails. Filename is taken from the Content-Disposition header
 * when present, falling back to `fallbackName`.
 */
export async function downloadFile(path, fallbackName = 'download') {
  const headers = {};
  const tok = getToken();
  if (tok) headers.Authorization = 'Bearer ' + tok;

  const res = await fetch(BASE + path, { headers });
  if (!res.ok) {
    const err = new Error('Download failed (HTTP ' + res.status + ')');
    err.status = res.status;
    throw err;
  }

  let filename = fallbackName;
  const disp = res.headers.get('Content-Disposition') || '';
  const m = disp.match(/filename\*?=(?:UTF-8'')?"?([^";]+)"?/i);
  if (m) filename = decodeURIComponent(m[1]);

  const blob = await res.blob();
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 5000);
}