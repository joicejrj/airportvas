// frontend/admin/pages/dashboard.js
// Dashboard: stat cards + 7-day trend.

import * as api from '../api.js';
import { CURRENCY, escape, fmtPrice, fmtAmount, fmtDate, toast } from '../utils.js';

let _root;

export async function init(root) {
  _root = root;
  paint();
  await load();
}
export function onShow()  { load(); }
export async function refresh() { return load(); }

function paint() {
  _root.innerHTML = `
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-label">Today's Revenue</div>
        <div class="stat-value tabular">
          <span class="stat-cur">${CURRENCY}</span><span id="d-revenue">—</span>
        </div>
        <div class="stat-delta" id="d-orders-line">— orders</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Active Team Leaders</div>
        <div class="stat-value tabular" id="d-active-tls">—</div>
        <div class="stat-delta" id="d-emp-line">— employees</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Pending Handovers</div>
        <div class="stat-value tabular" id="d-pending-hand">—</div>
        <div class="stat-delta"><a href="#" id="d-hand-link" style="color:var(--accent);text-decoration:none;font-weight:600">View →</a></div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Unpaid Orders</div>
        <div class="stat-value tabular" id="d-unpaid">—</div>
        <div class="stat-delta">today</div>
      </div>
    </div>

    <div class="panel">
      <div class="panel-header">
        <div class="panel-title">7-Day Revenue Trend</div>
      </div>
      <div class="panel-body" style="padding:20px">
        <div id="d-trend"></div>
      </div>
    </div>

    <div class="panel">
      <div class="panel-header">
        <div class="panel-title">Service Breakdown — Today</div>
      </div>
      <div class="panel-body">
        <div class="table-wrap">
          <table>
            <thead><tr><th>Service</th><th>Orders</th><th>Revenue (${CURRENCY})</th></tr></thead>
            <tbody id="d-svc-body">
              <tr class="empty-row"><td colspan="3">Loading…</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  `;

  _root.querySelector('#d-hand-link').addEventListener('click', (e) => {
    e.preventDefault();
    document.querySelector('.nav-item[data-page="handovers"]')?.click();
  });
}

async function load() {
  try {
    const today = new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Dubai' });
    const [rep, tls, emps, hands, unpaid] = await Promise.all([
      api.get('/reports/daily?date=' + today),
      api.get('/team-leaders'),
      api.get('/employees?active=1&limit=1'),
      // Count team leaders with a pending submission right now — same
      // source the sidebar badge uses. The old /handovers?status=pending
      // counted ALL pending handover rows (including stale legacy ones
      // from before submit-gating was added), which double-counted
      // anything a TL had submitted multiple times.
      api.get('/handovers/unsettled-by-tl'),
      api.get('/orders/summary?date=' + today),
    ]);

    const summary = rep?.data?.summary || {};
    _root.querySelector('#d-revenue').textContent     = fmtPrice(summary.total_revenue || 0);
    _root.querySelector('#d-orders-line').textContent = (summary.order_count || 0) + ' orders';
    _root.querySelector('#d-unpaid').textContent      = summary.unpaid_orders || 0;

    const tlList = tls?.data || [];
    _root.querySelector('#d-active-tls').textContent = tlList.filter(t => t.is_active).length;
    _root.querySelector('#d-emp-line').textContent =
      (emps?.data?.meta?.total ?? 0) + ' employees';

    const handItems = hands?.data?.items || [];
    const pendingTLs = handItems.filter(
      (it) => it.pending_handover && it.pending_handover.status === 'pending'
    ).length;
    _root.querySelector('#d-pending-hand').textContent = pendingTLs;

    // Service breakdown
    const svcBody = _root.querySelector('#d-svc-body');
    const svcs = rep?.data?.services || [];
    if (!svcs.length) {
      svcBody.innerHTML = '<tr class="empty-row"><td colspan="3">No orders today</td></tr>';
    } else {
      svcBody.innerHTML = svcs.map((s) => `
        <tr>
          <td><strong>${escape(s.name)}</strong></td>
          <td class="tabular">${s.count}</td>
          <td class="tabular">${fmtPrice(s.revenue)}</td>
        </tr>
      `).join('');
    }

    // Trend
    renderTrend(rep?.data?.trend || []);
  } catch (e) {
    console.error(e);
    toast(e.message || 'Failed to load dashboard', 'error');
  }
}

function renderTrend(days) {
  const host = _root.querySelector('#d-trend');
  if (!days.length) {
    host.innerHTML = '<div style="color:var(--muted);text-align:center">No data</div>';
    return;
  }
  const max = Math.max(1, ...days.map((d) => parseFloat(d.revenue) || 0));
  host.innerHTML = `
    <div style="display:grid;grid-template-columns:repeat(${days.length},1fr);gap:10px;align-items:end;height:170px">
      ${days.map((d) => {
        const v = parseFloat(d.revenue) || 0;
        const h = Math.max(3, Math.round((v / max) * 140));
        return `
          <div style="display:flex;flex-direction:column;align-items:center;gap:6px">
            <div style="font-family:var(--font-head);font-weight:700;font-size:11px;color:var(--muted)">
              ${fmtPrice(v)}
            </div>
            <div title="${escape(d.day)}" style="width:100%;height:${h}px;background:var(--accent);border-radius:4px 4px 0 0;"></div>
            <div style="font-size:10px;color:var(--muted);font-weight:600">${escape(d.label)}</div>
          </div>`;
      }).join('')}
    </div>`;
}