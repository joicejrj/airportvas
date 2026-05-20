// frontend/admin/pages/handovers.js
// Payment handover management — list + detail modal with confirm/dispute.

import * as api from '../api.js';
import { getToken } from '../api.js';
import {
  CURRENCY, escape, fmtTime, fmtDate, fmtAmount, fmtPrice,
  toast, openModal, confirmDialog,
} from '../utils.js';
import { mountDateRange } from '../components/daterange.js';

let _root;
let _items = [];
let _meta  = {};
let _filter = { status: '', team_leader_id: '', service_id: '', from: '', to: '' };
let _tls       = [];
let _services  = [];
let _page   = 1;
let _dateCtl = null;
const PAGE_SIZE = 25;

export async function init(root) {
  _root = root;
  ensureCss();
  paint();
  await Promise.all([loadTLs(), loadServices(), load()]);
}
export function onShow()      { load(); }
export async function refresh() { return load(); }

async function loadTLs() {
  try { _tls = (await api.get('/users/team-leaders'))?.data || []; }
  catch { _tls = []; }
  populateTLs();
}

async function loadServices() {
  try { _services = (await api.get('/services'))?.data || []; }
  catch { _services = []; }
  populateServices();
}

function populateTLs() {
  const sel = _root.querySelector('#hov-tl');
  if (!sel || sel.options.length > 1 || !_tls.length) return;
  for (const t of _tls) {
    const o = document.createElement('option');
    o.value = t.id;
    o.textContent = t.service_name ? `${t.name} (${t.service_name})` : t.name;
    sel.appendChild(o);
  }
}

function populateServices() {
  const sel = _root.querySelector('#hov-svc');
  if (!sel || sel.options.length > 1 || !_services.length) return;
  for (const s of _services) {
    const o = document.createElement('option');
    o.value = s.id;
    o.textContent = s.name;
    sel.appendChild(o);
  }
}

async function load() {
  try {
    const qs = new URLSearchParams();
    if (_filter.status) {
      qs.set('status', _filter.status);
    } else {
      // When "All status" is selected on the History page, exclude
      // pending handovers — those are managed on the main
      // Payment Handovers page (tile view).
      qs.set('exclude_status', 'pending');
    }
    if (_filter.team_leader_id) qs.set('team_leader_id', _filter.team_leader_id);
    if (_filter.service_id)     qs.set('service_id', _filter.service_id);
    if (_filter.from)           qs.set('from', _filter.from);
    if (_filter.to)             qs.set('to', _filter.to);
    qs.set('page', String(_page));
    qs.set('limit', String(PAGE_SIZE));

    const r = await api.get('/handovers?' + qs.toString());
    _items = r?.data?.items || [];
    _meta  = r?.data?.meta  || {};
    paintTable();
    updatePendingBadge();
  } catch (e) {
    toast(e.message || 'Failed to load handovers', 'error');
  }
}

function updatePendingBadge() {
  // The badge counts TEAM LEADERS with a pending submission right now —
  // not the count of pending handover rows in the DB. Use the same
  // endpoint the main Payment Handovers page uses so both pages agree
  // on the number (otherwise one page can set the badge to N while the
  // other immediately overwrites it with a different N, depending on
  // which page is opened last).
  api.get('/handovers/unsettled-by-tl').then((r) => {
    const items = r?.data?.items || [];
    const total = items.filter(
      (it) => it.pending_handover && it.pending_handover.status === 'pending'
    ).length;
    const b = document.getElementById('handovers-badge');
    if (b) {
      if (total > 0) { b.textContent = total > 99 ? '99+' : String(total); b.hidden = false; }
      else b.hidden = true;
    }
  }).catch(() => {});
}

function paint() {
  _root.innerHTML = `
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title">Handover History</div>
        <div class="filters">
          <select class="filter-input" id="hov-status">
            <option value="" selected>All status</option>
            <option value="confirmed">Approved</option>
            <option value="disputed">Rejected</option>
          </select>
          <select class="filter-input" id="hov-svc">
            <option value="">All services</option>
          </select>
          <select class="filter-input" id="hov-tl">
            <option value="">All team leaders</option>
          </select>
          <div id="hov-daterange"></div>
          <button class="btn sm" id="hov-export" title="Download filtered handovers as CSV">⬇ Export CSV</button>
        </div>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Submitted</th>
              <th>Team Leader</th>
              <th>Service</th>
              <th>Handover Date</th>
              <th>Orders</th>
              <th>Amount</th>
              <th>Status</th>
              <th>Confirmed By</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="hov-body">
            <tr class="empty-row"><td colspan="9">Loading…</td></tr>
          </tbody>
        </table>
      </div>
      <div id="hov-pager" style="padding:14px 22px;border-top:1px solid var(--border);background:var(--hover-bg);display:flex;justify-content:space-between;align-items:center"></div>
    </div>
  `;

  // History page shows approved + rejected by default (excludes pending —
  // those live on the main Payment Handovers tile-view page).
  _filter.status = '';

  _root.querySelector('#hov-status').addEventListener('change', (e) => { _filter.status = e.target.value; _page = 1; load(); });
  _root.querySelector('#hov-svc').addEventListener('change',    (e) => { _filter.service_id = e.target.value; _page = 1; load(); });
  _root.querySelector('#hov-tl').addEventListener('change',     (e) => { _filter.team_leader_id = e.target.value; _page = 1; load(); });

  // Date-range picker — shared component. The popover handles all
  // preset/custom UI; we just react to the onChange callback.
  if (_dateCtl) { try { _dateCtl.destroy(); } catch {} }
  _dateCtl = mountDateRange({
    anchor: _root.querySelector('#hov-daterange'),
    value:  { from: _filter.from, to: _filter.to },
    onChange: (range) => {
      _filter.from = range.from;
      _filter.to   = range.to;
      _page = 1;
      load();
    },
  });

  _root.querySelector('#hov-export').addEventListener('click', doExport);
}

// Date helpers — return YYYY-MM-DD strings in the local (Asia/Dubai) day,
// which is what the backend's handover_date column stores.
function doExport() {
  // The CSV endpoint mirrors the same filters but doesn't paginate. We
  // hit it via a query-string-authed link so the browser handles the
  // download — fetch() with the Authorization header would give us a
  // blob we'd have to programmatically save, which is fiddly and worse
  // UX (no "Save as" prompt).
  const qs = new URLSearchParams();
  if (_filter.status) {
    qs.set('status', _filter.status);
  } else {
    qs.set('exclude_status', 'pending');
  }
  if (_filter.team_leader_id) qs.set('team_leader_id', _filter.team_leader_id);
  if (_filter.service_id)     qs.set('service_id', _filter.service_id);
  if (_filter.from)           qs.set('from', _filter.from);
  if (_filter.to)             qs.set('to', _filter.to);
  const token = getToken();
  if (token) qs.set('token', token);

  const url = '/api/handovers/export.csv?' + qs.toString();
  // Trigger a download — opening in same tab triggers the
  // Content-Disposition: attachment header, which never navigates.
  const a = document.createElement('a');
  a.href = url;
  a.rel = 'noopener';
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  toast('Exporting…', 'success');
}

function paintTable() {
  const body = _root.querySelector('#hov-body');
  if (!body) return;
  if (!_items.length) {
    body.innerHTML = '<tr class="empty-row"><td colspan="9">No handovers match these filters</td></tr>';
    paintPager();
    return;
  }

  body.innerHTML = _items.map((h) => `
    <tr>
      <td style="color:var(--muted);font-size:12px">${fmtTime(h.created_at)}</td>
      <td><strong>${escape(h.team_leader_name || '—')}</strong></td>
      <td>${h.service_name ? `<span class="badge team_leader">${escape(h.service_name)}</span>` : '<span class="muted">—</span>'}</td>
      <td>${fmtDate(h.handover_date)}</td>
      <td class="tabular">${h.order_count}</td>
      <td class="mono tabular"><strong>${fmtAmount(h.amount)}</strong></td>
      <td><span class="badge ${escape(h.status)}">${escape(statusLabel(h.status))}</span></td>
      <td style="color:var(--muted);font-size:12px">
        ${h.confirmed_by_name ? escape(h.confirmed_by_name) + '<br><span style="font-size:10px">' + fmtTime(h.confirmed_at) + '</span>' : '—'}
      </td>
      <td><button class="btn sm" data-view="${escape(h.id)}">View</button></td>
    </tr>
  `).join('');

  body.querySelectorAll('[data-view]').forEach((b) =>
    b.addEventListener('click', () => openDetail(b.dataset.view)));

  paintPager();
}

function statusLabel(s) {
  return { pending: 'Pending', confirmed: 'Approved', disputed: 'Rejected' }[s] || s;
}

function paintPager() {
  const pager = _root.querySelector('#hov-pager');
  if (!pager) return;
  const total = _meta.total ?? 0;
  const totalPages = _meta.total_pages ?? 1;
  pager.innerHTML = `
    <div style="font-size:12px;color:var(--muted)">
      ${total > 0
        ? `Page <strong>${_meta.page || _page}</strong> of <strong>${totalPages}</strong> · ${total} ${total === 1 ? 'handover' : 'handovers'}`
        : 'No handovers'}
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
    const r = await api.get('/handovers/' + id);
    data = r?.data;
  } catch (e) {
    toast(e.message || 'Failed to load', 'error');
    return;
  }
  if (!data) return;

  const orders = data.orders || [];
  const orderRow = (o) => {
    const cash   = parseFloat(o.cash_amount   || 0);
    const card   = parseFloat(o.card_amount   || 0);
    const online = parseFloat(o.online_amount || 0);
    const amt    = parseFloat(o.amount        || 0);
    const chips = [];
    if (cash   > 0.005) chips.push(`<span class="ord-method ord-method-cash">💵 ${fmtPrice(cash)}</span>`);
    if (card   > 0.005) chips.push(`<span class="ord-method ord-method-card">💳 ${fmtPrice(card)}</span>`);
    if (online > 0.005) chips.push(`<span class="ord-method ord-method-online">🔗 ${fmtPrice(online)}</span>`);
    return `
      <div class="ord-row">
        <span class="serial-pill">#${escape(o.daily_serial || '—')}</span>
        <span class="ord-num"><strong class="mono">${escape(o.order_number || '—')}</strong></span>
        <span class="ord-methods">${chips.join(' ') || '<span class="muted" style="font-style:italic">—</span>'}</span>
        <span class="ord-total mono tabular"><strong>${CURRENCY} ${fmtPrice(amt)}</strong></span>
      </div>`;
  };
  const ordersBlock = orders.length
    ? orders.map(orderRow).join('')
    : '<div style="color:var(--muted);font-style:italic;padding:8px 0">No order snapshot</div>';

  // Reconciliation: same audit hint as the live tile page.
  const ordersSum = orders.reduce((a, o) => a + parseFloat(o.amount || 0), 0);
  const recordedTotal = parseFloat(data.amount || 0);
  const mismatch = Math.abs(ordersSum - recordedTotal) > 0.015;
  const orderCountMismatch = orders.length !== parseInt(data.order_count || 0, 10);
  const reconcileWarning = (mismatch || orderCountMismatch)
    ? `<div class="ord-warn">
         <strong>⚠ Reconciliation:</strong>
         ${orderCountMismatch ? `<div>Recorded ${data.order_count} orders, but ${orders.length} contributing rows fetched.</div>` : ''}
         ${mismatch ? `<div>Sum of rows (${CURRENCY} ${fmtPrice(ordersSum)}) differs from recorded total (${CURRENCY} ${fmtPrice(recordedTotal)}).</div>` : ''}
       </div>`
    : '';

  const cashAmt   = parseFloat(data.cash_amount   || 0);
  const cardAmt   = parseFloat(data.card_amount   || 0);
  const onlineAmt = parseFloat(data.online_amount || 0);
  const hasBreakdown = (cashAmt + cardAmt + onlineAmt) > 0.005;

  const body = `
    <div class="handover-report" id="handover-print-area">
      <!-- Print-only header (visible when this section is printed) -->
      <div class="hov-print-header">
        <div class="hov-print-title">Payment Handover Report</div>
        <div class="hov-print-meta">Generated ${fmtTime(new Date().toISOString())}</div>
      </div>

      <div class="hov-grid">
        <div class="hov-cell">
          <div class="form-label">Team leader</div>
          <div><strong>${escape(data.team_leader_name || '—')}</strong>
            ${data.service_name ? '<br><span class="badge team_leader" style="margin-top:4px">' + escape(data.service_name) + '</span>' : ''}
          </div>
        </div>
        <div class="hov-cell">
          <div class="form-label">Status</div>
          <div><span class="badge ${escape(data.status)}">${escape(statusLabel(data.status))}</span></div>
        </div>
        <div class="hov-cell">
          <div class="form-label">Handover date</div>
          <div>${fmtDate(data.handover_date)}</div>
        </div>
        <div class="hov-cell">
          <div class="form-label">Submitted</div>
          <div>${fmtTime(data.created_at)}</div>
        </div>
      </div>

      <div class="hov-total-card">
        <div class="form-label" style="margin-bottom:4px">Total amount</div>
        <div class="hov-total-val tabular">${fmtAmount(data.amount)}</div>
        <div class="hov-total-sub">
          from ${data.order_count} ${data.order_count === 1 ? 'order' : 'orders'}
        </div>
      </div>

      ${hasBreakdown ? `
        <h4 class="hov-section-h">Payment breakdown</h4>
        <div class="hov-method-grid">
          <div class="hov-method-tile hov-method-cash">
            <div class="hov-method-icon">💵</div>
            <div class="hov-method-label">Cash</div>
            <div class="hov-method-amt tabular">${fmtAmount(cashAmt)}</div>
          </div>
          <div class="hov-method-tile hov-method-card">
            <div class="hov-method-icon">💳</div>
            <div class="hov-method-label">Card</div>
            <div class="hov-method-amt tabular">${fmtAmount(cardAmt)}</div>
          </div>
          <div class="hov-method-tile hov-method-online">
            <div class="hov-method-icon">🔗</div>
            <div class="hov-method-label">Online</div>
            <div class="hov-method-amt tabular">${fmtAmount(onlineAmt)}</div>
          </div>
        </div>
      ` : ''}

      <h4 class="hov-section-h">Contributing orders</h4>
      <div style="margin-bottom:16px">${ordersBlock}${reconcileWarning}</div>

      ${data.notes ? `
        <h4 class="hov-section-h">TL notes</h4>
        <div class="hov-notes-box">${escape(data.notes)}</div>` : ''}

      ${data.status === 'confirmed' ? `
        <div class="hov-status-banner hov-status-confirmed">
          Approved by <strong>${escape(data.confirmed_by_name || '—')}</strong> · ${fmtTime(data.confirmed_at)}
        </div>` : ''}

      ${data.status === 'disputed' ? `
        <div class="hov-status-banner hov-status-disputed">
          Rejected by <strong>${escape(data.confirmed_by_name || '—')}</strong> · ${fmtTime(data.confirmed_at)}
          ${data.dispute_reason ? '<br><br><strong>Reason:</strong> ' + escape(data.dispute_reason) : ''}
        </div>` : ''}

      <!-- Print-only signature line -->
      <div class="hov-print-sign">
        <div class="hov-print-sign-row">
          <div>
            <div class="hov-print-sign-line"></div>
            <div class="hov-print-sign-label">Team leader signature</div>
          </div>
          <div>
            <div class="hov-print-sign-line"></div>
            <div class="hov-print-sign-label">Admin signature</div>
          </div>
        </div>
      </div>
    </div>
  `;

  // Footer: Print button always available; Reject/Approve only for pending
  // (which the history page filters out by default, but supports if the
  // admin opts in via the status dropdown).
  const printBtn = '<button class="btn" id="hov-print">🖨 Print report</button>';
  const footer = data.status === 'pending'
    ? `${printBtn}
       <button class="btn danger" id="hov-dispute">Reject</button>
       <button class="btn primary" id="hov-confirm">✓ Approve</button>
       <button class="btn" data-close-x>Close</button>`
    : `${printBtn}<button class="btn" data-close-x>Close</button>`;

  const m = openModal({
    title: `Handover · ${fmtDate(data.handover_date)}`,
    width: 600, body, footer,
  });
  m.overlay.querySelector('[data-close-x]').addEventListener('click', m.close);

  m.overlay.querySelector('#hov-print')?.addEventListener('click', () => {
    // Set a body class so the print CSS knows which modal to render and
    // hide everything else. Cleared after the print dialog closes.
    document.body.classList.add('hov-printing');
    // Tiny delay to let the class settle before the dialog renders
    setTimeout(() => {
      window.print();
      // Clear the class on the next event-loop tick (Chrome fires
      // afterprint reliably; other browsers don't, so use a fallback).
      const cleanup = () => document.body.classList.remove('hov-printing');
      window.addEventListener('afterprint', cleanup, { once: true });
      setTimeout(cleanup, 2000);
    }, 50);
  });

  m.overlay.querySelector('#hov-confirm')?.addEventListener('click', async () => {
    const ok = await confirmDialog(`Approve receipt of ${CURRENCY} ${fmtPrice(data.amount)} from ${data.team_leader_name}?`);
    if (!ok) return;
    try {
      await api.patch('/handovers/' + id + '/confirm');
      toast('Handover approved — email sent to TL', 'success');
      m.close();
      load();
    } catch (e) {
      toast(e.message || 'Approve failed', 'error');
    }
  });

  m.overlay.querySelector('#hov-dispute')?.addEventListener('click', async () => {
    const reason = prompt('Reason for rejection (required):');
    if (!reason || !reason.trim()) return;
    try {
      await api.patch('/handovers/' + id + '/reject', { reason: reason.trim() });
      toast('Handover rejected', 'success');
      m.close();
      load();
    } catch (e) {
      toast(e.message || 'Reject failed', 'error');
    }
  });
}

// ─── CSS for the handover detail modal + print report ──────────────
function ensureCss() {
  if (document.getElementById('handovers-css')) return;
  const css = `
    /* On-screen layout */
    .handover-report { font-size: 14px; }

    .hov-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px 18px;
      margin-bottom: 18px;
    }
    .hov-cell .form-label {
      font-size: 10px;
      letter-spacing: 0.1em;
      text-transform: uppercase;
      color: var(--muted);
      font-weight: 700;
      margin-bottom: 4px;
    }

    .hov-total-card {
      background: var(--accent-soft);
      padding: 16px;
      border-radius: 8px;
      margin-bottom: 18px;
    }
    .hov-total-val {
      font-family: var(--font-head);
      font-size: 30px;
      font-weight: 800;
      color: var(--accent);
      letter-spacing: -0.02em;
    }
    .hov-total-sub {
      font-size: 12px;
      color: var(--muted);
      margin-top: 4px;
    }

    .hov-section-h {
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: 0.1em;
      color: var(--muted);
      margin-bottom: 8px;
      font-weight: 700;
    }

    .hov-method-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 10px;
      margin-bottom: 18px;
    }
    .hov-method-tile {
      padding: 14px 12px;
      border-radius: 10px;
      border: 1px solid var(--border, #e5e7eb);
      background: var(--hover-bg, #f8fafc);
      text-align: center;
    }
    .hov-method-tile.hov-method-cash   { background: rgba(22,163,74,0.06);  border-color: rgba(22,163,74,0.20); }
    .hov-method-tile.hov-method-card   { background: rgba(14,165,233,0.06); border-color: rgba(14,165,233,0.20); }
    .hov-method-tile.hov-method-online { background: rgba(124,58,237,0.06); border-color: rgba(124,58,237,0.20); }
    .hov-method-icon { font-size: 22px; margin-bottom: 4px; }
    .hov-method-label {
      font-size: 11px;
      letter-spacing: 0.1em;
      text-transform: uppercase;
      color: var(--muted);
      font-weight: 700;
      margin-bottom: 3px;
    }
    .hov-method-amt {
      font-size: 16px;
      font-weight: 800;
      color: var(--ink, var(--text-primary, #111));
      font-variant-numeric: tabular-nums;
    }

    .hov-notes-box {
      padding: 10px 12px;
      background: var(--hover-bg);
      border-radius: 6px;
      margin-bottom: 16px;
      font-size: 13px;
    }

    .hov-status-banner {
      padding: 10px 12px;
      border-radius: 6px;
      font-size: 13px;
      margin-bottom: 12px;
    }
    .hov-status-confirmed { background: var(--success-soft); color: var(--success); }
    .hov-status-disputed  { background: var(--danger-soft);  color: var(--danger);  }

    /* Hidden by default on screen — only shown when printing */
    .hov-print-header,
    .hov-print-sign { display: none; }

    /* ─── Print rules ─── */
    @media print {
      /* Keep @page minimal — many users print with "Margins: None" which
       * overrides this anyway. The real whitespace comes from padding on
       * the report container itself (below) so it always applies. */
      @page {
        size: A4 portrait;
        margin: 0;
      }
      html, body {
        background: #fff !important;
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
        /* The admin shell sets display:flex on the body to lay out the
         * fixed sidebar plus main column. In print, that flex layout
         * makes the modal-overlay (a third flex child) shrink to its
         * content intrinsic width, which is why the report rendered at
         * half-page with a huge blank right margin. Reset to block flow. */
        display: block !important;
      }

      /* Hide everything in the admin shell — only the print area shows. */
      body.hov-printing > *:not(.modal-overlay) { display: none !important; }

      /* Strip the overlay's fixed-position centering and any flex sizing. */
      body.hov-printing .modal-overlay {
        position: static !important;
        background: #fff !important;
        display: block !important;
        padding: 0 !important;
        inset: auto !important;
        margin: 0 !important;
        width: 100% !important;
        max-width: none !important;
        flex: none !important;
      }
      body.hov-printing .modal {
        /* Beats the inline style="max-width:600px" set by openModal() */
        max-width: none !important;
        width: 100% !important;
        max-height: none !important;
        box-shadow: none !important;
        border: none !important;
        border-radius: 0 !important;
        background: #fff !important;
        margin: 0 !important;
        padding: 0 !important;
        display: block !important;
        overflow: visible !important;
      }
      body.hov-printing .modal-header,
      body.hov-printing .modal-footer { display: none !important; }
      body.hov-printing .modal-body {
        padding: 0 !important;
        max-height: none !important;
        overflow: visible !important;
        width: 100% !important;
      }
      /* THIS is where the print whitespace lives. Padding on the report
       * container is part of the document, not the @page box, so it
       * survives "Margins: None" in the print dialog. */
      body.hov-printing #handover-print-area {
        width: 100% !important;
        max-width: none !important;
        padding: 16mm 14mm !important;
        box-sizing: border-box !important;
      }

      /* The print-only header + signature block come into view. */
      body.hov-printing .hov-print-header { display: block; margin-bottom: 18px; }
      body.hov-printing .hov-print-title {
        font-size: 22px;
        font-weight: 800;
        text-align: center;
        margin-bottom: 4px;
      }
      body.hov-printing .hov-print-meta {
        font-size: 11px;
        text-align: center;
        color: #666;
      }
      body.hov-printing .hov-print-sign { display: block; margin-top: 40px; }
      body.hov-printing .hov-print-sign-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 50px;
      }
      body.hov-printing .hov-print-sign-line {
        border-bottom: 1px solid #000;
        height: 40px;
        margin-bottom: 6px;
      }
      body.hov-printing .hov-print-sign-label {
        font-size: 11px;
        color: #444;
        text-align: center;
      }
      /* Coloured chips (badges, total card, method tiles) need explicit
       * print-color-adjust so the browser doesn't drop their fills. */
      body.hov-printing .hov-total-card,
      body.hov-printing .hov-method-tile,
      body.hov-printing .badge,
      body.hov-printing .hov-status-banner {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
      }
      /* Avoid awkward mid-section page breaks */
      body.hov-printing .hov-section-h,
      body.hov-printing .hov-method-grid,
      body.hov-printing .hov-total-card,
      body.hov-printing .hov-print-sign { break-inside: avoid; }
    }

    /* ─── Contributing-order rows (per-method breakdown) ─── */
    .ord-row {
      display: grid;
      grid-template-columns: 56px 90px 1fr auto;
      gap: 12px;
      padding: 8px 0;
      border-bottom: 1px dotted var(--border);
      font-size: 13px;
      align-items: center;
    }
    .ord-row:last-child { border-bottom: none; }
    .ord-num { color: var(--ink); }
    .ord-methods {
      display: flex;
      flex-wrap: wrap;
      gap: 4px;
      justify-content: flex-end;
    }
    .ord-method {
      display: inline-block;
      padding: 2px 8px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 600;
      font-variant-numeric: tabular-nums;
      white-space: nowrap;
    }
    .ord-method-cash   { background: #dcfce7; color: #166534; }
    .ord-method-card   { background: #dbeafe; color: #1e40af; }
    .ord-method-online { background: #f3e8ff; color: #6b21a8; }
    .ord-total { white-space: nowrap; color: var(--ink); }
    .ord-warn {
      margin-top: 10px;
      padding: 10px 12px;
      background: #fef3c7;
      border-left: 3px solid #d97706;
      border-radius: 6px;
      font-size: 12px;
      line-height: 1.5;
      color: #7c2d12;
    }
    .ord-warn strong { color: #92400e; }
  `;
  const s = document.createElement('style');
  s.id = 'handovers-css';
  s.textContent = css;
  document.head.appendChild(s);
}