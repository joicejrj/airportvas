// frontend/admin/pages/employees.js
// Employee management — list with filters + CRUD.

import * as api from '../api.js';
import {
  escape, fmtTime, toast, openModal, confirmDialog,
} from '../utils.js';

let _root;
let _items = [];
let _meta = {};
let _services = [];
let _tls = [];
let _filter = { service_id: '', team_leader_id: '', active: '', q: '' };
let _page = 1;
const PAGE_SIZE = 50;

export async function init(root) {
  _root = root;
  ensureCss();
  paint();
  await Promise.all([loadServices(), loadTLs(), load()]);
}
export function onShow()      { load(); }
export async function refresh() { return load(); }

async function loadServices() {
  try { _services = (await api.get('/services'))?.data || []; }
  catch { _services = []; }
  populateFilterDropdowns();
}
async function loadTLs() {
  try { _tls = (await api.get('/users/team-leaders'))?.data || []; }
  catch { _tls = []; }
  populateFilterDropdowns();
}

function populateFilterDropdowns() {
  const svc = _root.querySelector('#emp-svc');
  if (svc && svc.options.length <= 1 && _services.length) {
    for (const s of _services) {
      const o = document.createElement('option');
      o.value = s.id; o.textContent = s.name;
      svc.appendChild(o);
    }
  }
  const tl = _root.querySelector('#emp-tl');
  if (tl && tl.options.length <= 1 && _tls.length) {
    for (const t of _tls) {
      const o = document.createElement('option');
      o.value = t.id;
      o.textContent = t.service_name ? `${t.name} (${t.service_name})` : t.name;
      tl.appendChild(o);
    }
  }
}

async function load() {
  try {
    const qs = new URLSearchParams();
    if (_filter.service_id)     qs.set('service_id', _filter.service_id);
    if (_filter.team_leader_id) qs.set('team_leader_id', _filter.team_leader_id);
    if (_filter.active !== '')  qs.set('active', _filter.active);
    if (_filter.q)              qs.set('q', _filter.q);
    qs.set('page', String(_page));
    qs.set('limit', String(PAGE_SIZE));

    const r = await api.get('/employees?' + qs.toString());
    _items = r?.data?.items || [];
    _meta  = r?.data?.meta  || {};
    paintTable();
  } catch (e) {
    toast(e.message || 'Failed to load employees', 'error');
  }
}

function paint() {
  _root.innerHTML = `
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title">Employees</div>
        <div class="filters">
          <select class="filter-input" id="emp-svc">
            <option value="">All services</option>
          </select>
          <select class="filter-input" id="emp-tl">
            <option value="">All team leaders</option>
          </select>
          <select class="filter-input" id="emp-active">
            <option value="">All status</option>
            <option value="1" selected>Active only</option>
            <option value="0">Inactive only</option>
          </select>
          <input class="filter-input" id="emp-search" type="search" placeholder="Code, name, phone…" style="min-width:160px">
          <button class="btn primary" id="emp-add">+ Add Employee</button>
        </div>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Code</th>
              <th>Name</th>
              <th>Phone</th>
              <th>Service</th>
              <th>Team Leader</th>
              <th>Status</th>
              <th>Created</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="emp-body">
            <tr class="empty-row"><td colspan="8">Loading…</td></tr>
          </tbody>
        </table>
      </div>
      <div id="emp-pager" style="padding:14px 22px;border-top:1px solid var(--border);background:var(--hover-bg);display:flex;justify-content:space-between;align-items:center"></div>
    </div>
  `;

  // Default to active=1 in the filter UI to match default value
  _filter.active = '1';

  _root.querySelector('#emp-svc').addEventListener('change', (e) => { _filter.service_id = e.target.value; _page = 1; load(); });
  _root.querySelector('#emp-tl').addEventListener('change',  (e) => { _filter.team_leader_id = e.target.value; _page = 1; load(); });
  _root.querySelector('#emp-active').addEventListener('change', (e) => { _filter.active = e.target.value; _page = 1; load(); });
  let qt;
  _root.querySelector('#emp-search').addEventListener('input', (e) => {
    clearTimeout(qt);
    qt = setTimeout(() => { _filter.q = e.target.value.trim(); _page = 1; load(); }, 300);
  });
  _root.querySelector('#emp-add').addEventListener('click', () => openEmpModal());
}

function paintTable() {
  const body = _root.querySelector('#emp-body');
  if (!body) return;

  if (!_items.length) {
    body.innerHTML = '<tr class="empty-row"><td colspan="8">No employees match these filters</td></tr>';
    paintPager();
    return;
  }

  body.innerHTML = _items.map((e) => `
    <tr>
      <td class="mono"><strong>${escape(e.employee_code)}</strong></td>
      <td>
        <div class="emp-list-name">
          <span class="emp-list-avatar">
            ${e.image_path
              ? `<img src="${escape(resolveImg(e.image_path))}" alt="">`
              : `<svg viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg"><circle cx="20" cy="15" r="6" fill="currentColor" opacity=".5"/><path d="M8 36c1-7 6-11 12-11s11 4 12 11z" fill="currentColor" opacity=".5"/></svg>`}
          </span>
          <span>${escape(e.name)}</span>
        </div>
      </td>
      <td class="tabular" style="color:var(--muted)">${escape(e.phone || '—')}</td>
      <td>${e.service_name ? `<span class="badge team_leader">${escape(e.service_name)}</span>` : '<span class="muted">—</span>'}</td>
      <td>${escape(e.team_leader_name || '— unassigned —')}</td>
      <td>
        <span class="badge ${e.is_active ? 'active' : 'inactive'}">
          ${e.is_active ? 'Active' : 'Inactive'}
        </span>
      </td>
      <td style="color:var(--muted);font-size:12px">${fmtTime(e.created_at)}</td>
      <td>
        <button class="btn sm" data-edit="${escape(e.id)}">Edit</button>
        ${e.is_active
          ? `<button class="btn sm danger" data-del="${escape(e.id)}">Off</button>`
          : `<button class="btn sm" data-on="${escape(e.id)}">On</button>`}
      </td>
    </tr>
  `).join('');

  body.querySelectorAll('[data-edit]').forEach((b) =>
    b.addEventListener('click', () => openEmpModal(b.dataset.edit)));
  body.querySelectorAll('[data-del]').forEach((b) =>
    b.addEventListener('click', () => toggleActive(b.dataset.del, 0)));
  body.querySelectorAll('[data-on]').forEach((b) =>
    b.addEventListener('click', () => toggleActive(b.dataset.on, 1)));

  paintPager();
}

function paintPager() {
  const pager = _root.querySelector('#emp-pager');
  if (!pager) return;
  const total = _meta.total ?? _items.length;
  const totalPages = _meta.total_pages ?? 1;
  pager.innerHTML = `
    <div style="font-size:12px;color:var(--muted)">
      ${total > 0
        ? `Showing page <strong>${_meta.page || _page}</strong> of <strong>${totalPages}</strong> · ${total} ${total === 1 ? 'employee' : 'employees'}`
        : 'No employees'}
    </div>
    <div class="row" style="gap:8px">
      <button class="btn sm" id="pg-prev" ${_page <= 1 ? 'disabled' : ''}>← Prev</button>
      <button class="btn sm" id="pg-next" ${_page >= totalPages ? 'disabled' : ''}>Next →</button>
    </div>
  `;
  pager.querySelector('#pg-prev')?.addEventListener('click', () => { if (_page > 1) { _page -= 1; load(); } });
  pager.querySelector('#pg-next')?.addEventListener('click', () => { if (_page < totalPages) { _page += 1; load(); } });
}

async function toggleActive(id, makeActive) {
  const ok = await confirmDialog(makeActive ? 'Reactivate this employee?' : 'Deactivate this employee?');
  if (!ok) return;
  try {
    if (makeActive) await api.put('/employees/' + id, { is_active: 1 });
    else            await api.del('/employees/' + id);
    toast(makeActive ? 'Reactivated' : 'Deactivated', 'success');
    load();
  } catch (e) {
    toast(e.message || 'Action failed', 'error');
  }
}

// ─── Add / Edit modal ──────────────────────────────────────────────
async function openEmpModal(id = null) {
  let emp = null;
  if (id) {
    try { emp = (await api.get('/employees/' + id))?.data; }
    catch (e) { toast(e.message || 'Load failed', 'error'); return; }
  }

  const svcOptions = _services.map((s) =>
    `<option value="${escape(s.id)}" ${emp?.service_id === s.id ? 'selected' : ''}>${escape(s.name)}</option>`
  ).join('');

  const body = `
    <input type="hidden" id="emp-id" value="${escape(emp?.id || '')}">

    <!-- Photo picker — clickable circle that swaps to a file <input>.
         Stores the data URL on the hidden input; backend normalizes it. -->
    <div class="form-group emp-photo-group">
      <label class="form-label">Photo</label>
      <div class="emp-photo-row">
        <div class="emp-photo-thumb" id="emp-photo-thumb">
          ${emp?.image_path
            ? `<img src="${escape(resolveImg(emp.image_path))}" alt="">`
            : `<svg viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><circle cx="20" cy="15" r="6" fill="currentColor" opacity=".5"/><path d="M8 36c1-7 6-11 12-11s11 4 12 11z" fill="currentColor" opacity=".5"/></svg>`}
        </div>
        <div class="emp-photo-actions">
          <button type="button" class="btn" id="emp-photo-pick">${emp?.image_path ? 'Replace photo' : 'Upload photo'}</button>
          ${emp?.image_path ? '<button type="button" class="btn" id="emp-photo-clear">Remove</button>' : ''}
        </div>
        <input id="emp-photo-file" type="file" accept="image/jpeg,image/png,image/webp" style="display:none">
        <input id="emp-photo-data" type="hidden" value="">
      </div>
      <div class="form-hint">Shown to customers when they pick an employee. JPG/PNG/WebP up to ~4 MB.</div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Employee Code *</label>
        <input class="form-input mono" id="emp-code" value="${escape(emp?.employee_code || '')}"
               style="text-transform:uppercase" placeholder="CW001" maxlength="20">
        <div class="form-hint">Unique. TLs use this to find the employee in the field.</div>
      </div>
      <div class="form-group">
        <label class="form-label">Phone</label>
        <input class="form-input" id="emp-phone" value="${escape(emp?.phone || '')}" placeholder="+971501234567">
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Full Name *</label>
      <input class="form-input" id="emp-name" value="${escape(emp?.name || '')}" placeholder="Mohammed Iqbal">
    </div>
    <div class="form-group">
      <label class="form-label">Emirates ID</label>
      <input class="form-input mono" id="emp-eid" value="${escape(emp?.emirates_id || '')}" placeholder="784-1985-1234567-1">
      <div class="form-hint">Optional. 15 digits (with or without dashes).</div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Service *</label>
        <select class="form-select" id="emp-service">
          <option value="">— Select —</option>
          ${svcOptions}
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Team Leader</label>
        <select class="form-select" id="emp-tl-sel">
          <option value="">— Unassigned —</option>
        </select>
        <div class="form-hint">Filtered to TLs of the selected service.</div>
      </div>
    </div>
  `;

  const footer = `
    <button class="btn" data-cancel>Cancel</button>
    <button class="btn primary" id="emp-save">${emp ? 'Save changes' : 'Create employee'}</button>
  `;

  const m = openModal({
    title: emp ? `Edit · ${emp.name}` : 'Add Employee',
    width: 540, body, footer,
  });

  const svcEl  = m.overlay.querySelector('#emp-service');
  const tlEl   = m.overlay.querySelector('#emp-tl-sel');

  // Populate TL dropdown filtered by current service
  function populateTLs() {
    const sid = svcEl.value;
    // Clear all but the placeholder
    while (tlEl.options.length > 1) tlEl.remove(1);
    if (!sid) return;
    for (const t of _tls.filter((x) => x.service_id === sid && x.is_active !== false)) {
      const o = document.createElement('option');
      o.value = t.id; o.textContent = t.name;
      if (emp?.team_leader_id === t.id) o.selected = true;
      tlEl.appendChild(o);
    }
  }
  populateTLs();
  svcEl.addEventListener('change', () => populateTLs());

  // Uppercase the code as the admin types
  const codeEl = m.overlay.querySelector('#emp-code');
  codeEl.addEventListener('input', () => {
    codeEl.value = codeEl.value.toUpperCase().replace(/[^A-Z0-9_-]/g, '');
  });

  // ── Photo picker wiring ──
  // The "data" hidden input holds the new data URL OR an empty string.
  // Empty string + no original → no change. Empty string + original →
  // request clears the image. Data URL → upload.
  const photoFile  = m.overlay.querySelector('#emp-photo-file');
  const photoData  = m.overlay.querySelector('#emp-photo-data');
  const photoThumb = m.overlay.querySelector('#emp-photo-thumb');
  let photoCleared = false;
  m.overlay.querySelector('#emp-photo-pick')?.addEventListener('click', () => photoFile.click());
  photoFile.addEventListener('change', async () => {
    const file = photoFile.files?.[0];
    if (!file) return;
    if (file.size > 5 * 1024 * 1024) {
      toast('Photo too large (max 5 MB)', 'error');
      photoFile.value = '';
      return;
    }
    try {
      const dataUrl = await fileToDataUrl(file);
      photoData.value = dataUrl;
      photoCleared = false;
      photoThumb.innerHTML = `<img src="${dataUrl}" alt="">`;
    } catch {
      toast('Could not read the file', 'error');
    }
  });
  m.overlay.querySelector('#emp-photo-clear')?.addEventListener('click', () => {
    photoCleared = true;
    photoData.value = '';
    photoThumb.innerHTML = `<svg viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><circle cx="20" cy="15" r="6" fill="currentColor" opacity=".5"/><path d="M8 36c1-7 6-11 12-11s11 4 12 11z" fill="currentColor" opacity=".5"/></svg>`;
    // Hide the Remove button now that there's nothing to remove
    m.overlay.querySelector('#emp-photo-clear')?.remove();
    m.overlay.querySelector('#emp-photo-pick').textContent = 'Upload photo';
  });

  m.overlay.querySelector('[data-cancel]').addEventListener('click', m.close);
  m.overlay.querySelector('#emp-save').addEventListener('click', async () => {
    const code  = codeEl.value.trim();
    const name  = m.overlay.querySelector('#emp-name').value.trim();
    const phone = m.overlay.querySelector('#emp-phone').value.trim();
    const eid   = m.overlay.querySelector('#emp-eid').value.trim();
    const sid   = svcEl.value;
    const tlid  = tlEl.value;
    const newPhotoData = photoData.value;

    if (!code) { toast('Code is required', 'error'); return; }
    if (!name) { toast('Name is required', 'error'); return; }
    if (!sid)  { toast('Service is required', 'error'); return; }

    const payload = {
      employee_code:  code,
      name:           name,
      phone:          phone || null,
      emirates_id:    eid || null,
      service_id:     sid,
      team_leader_id: tlid || null,
    };

    // Image semantics:
    //   newPhotoData non-empty → send the data URL (backend stores it)
    //   photoCleared = true   → send empty string (backend sets NULL)
    //   neither               → omit image_path (no change)
    if (newPhotoData) {
      payload.image_path = newPhotoData;
    } else if (photoCleared) {
      payload.image_path = '';
    }

    const btn = m.overlay.querySelector('#emp-save');
    btn.disabled = true; btn.textContent = 'Saving…';
    try {
      if (emp) await api.put('/employees/' + emp.id, payload);
      else     await api.post('/employees', payload);
      toast(emp ? 'Updated' : 'Created', 'success');
      m.close();
      load();
    } catch (e) {
      toast(e.message || 'Save failed', 'error');
      btn.disabled = false; btn.textContent = emp ? 'Save changes' : 'Create employee';
    }
  });
}

// Tiny helpers for the photo picker
function fileToDataUrl(file) {
  return new Promise((resolve, reject) => {
    const r = new FileReader();
    r.onload  = () => resolve(r.result);
    r.onerror = () => reject(r.error || new Error('read failed'));
    r.readAsDataURL(file);
  });
}
function resolveImg(path) {
  if (!path) return '';
  if (/^https?:\/\//.test(path) || path.startsWith('data:')) return path;
  return location.origin + (path.startsWith('/') ? path : '/' + path);
}

// ─── CSS for the photo widget + list avatar ─────────────────────
function ensureCss() {
  if (document.getElementById('employees-css')) return;
  const css = `
    .emp-list-name {
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .emp-list-avatar {
      flex-shrink: 0;
      width: 32px; height: 32px;
      border-radius: 50%;
      background: var(--hover-bg, #f1f5f9);
      overflow: hidden;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      color: var(--muted, #94a3b8);
    }
    .emp-list-avatar img {
      width: 100%; height: 100%;
      object-fit: cover;
    }
    .emp-list-avatar svg {
      width: 100%; height: 100%;
    }

    .emp-photo-group { margin-bottom: 16px; }
    .emp-photo-row {
      display: flex;
      align-items: center;
      gap: 14px;
      margin-bottom: 6px;
    }
    .emp-photo-thumb {
      flex-shrink: 0;
      width: 72px;
      height: 72px;
      border-radius: 50%;
      background: var(--hover-bg, #f1f5f9);
      overflow: hidden;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      color: var(--muted, #94a3b8);
      border: 1.5px solid var(--border, #e5e7eb);
    }
    .emp-photo-thumb img {
      width: 100%; height: 100%;
      object-fit: cover;
    }
    .emp-photo-thumb svg {
      width: 60%; height: 60%;
    }
    .emp-photo-actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }
  `;
  const s = document.createElement('style');
  s.id = 'employees-css';
  s.textContent = css;
  document.head.appendChild(s);
}