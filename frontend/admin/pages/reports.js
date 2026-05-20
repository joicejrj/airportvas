// frontend/admin/pages/reports.js
// Five tabs: Orders · Employees · Team Leaders · Services · Payments.
// Each tab has a date range filter, a detailed table, and an Export CSV button.

import * as api from '../api.js';
import {
  CURRENCY, escape, escapeAttr, fmtPrice, fmtDate, fmtTime, toast,
} from '../utils.js';
import { mountDateRange } from '../components/daterange.js';

let _root;
let _tab = 'orders';
let _dateCtl = null;

// Filter state — shared across tabs where it makes sense
const _f = {
  from: defaultFrom(),
  to:   defaultTo(),
  // Orders-specific
  status:          '',
  payment_status:  '',
  payment_method:  '',
  service_id:      '',
  team_leader_id:  '',
  employee_id:     '',
};

// Cached dropdown options
let _services = [];
let _tls      = [];
let _employees = [];

// ─── Lifecycle ─────────────────────────────────────────────────────
export async function init(root) {
  _root = root;
  injectStyles();
  paintShell();
  // Load filter options in parallel (best-effort; failure is OK)
  await Promise.allSettled([loadServices(), loadTLs(), loadEmployees()]);
  loadTab();
}
export function onShow()        { loadTab(); }
export async function refresh() { return loadTab(); }

async function loadServices() {
  try { _services = (await api.get('/services?active=1'))?.data || []; }
  catch { _services = []; }
}
async function loadTLs() {
  try { _tls = (await api.get('/users/team-leaders'))?.data || []; }
  catch { _tls = []; }
}
async function loadEmployees() {
  try {
    const r = await api.get('/employees?active=1&limit=200');
    _employees = r?.data?.items || r?.data || [];
  } catch { _employees = []; }
}

// ─── Shell ─────────────────────────────────────────────────────────
function paintShell() {
  _root.innerHTML = `
    <div class="panel" style="padding:0 0 8px">
      <div class="panel-header" style="border-bottom:none">
        <div class="row" style="gap:6px;flex-wrap:wrap">
          ${tabBtn('orders',   'Orders')}
          ${tabBtn('employee', 'By Employee')}
          ${tabBtn('tl',       'By Team Leader')}
          ${tabBtn('service',  'By Service')}
          ${tabBtn('payments', 'Payments')}
        </div>
      </div>
    </div>
    <div id="rep-content"></div>
  `;

  _root.querySelectorAll('[data-tab]').forEach((b) =>
    b.addEventListener('click', () => { _tab = b.dataset.tab; paintShell(); loadTab(); }));
}

function tabBtn(id, label) {
  return `<button class="btn ${_tab === id ? 'primary' : ''}" data-tab="${id}">${label}</button>`;
}

async function loadTab() {
  const host = _root.querySelector('#rep-content');
  if (!host) return;
  host.innerHTML = `<div class="panel"><div class="panel-body" style="padding:32px;text-align:center;color:var(--muted)">Loading…</div></div>`;
  try {
    if      (_tab === 'orders')   await renderOrders(host);
    else if (_tab === 'employee') await renderEmployees(host);
    else if (_tab === 'tl')       await renderTLs(host);
    else if (_tab === 'service')  await renderServices(host);
    else if (_tab === 'payments') await renderPayments(host);
  } catch (e) {
    host.innerHTML = `<div class="panel"><div class="panel-body" style="padding:24px;color:var(--bad)">${escape(e.message || 'Report failed')}</div></div>`;
    toast(e.message || 'Report failed', 'error');
  }
}

// ─── Shared filter bar ─────────────────────────────────────────────
function dateRangeBar(extra = '') {
  return `
    <div class="filters">
      <div id="f-daterange"></div>
      ${extra}
      <button class="btn primary" id="f-apply">Apply</button>
      <button class="btn"          id="f-csv">⬇ Export CSV</button>
    </div>
  `;
}

function wireFilterBar(host, onApply, csvUrl) {
  // Mount the shared date-range picker. Destroy any prior controller
  // first so re-rendering a tab doesn't leak listeners.
  if (_dateCtl) { try { _dateCtl.destroy(); } catch {} }
  const anchor = host.querySelector('#f-daterange');
  if (anchor) {
    _dateCtl = mountDateRange({
      anchor,
      value: { from: _f.from, to: _f.to },
      onChange: (range) => {
        _f.from = range.from;
        _f.to   = range.to;
        // Don't auto-reload here — reports use an explicit Apply button.
        // The user expects to set range + other filters and then commit.
      },
    });
  }
  host.querySelector('#f-apply')?.addEventListener('click', onApply);
  host.querySelector('#f-csv')  ?.addEventListener('click', () => downloadCSV(csvUrl()));
}

// Trigger a CSV download via the admin api helper (handles auth header & filename).
function downloadCSV(path) {
  toast('Preparing export…');
  api.downloadFile(path, 'report.csv')
    .then(() => toast('Export ready', 'success'))
    .catch((e) => toast(e.message || 'Could not export', 'error'));
}

// ─── Orders tab ────────────────────────────────────────────────────
async function renderOrders(host) {
  // Build extra filter controls — service / TL / employee / payment / status
  const svcOptions = ['<option value="">All services</option>']
    .concat(_services.map((s) => `<option value="${escapeAttr(s.id)}" ${_f.service_id === s.id ? 'selected' : ''}>${escape(s.name)}</option>`))
    .join('');
  const tlOptions  = ['<option value="">All team leaders</option>']
    .concat(_tls.map((u) => `<option value="${escapeAttr(u.id)}" ${_f.team_leader_id === u.id ? 'selected' : ''}>${escape(u.name)}</option>`))
    .join('');
  const empOptions = ['<option value="">All employees</option>']
    .concat(_employees.map((e) => `<option value="${escapeAttr(e.id)}" ${_f.employee_id === e.id ? 'selected' : ''}>${escape(e.employee_code + ' · ' + e.name)}</option>`))
    .join('');

  const extra = `
    <select class="filter-input" id="f-status">
      <option value="">All statuses</option>
      ${['pending','accepted','in_progress','completed','cancelled','rejected']
        .map((s) => `<option value="${s}" ${_f.status === s ? 'selected' : ''}>${labelStatus(s)}</option>`).join('')}
    </select>
    <select class="filter-input" id="f-pstatus">
      <option value="">All payment states</option>
      ${['paid','unpaid']
        .map((s) => `<option value="${s}" ${_f.payment_status === s ? 'selected' : ''}>${s[0].toUpperCase()+s.slice(1)}</option>`).join('')}
    </select>
    <select class="filter-input" id="f-pmethod">
      <option value="">All methods</option>
      ${['cash','card','online']
        .map((s) => `<option value="${s}" ${_f.payment_method === s ? 'selected' : ''}>${s[0].toUpperCase()+s.slice(1)}</option>`).join('')}
    </select>
    <select class="filter-input" id="f-svc">${svcOptions}</select>
    <select class="filter-input" id="f-tl">${tlOptions}</select>
    <select class="filter-input" id="f-emp">${empOptions}</select>
  `;

  host.innerHTML = `
    <div id="ord-stats" class="stats-grid"></div>
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title">Orders · Detailed list</div>
        ${dateRangeBar(extra)}
      </div>
      <div class="table-wrap">
        <table class="report-table">
          <thead>
            <tr>
              <th>Order #</th>
              <th>Serial</th>
              <th>Date / Time</th>
              <th>Service</th>
              <th>Team Leader</th>
              <th>Employee</th>
              <th>Status</th>
              <th>Payment</th>
              <th class="num">Total</th>
              <th class="num">Paid</th>
              <th class="num">Balance</th>
            </tr>
          </thead>
          <tbody id="ord-body"><tr class="empty-row"><td colspan="11">Loading…</td></tr></tbody>
        </table>
      </div>
    </div>
  `;

  wireFilterBar(host, () => {
    _f.status         = host.querySelector('#f-status').value;
    _f.payment_status = host.querySelector('#f-pstatus').value;
    _f.payment_method = host.querySelector('#f-pmethod').value;
    _f.service_id     = host.querySelector('#f-svc').value;
    _f.team_leader_id = host.querySelector('#f-tl').value;
    _f.employee_id    = host.querySelector('#f-emp').value;
    renderOrders(host);
  }, () => '/reports/orders' + ordersQuery(true));

  const res = await api.get('/reports/orders' + ordersQuery(false));
  const data = res?.data || {};
  const rows = data.rows || [];
  const totals = data.totals || {};

  // Stat cards
  const stats = host.querySelector('#ord-stats');
  stats.innerHTML = `
    ${statCard('Orders',  String(totals.rows ?? rows.length))}
    ${statCard('Total revenue', CURRENCY + ' ' + fmtPrice(totals.total_amount))}
    ${statCard('Collected',     CURRENCY + ' ' + fmtPrice(totals.paid_amount))}
    ${statCard('Balance due',   CURRENCY + ' ' + fmtPrice(totals.balance), totals.balance > 0 ? 'warn' : '')}
  `;

  const body = host.querySelector('#ord-body');
  if (!rows.length) {
    body.innerHTML = '<tr class="empty-row"><td colspan="11">No orders match these filters.</td></tr>';
    return;
  }
  body.innerHTML = rows.map((r) => `
    <tr>
      <td class="mono">${escape(r.order_number)}</td>
      <td class="mono"><b>#${escape(r.daily_serial || '—')}</b></td>
      <td>${fmtTime(r.created_at)}</td>
      <td>${escape(r.service_name || '—')}</td>
      <td>${escape(r.team_leader_name || '—')}</td>
      <td>${r.employee_code ? `<span class="mono">${escape(r.employee_code)}</span> · ${escape(r.employee_name || '')}` : '—'}</td>
      <td>${statusBadge(r.job_status)}</td>
      <td>${paymentBadge(r.payment_status)}${r.payment_methods ? ` <span class="muted">(${escape(r.payment_methods)})</span>` : ''}</td>
      <td class="num mono">${fmtPrice(r.total_amount)}</td>
      <td class="num mono">${fmtPrice(r.paid_amount)}</td>
      <td class="num mono ${parseFloat(r.balance) > 0 ? 'cell-bad' : ''}">${fmtPrice(r.balance)}</td>
    </tr>
  `).join('');
}

function ordersQuery(forCsv) {
  const q = new URLSearchParams();
  q.set('from', _f.from); q.set('to', _f.to);
  if (_f.status)         q.set('status', _f.status);
  if (_f.payment_status) q.set('payment_status', _f.payment_status);
  if (_f.payment_method) q.set('payment_method', _f.payment_method);
  if (_f.service_id)     q.set('service_id', _f.service_id);
  if (_f.team_leader_id) q.set('team_leader_id', _f.team_leader_id);
  if (_f.employee_id)    q.set('employee_id', _f.employee_id);
  if (forCsv) q.set('format', 'csv');
  return '?' + q.toString();
}

// ─── By Employee tab ───────────────────────────────────────────────
async function renderEmployees(host) {
  const svcOptions = ['<option value="">All services</option>']
    .concat(_services.map((s) => `<option value="${escapeAttr(s.id)}" ${_f.service_id === s.id ? 'selected' : ''}>${escape(s.name)}</option>`))
    .join('');
  // TL options reuse the _tls list loaded at init (also used on the
  // Orders tab). Filtering by TL narrows the employee performance
  // table to that TL's direct reports via employees.team_leader_id.
  const tlOptions = ['<option value="">All team leaders</option>']
    .concat(_tls.map((u) => `<option value="${escapeAttr(u.id)}" ${_f.team_leader_id === u.id ? 'selected' : ''}>${escape(u.name)}</option>`))
    .join('');

  host.innerHTML = `
    <div id="emp-stats" class="stats-grid"></div>
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title">Performance by Employee</div>
        ${dateRangeBar(`
          <select class="filter-input" id="f-tl">${tlOptions}</select>
          <select class="filter-input" id="f-svc">${svcOptions}</select>
        `)}
      </div>
      <div class="table-wrap">
        <table class="report-table">
          <thead>
            <tr>
              <th>Code</th>
              <th>Name</th>
              <th>Service</th>
              <th>Team Leader</th>
              <th class="num">Jobs</th>
              <th class="num">Cash</th>
              <th class="num">Card</th>
              <th class="num">Online</th>
              <th class="num">Paid</th>
              <th class="num">Unpaid</th>
              <th class="num">Revenue</th>
            </tr>
          </thead>
          <tbody id="emp-body"><tr class="empty-row"><td colspan="11">Loading…</td></tr></tbody>
        </table>
      </div>
    </div>
  `;

  wireFilterBar(host, () => {
    _f.service_id     = host.querySelector('#f-svc').value;
    _f.team_leader_id = host.querySelector('#f-tl').value;
    renderEmployees(host);
  }, () => byEmployeeQuery(true));

  const res    = await api.get(byEmployeeQuery(false));
  const data   = res?.data || {};
  const rows   = data.rows || [];
  const totals = data.totals || {};

  host.querySelector('#emp-stats').innerHTML = `
    ${statCard('Employees',     String(totals.employees ?? rows.length))}
    ${statCard('Jobs total',    String(totals.jobs_total ?? 0))}
    ${statCard('Paid',          CURRENCY + ' ' + fmtPrice(totals.paid))}
    ${statCard('Unpaid',        CURRENCY + ' ' + fmtPrice(totals.unpaid))}
  `;

  const body = host.querySelector('#emp-body');
  if (!rows.length) {
    body.innerHTML = '<tr class="empty-row"><td colspan="11">No data for this range.</td></tr>';
    return;
  }
  body.innerHTML = rows.map((r) => `
    <tr>
      <td class="mono">${escape(r.employee_code || '—')}</td>
      <td><b>${escape(r.employee_name || '—')}</b>${r.is_active == 0 ? ' <span class="muted">(inactive)</span>' : ''}</td>
      <td>${escape(r.service_name || '—')}</td>
      <td>${escape(r.team_leader_name || '—')}</td>
      <td class="num mono">${escape(r.jobs_total || 0)}</td>
      <td class="num mono">${fmtPrice(r.cash)}</td>
      <td class="num mono">${fmtPrice(r.card)}</td>
      <td class="num mono">${fmtPrice(r.online)}</td>
      <td class="num mono"><b>${fmtPrice(r.paid)}</b></td>
      <td class="num mono ${parseFloat(r.unpaid) > 0 ? 'amt-unpaid' : 'muted'}">${fmtPrice(r.unpaid)}</td>
      <td class="num mono"><b>${fmtPrice(r.revenue)}</b></td>
    </tr>
  `).join('');
}

function byEmployeeQuery(forCsv) {
  const q = new URLSearchParams();
  q.set('from', _f.from); q.set('to', _f.to);
  if (_f.service_id)     q.set('service_id',     _f.service_id);
  if (_f.team_leader_id) q.set('team_leader_id', _f.team_leader_id);
  if (forCsv) q.set('format', 'csv');
  return '/reports/by-employee?' + q.toString();
}

// ─── By Team Leader tab ────────────────────────────────────────────
async function renderTLs(host) {
  host.innerHTML = `
    <div id="tl-stats" class="stats-grid"></div>
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title">Performance by Team Leader</div>
        ${dateRangeBar('')}
      </div>
      <div class="table-wrap">
        <table class="report-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Service</th>
              <th class="num">Team Size</th>
              <th class="num">Jobs</th>
              <th class="num">Cash</th>
              <th class="num">Card</th>
              <th class="num">Online</th>
              <th class="num">Paid</th>
              <th class="num">Unpaid</th>
              <th class="num">Revenue</th>
            </tr>
          </thead>
          <tbody id="tl-body"><tr class="empty-row"><td colspan="10">Loading…</td></tr></tbody>
        </table>
      </div>
    </div>
  `;

  wireFilterBar(host, () => renderTLs(host), () => byTLQuery(true));

  const res    = await api.get(byTLQuery(false));
  const data   = res?.data || {};
  const rows   = data.rows || [];
  const totals = data.totals || {};

  host.querySelector('#tl-stats').innerHTML = `
    ${statCard('Team leaders',  String(totals.team_leaders ?? rows.length))}
    ${statCard('Jobs total',    String(totals.jobs_total ?? 0))}
    ${statCard('Paid',          CURRENCY + ' ' + fmtPrice(totals.paid))}
    ${statCard('Unpaid',        CURRENCY + ' ' + fmtPrice(totals.unpaid))}
  `;

  const body = host.querySelector('#tl-body');
  if (!rows.length) {
    body.innerHTML = '<tr class="empty-row"><td colspan="10">No data for this range.</td></tr>';
    return;
  }
  body.innerHTML = rows.map((r) => `
    <tr>
      <td><b>${escape(r.team_leader_name || '—')}</b><div class="muted small">${escape(r.email || '')}</div></td>
      <td>${escape(r.service_name || '—')}</td>
      <td class="num mono">${escape(r.team_size || 0)}</td>
      <td class="num mono">${escape(r.jobs_total || 0)}</td>
      <td class="num mono">${fmtPrice(r.cash)}</td>
      <td class="num mono">${fmtPrice(r.card)}</td>
      <td class="num mono">${fmtPrice(r.online)}</td>
      <td class="num mono"><b>${fmtPrice(r.paid)}</b></td>
      <td class="num mono ${parseFloat(r.unpaid) > 0 ? 'amt-unpaid' : 'muted'}">${fmtPrice(r.unpaid)}</td>
      <td class="num mono"><b>${fmtPrice(r.revenue)}</b></td>
    </tr>
  `).join('');
}

function byTLQuery(forCsv) {
  const q = new URLSearchParams();
  q.set('from', _f.from); q.set('to', _f.to);
  if (forCsv) q.set('format', 'csv');
  return '/reports/by-team-leader?' + q.toString();
}

// ─── By Service tab ────────────────────────────────────────────────
async function renderServices(host) {
  host.innerHTML = `
    <div id="svc-stats" class="stats-grid"></div>
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title">Performance by Service</div>
        ${dateRangeBar('')}
      </div>
      <div class="table-wrap">
        <table class="report-table">
          <thead>
            <tr>
              <th>Service</th>
              <th class="num">Base Price</th>
              <th class="num">Jobs</th>
              <th class="num">Completed</th>
              <th class="num">Cancelled</th>
              <th class="num">Cash</th>
              <th class="num">Card</th>
              <th class="num">Online</th>
              <th class="num">Revenue</th>
              <th class="num">% of total</th>
            </tr>
          </thead>
          <tbody id="svc-body"><tr class="empty-row"><td colspan="10">Loading…</td></tr></tbody>
        </table>
      </div>
    </div>
  `;

  wireFilterBar(host, () => renderServices(host), () => byServiceQuery(true));

  const res    = await api.get(byServiceQuery(false));
  const data   = res?.data || {};
  const rows   = data.rows || [];
  const totals = data.totals || {};
  const totalRev = parseFloat(totals.revenue || 0);

  // "Most popular" = highest job count, not highest revenue. A service
  // with one expensive job shouldn't beat a service with five cheap
  // ones for "popularity." Tie-break on revenue, then alphabetical.
  let mostPopular = '—';
  if (rows.length) {
    const sorted = [...rows].sort((a, b) => {
      const j = parseInt(b.jobs_total, 10) - parseInt(a.jobs_total, 10);
      if (j !== 0) return j;
      const r = parseFloat(b.revenue || 0) - parseFloat(a.revenue || 0);
      if (r !== 0) return r;
      return (a.service_name || '').localeCompare(b.service_name || '');
    });
    if (parseInt(sorted[0].jobs_total, 10) > 0) {
      mostPopular = escape(sorted[0].service_name);
    }
  }

  host.querySelector('#svc-stats').innerHTML = `
    ${statCard('Services with jobs', String(rows.filter((r) => parseInt(r.jobs_total,10) > 0).length))}
    ${statCard('Jobs total',         String(totals.jobs_total ?? 0))}
    ${statCard('Revenue',            CURRENCY + ' ' + fmtPrice(totals.revenue))}
    ${statCard('Most popular',       mostPopular)}
  `;

  const body = host.querySelector('#svc-body');
  if (!rows.length) {
    body.innerHTML = '<tr class="empty-row"><td colspan="10">No data for this range.</td></tr>';
    return;
  }
  body.innerHTML = rows.map((r) => {
    const pct = totalRev > 0 ? (parseFloat(r.revenue) / totalRev) * 100 : 0;
    return `
      <tr>
        <td><b>${escape(r.service_name || '—')}</b></td>
        <td class="num mono muted">${fmtPrice(r.base_price)}</td>
        <td class="num mono">${escape(r.jobs_total || 0)}</td>
        <td class="num mono">${escape(r.jobs_completed || 0)}</td>
        <td class="num mono">${escape(r.jobs_cancelled || 0)}</td>
        <td class="num mono">${fmtPrice(r.cash)}</td>
        <td class="num mono">${fmtPrice(r.card)}</td>
        <td class="num mono">${fmtPrice(r.online)}</td>
        <td class="num mono"><b>${fmtPrice(r.revenue)}</b></td>
        <td class="num mono">${pct.toFixed(1)}%</td>
      </tr>
    `;
  }).join('');
}

function byServiceQuery(forCsv) {
  const q = new URLSearchParams();
  q.set('from', _f.from); q.set('to', _f.to);
  if (forCsv) q.set('format', 'csv');
  return '/reports/by-service?' + q.toString();
}

// ─── Payments tab ──────────────────────────────────────────────────
async function renderPayments(host) {
  host.innerHTML = `
    <div id="pay-stats" class="stats-grid"></div>
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title">Payments · Cash / Card / Online</div>
        ${dateRangeBar('')}
      </div>
      <div class="table-wrap">
        <table class="report-table">
          <thead>
            <tr>
              <th>Date</th>
              <th class="num">Cash</th>
              <th class="num">Card</th>
              <th class="num">Online</th>
              <th class="num">Total</th>
            </tr>
          </thead>
          <tbody id="pay-body"><tr class="empty-row"><td colspan="5">Loading…</td></tr></tbody>
        </table>
      </div>
    </div>
  `;

  wireFilterBar(host, () => renderPayments(host), () => paymentsQuery(true));

  const res    = await api.get(paymentsQuery(false));
  const data   = res?.data || {};
  const totals = data.totals || {};
  const counts = data.counts || {};
  const byDay  = data.by_day || [];

  host.querySelector('#pay-stats').innerHTML = `
    ${statCard('Cash',   CURRENCY + ' ' + fmtPrice(totals.cash),   '', `${counts.cash || 0} txns`)}
    ${statCard('Card',   CURRENCY + ' ' + fmtPrice(totals.card),   '', `${counts.card || 0} txns`)}
    ${statCard('Online', CURRENCY + ' ' + fmtPrice(totals.online), '', `${counts.online || 0} txns`)}
    ${statCard('Total',  CURRENCY + ' ' + fmtPrice(data.total),    '', `${(counts.cash||0)+(counts.card||0)+(counts.online||0)} txns total`)}
  `;

  const body = host.querySelector('#pay-body');
  if (!byDay.length) {
    body.innerHTML = '<tr class="empty-row"><td colspan="5">No payments in this range.</td></tr>';
    return;
  }
  body.innerHTML = byDay.map((r) => `
    <tr>
      <td><b>${fmtDate(r.day)}</b></td>
      <td class="num mono">${fmtPrice(r.cash)}</td>
      <td class="num mono">${fmtPrice(r.card)}</td>
      <td class="num mono">${fmtPrice(r.online)}</td>
      <td class="num mono"><b>${fmtPrice(r.total)}</b></td>
    </tr>
  `).join('');
}

function paymentsQuery(forCsv) {
  const q = new URLSearchParams();
  q.set('from', _f.from); q.set('to', _f.to);
  if (forCsv) q.set('format', 'csv');
  return '/reports/payments?' + q.toString();
}

// ─── Helpers ───────────────────────────────────────────────────────
function defaultFrom() {
  const d = new Date();
  d.setDate(1);
  return d.toLocaleDateString('en-CA', { timeZone: 'Asia/Dubai' });
}
function defaultTo() {
  return new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Dubai' });
}

function statCard(label, value, kind = '', sub = '') {
  return `
    <div class="stat-card">
      <div class="stat-label">${escape(label)}</div>
      <div class="stat-value">${value}</div>
      ${sub ? `<div class="stat-delta">${escape(sub)}</div>` : ''}
    </div>
  `;
}

function statusBadge(s) {
  // Admin CSS already styles .badge.completed, .badge.cancelled, etc.
  return `<span class="badge ${escape(s || '')}">${escape(labelStatus(s))}</span>`;
}

function paymentBadge(s) {
  // .badge.paid does not exist in admin CSS — map paid → completed for green
  const cls = { paid: 'completed', partial: 'partial', unpaid: 'unpaid' }[s] || '';
  return `<span class="badge ${cls}">${escape(s || '—')}</span>`;
}

function labelStatus(s) {
  return ({
    pending:'Pending', assigned:'Assigned', accepted:'Accepted',
    in_progress:'In progress', completed:'Completed', cancelled:'Cancelled',
    rejected:'Rejected',
  })[s] || s || '—';
}

function formatLocation(r) {
  const parts = [];
  if (r.location_terminal) parts.push(r.location_terminal);
  if (r.location_name)     parts.push(r.location_name);
  const head = parts.join(' · ');
  return r.location_details ? (head ? head + ' · ' + r.location_details : r.location_details) : head;
}

// Report-specific CSS not provided by the global admin stylesheet.
function injectStyles() {
  if (document.getElementById('reports-css')) return;
  const css = `
    .report-table td,
    .report-table th { padding: 10px 14px; }
    .report-table th.num,
    .report-table td.num { text-align: right; }
    .report-table td.cell-bad { color: var(--danger); }
    .report-table .muted { color: var(--muted); }
    .report-table .small { font-size: 11px; }
    .filter-label {
      display: inline-flex; align-items: center; gap: 6px;
      font-size: 11px; text-transform: uppercase; letter-spacing: 0.1em;
      color: var(--muted); font-weight: 600;
    }
    .filter-label .filter-input { margin-left: 0; }
    .stat-card.warn .stat-value { color: var(--danger); }
  `;
  const s = document.createElement('style');
  s.id = 'reports-css';
  s.textContent = css;
  document.head.appendChild(s);
}