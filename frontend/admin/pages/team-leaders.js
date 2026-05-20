// frontend/admin/pages/team-leaders.js
// Team Leader management — list with stats + CRUD modal.

import * as api from '../api.js';
import {
  CURRENCY, escape, fmtTime, fmtAmount, toast, openModal, confirmDialog,
} from '../utils.js';

let _root;
let _tls = [];
let _services = [];
let _filter = { service_id: '', search: '', active: '' };

export async function init(root) {
  _root = root;
  paint();
  await Promise.all([loadServices(), load()]);
}
export function onShow()  { load(); }
export async function refresh() { return load(); }

async function loadServices() {
  try {
    const r = await api.get('/services');
    _services = r?.data || [];
  } catch {
    _services = [];
  }
}

async function load() {
  try {
    const r = await api.get('/team-leaders');
    _tls = r?.data || [];
    paintTable();
  } catch (e) {
    toast(e.message || 'Failed to load team leaders', 'error');
  }
}

function paint() {
  _root.innerHTML = `
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title">Team Leaders</div>
        <div class="filters">
          <select class="filter-input" id="tl-svc">
            <option value="">All services</option>
          </select>
          <select class="filter-input" id="tl-active">
            <option value="">All status</option>
            <option value="1">Active</option>
            <option value="0">Inactive</option>
          </select>
          <input class="filter-input" id="tl-search" type="search" placeholder="Search name/email…" style="min-width:160px">
          <button class="btn primary" id="tl-add">+ Add Team Leader</button>
        </div>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Name</th>
              <th>Email</th>
              <th>Service</th>
              <th>Team</th>
              <th>Active jobs</th>
              <th>Completed</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="tl-body">
            <tr class="empty-row"><td colspan="8">Loading…</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  `;

  _root.querySelector('#tl-svc').addEventListener('change', (e) => { _filter.service_id = e.target.value; paintTable(); });
  _root.querySelector('#tl-active').addEventListener('change', (e) => { _filter.active = e.target.value; paintTable(); });
  let qt;
  _root.querySelector('#tl-search').addEventListener('input', (e) => {
    clearTimeout(qt);
    qt = setTimeout(() => { _filter.search = e.target.value.trim().toLowerCase(); paintTable(); }, 250);
  });
  _root.querySelector('#tl-add').addEventListener('click', () => openTLModal());

  populateServiceFilter();
}

function populateServiceFilter() {
  const sel = _root.querySelector('#tl-svc');
  if (!sel || !_services.length) return;
  // Avoid duplicating options on subsequent paint() calls
  if (sel.options.length > 1) return;
  for (const s of _services) {
    const o = document.createElement('option');
    o.value = s.id; o.textContent = s.name;
    sel.appendChild(o);
  }
}

function paintTable() {
  populateServiceFilter();
  const body = _root.querySelector('#tl-body');
  if (!body) return;

  let rows = _tls.slice();
  if (_filter.service_id) rows = rows.filter((t) => t.service_id === _filter.service_id);
  if (_filter.active !== '') rows = rows.filter((t) => String(t.is_active) === _filter.active);
  if (_filter.search) {
    const q = _filter.search;
    rows = rows.filter((t) =>
      (t.name || '').toLowerCase().includes(q) ||
      (t.email || '').toLowerCase().includes(q)
    );
  }

  if (!rows.length) {
    body.innerHTML = '<tr class="empty-row"><td colspan="8">No team leaders match these filters</td></tr>';
    return;
  }

  body.innerHTML = rows.map((t) => `
    <tr>
      <td><strong>${escape(t.name)}</strong></td>
      <td style="color:var(--muted)">${escape(t.email)}</td>
      <td>${t.service_name ? `<span class="badge team_leader">${escape(t.service_name)}</span>` : '<span class="muted">—</span>'}</td>
      <td class="tabular">${t.employee_count ?? 0}</td>
      <td class="tabular">${t.active_jobs ?? 0}</td>
      <td class="tabular">${t.total_completed ?? 0}</td>
      <td>
        <span class="badge ${t.is_active ? 'active' : 'inactive'}">
          ${t.is_active ? 'Active' : 'Inactive'}
        </span>
      </td>
      <td>
        <button class="btn sm" data-edit="${escape(t.id)}">Edit</button>
        ${t.is_active
          ? `<button class="btn sm danger" data-deact="${escape(t.id)}">Off</button>`
          : `<button class="btn sm" data-react="${escape(t.id)}">On</button>`}
      </td>
    </tr>
  `).join('');

  body.querySelectorAll('[data-edit]').forEach((b) =>
    b.addEventListener('click', () => openTLModal(b.dataset.edit)));
  body.querySelectorAll('[data-deact]').forEach((b) =>
    b.addEventListener('click', () => toggleActive(b.dataset.deact, 0)));
  body.querySelectorAll('[data-react]').forEach((b) =>
    b.addEventListener('click', () => toggleActive(b.dataset.react, 1)));
}

async function toggleActive(id, makeActive) {
  const ok = await confirmDialog(makeActive ? 'Reactivate this team leader?' : 'Deactivate this team leader?');
  if (!ok) return;
  try {
    if (makeActive) await api.put('/users/' + id, { is_active: 1 });
    else            await api.del('/users/' + id);
    toast(makeActive ? 'Reactivated' : 'Deactivated', 'success');
    load();
  } catch (e) {
    toast(e.message || 'Action failed', 'error');
  }
}

// ─── Add / Edit modal ──────────────────────────────────────────────
async function openTLModal(id = null) {
  let tl = null;
  if (id) {
    try {
      const r = await api.get('/users/' + id);
      tl = r?.data;
    } catch (e) {
      toast(e.message || 'Failed to load', 'error');
      return;
    }
  }

  const svcOptions = _services.map((s) =>
    `<option value="${escape(s.id)}" ${tl?.service_id === s.id ? 'selected' : ''}>${escape(s.name)}</option>`
  ).join('');

  const body = `
    <input type="hidden" id="tl-id" value="${escape(tl?.id || '')}">
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Name *</label>
        <input class="form-input" id="tl-name" value="${escape(tl?.name || '')}" placeholder="Ahmed Al Mansoori">
      </div>
      <div class="form-group">
        <label class="form-label">Phone</label>
        <input class="form-input" id="tl-phone" value="${escape(tl?.phone || '')}" placeholder="+971501112233">
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Email *</label>
      <input class="form-input" id="tl-email" type="email" value="${escape(tl?.email || '')}" placeholder="tl.name@airportvas.ae">
    </div>
    <div class="form-group">
      <label class="form-label">Password ${tl ? '' : '*'}</label>
      <input class="form-input" id="tl-pwd" type="password" placeholder="${tl ? 'Leave blank to keep existing' : 'Min 8 characters'}">
    </div>
    <div class="form-group">
      <label class="form-label">Service * <span class="form-hint" style="margin-left:8px">Each team leader handles exactly one service</span></label>
      <select class="form-select" id="tl-service">
        <option value="">— Select —</option>
        ${svcOptions}
      </select>
    </div>
  `;

  const footer = `
    <button class="btn" data-cancel>Cancel</button>
    <button class="btn primary" id="tl-save">${tl ? 'Save changes' : 'Create team leader'}</button>
  `;

  const m = openModal({
    title: tl ? `Edit · ${tl.name}` : 'Add Team Leader',
    width: 540, body, footer,
  });

  m.overlay.querySelector('[data-cancel]').addEventListener('click', m.close);
  m.overlay.querySelector('#tl-save').addEventListener('click', async () => {
    const id    = m.overlay.querySelector('#tl-id').value;
    const name  = m.overlay.querySelector('#tl-name').value.trim();
    const email = m.overlay.querySelector('#tl-email').value.trim();
    const phone = m.overlay.querySelector('#tl-phone').value.trim();
    const pwd   = m.overlay.querySelector('#tl-pwd').value;
    const svc   = m.overlay.querySelector('#tl-service').value;

    if (!name)  { toast('Name is required', 'error'); return; }
    if (!email) { toast('Email is required', 'error'); return; }
    if (!svc)   { toast('Service is required', 'error'); return; }
    if (!id && (!pwd || pwd.length < 8)) {
      toast('Password (8+ chars) is required for new users', 'error');
      return;
    }

    const body = {
      name, email, phone: phone || null,
      role: 'team_leader',
      service_id: svc,
    };
    if (pwd) body.password = pwd;

    const btn = m.overlay.querySelector('#tl-save');
    btn.disabled = true; btn.textContent = 'Saving…';
    try {
      if (id) await api.put('/users/' + id, body);
      else    await api.post('/users', body);
      toast(id ? 'Updated' : 'Created', 'success');
      m.close();
      load();
    } catch (e) {
      toast(e.message || 'Save failed', 'error');
      btn.disabled = false; btn.textContent = tl ? 'Save changes' : 'Create team leader';
    }
  });
}
