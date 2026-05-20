// frontend/admin/pages/orders.js
// Order list with rich filters, daily serial column, and detail modal.

import * as api from '../api.js';
import {
  CURRENCY, escape, fmtTime, fmtAmount, fmtPrice, toast, openModal, confirmDialog,
} from '../utils.js';
import { mountDateRange } from '../components/daterange.js';

let _root;
let _items   = [];
let _meta    = {};
let _services= [];
let _tls     = [];
let _filter  = {
  status: '', payment_status: '', service_id: '', team_leader_id: '',
  source: '', date: '', from: '', to: '', q: '',
};
let _page = 1;
let _dateCtl = null;
const PAGE_SIZE = 25;

export async function init(root) {
  _root = root;
  paint();
  await Promise.all([loadServices(), loadTLs(), load()]);
}
export function onShow()      { load(); }
export async function refresh() { return load(); }

async function loadServices() {
  try { _services = (await api.get('/services'))?.data || []; }
  catch { _services = []; }
  populateFilters();
}
async function loadTLs() {
  try { _tls = (await api.get('/users/team-leaders'))?.data || []; }
  catch { _tls = []; }
  populateFilters();
}

function populateFilters() {
  const svc = _root.querySelector('#ord-svc');
  if (svc && svc.options.length <= 1 && _services.length) {
    for (const s of _services) {
      const o = document.createElement('option');
      o.value = s.id; o.textContent = s.name;
      svc.appendChild(o);
    }
  }
  const tl = _root.querySelector('#ord-tl');
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
    if (_filter.payment_status) qs.set('payment_status', _filter.payment_status);
    if (_filter.service_id)     qs.set('service_id', _filter.service_id);
    if (_filter.team_leader_id) qs.set('team_leader_id', _filter.team_leader_id);
    if (_filter.source)         qs.set('source', _filter.source);
    if (_filter.date)           qs.set('date', _filter.date);
    if (_filter.from)           qs.set('from', _filter.from);
    if (_filter.to)             qs.set('to', _filter.to);
    if (_filter.q)              qs.set('q', _filter.q);
    qs.set('page', String(_page));
    qs.set('limit', String(PAGE_SIZE));

    const r = await api.get('/orders?' + qs.toString());
    _items = r?.data?.items || [];
    _meta  = r?.data?.meta  || {};
    paintTable();
    loadSummary();
  } catch (e) {
    toast(e.message || 'Failed to load orders', 'error');
  }
}

async function loadSummary() {
  try {
    const qs = new URLSearchParams();
    if (_filter.date) qs.set('date', _filter.date);
    if (_filter.from) qs.set('from', _filter.from);
    if (_filter.to)   qs.set('to', _filter.to);
    const r = await api.get('/orders/summary?' + qs.toString());
    const d = r?.data || {};
    const grid = _root.querySelector('#ord-summary');
    if (grid) {
      grid.innerHTML = `
        <div class="stat-card">
          <div class="stat-label">Total Orders</div>
          <div class="stat-value tabular">${d.total_orders ?? 0}</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Gross (${CURRENCY})</div>
          <div class="stat-value tabular">${fmtPrice(d.gross_amount || 0)}</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Paid (${CURRENCY})</div>
          <div class="stat-value tabular">${fmtPrice(d.amount_paid || 0)}</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Unpaid (${CURRENCY})</div>
          <div class="stat-value tabular">${fmtPrice(d.amount_unpaid || 0)}</div>
        </div>`;
    }
  } catch { /* ignore */ }
}

function paint() {
  _root.innerHTML = `
    <div id="ord-summary" class="stats-grid"></div>

    <div class="panel">
      <div class="panel-header">
        <div class="panel-title">Orders</div>
        <div class="filters">
          <select class="filter-input" id="ord-pay">
            <option value="">All payments</option>
            <option value="paid">Paid</option>
            <option value="unpaid">Unpaid</option>
          </select>
          <select class="filter-input" id="ord-svc">
            <option value="">All services</option>
          </select>
          <select class="filter-input" id="ord-tl">
            <option value="">All team leaders</option>
          </select>
          <select class="filter-input" id="ord-source">
            <option value="">All sources</option>
            <option value="team_leader">Team Leader</option>
            <option value="customer_qr">Customer · QR</option>
            <option value="customer_web">Customer · Web</option>
          </select>
          <div id="ord-daterange"></div>
          <input class="filter-input" id="ord-search" type="search" placeholder="Order #, customer name, phone" style="min-width:180px">
        </div>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Order #</th>
              <th>Serial</th>
              <th>Service</th>
              <th>Team Leader</th>
              <th>Total</th>
              <th>Payment</th>
              <th>Status</th>
              <th>Created</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="ord-body">
            <tr class="empty-row"><td colspan="10">Loading…</td></tr>
          </tbody>
        </table>
      </div>
      <div id="ord-pager" style="padding:14px 22px;border-top:1px solid var(--border);background:var(--hover-bg);display:flex;justify-content:space-between;align-items:center"></div>
    </div>
  `;

  _root.querySelector('#ord-pay').addEventListener('change', (e) => { _filter.payment_status = e.target.value; _page = 1; load(); });
  _root.querySelector('#ord-svc').addEventListener('change', (e) => { _filter.service_id = e.target.value; _page = 1; load(); });
  _root.querySelector('#ord-tl').addEventListener('change', (e) => { _filter.team_leader_id = e.target.value; _page = 1; load(); });
  _root.querySelector('#ord-source').addEventListener('change', (e) => { _filter.source = e.target.value; _page = 1; load(); });

  // Shared date-range picker. We use from/to (not the old single-date
  // _filter.date) because the popover natively expresses ranges. Single-
  // day filters still work — both presets and custom inputs can set
  // from==to for "today" style queries.
  if (_dateCtl) { try { _dateCtl.destroy(); } catch {} }
  _dateCtl = mountDateRange({
    anchor: _root.querySelector('#ord-daterange'),
    value:  { from: _filter.from, to: _filter.to },
    onChange: (range) => {
      _filter.from = range.from;
      _filter.to   = range.to;
      _filter.date = '';   // clear legacy single-date param so it doesn't leak
      _page = 1; load();
    },
  });

  let qt;
  _root.querySelector('#ord-search').addEventListener('input', (e) => {
    clearTimeout(qt);
    qt = setTimeout(() => { _filter.q = e.target.value.trim(); _page = 1; load(); }, 300);
  });
}

function paintTable() {
  const body = _root.querySelector('#ord-body');
  if (!body) return;
  if (!_items.length) {
    body.innerHTML = '<tr class="empty-row"><td colspan="10">No orders match these filters</td></tr>';
    paintPager();
    return;
  }

  body.innerHTML = _items.map((o, i) => {
    const startIdx = (_meta.page - 1) * (_meta.limit || PAGE_SIZE);
    const rowNum = startIdx + i + 1;
    const serials = (o.daily_serials || '').toString().split(',').filter(Boolean);
    const serialChip = serials.length
      ? serials.map((s) => `<span class="serial-pill">#${escape(s)}</span>`).join(' ')
      : '<span class="muted">—</span>';
    const statuses = (o.service_statuses || '').toString().split(',').filter(Boolean);
    const statusBadge = statuses.length
      ? statuses.map((s) => `<span class="badge ${escape(s)}">${escape(s.replace('_',' '))}</span>`).join(' ')
      : '<span class="muted">—</span>';
    return `
      <tr data-id="${escape(o.id)}">
        <td class="mono" style="color:var(--muted)">${rowNum}</td>
        <td class="mono"><strong>${escape(String(o.order_number || '—'))}</strong></td>
        <td>${serialChip}</td>
        <td>${escape(o.service_names || '—')}</td>
        <td>${escape(o.team_leader_names || '—')}</td>
        <td class="mono tabular">${fmtAmount(o.total_amount)}</td>
        <td><span class="badge ${escape(o.payment_status)}">${escape(o.payment_status)}</span></td>
        <td>${statusBadge}</td>
        <td style="color:var(--muted);font-size:12px">${fmtTime(o.created_at)}</td>
        <td><button class="btn sm" data-view="${escape(o.id)}">View</button></td>
      </tr>`;
  }).join('');

  body.querySelectorAll('[data-view]').forEach((b) =>
    b.addEventListener('click', () => openDetail(b.dataset.view)));

  paintPager();
}

function paintPager() {
  const pager = _root.querySelector('#ord-pager');
  if (!pager) return;
  const total = _meta.total ?? 0;
  const totalPages = _meta.total_pages ?? 1;
  pager.innerHTML = `
    <div style="font-size:12px;color:var(--muted)">
      ${total > 0
        ? `Page <strong>${_meta.page || _page}</strong> of <strong>${totalPages}</strong> · ${total} ${total === 1 ? 'order' : 'orders'}`
        : 'No orders'}
    </div>
    <div class="row" style="gap:8px">
      <button class="btn sm" id="pg-prev" ${_page <= 1 ? 'disabled' : ''}>← Prev</button>
      <button class="btn sm" id="pg-next" ${_page >= totalPages ? 'disabled' : ''}>Next →</button>
    </div>
  `;
  pager.querySelector('#pg-prev')?.addEventListener('click', () => { if (_page > 1) { _page -= 1; load(); } });
  pager.querySelector('#pg-next')?.addEventListener('click', () => { if (_page < totalPages) { _page += 1; load(); } });
}

// ─── Detail modal ──────────────────────────────────────────────────
async function openDetail(id) {
  let data;
  try {
    const r = await api.get('/orders/' + id);
    data = r?.data;
  } catch (e) {
    toast(e.message || 'Failed to load', 'error');
    return;
  }
  if (!data) return;

  const svcs = data.services || [];
  const pays = data.payments || [];

  const services = svcs.map((s) => `
    <div style="padding:10px 0;border-bottom:1px dotted var(--border)">
      <div style="display:flex;justify-content:space-between;gap:8px">
        <div><strong>${escape(s.service_name)}</strong>
          ${s.daily_serial ? `<span class="serial-pill" style="margin-left:6px">#${escape(s.daily_serial)}</span>` : ''}
        </div>
        <div class="mono tabular">${fmtAmount(s.price)}</div>
      </div>
      <div style="font-size:12px;color:var(--muted);margin-top:4px">
        <span class="badge ${escape(s.status)}">${escape(s.status.replace('_',' '))}</span>
        ${s.team_leader_name ? ' · TL: ' + escape(s.team_leader_name) : ''}
        ${s.employee_code ? ' · ' + escape(s.employee_code) + ' ' + escape(s.employee_name || '') : ''}
      </div>
    </div>`).join('');

  const payments = pays.length ? pays.map((p) => `
    <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px dotted var(--border);font-size:13px">
      <div><strong>${escape(p.payment_method)}</strong>${p.transaction_ref ? ' · <span class="mono" style="color:var(--muted)">' + escape(p.transaction_ref) + '</span>' : ''}</div>
      <div class="mono tabular">${fmtAmount(p.amount)}</div>
    </div>`).join('') : '<div style="color:var(--muted);font-style:italic;padding:8px 0">No payments recorded</div>';

  const body = `
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:18px">
      <div>
        <div class="form-label">Order #</div>
        <div class="mono" style="font-size:15px"><strong>${escape(String(data.order_number || '—'))}</strong></div>
      </div>
      <div>
        <div class="form-label">Created</div>
        <div>${fmtTime(data.created_at)}</div>
      </div>
      ${data.customer_phone ? `
      <div>
        <div class="form-label">Contact</div>
        <div><span style="color:var(--muted)">${escape(data.customer_phone)}</span></div>
      </div>` : ''}
      <div>
        <div class="form-label">Source</div>
        <div>${escape((data.source || '—').replace('_',' '))}</div>
      </div>
    </div>

    <h4 style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);margin-bottom:6px">Services</h4>
    <div style="margin-bottom:16px">${services || '<div style="color:var(--muted)">No services</div>'}</div>

    <h4 style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);margin-bottom:6px">Payments</h4>
    <div style="margin-bottom:8px">${payments}</div>

    <div style="display:flex;justify-content:space-between;padding:10px 0;border-top:2px solid var(--ink);margin-top:6px">
      <div><strong>Total</strong></div>
      <div class="mono tabular"><strong>${fmtAmount(data.total_amount)}</strong></div>
    </div>
    <div style="display:flex;justify-content:space-between;padding:4px 0;color:var(--success)">
      <div>Paid</div>
      <div class="mono tabular">${fmtAmount(data.paid_amount)}</div>
    </div>
  `;

  // Cancel-order UI removed by request. The DB still has a 'cancelled'
  // status (legacy data), but no operator can create new cancellations.
  const footer = `
    <button class="btn" data-close-x>Close</button>
  `;

  const m = openModal({
    title: `Order #${data.order_number || '—'}`,
    width: 640, body, footer,
  });
  m.overlay.querySelector('[data-close-x]').addEventListener('click', m.close);
}