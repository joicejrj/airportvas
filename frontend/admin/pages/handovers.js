// frontend/admin/pages/handovers.js
// Payment Handovers — admin tile view.
//
// One card per team leader showing:
//   - identity (name + service chip)
//   - unsettled per-method tiles (cash / card / online)
//   - big total + order count
//   - if they have a pending handover: Approve / Reject inline
//   - if they have a rejected handover: status banner with reason
//   - if neither: read-only "Unsettled" pill — waiting on TL to submit
//
// Auto-polls every 20s so admins see live unsettled balances as TLs
// complete jobs and collect payments.

import * as api from '../api.js';
import {
  CURRENCY, escape, fmtTime, fmtPrice,
  toast, openModal, confirmDialog,
} from '../utils.js';

let _root;
let _items     = [];
let _services  = [];
let _tls       = [];
let _filter    = { team_leader_id: '', service_id: '' };
let _pollTimer = null;

const POLL_MS = 20_000;

export async function init(root) {
  _root = root;
  ensureCss();
  paint();
  await Promise.all([loadServices(), loadTLs(), load()]);
  startPolling();
}
export function onShow()      { load(); startPolling(); }
export function onHide()      { stopPolling(); }
export async function refresh() { return load(); }

function startPolling() {
  stopPolling();
  _pollTimer = setInterval(() => {
    // Skip the poll if any modal is open — refresh would obliterate state.
    if (document.querySelector('.modal-overlay.open')) return;
    load();
  }, POLL_MS);
}
function stopPolling() {
  if (_pollTimer) { clearInterval(_pollTimer); _pollTimer = null; }
}

async function loadServices() {
  try { _services = (await api.get('/services'))?.data || []; }
  catch { _services = []; }
  populateServices();
}
async function loadTLs() {
  try { _tls = (await api.get('/users/team-leaders'))?.data || []; }
  catch { _tls = []; }
  populateTLs();
}
function populateServices() {
  const sel = _root.querySelector('#h-svc');
  if (!sel || sel.options.length > 1 || !_services.length) return;
  for (const s of _services) {
    const o = document.createElement('option');
    o.value = s.id;
    o.textContent = s.name;
    sel.appendChild(o);
  }
}
function populateTLs() {
  const sel = _root.querySelector('#h-tl');
  if (!sel || sel.options.length > 1 || !_tls.length) return;
  for (const t of _tls) {
    const o = document.createElement('option');
    o.value = t.id;
    o.textContent = t.service_name ? `${t.name} (${t.service_name})` : t.name;
    sel.appendChild(o);
  }
}

async function load() {
  try {
    const qs = new URLSearchParams();
    if (_filter.team_leader_id) qs.set('team_leader_id', _filter.team_leader_id);
    const r = await api.get('/handovers/unsettled-by-tl?' + qs.toString());
    let items = r?.data?.items || [];
    // Service filter — applied client-side since the backend endpoint
    // doesn't take it (saves rebuilding SQL on every poll).
    if (_filter.service_id) {
      items = items.filter((it) => it.service_id === _filter.service_id);
    }
    _items = items;
    paintCards();
    updatePendingBadge();
  } catch (e) {
    toast(e.message || 'Failed to load handover data', 'error');
  }
}

function updatePendingBadge() {
  const total = _items.filter(
    (it) => it.pending_handover && it.pending_handover.status === 'pending'
  ).length;
  const b = document.getElementById('handovers-badge');
  if (b) {
    if (total > 0) { b.textContent = total > 99 ? '99+' : String(total); b.hidden = false; }
    else b.hidden = true;
  }
}

function paint() {
  _root.innerHTML = `
    <div class="panel">
      <div class="panel-header">
        <div class="panel-title">Payment Handovers</div>
        <div class="filters">
          <select class="filter-input" id="h-svc">
            <option value="">All services</option>
          </select>
          <select class="filter-input" id="h-tl">
            <option value="">All team leaders</option>
          </select>
          <button class="btn sm" id="h-print-all" title="Print one-page summary of all TL rows">🖨 Print summary</button>
          <button class="btn sm" id="h-refresh" title="Refresh now">↻</button>
        </div>
      </div>
      <div class="panel-body" id="h-body" style="padding:0">
        <div class="table-wrap">
          <table class="hov-tbl">
            <thead>
              <tr>
                <th>Team Leader</th>
                <th>Status</th>
                <th class="num">Cash</th>
                <th class="num">Card</th>
                <th class="num">Online</th>
                <th class="num">Total</th>
                <th class="num">Orders</th>
                <th class="actions">Actions</th>
              </tr>
            </thead>
            <tbody id="h-rows">
              <tr><td colspan="8" class="hov-loading">Loading team leaders…</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  `;

  _root.querySelector('#h-svc').addEventListener('change', (e) => {
    _filter.service_id = e.target.value; load();
  });
  _root.querySelector('#h-tl').addEventListener('change', (e) => {
    _filter.team_leader_id = e.target.value; load();
  });
  _root.querySelector('#h-refresh').addEventListener('click', () => load());
  _root.querySelector('#h-print-all').addEventListener('click', printAllSummary);
}

// Print a one-page consolidated summary of every TL currently on screen
// (after filter). One row per TL with name + service + status + cash + card
// + online + total + orders. Same data as the table; print-friendly layout.
function printAllSummary() {
  if (!_items.length) {
    toast('No team leaders to print', 'info');
    return;
  }

  // Build a print-only block. We attach it to document.body so it gets
  // the same CSS context as the rest of the page; the @media print
  // rules below hide everything else.
  let host = document.getElementById('h-print-all-host');
  if (host) host.remove();
  host = document.createElement('div');
  host.id = 'h-print-all-host';
  host.className = 'hov-print-summary';

  const today = new Date().toLocaleDateString('en-GB', {
    day: '2-digit', month: 'short', year: 'numeric', timeZone: 'Asia/Dubai',
  });
  const printTime = new Date().toLocaleString('en-GB', {
    hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Dubai', hour12: false,
  });

  let grandCash = 0, grandCard = 0, grandOnline = 0, grandOrders = 0;

  const rows = _items.map((it) => {
    const u  = it.unsettled || { cash_amount: 0, card_amount: 0, online_amount: 0, total: 0, order_count: 0 };
    const ph = it.pending_handover;
    const useSubmitted = ph && (ph.status === 'pending' || ph.status === 'disputed');

    const cashV   = useSubmitted ? (ph.cash_amount   || 0) : u.cash_amount;
    const cardV   = useSubmitted ? (ph.card_amount   || 0) : u.card_amount;
    const onlineV = useSubmitted ? (ph.online_amount || 0) : u.online_amount;
    const totalV  = useSubmitted ? (ph.amount        || 0) : u.total;
    const orderV  = useSubmitted ? (ph.order_count   || 0) : u.order_count;

    grandCash   += parseFloat(cashV)   || 0;
    grandCard   += parseFloat(cardV)   || 0;
    grandOnline += parseFloat(onlineV) || 0;
    grandOrders += parseInt(orderV, 10) || 0;

    let statusText = '—';
    if (ph && ph.status === 'pending')  statusText = 'Awaiting approval';
    else if (ph && ph.status === 'disputed') statusText = 'Rejected';
    else if ((u.total || 0) > 0.005)    statusText = 'Unsettled';
    else                                statusText = 'All clear';

    return `
      <tr>
        <td>${escape(it.team_leader_name || '—')}</td>
        <td>${escape(it.service_name || '—')}</td>
        <td>${statusText}</td>
        <td class="num">${CURRENCY} ${fmtPrice(cashV)}</td>
        <td class="num">${CURRENCY} ${fmtPrice(cardV)}</td>
        <td class="num">${CURRENCY} ${fmtPrice(onlineV)}</td>
        <td class="num"><strong>${CURRENCY} ${fmtPrice(totalV)}</strong></td>
        <td class="num">${orderV}</td>
      </tr>`;
  }).join('');

  const grandTotal = grandCash + grandCard + grandOnline;

  host.innerHTML = `
    <header class="hov-print-head">
      <h1>Payment Handovers — Summary</h1>
      <div class="hov-print-meta">
        ${today} · printed ${printTime} · ${_items.length} team leader${_items.length === 1 ? '' : 's'}
      </div>
    </header>
    <table class="hov-print-tbl">
      <thead>
        <tr>
          <th>Team Leader</th>
          <th>Service</th>
          <th>Status</th>
          <th class="num">Cash</th>
          <th class="num">Card</th>
          <th class="num">Online</th>
          <th class="num">Total</th>
          <th class="num">Orders</th>
        </tr>
      </thead>
      <tbody>${rows}</tbody>
      <tfoot>
        <tr>
          <td colspan="3"><strong>Grand total</strong></td>
          <td class="num"><strong>${CURRENCY} ${fmtPrice(grandCash)}</strong></td>
          <td class="num"><strong>${CURRENCY} ${fmtPrice(grandCard)}</strong></td>
          <td class="num"><strong>${CURRENCY} ${fmtPrice(grandOnline)}</strong></td>
          <td class="num"><strong>${CURRENCY} ${fmtPrice(grandTotal)}</strong></td>
          <td class="num"><strong>${grandOrders}</strong></td>
        </tr>
      </tfoot>
    </table>
    <footer class="hov-print-foot">
      <div class="hov-print-sig">
        <div class="sig-line">Approved by</div>
      </div>
      <div class="hov-print-sig">
        <div class="sig-line">Received from</div>
      </div>
    </footer>`;

  document.body.appendChild(host);
  document.body.classList.add('hov-printing-summary');

  // Use afterprint to clean up. afterprint fires when the dialog closes
  // (either after print or cancel).
  const cleanup = () => {
    document.body.classList.remove('hov-printing-summary');
    host.remove();
    window.removeEventListener('afterprint', cleanup);
  };
  window.addEventListener('afterprint', cleanup);
  window.print();
}

function paintCards() {
  const tbody = _root.querySelector('#h-rows');
  if (!tbody) return;
  if (!_items.length) {
    tbody.innerHTML = `
      <tr><td colspan="8" class="hov-empty-row">
        <div class="hov-empty-icon">∅</div>
        <div class="hov-empty-title">No team leaders</div>
        <div class="hov-empty-sub">Adjust filters or invite a team leader to get started.</div>
      </td></tr>`;
    return;
  }
  tbody.innerHTML = _items.map(tlRow).join('');

  tbody.querySelectorAll('[data-approve]').forEach((b) =>
    b.addEventListener('click', (e) => { e.stopPropagation(); approveHandover(b.dataset.approve); })
  );
  tbody.querySelectorAll('[data-reject]').forEach((b) =>
    b.addEventListener('click', (e) => { e.stopPropagation(); rejectHandover(b.dataset.reject); })
  );
  tbody.querySelectorAll('[data-view]').forEach((b) =>
    b.addEventListener('click', (e) => { e.stopPropagation(); openDetail(b.dataset.view); })
  );
  tbody.querySelectorAll('[data-print]').forEach((b) =>
    b.addEventListener('click', (e) => { e.stopPropagation(); openDetail(b.dataset.print, { autoPrint: true }); })
  );
}

function tlRow(it) {
  const u = it.unsettled || { cash_amount: 0, card_amount: 0, online_amount: 0, total: 0, order_count: 0 };
  const ph = it.pending_handover;

  let mode;
  if (ph && ph.status === 'pending')       mode = 'submitted';
  else if (ph && ph.status === 'disputed') mode = 'rejected';
  else if ((u.total || 0) > 0.005)         mode = 'collecting';
  else                                     mode = 'idle';

  const initials = (it.team_leader_name || '?')
    .split(/\s+/).filter(Boolean).slice(0, 2)
    .map((w) => w[0].toUpperCase()).join('') || '?';

  const useSubmitted = (mode === 'submitted' || mode === 'rejected');
  const cashV   = useSubmitted ? (ph.cash_amount   || 0) : u.cash_amount;
  const cardV   = useSubmitted ? (ph.card_amount   || 0) : u.card_amount;
  const onlineV = useSubmitted ? (ph.online_amount || 0) : u.online_amount;
  const totalV  = useSubmitted ? (ph.amount        || 0) : u.total;
  const orderV  = useSubmitted ? (ph.order_count   || 0) : u.order_count;

  const statusPill = (() => {
    switch (mode) {
      case 'submitted':
        return `<span class="hov-pill hov-pill-pending" title="Submitted ${ph.created_at ? fmtTime(ph.created_at) : ''}">⧖ Awaiting approval</span>`;
      case 'rejected':
        return `<span class="hov-pill hov-pill-rejected" title="${escape(ph.dispute_reason || '')}">✗ Rejected</span>`;
      case 'collecting':
        return `<span class="hov-pill hov-pill-collecting">● Unsettled</span>`;
      default:
        return `<span class="hov-pill hov-pill-idle">✓ All clear</span>`;
    }
  })();

  const actions = (() => {
    if (mode === 'submitted') {
      return `
        <button class="btn-icon" data-view="${escape(ph.id)}" title="View detail">👁</button>
        <button class="btn-icon" data-print="${escape(ph.id)}" title="Print report">🖨</button>
        <button class="btn-sm btn-reject" data-reject="${escape(ph.id)}">Reject</button>
        <button class="btn-sm btn-approve" data-approve="${escape(ph.id)}">✓ Approve</button>`;
    }
    if (mode === 'rejected') {
      return `<button class="btn-icon" data-view="${escape(ph.id)}" title="View rejected handover">👁</button>
              <span class="hov-row-hint">TL must resubmit</span>`;
    }
    return '<span class="hov-row-hint">—</span>';
  })();

  // Service chip lives under the name
  const serviceChip = it.service_name
    ? `<span class="hov-chip">${escape(it.service_name)}</span>` : '';

  // For zero values, show in muted color
  const moneyCell = (v) => {
    const s = `${CURRENCY} ${fmtPrice(v)}`;
    return v > 0.005
      ? `<span class="tabular hov-money">${s}</span>`
      : `<span class="tabular hov-money-zero">${s}</span>`;
  };

  return `
    <tr class="hov-row hov-row-${mode}">
      <td class="hov-tl-cell">
        <div class="hov-tl-cell-inner">
          <div class="hov-tl-avatar">${escape(initials)}</div>
          <div class="hov-tl-meta">
            <div class="hov-tl-name">${escape(it.team_leader_name || '—')}</div>
            <div class="hov-tl-svc">${serviceChip}</div>
          </div>
        </div>
      </td>
      <td>${statusPill}</td>
      <td class="num">${moneyCell(cashV)}</td>
      <td class="num">${moneyCell(cardV)}</td>
      <td class="num">${moneyCell(onlineV)}</td>
      <td class="num hov-total-cell"><strong class="tabular">${CURRENCY} ${fmtPrice(totalV)}</strong></td>
      <td class="num"><span class="tabular">${orderV}</span></td>
      <td class="actions">${actions}</td>
    </tr>`;
}


// ─── Inline approve/reject ────────────────────────────────────────────
async function approveHandover(id) {
  const item = _items.find((it) => it.pending_handover?.id === id);
  const name = item?.team_leader_name || 'this team leader';
  const amt  = item?.pending_handover?.amount;
  const msg = amt != null
    ? `Approve receipt of ${CURRENCY} ${fmtPrice(amt)} from ${name}?`
    : `Approve this handover from ${name}?`;
  const ok = await confirmDialog(msg);
  if (!ok) return;
  try {
    await api.patch('/handovers/' + id + '/confirm');
    toast('Handover approved — TL notified', 'success');
    await load();
  } catch (e) {
    toast(e.message || 'Approve failed', 'error');
  }
}

async function rejectHandover(id) {
  const reason = prompt('Reason for rejection (required):');
  if (!reason || !reason.trim()) return;
  try {
    await api.patch('/handovers/' + id + '/reject', { reason: reason.trim() });
    toast('Handover rejected — TL will be notified', 'success');
    await load();
  } catch (e) {
    toast(e.message || 'Reject failed', 'error');
  }
}

// ─── Detail modal ────────────────────────────────────────────────────
async function openDetail(id, { autoPrint = false } = {}) {
  let data;
  try { data = (await api.get('/handovers/' + id))?.data; }
  catch (e) { toast(e.message || 'Failed to load handover', 'error'); return; }
  if (!data) return;
  renderDetail(data, { autoPrint });
}

function renderDetail(data, { autoPrint }) {
  const id = data.id;
  const cashAmt   = parseFloat(data.cash_amount   || 0);
  const cardAmt   = parseFloat(data.card_amount   || 0);
  const onlineAmt = parseFloat(data.online_amount || 0);
  const total     = parseFloat(data.amount        || 0);

  const orders = data.orders || [];
  const orderRow = (o) => {
    const cash   = parseFloat(o.cash_amount   || 0);
    const card   = parseFloat(o.card_amount   || 0);
    const online = parseFloat(o.online_amount || 0);
    const amt    = parseFloat(o.amount        || 0);

    // Per-method chips — only render those with a non-zero amount.
    // For single-method orders this is one chip; for split payments
    // we get 2-3 chips inline, which makes the split obvious at a glance.
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

  // Reconciliation: if the sum of contributing orders doesn't match the
  // recorded total, surface a small warning so admin can investigate.
  // Tolerance 1 fil (0.01) to handle rounding.
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

  const statusLbl = { pending: 'Awaiting approval', confirmed: 'Approved', disputed: 'Rejected' }[data.status] || data.status;

  const body = `
    <div class="handover-report" id="handover-print-area">
      <div class="hov-print-header">
        <div class="hov-print-title">Payment Handover Report</div>
        <div class="hov-print-meta">Generated ${fmtTime(new Date().toISOString())}</div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:18px">
        <div>
          <div class="hov-section-h">Team Leader</div>
          <div style="font-weight:700;font-size:15px">${escape(data.team_leader_name)}</div>
          ${data.service_name ? `<div class="hov-chip" style="display:inline-block;margin-top:4px">${escape(data.service_name)}</div>` : ''}
        </div>
        <div>
          <div class="hov-section-h">Status</div>
          <div><span class="badge ${escape(data.status)}">${escape(statusLbl)}</span></div>
        </div>
        <div>
          <div class="hov-section-h">Handover Date</div>
          <div>${escape(data.handover_date)}</div>
        </div>
        <div>
          <div class="hov-section-h">Submitted</div>
          <div>${fmtTime(data.created_at)}</div>
        </div>
      </div>

      <div class="hov-total-card">
        <div class="hov-total-lbl">Total amount</div>
        <div class="hov-total-val">${CURRENCY} <span>${fmtPrice(total)}</span></div>
        <div class="hov-total-sub">from ${data.order_count || 0} ${data.order_count == 1 ? 'order' : 'orders'}</div>
      </div>

      <div class="hov-section-h">Payment breakdown</div>
      <div class="hov-method-grid">
        <div class="hov-method-tile hov-method-cash">
          <div class="hov-method-icon">💵</div>
          <div class="hov-method-label">Cash</div>
          <div class="hov-method-val">${CURRENCY} ${fmtPrice(cashAmt)}</div>
        </div>
        <div class="hov-method-tile hov-method-card">
          <div class="hov-method-icon">💳</div>
          <div class="hov-method-label">Card</div>
          <div class="hov-method-val">${CURRENCY} ${fmtPrice(cardAmt)}</div>
        </div>
        <div class="hov-method-tile hov-method-online">
          <div class="hov-method-icon">🔗</div>
          <div class="hov-method-label">Online</div>
          <div class="hov-method-val">${CURRENCY} ${fmtPrice(onlineAmt)}</div>
        </div>
      </div>

      <div class="hov-section-h">Contributing Orders</div>
      ${ordersBlock}
      ${reconcileWarning}

      ${data.notes ? `
        <div class="hov-section-h" style="margin-top:16px">TL Notes</div>
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

  const printBtn = '<button class="btn" id="hov-print">🖨 Print report</button>';
  const footer = data.status === 'pending'
    ? `${printBtn}
       <button class="btn danger" id="hov-dispute">Reject</button>
       <button class="btn primary" id="hov-confirm">✓ Approve</button>
       <button class="btn" data-close-x>Close</button>`
    : `${printBtn}<button class="btn" data-close-x>Close</button>`;

  const m = openModal({
    title: `Handover · ${escape(data.team_leader_name)}`,
    width: 640, body, footer,
  });
  m.overlay.querySelector('[data-close-x]').addEventListener('click', m.close);

  m.overlay.querySelector('#hov-print').addEventListener('click', () => {
    document.body.classList.add('hov-printing');
    setTimeout(() => {
      window.print();
      const cleanup = () => document.body.classList.remove('hov-printing');
      window.addEventListener('afterprint', cleanup, { once: true });
      setTimeout(cleanup, 1500);
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

  if (autoPrint) {
    setTimeout(() => m.overlay.querySelector('#hov-print')?.click(), 200);
  }
}

// ─── CSS ─────────────────────────────────────────────────────────────
function ensureCss() {
  if (document.getElementById('admin-handovers-css')) return;
  const s = document.createElement('style');
  s.id = 'admin-handovers-css';
  s.textContent = `
    /* ─── Table layout ─── */
    .hov-tbl {
      width: 100%;
      border-collapse: collapse;
      font-size: 13px;
    }
    .hov-tbl thead th {
      text-align: left;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--muted);
      padding: 12px 14px;
      border-bottom: 1px solid var(--border);
      background: var(--hover-bg, #f8fafc);
      position: sticky;
      top: 0;
    }
    .hov-tbl thead th.num     { text-align: right; }
    .hov-tbl thead th.actions { text-align: right; width: 280px; }
    .hov-tbl tbody td {
      padding: 14px;
      border-bottom: 1px solid var(--border);
      vertical-align: middle;
    }
    .hov-tbl tbody td.num     { text-align: right; }
    .hov-tbl tbody td.actions { text-align: right; white-space: nowrap; }
    .hov-tbl tbody tr:last-child td { border-bottom: none; }

    /* Row mode tints — subtle left border + bg */
    .hov-row-submitted  { background: rgba(251,191,36,0.06); }
    .hov-row-submitted  td:first-child { box-shadow: inset 3px 0 0 #fbbf24; }
    .hov-row-rejected   { background: rgba(220,38,38,0.06); }
    .hov-row-rejected   td:first-child { box-shadow: inset 3px 0 0 #dc2626; }
    .hov-row-collecting td:first-child { box-shadow: inset 3px 0 0 #0ea5e9; }
    .hov-row-idle       { color: var(--ink-soft, #64748b); }
    .hov-row-idle .hov-tl-name { color: var(--ink-soft, #64748b); }

    /* TL cell */
    .hov-tl-cell-inner {
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .hov-tl-avatar {
      width: 36px; height: 36px; border-radius: 50%;
      background: var(--accent, #0ea5e9); color: #fff;
      display: flex; align-items: center; justify-content: center;
      font-weight: 800; font-size: 13px;
      flex: 0 0 36px;
    }
    .hov-tl-meta { min-width: 0; }
    .hov-tl-name {
      font-size: 14px;
      font-weight: 700;
      color: var(--ink);
      letter-spacing: -0.01em;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .hov-tl-svc { margin-top: 2px; }
    .hov-chip {
      display: inline-block;
      padding: 2px 7px;
      background: var(--hover-bg, #f1f5f9);
      color: var(--ink-soft, #475569);
      border-radius: 999px;
      font-size: 10px;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }

    /* Status pill in the Status column */
    .hov-pill {
      display: inline-block;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 0.04em;
      padding: 4px 10px;
      border-radius: 999px;
      white-space: nowrap;
    }
    .hov-pill-pending    { background: #fef3c7; color: #92400e; }
    .hov-pill-rejected   { background: #fee2e2; color: #991b1b; }
    .hov-pill-collecting { background: rgba(14,165,233,0.12); color: #0369a1; }
    .hov-pill-idle       { background: #d1fae5; color: #065f46; }

    /* Money cells */
    .hov-money { color: var(--ink); font-weight: 600; }
    .hov-money-zero { color: var(--muted, #94a3b8); }
    .hov-total-cell strong { font-size: 14px; color: var(--ink); }

    /* Action buttons in row */
    .hov-row .actions .btn-icon,
    .hov-row .actions .btn-sm {
      font: inherit;
      cursor: pointer;
      margin-left: 4px;
    }
    .hov-row .actions .btn-icon {
      width: 30px; height: 30px;
      background: transparent;
      border: 1px solid var(--border);
      border-radius: 6px;
      font-size: 14px;
      color: var(--ink-soft);
      vertical-align: middle;
    }
    .hov-row .actions .btn-icon:hover {
      background: var(--hover-bg);
      border-color: var(--accent);
      color: var(--accent);
    }
    .hov-row .actions .btn-sm {
      padding: 6px 12px;
      font-size: 12px;
      font-weight: 600;
      border-radius: 6px;
      border: 1px solid;
    }
    .hov-row .actions .btn-approve {
      background: #16a34a; color: #fff; border-color: #16a34a;
    }
    .hov-row .actions .btn-approve:hover { background: #15803d; border-color: #15803d; }
    .hov-row .actions .btn-reject {
      background: #fff; color: #dc2626; border-color: #fca5a5;
    }
    .hov-row .actions .btn-reject:hover { background: #fef2f2; }
    .hov-row-hint {
      font-size: 11px;
      color: var(--muted);
      font-style: italic;
      margin-left: 6px;
    }

    /* Loading + empty in table form */
    .hov-loading {
      text-align: center;
      padding: 40px;
      color: var(--muted);
      font-size: 13px;
    }
    .hov-empty-row {
      text-align: center;
      padding: 48px 20px;
      color: var(--muted);
    }
    .hov-empty-icon { font-size: 42px; margin-bottom: 8px; opacity: 0.5; }
    .hov-empty-title { font-size: 15px; font-weight: 700; color: var(--ink); margin-bottom: 4px; }
    .hov-empty-sub { font-size: 13px; }

    /* Narrow viewports — collapse the per-method columns so the
     * essentials stay readable. Admins on mobile see Name + Status +
     * Total + Actions. */
    @media (max-width: 900px) {
      .hov-tbl thead th:nth-child(3),
      .hov-tbl thead th:nth-child(4),
      .hov-tbl thead th:nth-child(5),
      .hov-tbl thead th:nth-child(7),
      .hov-tbl tbody td:nth-child(3),
      .hov-tbl tbody td:nth-child(4),
      .hov-tbl tbody td:nth-child(5),
      .hov-tbl tbody td:nth-child(7) {
        display: none;
      }
    }


    .hov-section-h {
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 0.1em;
      text-transform: uppercase;
      color: var(--muted);
      margin-bottom: 4px;
    }
    .hov-total-card {
      /* Solid, darker color so the white AED number reads cleanly.
       * The earlier gradient (#0ea5e9 → #0284c7) was too light at the top
       * which made "AED 20.00" wash out — fails contrast on small viewports. */
      background: #0c4a6e;
      color: #fff;
      padding: 18px;
      border-radius: 10px;
      margin: 14px 0 18px;
      text-align: center;
    }
    .hov-total-lbl {
      font-size: 11px; letter-spacing: 0.1em; text-transform: uppercase;
      font-weight: 700; opacity: 0.85;
    }
    .hov-total-val {
      font-size: 30px; font-weight: 800; letter-spacing: -0.02em;
      margin: 4px 0 2px;
    }
    .hov-total-sub { font-size: 12px; opacity: 0.85; }

    .hov-method-grid {
      display: grid; grid-template-columns: 1fr 1fr 1fr;
      gap: 8px; margin: 8px 0 16px;
    }
    .hov-method-tile {
      background: var(--hover-bg);
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 12px;
      text-align: center;
    }
    .hov-method-cash   { background: #f0fdf4; }
    .hov-method-card   { background: #eff6ff; }
    .hov-method-online { background: #faf5ff; }
    .hov-method-icon { font-size: 20px; }
    .hov-method-label { font-size: 10px; font-weight: 700; letter-spacing: 0.1em;
      text-transform: uppercase; color: var(--muted); margin-top: 4px; }
    .hov-method-val { font-weight: 700; font-size: 14px; color: var(--ink); margin-top: 2px; }

    .hov-notes-box {
      background: var(--hover-bg); padding: 10px 12px; border-radius: 8px;
      font-size: 13px; line-height: 1.5; white-space: pre-wrap;
      color: var(--ink-soft);
    }

    .hov-status-banner {
      margin-top: 14px; padding: 12px 14px; border-radius: 8px;
      font-size: 13px; line-height: 1.5;
    }
    .hov-status-confirmed { background: #d1fae5; color: #065f46; }
    .hov-status-disputed  { background: #fee2e2; color: #991b1b; }
    .serial-pill {
      display: inline-block; padding: 2px 8px;
      background: var(--hover-bg); border-radius: 999px;
      font-size: 11px; font-weight: 600; color: var(--ink-soft);
      font-variant-numeric: tabular-nums;
    }

    /* ─── Contributing-order rows ─── */
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

    /* Reconciliation mismatch warning */
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

    @media (max-width: 600px) {
      .ord-row {
        grid-template-columns: auto 1fr;
        grid-template-areas:
          "serial num"
          "methods methods"
          "total total";
        row-gap: 4px;
      }
      .ord-row .serial-pill { grid-area: serial; }
      .ord-row .ord-num     { grid-area: num; }
      .ord-row .ord-methods { grid-area: methods; justify-content: flex-start; }
      .ord-row .ord-total   { grid-area: total; justify-self: end; }
    }

    .hov-print-header, .hov-print-sign { display: none; }

    @media print {
      @page { size: A4 portrait; margin: 0; }
      html, body {
        background: #fff !important;
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
        display: block !important;
      }
      body.hov-printing > *:not(.modal-overlay) { display: none !important; }
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
      body.hov-printing #handover-print-area {
        width: 100% !important;
        max-width: none !important;
        padding: 16mm 14mm !important;
        box-sizing: border-box !important;
      }
      body.hov-printing .hov-print-header { display: block; margin-bottom: 18px; }
      body.hov-printing .hov-print-title {
        font-size: 22px; font-weight: 800; text-align: center; margin-bottom: 4px;
      }
      body.hov-printing .hov-print-meta {
        font-size: 11px; text-align: center; color: #666;
      }
      body.hov-printing .hov-print-sign { display: block; margin-top: 40px; }
      body.hov-printing .hov-print-sign-row {
        display: grid; grid-template-columns: 1fr 1fr; gap: 50px;
      }
      body.hov-printing .hov-print-sign-line {
        border-bottom: 1px solid #000; height: 40px; margin-bottom: 6px;
      }
      body.hov-printing .hov-print-sign-label {
        font-size: 11px; color: #444; text-align: center;
      }
      body.hov-printing .hov-total-card,
      body.hov-printing .hov-method-tile,
      body.hov-printing .badge,
      body.hov-printing .hov-status-banner {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
      }

      /* ─── Print summary (Print all TLs button) ───
       * When .hov-printing-summary is on body, hide everything except
       * the dynamically-mounted #h-print-all-host block. */
      body.hov-printing-summary > *:not(#h-print-all-host) { display: none !important; }
      body.hov-printing-summary #h-print-all-host {
        display: block !important;
        padding: 12mm 12mm 16mm !important;
        box-sizing: border-box !important;
        width: 100% !important;
      }
    }

    /* Hidden by default; only visible when .hov-printing-summary is active */
    .hov-print-summary { display: none; }

    @media print {
      .hov-print-summary {
        font-family: -apple-system, system-ui, sans-serif;
        color: #000;
        font-size: 11px;
      }
      .hov-print-head h1 {
        font-size: 18px; font-weight: 800;
        margin: 0 0 4px 0;
      }
      .hov-print-meta {
        font-size: 11px; color: #555;
        margin-bottom: 14px;
      }
      .hov-print-tbl {
        width: 100%; border-collapse: collapse;
        font-size: 11px;
      }
      .hov-print-tbl th,
      .hov-print-tbl td {
        padding: 6px 8px;
        border-bottom: 1px solid #ddd;
        text-align: left;
      }
      .hov-print-tbl th.num,
      .hov-print-tbl td.num { text-align: right; }
      .hov-print-tbl thead th {
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        font-weight: 700;
        border-bottom: 2px solid #000;
      }
      .hov-print-tbl tfoot td {
        border-top: 2px solid #000;
        border-bottom: none;
        font-size: 12px;
        padding-top: 8px;
      }
      .hov-print-foot {
        margin-top: 32px;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 60px;
      }
      .hov-print-sig .sig-line {
        margin-top: 36px;
        padding-top: 4px;
        border-top: 1px solid #000;
        font-size: 11px;
        color: #444;
      }
    }
  `;
  document.head.appendChild(s);
}