// frontend/admin/pages/payments.js
// Payments list — filterable & paginated.

import * as api from '../api.js';
import {
  CURRENCY, escape, fmtTime, fmtAmount, fmtPrice, toast,
} from '../utils.js';
import { mountDateRange } from '../components/daterange.js';

let _root;
let _items = [];
let _meta  = {};
let _filter = { method: '', from: '', to: '', q: '' };
let _page = 1;
let _dateCtl = null;
const PAGE_SIZE = 25;

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
    if (_filter.method) qs.set('method', _filter.method);
    if (_filter.from)   qs.set('from', _filter.from);
    if (_filter.to)     qs.set('to', _filter.to);
    if (_filter.q)      qs.set('q', _filter.q);
    qs.set('page', String(_page));
    qs.set('limit', String(PAGE_SIZE));

    const r = await api.get('/payments?' + qs.toString());
    _items  = r?.data?.items  || [];
    _meta   = r?.data?.meta   || {};
    const totals = r?.data?.totals || { cash: 0, card: 0, online: 0, total: 0 };
    paintTable();
    paintTotals(totals);
  } catch (e) {
    toast(e.message || 'Failed to load payments', 'error');
  }
}

function paintTotals(totals) {
  // Tiles always reflect the SAME filter as the table — server returns
  // aggregate over the matching rows alongside the paginated list.
  // The previous implementation called /reports/payments with a "today"
  // default, which silently disagreed with the table when no date
  // filter was active (table showed all-time, tiles showed only today
  // → confusing zero-card/zero-cash mismatches).
  const grid = _root.querySelector('#pay-totals');
  if (!grid) return;
  grid.innerHTML = `
    <div class="stat-card">
      <div class="stat-label">Cash (${CURRENCY})</div>
      <div class="stat-value tabular">${fmtPrice(totals.cash || 0)}</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Card (${CURRENCY})</div>
      <div class="stat-value tabular">${fmtPrice(totals.card || 0)}</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Online (${CURRENCY})</div>
      <div class="stat-value tabular">${fmtPrice(totals.online || 0)}</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Total (${CURRENCY})</div>
      <div class="stat-value tabular">${fmtPrice(totals.total || 0)}</div>
    </div>`;
}

function paint() {
  _root.innerHTML = `
    <div id="pay-totals" class="stats-grid"></div>

    <div class="panel">
      <div class="panel-header">
        <div class="panel-title">Payments</div>
        <div class="filters">
          <select class="filter-input" id="pay-method">
            <option value="">All methods</option>
            <option value="cash">Cash</option>
            <option value="card">Card</option>
            <option value="online">Online</option>
          </select>
          <div id="pay-daterange"></div>
          <input class="filter-input" id="pay-search" type="search" placeholder="Order #, ref…" style="min-width:160px">
        </div>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>When</th>
              <th>Order #</th>
              <th>Method</th>
              <th>Reference</th>
              <th>Recorded By</th>
              <th>Amount</th>
            </tr>
          </thead>
          <tbody id="pay-body">
            <tr class="empty-row"><td colspan="6">Loading…</td></tr>
          </tbody>
        </table>
      </div>
      <div id="pay-pager" style="padding:14px 22px;border-top:1px solid var(--border);background:var(--hover-bg);display:flex;justify-content:space-between;align-items:center"></div>
    </div>
  `;

  _root.querySelector('#pay-method').addEventListener('change', (e) => { _filter.method = e.target.value; _page = 1; load(); });

  // Shared date-range component
  if (_dateCtl) { try { _dateCtl.destroy(); } catch {} }
  _dateCtl = mountDateRange({
    anchor: _root.querySelector('#pay-daterange'),
    value:  { from: _filter.from, to: _filter.to },
    onChange: (range) => {
      _filter.from = range.from;
      _filter.to   = range.to;
      _page = 1; load();
    },
  });

  let qt;
  _root.querySelector('#pay-search').addEventListener('input', (e) => {
    clearTimeout(qt);
    qt = setTimeout(() => { _filter.q = e.target.value.trim(); _page = 1; load(); }, 300);
  });
}

function paintTable() {
  const body = _root.querySelector('#pay-body');
  if (!body) return;
  if (!_items.length) {
    body.innerHTML = '<tr class="empty-row"><td colspan="6">No payments match these filters</td></tr>';
    paintPager();
    return;
  }

  body.innerHTML = _items.map((p) => `
    <tr>
      <td style="color:var(--muted);font-size:12px">${fmtTime(p.created_at)}</td>
      <td class="mono"><strong>${escape(String(p.order_number || '—'))}</strong></td>
      <td><span class="badge ${escape(p.payment_method)}">${escape(p.payment_method)}</span></td>
      <td class="mono" style="color:var(--muted);font-size:12px">${escape(p.transaction_ref || '—')}</td>
      <td>${escape(p.recorded_by_name || '—')}</td>
      <td class="mono tabular"><strong>${fmtAmount(p.amount)}</strong></td>
    </tr>
  `).join('');

  paintPager();
}

function paintPager() {
  const pager = _root.querySelector('#pay-pager');
  if (!pager) return;
  const total = _meta.total ?? 0;
  const totalPages = _meta.total_pages ?? 1;
  pager.innerHTML = `
    <div style="font-size:12px;color:var(--muted)">
      ${total > 0
        ? `Page <strong>${_meta.page || _page}</strong> of <strong>${totalPages}</strong> · ${total} ${total === 1 ? 'payment' : 'payments'}`
        : 'No payments'}
    </div>
    <div class="row" style="gap:8px">
      <button class="btn sm" id="pg-prev" ${_page <= 1 ? 'disabled' : ''}>← Prev</button>
      <button class="btn sm" id="pg-next" ${_page >= totalPages ? 'disabled' : ''}>Next →</button>
    </div>
  `;
  pager.querySelector('#pg-prev')?.addEventListener('click', () => { if (_page > 1) { _page -= 1; load(); } });
  pager.querySelector('#pg-next')?.addEventListener('click', () => { if (_page < totalPages) { _page += 1; load(); } });
}