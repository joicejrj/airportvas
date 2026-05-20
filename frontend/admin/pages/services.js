// frontend/admin/pages/services.js
// Service catalogue CRUD.

import * as api from '../api.js';
import {
  CURRENCY, escape, fmtAmount, fmtPrice, toast, openModal, confirmDialog,
} from '../utils.js';

let _root;
let _items = [];

export async function init(root) {
  _root = root;
  paint();
  await load();
}
export function onShow()      { load(); }
export async function refresh() { return load(); }

async function load() {
  try {
    // Force admin response (includes inactive) via Bearer token in api.js
    const r = await api.get('/services');
    _items = r?.data || [];
    paintTable();
  } catch (e) {
    toast(e.message || 'Failed to load services', 'error');
  }
}

function paint() {
  _root.innerHTML = `
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title">Services</div>
        <button class="btn primary" id="svc-add">+ Add Service</button>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Sort</th>
              <th>Name</th>
              <th>Description</th>
              <th>Price (${CURRENCY})</th>
              <th>Duration</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="svc-body">
            <tr class="empty-row"><td colspan="7">Loading…</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  `;
  _root.querySelector('#svc-add').addEventListener('click', () => openSvcModal());
}

function paintTable() {
  const body = _root.querySelector('#svc-body');
  if (!body) return;
  if (!_items.length) {
    body.innerHTML = '<tr class="empty-row"><td colspan="7">No services configured yet</td></tr>';
    return;
  }

  body.innerHTML = _items.map((s) => `
    <tr>
      <td class="mono tabular" style="color:var(--muted)">${s.sort_order ?? '—'}</td>
      <td><strong>${escape(s.name)}</strong></td>
      <td style="color:var(--muted);font-size:12px">${escape(s.description || '—')}</td>
      <td class="mono tabular">${fmtPrice(s.price || s.base_price)}</td>
      <td class="tabular">${s.duration_minutes ?? '—'} min</td>
      <td>
        <span class="badge ${s.is_active ? 'active' : 'inactive'}">
          ${s.is_active ? 'Active' : 'Inactive'}
        </span>
      </td>
      <td>
        <button class="btn sm" data-edit="${escape(s.id)}">Edit</button>
        ${s.is_active
          ? `<button class="btn sm danger" data-deact="${escape(s.id)}">Off</button>`
          : `<button class="btn sm" data-react="${escape(s.id)}">On</button>`}
      </td>
    </tr>
  `).join('');

  body.querySelectorAll('[data-edit]').forEach((b) =>
    b.addEventListener('click', () => openSvcModal(b.dataset.edit)));
  body.querySelectorAll('[data-deact]').forEach((b) =>
    b.addEventListener('click', () => toggleActive(b.dataset.deact, 0)));
  body.querySelectorAll('[data-react]').forEach((b) =>
    b.addEventListener('click', () => toggleActive(b.dataset.react, 1)));
}

async function toggleActive(id, makeActive) {
  const ok = await confirmDialog(makeActive ? 'Reactivate this service?' : 'Deactivate this service?');
  if (!ok) return;
  try {
    if (makeActive) await api.put('/services/' + id, { is_active: 1 });
    else            await api.del('/services/' + id);
    toast(makeActive ? 'Reactivated' : 'Deactivated', 'success');
    load();
  } catch (e) {
    toast(e.message || 'Action failed', 'error');
  }
}

async function openSvcModal(id = null) {
  const svc = id ? _items.find((x) => x.id === id) : null;

  const body = `
    <input type="hidden" id="s-id" value="${escape(svc?.id || '')}">
    <div class="form-group">
      <label class="form-label">Service Name *</label>
      <input class="form-input" id="s-name" value="${escape(svc?.name || '')}" placeholder="e.g. Car Wash">
    </div>
    <div class="form-group">
      <label class="form-label">Description</label>
      <textarea class="form-textarea" id="s-desc">${escape(svc?.description || '')}</textarea>
    </div>
    <div class="form-row three">
      <div class="form-group">
        <label class="form-label">Base Price (${CURRENCY}) *</label>
        <input class="form-input mono" id="s-price" type="number" step="0.01" min="0"
               value="${escape(svc?.base_price || svc?.price || '')}">
      </div>
      <div class="form-group">
        <label class="form-label">Duration (min)</label>
        <input class="form-input" id="s-duration" type="number" min="0"
               value="${escape(svc?.duration_minutes || 30)}">
      </div>
      <div class="form-group">
        <label class="form-label">Sort Order</label>
        <input class="form-input" id="s-sort" type="number" min="0"
               value="${escape(svc?.sort_order ?? 99)}">
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Icon (text/emoji)</label>
      <input class="form-input" id="s-icon" value="${escape(svc?.icon || 'wrench')}"
             placeholder="wrench, droplet, 🚗 …">
    </div>
  `;

  const footer = `
    <button class="btn" data-cancel>Cancel</button>
    <button class="btn primary" id="s-save">${svc ? 'Save changes' : 'Create service'}</button>
  `;

  const m = openModal({
    title: svc ? `Edit · ${svc.name}` : 'Add Service',
    width: 540, body, footer,
  });

  m.overlay.querySelector('[data-cancel]').addEventListener('click', m.close);
  m.overlay.querySelector('#s-save').addEventListener('click', async () => {
    const id = m.overlay.querySelector('#s-id').value;
    const payload = {
      name:             m.overlay.querySelector('#s-name').value.trim(),
      description:      m.overlay.querySelector('#s-desc').value.trim() || null,
      base_price:       parseFloat(m.overlay.querySelector('#s-price').value) || 0,
      duration_minutes: parseInt(m.overlay.querySelector('#s-duration').value || 30, 10),
      sort_order:       parseInt(m.overlay.querySelector('#s-sort').value || 99, 10),
      icon:             m.overlay.querySelector('#s-icon').value.trim() || 'wrench',
    };
    if (!payload.name)        { toast('Name is required', 'error'); return; }
    if (payload.base_price <= 0) { toast('Price must be > 0', 'error'); return; }

    const btn = m.overlay.querySelector('#s-save');
    btn.disabled = true; btn.textContent = 'Saving…';
    try {
      if (id) await api.put('/services/' + id, payload);
      else    await api.post('/services', payload);
      toast(id ? 'Updated' : 'Created', 'success');
      m.close();
      load();
    } catch (e) {
      toast(e.message || 'Save failed', 'error');
      btn.disabled = false; btn.textContent = svc ? 'Save changes' : 'Create service';
    }
  });
}
