// frontend/team-leader/api.js
//
// Token-aware fetch wrapper.
//
// All requests:
//   • Add Bearer token if available
//   • Send/receive JSON
//   • On 401 → clear token, emit "logout" event
//
// Returns the parsed JSON body on success.
// Throws an Error with .status and .code on non-2xx.

const BASE = location.origin + '/api';

const _listeners = { logout: [] };
export function on(event, fn) {
  if (_listeners[event]) _listeners[event].push(fn);
}

export function getToken()  { return localStorage.getItem('tl_token'); }
export function setToken(t) { localStorage.setItem('tl_token', t); }
export function clearToken() {
  localStorage.removeItem('tl_token');
  localStorage.removeItem('tl_user');
}

async function request(method, path, body = null) {
  const headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
  const token = getToken();
  if (token) headers.Authorization = 'Bearer ' + token;

  const init = { method, headers };
  if (body !== null) init.body = JSON.stringify(body);

  let res;
  try {
    res = await fetch(BASE + path, init);
  } catch (e) {
    const err = new Error('Network error');
    err.code   = 'network';
    err.status = 0;
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
    err.status = res.status;
    err.code   = data?.code || null;
    err.data   = data;
    throw err;
  }

  return data;
}

export const get   = (p)     => request('GET',   p);
export const post  = (p, b)  => request('POST',  p, b ?? {});
export const put   = (p, b)  => request('PUT',   p, b ?? {});
export const patch = (p, b)  => request('PATCH', p, b ?? {});
export const del   = (p)     => request('DELETE',p);
