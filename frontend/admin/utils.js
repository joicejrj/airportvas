// frontend/admin/utils.js
// Formatting and DOM helpers shared by every page module.

export const CURRENCY = 'AED';

export function escape(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
  ));
}
export const escapeAttr = escape;

export function fmtPrice(p) {
  const n = parseFloat(p);
  return Number.isFinite(n) ? n.toFixed(2) : '0.00';
}

export function fmtAmount(p) {
  return CURRENCY + ' ' + fmtPrice(p);
}

export function fmtTime(iso) {
  if (!iso) return '—';
  const dt = new Date(String(iso).replace(' ', 'T') + (String(iso).endsWith('Z') ? '' : 'Z'));
  if (Number.isNaN(dt.getTime())) return '—';
  return dt.toLocaleString('en-GB', {
    day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit',
    timeZone: 'Asia/Dubai', hour12: false,
  });
}

export function fmtDate(s) {
  if (!s) return '—';
  if (typeof s === 'string' && s.length === 10) {
    const [y, m, d] = s.split('-').map(Number);
    return new Date(Date.UTC(y, m - 1, d)).toLocaleDateString('en-GB', {
      day: '2-digit', month: 'short', year: 'numeric',
    });
  }
  const dt = new Date(String(s).replace(' ', 'T') + (String(s).endsWith('Z') ? '' : 'Z'));
  if (Number.isNaN(dt.getTime())) return s;
  return dt.toLocaleDateString('en-GB', {
    day: '2-digit', month: 'short', year: 'numeric', timeZone: 'Asia/Dubai',
  });
}

export function toast(msg, kind = '') {
  const host = document.getElementById('toasts');
  if (!host) { console.log('[toast]', kind, msg); return; }
  const el = document.createElement('div');
  el.className = 'toast ' + kind;
  el.textContent = msg;
  host.appendChild(el);
  setTimeout(() => {
    el.style.opacity = '0';
    el.style.transition = 'opacity .25s';
    setTimeout(() => el.remove(), 300);
  }, 3000);
}

// Build a modal element on the fly. Returns { overlay, close() }.
export function openModal({ title, body, footer, width = 540 }) {
  const overlay = document.createElement('div');
  overlay.className = 'modal-overlay open';
  overlay.innerHTML = `
    <div class="modal" style="max-width:${width}px">
      <div class="modal-header">
        <div class="modal-title">${escape(title)}</div>
        <button class="modal-close" data-close>✕</button>
      </div>
      <div class="modal-body"></div>
      <div class="modal-footer"></div>
    </div>`;
  const bodyEl = overlay.querySelector('.modal-body');
  const footEl = overlay.querySelector('.modal-footer');
  if (typeof body === 'string')      bodyEl.innerHTML = body;
  else if (body instanceof Node)     bodyEl.appendChild(body);
  if (typeof footer === 'string')    footEl.innerHTML = footer;
  else if (footer instanceof Node)   footEl.appendChild(footer);

  const close = () => overlay.remove();
  overlay.addEventListener('click', (e) => {
    if (e.target === overlay) close();
  });
  overlay.querySelector('[data-close]').addEventListener('click', close);
  document.body.appendChild(overlay);
  return { overlay, close, bodyEl, footEl };
}

export function confirmDialog(message) {
  return new Promise((resolve) => {
    // Use the overlay handle returned by openModal directly. We can't use
    // document.querySelector('.modal-overlay.open') here — if another modal
    // (e.g. a detail view) is already on screen, it has the same classes,
    // and querySelector returns the FIRST match (the older modal), so the
    // OK/Cancel handlers get attached to the wrong overlay and the buttons
    // appear dead. Took us a while to find this one.
    const { overlay, close } = openModal({
      title: 'Confirm',
      width: 420,
      body: `<div style="font-size:13px;line-height:1.5">${escape(message)}</div>`,
      footer: `<button class="btn" data-cancel>Cancel</button>
               <button class="btn danger" data-ok>OK</button>`,
    });
    overlay.querySelector('[data-cancel]').addEventListener('click', () => { close(); resolve(false); });
    overlay.querySelector('[data-ok]').addEventListener('click',     () => { close(); resolve(true);  });
    // Backdrop click / Escape — treat as cancel so the promise resolves
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) resolve(false);
    });
  });
}