// frontend/admin/pages/locations.js
// Parking locations CRUD.

import * as api from '../api.js';
import { escape, toast, openModal, confirmDialog } from '../utils.js';

let _root;
let _items = [];
let _filter = { airport: '' };

const AIRPORTS = ['DXB', 'AUH', 'SHJ', 'RKT', 'AAN', 'DWC'];

export async function init(root) {
  _root = root;
  paint();
  await load();
}
export function onShow()      { load(); }
export async function refresh() { return load(); }

async function load() {
  try {
    const qs = new URLSearchParams();
    if (_filter.airport) qs.set('airport', _filter.airport);
    const r = await api.get('/locations' + (qs.toString() ? '?' + qs.toString() : ''));
    _items = r?.data || [];
    paintTable();
  } catch (e) {
    toast(e.message || 'Failed to load locations', 'error');
  }
}

function paint() {
  _root.innerHTML = `
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title">Parking Locations</div>
        <div class="filters">
          <select class="filter-input" id="loc-airport">
            <option value="">All airports</option>
            ${AIRPORTS.map((a) => `<option value="${a}">${a}</option>`).join('')}
          </select>
          <button class="btn primary" id="loc-add">+ Add Location</button>
        </div>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Airport</th>
              <th>Terminal</th>
              <th>Code</th>
              <th>Name</th>
              <th>Zone</th>
              <th>Capacity</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="loc-body">
            <tr class="empty-row"><td colspan="8">Loading…</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  `;

  _root.querySelector('#loc-airport').addEventListener('change', (e) => {
    _filter.airport = e.target.value; load();
  });
  _root.querySelector('#loc-add').addEventListener('click', () => openLocModal());
}

function paintTable() {
  const body = _root.querySelector('#loc-body');
  if (!body) return;
  if (!_items.length) {
    body.innerHTML = '<tr class="empty-row"><td colspan="8">No parking locations configured</td></tr>';
    return;
  }

  body.innerHTML = _items.map((l) => `
    <tr>
      <td class="mono"><strong>${escape(l.airport || '—')}</strong></td>
      <td>${escape(l.terminal || '—')}</td>
      <td class="mono">${escape(l.code || '—')}</td>
      <td><strong>${escape(l.name)}</strong></td>
      <td style="color:var(--muted)">${escape(l.zone || '—')}</td>
      <td class="tabular">${l.capacity ?? '—'}</td>
      <td>
        <span class="badge ${l.is_active ? 'active' : 'inactive'}">
          ${l.is_active ? 'Active' : 'Inactive'}
        </span>
      </td>
      <td>
        <button class="btn sm" data-edit="${escape(l.id)}">Edit</button>
        ${l.is_active
          ? `<button class="btn sm danger" data-deact="${escape(l.id)}">Off</button>`
          : `<button class="btn sm" data-react="${escape(l.id)}">On</button>`}
      </td>
    </tr>
  `).join('');

  body.querySelectorAll('[data-edit]').forEach((b) =>
    b.addEventListener('click', () => openLocModal(b.dataset.edit)));
  body.querySelectorAll('[data-deact]').forEach((b) =>
    b.addEventListener('click', () => toggleActive(b.dataset.deact, 0)));
  body.querySelectorAll('[data-react]').forEach((b) =>
    b.addEventListener('click', () => toggleActive(b.dataset.react, 1)));
}

async function toggleActive(id, makeActive) {
  const ok = await confirmDialog(makeActive ? 'Reactivate this location?' : 'Deactivate this location?');
  if (!ok) return;
  try {
    if (makeActive) await api.put('/locations/' + id, { is_active: 1 });
    else            await api.del('/locations/' + id);
    toast(makeActive ? 'Reactivated' : 'Deactivated', 'success');
    load();
  } catch (e) {
    toast(e.message || 'Action failed', 'error');
  }
}

async function openLocModal(id = null) {
  const loc = id ? _items.find((x) => x.id === id) : null;

  const airportOptions = AIRPORTS.map((a) =>
    `<option value="${a}" ${loc?.airport === a ? 'selected' : ''}>${a}</option>`
  ).join('');

  const body = `
    <input type="hidden" id="l-id" value="${escape(loc?.id || '')}">
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Airport *</label>
        <select class="form-select" id="l-airport">
          ${airportOptions}
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Terminal</label>
        <input class="form-input" id="l-terminal" value="${escape(loc?.terminal || '')}" placeholder="T1, T2, T3">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Code</label>
        <input class="form-input mono" id="l-code" value="${escape(loc?.code || '')}" placeholder="LP-DXB-T1-A">
      </div>
      <div class="form-group">
        <label class="form-label">Zone</label>
        <input class="form-input" id="l-zone" value="${escape(loc?.zone || '')}" placeholder="Long stay, Short stay…">
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Name *</label>
      <input class="form-input" id="l-name" value="${escape(loc?.name || '')}" placeholder="e.g. Long Stay A">
    </div>
    <div class="form-group">
      <label class="form-label">Capacity</label>
      <input class="form-input" id="l-capacity" type="number" min="0" value="${escape(loc?.capacity || 0)}">
    </div>
    <div class="form-group">
      <label class="form-label">Description</label>
      <textarea class="form-textarea" id="l-desc">${escape(loc?.description || '')}</textarea>
    </div>
  `;

  const footer = `
    <button class="btn" data-cancel>Cancel</button>
    <button class="btn primary" id="l-save">${loc ? 'Save changes' : 'Create location'}</button>
  `;

  const m = openModal({
    title: loc ? `Edit · ${loc.name}` : 'Add Location',
    width: 560, body, footer,
  });

  m.overlay.querySelector('[data-cancel]').addEventListener('click', m.close);
  m.overlay.querySelector('#l-save').addEventListener('click', async () => {
    const id = m.overlay.querySelector('#l-id').value;
    const payload = {
      airport:     m.overlay.querySelector('#l-airport').value,
      terminal:    m.overlay.querySelector('#l-terminal').value.trim() || null,
      code:        m.overlay.querySelector('#l-code').value.trim() || null,
      zone:        m.overlay.querySelector('#l-zone').value.trim() || null,
      name:        m.overlay.querySelector('#l-name').value.trim(),
      capacity:    parseInt(m.overlay.querySelector('#l-capacity').value || 0, 10),
      description: m.overlay.querySelector('#l-desc').value.trim() || null,
    };
    if (!payload.name)    { toast('Name is required', 'error'); return; }
    if (!payload.airport) { toast('Airport is required', 'error'); return; }

    const btn = m.overlay.querySelector('#l-save');
    btn.disabled = true; btn.textContent = 'Saving…';
    try {
      if (id) await api.put('/locations/' + id, payload);
      else    await api.post('/locations', payload);
      toast(id ? 'Updated' : 'Created', 'success');
      m.close();
      load();
    } catch (e) {
      toast(e.message || 'Save failed', 'error');
      btn.disabled = false; btn.textContent = loc ? 'Save changes' : 'Create location';
    }
  });
}
