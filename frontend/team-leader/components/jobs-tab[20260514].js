// frontend/team-leader/components/jobs-tab.js
//
// My Jobs tab — the TL's main workhorse.
//
// Features:
//   • Status chips (Active / Accepted / In progress / Completed) with counts
//   • Filters: employee dropdown, date range, free-text search
//   • Each job card shows: serial, plate, customer, location, employee,
//     payment block, status badge, and Start / Complete / Print Slip buttons
//   • Start: PATCH /api/tl/jobs/{id}/start  (accepted → in_progress)
//   • Complete: PATCH /api/tl/jobs/{id}/complete  (in_progress → completed)
//   • Both operations are race-safe (server uses atomic UPDATE) and
//     offline-queued via the sync engine on network failure
//   • Print Slip: opens a hidden slip element populated from /slip endpoint
//     then calls window.print(). Print CSS hides everything else.
//   • Cache-first paint from IndexedDB so the list shows instantly offline
//
// Notes:
//   • "Active" chip is the default — it's what TLs see most often
//   • Pagination is "load more" style (cursor by page number)
//   • Polling every 20s while the tab is visible — slower than Available
//     since the TL is acting on jobs in their list, not waiting for new ones

import * as api    from '../api.js';
import * as state  from '../state.js';
import { dbReplaceAll, dbGetAll, kvGet, kvSet } from '../pwa/db.js';
import { queueAction } from '../pwa/sync-engine.js';

const POLL_MS  = 20_000;
const CURRENCY = 'AED';
const PAGE_SIZE = 25;

let _root  = null;
let _timer = null;

// Filter state — persisted to IndexedDB.kv so it survives reloads
let _filter = {
  status:      'active',
  employee_id: '',
  from:        '',
  to:          '',
  q:           '',
};

// Pagination
let _items   = [];
let _page    = 1;
let _hasMore = false;
let _total   = 0;
let _busy    = new Set();
let _payOpen  = new Set();    // job ids whose inline record-payment form is open
let _payDraft = new Map();    // job id → { method, amount, ref }

// Employee dropdown source (TL's own team)
let _employees = [];

// Modal element refs (lazy)
let _slipEl = null;

// ─── Lifecycle ─────────────────────────────────────────────────────
export async function init(root) {
  _root = root;
  injectStyles();

  // Restore last-used filter
  try {
    const saved = await kvGet('jobs_filter');
    if (saved) Object.assign(_filter, saved);
  } catch {}

  paintShell();
  attachVisibility();
  await loadEmployees();      // populate the dropdown
  paintFilterBar();           // re-paint with employees loaded

  // Cache-first paint
  try {
    const cached = await dbGetAll('jobs_cache');
    if (cached?.length) {
      _items = cached;
      paintList();
    }
  } catch {}

  await refresh();
  startPolling();
}

export function onShow() {
  refresh();
  startPolling();
}

export async function refresh() {
  if (!navigator.onLine) return;
  _page = 1;
  await fetchPage(false);
}

// ─── Polling ───────────────────────────────────────────────────────
function startPolling() {
  stopPolling();
  _timer = setInterval(() => { if (!document.hidden) silentRefresh(); }, POLL_MS);
}
function stopPolling() {
  if (_timer) { clearInterval(_timer); _timer = null; }
}
function attachVisibility() {
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) stopPolling();
    else { silentRefresh(); startPolling(); }
  });
  window.addEventListener('online', () => silentRefresh());
}
async function silentRefresh() {
  // Re-fetch page 1 without resetting the user's scroll position
  const prevPage = _page;
  _page = 1;
  await fetchPage(false, true);
  if (prevPage > 1) {
    // If they had scrolled past page 1 we don't reload further pages;
    // they can pull-down or change filter
  }
}

// ─── Data fetching ─────────────────────────────────────────────────
async function fetchPage(append, silent = false) {
  if (!silent && !append) paintLoading();
  try {
    const params = new URLSearchParams();
    if (_filter.status)      params.set('status',      _filter.status);
    if (_filter.employee_id) params.set('employee_id', _filter.employee_id);
    if (_filter.from)        params.set('from',        _filter.from);
    if (_filter.to)          params.set('to',          _filter.to);
    if (_filter.q)           params.set('q',           _filter.q);
    params.set('page',  String(_page));
    params.set('limit', String(PAGE_SIZE));

    const res = await api.get('/tl/jobs?' + params.toString());
    const data = res?.data ?? {};
    const items = data.items ?? [];
    const meta  = data.meta  ?? {};

    if (append) _items = [..._items, ...items];
    else        _items = items;

    _hasMore = _page < (meta.total_pages || 1);
    _total   = meta.total ?? _items.length;
    paintCount();
    paintList();

    // Persist for offline
    if (!append) {
      try { await dbReplaceAll('jobs_cache', _items); } catch {}
    }

    state.set('jobsCount', _items.filter(j => j.status !== 'completed' && j.status !== 'cancelled').length);
    updateBadge(state.get('jobsCount'));
  } catch (e) {
    if (!silent) toast(e.message || 'Failed to load jobs', 'error');
  }
}

async function loadEmployees() {
  // The TL can filter by employee in their team. Lookup endpoint is
  // for code-search; for a list we use admin employees endpoint with
  // team_leader_id = self (admin-only) — falls back to nothing for TL.
  // Instead, derive from the jobs themselves so we don't need a new
  // endpoint: scan _items + cached jobs for unique employees.
  // For first load, we just leave it empty and it populates as data flows.
  _employees = [];
  refreshEmployeeList();
}
function refreshEmployeeList() {
  const seen = new Map();
  for (const j of _items) {
    if (j.employee_id && !seen.has(j.employee_id)) {
      seen.set(j.employee_id, {
        id:    j.employee_id,
        name:  j.employee_name || '—',
        code:  j.employee_code || '',
      });
    }
  }
  _employees = Array.from(seen.values()).sort((a, b) =>
    (a.code || a.name).localeCompare(b.code || b.name)
  );
}

function updateBadge(n) {
  const badge = document.getElementById('badge-jobs');
  if (!badge) return;
  if (n > 0) {
    badge.textContent = n > 99 ? '99+' : String(n);
    badge.hidden = false;
  } else {
    badge.hidden = true;
  }
}

// ─── Rendering ─────────────────────────────────────────────────────
function paintShell() {
  _root.innerHTML = `
    <h1 class="tab-h"><small>Your work</small>My jobs</h1>
    <div id="jobs-filterbar"></div>
    <div id="jobs-count" class="jobs-count"></div>
    <div id="jobs-list"></div>
    <div id="jobs-more"></div>
  `;
}

function paintFilterBar() {
  const host = _root.querySelector('#jobs-filterbar');
  if (!host) return;

  const empOptions = _employees.length
    ? _employees.map((e) => `
        <option value="${escapeAttr(e.id)}" ${_filter.employee_id === e.id ? 'selected' : ''}>
          ${escape(e.code ? `${e.code} — ${e.name}` : e.name)}
        </option>`).join('')
    : '';

  host.innerHTML = `
    <div class="status-chips">
      ${chip('active',      'Active')}
      ${chip('accepted',    'Accepted')}
      ${chip('in_progress', 'In progress')}
      ${chip('completed',   'Completed')}
    </div>

    <details class="filters-more">
      <summary>More filters</summary>
      <div class="filters-grid">
        <label class="filt">
          <span>Employee</span>
          <select id="filt-emp">
            <option value="">All employees</option>
            ${empOptions}
          </select>
        </label>
        <label class="filt">
          <span>From</span>
          <input id="filt-from" type="date" value="${escapeAttr(_filter.from || '')}">
        </label>
        <label class="filt">
          <span>To</span>
          <input id="filt-to" type="date" value="${escapeAttr(_filter.to || '')}">
        </label>
        <label class="filt filt-full">
          <span>Search</span>
          <input id="filt-q" type="search"
                 placeholder="plate, customer name, phone…"
                 value="${escapeAttr(_filter.q || '')}">
        </label>
        <button id="filt-clear" class="filt-clear">Clear filters</button>
      </div>
    </details>
  `;

  host.querySelectorAll('.chip').forEach((c) => {
    c.addEventListener('click', () => {
      _filter.status = c.dataset.status;
      persistFilter();
      _page = 1; fetchPage(false);
      paintFilterBar();
    });
  });
  host.querySelector('#filt-emp').addEventListener('change', (e) => {
    _filter.employee_id = e.target.value;
    persistFilter(); _page = 1; fetchPage(false);
  });
  host.querySelector('#filt-from').addEventListener('change', (e) => {
    _filter.from = e.target.value;
    persistFilter(); _page = 1; fetchPage(false);
  });
  host.querySelector('#filt-to').addEventListener('change', (e) => {
    _filter.to = e.target.value;
    persistFilter(); _page = 1; fetchPage(false);
  });
  const qInput = host.querySelector('#filt-q');
  let qTimer;
  qInput.addEventListener('input', (e) => {
    clearTimeout(qTimer);
    qTimer = setTimeout(() => {
      _filter.q = e.target.value.trim();
      persistFilter(); _page = 1; fetchPage(false);
    }, 350);
  });
  host.querySelector('#filt-clear').addEventListener('click', () => {
    _filter = { status: 'active', employee_id: '', from: '', to: '', q: '' };
    persistFilter(); _page = 1; fetchPage(false);
    paintFilterBar();
  });
}

function chip(value, label) {
  const active = _filter.status === value;
  return `<button class="chip ${active ? 'active' : ''}" data-status="${value}">${label}</button>`;
}

function paintCount() {
  const el = _root.querySelector('#jobs-count');
  if (!el) return;
  if (_total > 0) {
    el.textContent = `${_total} ${_total === 1 ? 'job' : 'jobs'}`;
  } else {
    el.textContent = '';
  }
}

function paintLoading() {
  _root.querySelector('#jobs-list').innerHTML = `
    <div class="loading"><div class="spinner"></div>Loading…</div>`;
}

function paintList() {
  // Refresh the employee dropdown if new employees appeared in data
  refreshEmployeeList();
  const empSel = _root.querySelector('#filt-emp');
  if (empSel && _employees.length && empSel.options.length - 1 !== _employees.length) {
    // re-render the bar to update the select
    paintFilterBar();
  }

  const host = _root.querySelector('#jobs-list');
  if (!host) return;

  if (!_items.length) {
    host.innerHTML = `
      <div class="empty-state">
        <div class="empty-state-glyph">◈</div>
        <div class="empty-state-msg">
          No jobs match your filters.
        </div>
      </div>`;
    _root.querySelector('#jobs-more').innerHTML = '';
    return;
  }

  host.innerHTML = _items.map(card).join('');

  host.querySelectorAll('[data-start]').forEach((b) =>
    b.addEventListener('click', () => doStart(b.dataset.start)));
  host.querySelectorAll('[data-complete]').forEach((b) =>
    b.addEventListener('click', () => doComplete(b.dataset.complete)));
  host.querySelectorAll('[data-slip]').forEach((b) =>
    b.addEventListener('click', () => doPrintSlip(b.dataset.slip)));
  host.querySelectorAll('[data-photo]').forEach((img) =>
    img.addEventListener('click', () => openPhoto(img.dataset.photo)));

  // Record-payment inline form
  host.querySelectorAll('[data-recordpay]').forEach((b) =>
    b.addEventListener('click', () => openRecordPay(b.dataset.recordpay)));
  host.querySelectorAll('[data-payform-cancel]').forEach((b) =>
    b.addEventListener('click', () => closeRecordPay(b.dataset.payformCancel)));
  host.querySelectorAll('[data-payform-save]').forEach((b) =>
    b.addEventListener('click', () => saveRecordPay(b.dataset.payformSave)));
  host.querySelectorAll('[data-payform-method]').forEach((b) =>
    b.addEventListener('click', () => {
      const id = b.dataset.job;
      const d = _payDraft.get(id) || {};
      d.method = b.dataset.payformMethod;
      _payDraft.set(id, d);
      paintList();
    }));
  host.querySelectorAll('[data-payform-amount]').forEach((inp) =>
    inp.addEventListener('input', () => {
      const id = inp.dataset.payformAmount;
      const d = _payDraft.get(id) || {};
      d.amount = parseFloat(inp.value) || 0;
      _payDraft.set(id, d);
    }));
  host.querySelectorAll('[data-payform-ref]').forEach((inp) =>
    inp.addEventListener('input', () => {
      const id = inp.dataset.payformRef;
      const d = _payDraft.get(id) || {};
      d.ref = inp.value;
      _payDraft.set(id, d);
    }));

  const more = _root.querySelector('#jobs-more');
  if (_hasMore) {
    more.innerHTML = `<button id="more-btn" class="more-btn">Load more</button>`;
    more.querySelector('#more-btn').addEventListener('click', async () => {
      _page += 1;
      const btn = more.querySelector('#more-btn');
      if (btn) { btn.disabled = true; btn.textContent = 'Loading…'; }
      await fetchPage(true);
    });
  } else {
    more.innerHTML = '';
  }
}

function card(j) {
  const location = formatLocation(j);
  const isBusy   = _busy.has(j.id);
  const showVehicle = state.needsVehicleDetails();
  const photo = showVehicle && j.image_path
    ? `<img class="jobs-photo" data-photo="${escapeAttr(j.image_path)}"
            src="${escapeAttr(resolvePhotoUrl(j.image_path))}" alt="" loading="lazy"
            onerror="this.style.display='none'">`
    : '';

  const total   = parseFloat(j.total_amount || 0);
  const paid    = parseFloat(j.paid_amount  || 0);
  const balance = Math.max(0, total - paid);
  const isPaid  = j.payment_status === 'paid';
  const showPayForm = _payOpen.has(j.id);

  // Action buttons depend on status + payment
  let actions = '';
  if (j.status === 'accepted') {
    actions = `
      <button class="btn-start" data-start="${escapeAttr(j.id)}" ${isBusy ? 'disabled' : ''}>
        ${isBusy ? '…' : '▶ Start service'}
      </button>`;
  } else if (j.status === 'in_progress') {
    if (isPaid) {
      actions = `
        <button class="btn-complete" data-complete="${escapeAttr(j.id)}" ${isBusy ? 'disabled' : ''}>
          ${isBusy ? '…' : '✓ Mark complete'}
        </button>`;
    } else {
      actions = `
        <button class="btn-complete" data-complete="${escapeAttr(j.id)}"
                title="Balance must be paid first" disabled>
          ✓ Mark complete
        </button>
        <div class="jobs-blocked-hint">
          Balance ${CURRENCY} ${formatPrice(balance)} must be paid before completion.
        </div>`;
    }
  } else if (j.status === 'completed') {
    actions = `<div class="jobs-done-stamp">Completed ${escape(formatTime(j.completed_at))}</div>`;
  } else if (j.status === 'cancelled') {
    actions = `<div class="jobs-done-stamp" style="color:var(--bad)">Cancelled</div>`;
  } else {
    actions = `<button class="btn-start" data-start="${escapeAttr(j.id)}">▶ Start service</button>`;
  }

  // Record-payment button (only while there's a balance and job is still active)
  const isActive = j.status === 'accepted' || j.status === 'in_progress';
  const recordPayBtn = (isActive && balance > 0.005 && !showPayForm)
    ? `<button class="btn-record-pay" data-recordpay="${escapeAttr(j.id)}">
         💰 Record payment (${CURRENCY} ${formatPrice(balance)})
       </button>`
    : '';

  // Inline record-payment form
  const draft = _payDraft.get(j.id) || { method: 'cash', amount: balance, ref: '' };
  const payForm = showPayForm
    ? `<div class="jobs-payform" data-payform-for="${escapeAttr(j.id)}">
         <div class="jobs-payform-row">
           <button class="pm-mini ${draft.method === 'cash'   ? 'active' : ''}" data-payform-method="cash"   data-job="${escapeAttr(j.id)}">💵 Cash</button>
           <button class="pm-mini ${draft.method === 'card'   ? 'active' : ''}" data-payform-method="card"   data-job="${escapeAttr(j.id)}">💳 Card</button>
           <button class="pm-mini ${draft.method === 'online' ? 'active' : ''}" data-payform-method="online" data-job="${escapeAttr(j.id)}">🔗 Online</button>
         </div>
         <input class="jobs-payform-amount" type="number" step="0.01" min="0.01" max="${balance.toFixed(2)}"
                value="${escapeAttr(String(draft.amount))}"
                data-payform-amount="${escapeAttr(j.id)}"
                placeholder="Amount in ${CURRENCY}">
         <input class="jobs-payform-ref" type="text"
                value="${escapeAttr(draft.ref || '')}"
                data-payform-ref="${escapeAttr(j.id)}"
                placeholder="Reference (optional)">
         <div class="jobs-payform-actions">
           <button class="btn-record-cancel" data-payform-cancel="${escapeAttr(j.id)}">Cancel</button>
           <button class="btn-record-save"   data-payform-save="${escapeAttr(j.id)}" ${isBusy ? 'disabled' : ''}>
             ${isBusy ? 'Saving…' : 'Record'}
           </button>
         </div>
       </div>`
    : '';

  // Payment block
  const payments = Array.isArray(j.payments) ? j.payments : [];
  const paymentBlock = payments.length
    ? `<div class="jobs-pay">
         ${payments.map((p) => `
           <div class="jobs-pay-row">
             <span class="jobs-pay-method">${escape(p.method || p.payment_method || '—')}</span>
             <span class="jobs-pay-amount tabular">${CURRENCY} ${formatPrice(p.amount)}</span>
             ${p.transaction_ref ? `<span class="jobs-pay-ref">${escape(p.transaction_ref)}</span>` : ''}
           </div>`).join('')}
         <div class="jobs-pay-summary">
           <span>Total ${CURRENCY} ${formatPrice(total)} · Paid ${formatPrice(paid)}${balance > 0 ? ` · <b style="color:var(--bad)">Balance ${formatPrice(balance)}</b>` : ' <span style="color:var(--good)">· paid in full</span>'}</span>
         </div>
       </div>`
    : `<div class="jobs-pay-empty">
         No payment recorded · ${CURRENCY} ${formatPrice(total)} due
       </div>`;

  const empLine = j.employee_name
    ? `<div class="jobs-row"><span class="label">Employee</span>
         <span>${escape(j.employee_code ? `${j.employee_code} · ${j.employee_name}` : j.employee_name)}</span>
       </div>` : '';

  return `
    <article class="card jobs-card" data-id="${escapeAttr(j.id)}">
      <div class="jobs-top">
        <div class="jobs-left">
          <span class="serial-badge tabular">#${parseInt(j.daily_serial, 10) || '—'}</span>
          <div>
            ${showVehicle
              ? `<div class="jobs-plate">${escape(j.vehicle_plate || '—')}</div>
                 <div class="jobs-svc">${escape(j.service_name || '')}</div>`
              : `<div class="jobs-svc jobs-svc-lg">${escape(j.service_name || '')}</div>`}
          </div>
        </div>
        <div class="jobs-right">
          <span class="tag ${j.status}">${escape(statusLabel(j.status))}</span>
          <div class="jobs-price tabular">${CURRENCY} ${formatPrice(j.price)}</div>
        </div>
      </div>

      ${location ? `
        <div class="jobs-row"><span class="label">Location</span>
          <span>${escape(location)}</span>
        </div>` : ''}
      ${empLine}

      ${photo ? `<div class="jobs-photo-wrap">${photo}</div>` : ''}

      ${paymentBlock}

      ${recordPayBtn}
      ${payForm}

      <div class="jobs-actions">
        ${actions}
        <button class="btn-slip" data-slip="${escapeAttr(j.id)}">🖨 Slip</button>
      </div>
    </article>`;
}

function statusLabel(s) {
  return {
    pending:     'Pending',
    assigned:    'Assigned',
    accepted:    'Accepted',
    in_progress: 'In progress',
    completed:   'Completed',
    cancelled:   'Cancelled',
    rejected:    'Rejected',
  }[s] || s;
}

// ─── Actions ───────────────────────────────────────────────────────
async function doStart(svcId) {
  return transition(svcId, 'start', 'in_progress', '▶ Start service');
}
async function doComplete(svcId) {
  return transition(svcId, 'complete', 'completed', '✓ Mark complete');
}

// ─── Record-payment handlers ───────────────────────────────────────
function openRecordPay(svcId) {
  // Seed draft with sensible defaults (full balance, cash) if not set yet
  if (!_payDraft.has(svcId)) {
    const j = _items.find((x) => x.id === svcId);
    const bal = j ? Math.max(0, parseFloat(j.total_amount || 0) - parseFloat(j.paid_amount || 0)) : 0;
    _payDraft.set(svcId, { method: 'cash', amount: bal, ref: '' });
  }
  _payOpen.add(svcId);
  paintList();
}

function closeRecordPay(svcId) {
  _payOpen.delete(svcId);
  _payDraft.delete(svcId);
  paintList();
}

async function saveRecordPay(svcId) {
  if (_busy.has(svcId)) return;

  const j = _items.find((x) => x.id === svcId);
  if (!j) { toast('Job not found', 'error'); return; }

  const draft = _payDraft.get(svcId) || {};
  const method = draft.method || 'cash';
  const amount = parseFloat(draft.amount || 0);
  const balance = Math.max(0, parseFloat(j.total_amount || 0) - parseFloat(j.paid_amount || 0));

  // Validate
  if (!['cash','card','online'].includes(method)) {
    toast('Pick cash, card, or online', 'error'); return;
  }
  if (!(amount > 0)) {
    toast('Enter an amount greater than zero', 'error'); return;
  }
  if (amount > balance + 0.005) {
    toast(`Amount can't exceed balance (${CURRENCY} ${formatPrice(balance)})`, 'error'); return;
  }

  _busy.add(svcId);
  paintList();

  const payload = {
    payment_method:  method,
    amount:          amount,
    transaction_ref: draft.ref || null,
    uuid_ref:        crypto.randomUUID(),
  };

  // Offline: queue and update locally so the UI reflects the new balance
  if (!navigator.onLine) {
    await queueAction({
      entity_type: 'payment',
      entity_id:   j.order_id,
      action_type: 'record',
      payload,
    });
    // Optimistic update — local card reflects the new state
    const newPaid = parseFloat(j.paid_amount || 0) + amount;
    j.paid_amount = newPaid;
    j.payment_status = newPaid >= parseFloat(j.total_amount || 0) ? 'paid' : 'partial';
    j.payments = Array.isArray(j.payments) ? j.payments : [];
    j.payments.push({
      method, amount,
      transaction_ref: draft.ref || null,
      status: 'success',
      created_at: new Date().toISOString(),
    });
    toast('Payment queued — will sync when reconnected');
    _payOpen.delete(svcId);
    _payDraft.delete(svcId);
    _busy.delete(svcId);
    paintList();
    return;
  }

  try {
    const res = await api.post(`/orders/${j.order_id}/payments`, payload);
    // Server-truth values
    const newPaid   = parseFloat(res?.data?.paid_amount    ?? j.paid_amount);
    const newStatus = res?.data?.payment_status            ?? j.payment_status;
    j.paid_amount     = newPaid;
    j.payment_status  = newStatus;
    j.payments = Array.isArray(j.payments) ? j.payments : [];
    j.payments.push({
      method, amount,
      transaction_ref: draft.ref || null,
      status: 'success',
      created_at: new Date().toISOString(),
    });

    toast(newStatus === 'paid'
      ? 'Payment recorded — order fully paid'
      : `Payment recorded — ${CURRENCY} ${formatPrice(Math.max(0, parseFloat(j.total_amount) - newPaid))} balance remains`,
      'success');

    _payOpen.delete(svcId);
    _payDraft.delete(svcId);
  } catch (e) {
    if (e.code === 'network' || e.status === 0) {
      // Connection dropped between the validity check and the request
      await queueAction({
        entity_type: 'payment',
        entity_id:   j.order_id,
        action_type: 'record',
        payload,
      });
      toast('Payment queued — will sync when reconnected');
      _payOpen.delete(svcId);
      _payDraft.delete(svcId);
    } else {
      toast(e.message || 'Could not record payment', 'error');
    }
  } finally {
    _busy.delete(svcId);
    paintList();
  }
}

async function transition(svcId, action, newStatus, defaultLabel) {
  if (_busy.has(svcId)) return;
  _busy.add(svcId);
  setRowDisabled(svcId, true);

  // Optimistically update local card state for instant feel
  const local = _items.find((j) => j.id === svcId);
  const prevStatus = local?.status;
  if (local) {
    local.status = newStatus;
    if (newStatus === 'in_progress') local.started_at   = new Date().toISOString();
    if (newStatus === 'completed')   local.completed_at = new Date().toISOString();
    paintList();
  }

  if (!navigator.onLine) {
    await queueAction({
      entity_type: 'service',
      entity_id:   svcId,
      action_type: action,
    });
    toast(`Saved — will ${action} when back online`);
    _busy.delete(svcId);
    return;
  }

  try {
    await api.patch(`/tl/jobs/${svcId}/${action}`);
    toast(action === 'start' ? 'Service started' : 'Job completed', 'success');
  } catch (e) {
    if (e.code === 'network' || e.status === 0) {
      await queueAction({
        entity_type: 'service',
        entity_id:   svcId,
        action_type: action,
      });
      toast(`Saved — will ${action} when reconnected`);
    } else {
      // Roll back optimistic change
      if (local && prevStatus) {
        local.status = prevStatus;
        paintList();
      }
      // Server signals payment-blocked completion with a special code
      if (e.data?.code === 'UNPAID_BALANCE' || /balance|unpaid|partial/i.test(e.message || '')) {
        toast(e.message || 'Balance must be paid before completion', 'error');
      } else {
        toast(e.message || `Could not ${action}`, 'error');
      }
    }
  } finally {
    _busy.delete(svcId);
  }
}

// ─── Slip printing ─────────────────────────────────────────────────
async function doPrintSlip(svcId) {
  let slipData = null;
  if (navigator.onLine) {
    try {
      const res = await api.get(`/tl/jobs/${svcId}/slip`);
      slipData = res?.data ?? null;
    } catch (e) {
      // Fall back to cached job
    }
  }
  if (!slipData) {
    const j = _items.find((x) => x.id === svcId);
    if (!j) {
      toast('Cannot print — open job offline first', 'error');
      return;
    }
    slipData = {
      daily_serial:     j.daily_serial,
      serial_date:      j.serial_date,
      service_name:     j.service_name,
      vehicle_plate:    state.needsVehicleDetails() ? j.vehicle_plate : null,
      location_name:    j.location_name,
      location_terminal: j.location_terminal,
      location_details: j.location_details,
      employee_name:    j.employee_name,
      employee_code:    j.employee_code,
      team_leader_name: state.get('user')?.name,
      order_number:     j.order_number,
      paid_total:       (Array.isArray(j.payments) ? j.payments : [])
                        .reduce((a, p) => a + (parseFloat(p.amount) || 0), 0),
      payment_methods:  (Array.isArray(j.payments) ? j.payments : [])
                        .map((p) => p.method || p.payment_method).filter(Boolean).join(', '),
      order_time:       j.accepted_at || j.created_at,
      currency:         CURRENCY,
      app_name:         'Airport VAS',
    };
  }

  renderSlip(slipData);
  // Give the browser a tick to paint, then print
  setTimeout(() => window.print(), 50);
}

export function renderSlip(s) {
  if (!_slipEl) {
    _slipEl = document.createElement('div');
    _slipEl.id = 'print-slip';
    document.body.appendChild(_slipEl);
  }
  const loc = [s.location_terminal, s.location_name, s.location_details]
              .filter(Boolean).join(' · ');
  const paidPart = s.paid_total
    ? `<div class="slip-row"><span>Paid</span>
         <span>${escape(s.currency)} ${formatPrice(s.paid_total)} ` +
         (s.payment_methods ? `<small>(${escape(s.payment_methods)})</small>` : '') +
       `</span></div>`
    : '';

  _slipEl.innerHTML = `
    <div class="slip-inner">
      <div class="slip-brand">${escape(s.app_name || 'Airport VAS')}</div>
      <div class="slip-sub">UAE Airport Services</div>
      <div class="slip-divider"></div>

      <div class="slip-serial">#${parseInt(s.daily_serial, 10) || '—'}</div>
      <div class="slip-svc">${escape(s.service_name || '')}</div>
      <div class="slip-date">${escape(formatDate(s.serial_date || s.order_time))}</div>

      <div class="slip-divider dashed"></div>

      ${s.vehicle_plate ? `<div class="slip-row"><span>Vehicle</span><span><b>${escape(s.vehicle_plate)}</b></span></div>` : ''}
      ${loc ? `<div class="slip-row"><span>Location</span><span>${escape(loc)}</span></div>` : ''}
      ${s.employee_name ? `<div class="slip-row"><span>Employee</span><span>${escape(s.employee_code ? `${s.employee_code} · ${s.employee_name}` : s.employee_name)}</span></div>` : ''}
      ${s.team_leader_name ? `<div class="slip-row"><span>Team leader</span><span>${escape(s.team_leader_name)}</span></div>` : ''}
      ${s.order_number ? `<div class="slip-row"><span>Order #</span><span class="tabular">${escape(String(s.order_number))}</span></div>` : ''}
      <div class="slip-row"><span>Time</span><span>${escape(formatTime(s.order_time))}</span></div>

      <div class="slip-divider dashed"></div>
      ${paidPart}

      <div class="slip-footer">Thank you · شكراً</div>
    </div>
  `;
}

// ─── DOM helpers ───────────────────────────────────────────────────
function setRowDisabled(svcId, disabled) {
  const card = _root.querySelector(`[data-id="${cssEscape(svcId)}"]`);
  if (!card) return;
  card.querySelectorAll('button').forEach((b) => { b.disabled = disabled; });
}

function openPhoto(src) {
  let overlay = document.querySelector('.photo-overlay');
  if (!overlay) {
    overlay = document.createElement('div');
    overlay.className = 'photo-overlay';
    overlay.addEventListener('click', () => overlay.classList.remove('open'));
    document.body.appendChild(overlay);
  }
  overlay.innerHTML = `<img src="${escapeAttr(resolvePhotoUrl(src))}" alt="">`;
  overlay.classList.add('open');
}

async function persistFilter() {
  try { await kvSet('jobs_filter', _filter); } catch {}
}

// ─── Format helpers ────────────────────────────────────────────────
function formatLocation(o) {
  const parts = [];
  if (o.location_terminal) parts.push(o.location_terminal);
  if (o.location_name)     parts.push(o.location_name);
  if (o.location_details && !parts.some(p => o.location_details.includes(p))) {
    parts.push(o.location_details);
  }
  return parts.join(' · ');
}
function formatPrice(p) {
  const n = parseFloat(p);
  return Number.isFinite(n) ? n.toFixed(2) : '—';
}
function formatTime(iso) {
  if (!iso) return '—';
  const dt = new Date(String(iso).replace(' ', 'T') + (String(iso).endsWith('Z') ? '' : 'Z'));
  if (Number.isNaN(dt.getTime())) return '—';
  return dt.toLocaleString('en-GB', {
    day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit',
    timeZone: 'Asia/Dubai', hour12: false,
  });
}
function formatDate(s) {
  if (!s) return '';
  if (s.length === 10) return s; // YYYY-MM-DD
  const dt = new Date(String(s).replace(' ', 'T') + (String(s).endsWith('Z') ? '' : 'Z'));
  if (Number.isNaN(dt.getTime())) return '';
  return dt.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'Asia/Dubai' });
}
function resolvePhotoUrl(p) {
  if (!p) return '';
  if (/^https?:\/\//.test(p)) return p;
  return location.origin + (p.startsWith('/') ? p : '/' + p);
}

function escape(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
  ));
}
function escapeAttr(s) { return escape(s); }
function cssEscape(s) {
  if (window.CSS && CSS.escape) return CSS.escape(s);
  return String(s).replace(/[^a-zA-Z0-9_-]/g, '\\$&');
}
function toast(msg, kind = '') {
  if (window.toast) window.toast(msg, kind);
  else console.log('[toast]', kind, msg);
}

// ─── Component-scoped CSS (injected once) ──────────────────────────
function injectStyles() {
  if (document.getElementById('jobs-tab-css')) return;
  const css = `
    .jobs-count {
      font-size: 11px;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      font-weight: 700;
      color: var(--ink-mute);
      margin: 4px 4px 10px;
      min-height: 14px;
    }

    .status-chips {
      display: flex; gap: 6px;
      overflow-x: auto;
      padding: 2px 0 8px;
      margin: 0 -4px 4px;
      scrollbar-width: none;
    }
    .status-chips::-webkit-scrollbar { display: none; }
    .chip {
      flex: 0 0 auto;
      padding: 8px 14px;
      border-radius: 999px;
      border: 1px solid var(--line);
      background: var(--bg-elev);
      color: var(--ink-soft);
      font-size: 13px;
      font-weight: 600;
      letter-spacing: 0.01em;
      transition: background .15s, color .15s, border-color .15s;
    }
    .chip.active {
      background: var(--accent);
      color: #fff;
      border-color: var(--accent);
    }

    .filters-more {
      margin: 4px 0 8px;
    }
    .filters-more summary {
      list-style: none;
      cursor: pointer;
      font-size: 12px;
      font-weight: 600;
      color: var(--accent);
      padding: 6px 4px;
    }
    .filters-more summary::-webkit-details-marker { display: none; }
    .filters-more summary::before {
      content: '+ ';
      font-weight: 700;
    }
    .filters-more[open] summary::before { content: '− '; }

    .filters-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 8px;
      padding: 8px 0;
    }
    .filt { display: flex; flex-direction: column; gap: 4px; }
    .filt span {
      font-size: 10px;
      text-transform: uppercase;
      letter-spacing: 0.1em;
      font-weight: 700;
      color: var(--ink-mute);
    }
    .filt select, .filt input {
      padding: 10px;
      border: 1px solid var(--line);
      border-radius: var(--r-sm);
      background: var(--bg-elev);
      color: var(--ink);
      font-size: 14px;
    }
    .filt-full { grid-column: 1 / -1; }
    .filt-clear {
      grid-column: 1 / -1;
      padding: 8px;
      background: none;
      border: 1px solid var(--line);
      border-radius: var(--r-sm);
      color: var(--ink-soft);
      font-size: 12px;
      font-weight: 600;
    }

    /* Cards */
    .jobs-card { padding: 14px; }
    .jobs-top {
      display: flex; justify-content: space-between; gap: 12px;
      margin-bottom: 12px;
    }
    .jobs-left { display: flex; gap: 12px; align-items: flex-start; }
    .jobs-plate {
      font-family: var(--font-display);
      font-weight: 700;
      font-size: 20px;
      letter-spacing: 0.02em;
      line-height: 1.05;
    }
    .jobs-svc {
      font-size: 12px;
      color: var(--ink-mute);
      margin-top: 2px;
    }
    .jobs-svc-lg {
      font-family: var(--font-display);
      font-size: 18px;
      font-weight: 700;
      color: var(--ink);
      letter-spacing: -0.01em;
      margin-top: 0;
      line-height: 1.1;
    }
    .jobs-right { text-align: right; }
    .jobs-price {
      font-family: var(--font-display);
      font-weight: 700;
      font-size: 16px;
      color: var(--accent);
      margin-top: 6px;
    }
    .jobs-row {
      display: flex; gap: 8px;
      padding: 3px 0;
      align-items: baseline;
      font-size: 13px;
      color: var(--ink-soft);
    }
    .jobs-row .label { flex: 0 0 78px; }
    .jobs-photo-wrap { margin: 8px 0; }
    .jobs-photo {
      width: 64px; height: 64px;
      object-fit: cover;
      border-radius: 8px;
      border: 1px solid var(--line);
      cursor: zoom-in;
    }

    .jobs-pay {
      margin: 10px 0 4px;
      padding: 10px 12px;
      background: var(--accent-pale);
      border-radius: var(--r-sm);
    }
    .jobs-pay-row {
      display: flex; justify-content: space-between; align-items: baseline;
      gap: 8px;
      padding: 2px 0;
      font-size: 13px;
    }
    .jobs-pay-method {
      text-transform: uppercase;
      letter-spacing: 0.06em;
      font-size: 11px;
      font-weight: 700;
      color: var(--accent);
    }
    .jobs-pay-amount {
      font-family: var(--font-display);
      font-weight: 700;
      color: var(--accent);
    }
    .jobs-pay-ref {
      font-size: 11px;
      color: var(--ink-mute);
      font-family: ui-monospace, monospace;
    }
    .jobs-pay-empty {
      margin: 10px 0 4px;
      padding: 8px 12px;
      background: var(--bg-soft);
      border-radius: var(--r-sm);
      font-size: 12px;
      color: var(--ink-mute);
      font-style: italic;
    }

    .jobs-actions {
      display: flex; gap: 8px;
      margin-top: 12px;
    }
    .btn-start, .btn-complete, .btn-slip {
      border: none;
      padding: 12px 14px;
      border-radius: var(--r-md);
      font-weight: 700;
      font-size: 14px;
      letter-spacing: 0.02em;
      transition: transform .1s, background .15s;
    }
    .btn-start    { flex: 2; background: var(--accent); color: #fff; }
    .btn-complete { flex: 2; background: var(--good);   color: #fff; }
    .btn-slip     { flex: 1; background: var(--bg-soft); color: var(--ink); border: 1px solid var(--line); }
    .btn-start:active, .btn-complete:active, .btn-slip:active { transform: scale(.98); }
    .btn-start:disabled, .btn-complete:disabled, .btn-slip:disabled { opacity: .5; }

    .jobs-done-stamp {
      flex: 2;
      padding: 12px 14px;
      background: var(--good-pale);
      border-radius: var(--r-md);
      color: var(--good);
      font-weight: 700;
      font-size: 13px;
      text-align: center;
    }

    .more-btn {
      width: 100%;
      padding: 12px;
      background: none;
      border: 1px solid var(--line);
      border-radius: var(--r-md);
      color: var(--ink-soft);
      font-weight: 600;
      margin: 16px 0;
    }
    .more-btn:disabled { opacity: .5; }

    /* ─── PRINT SLIP ─────────────────────────────────────────── */
    #print-slip {
      display: none;
      position: fixed; inset: 0;
      background: #fff;
      z-index: -1;
      pointer-events: none;
    }
    .slip-inner {
      width: 100%; max-width: 320px;
      margin: 20px auto;
      padding: 16px 20px;
      font-family: var(--font-body);
      color: #000;
    }
    .slip-brand {
      font-family: var(--font-display);
      font-weight: 900;
      font-size: 28px;
      letter-spacing: -0.02em;
      text-align: center;
    }
    .slip-sub {
      text-align: center;
      font-size: 11px;
      letter-spacing: 0.16em;
      text-transform: uppercase;
      color: #666;
      margin-bottom: 8px;
    }
    .slip-divider {
      border-top: 1px solid #000;
      margin: 12px 0;
    }
    .slip-divider.dashed { border-top: 1px dashed #999; }
    .slip-serial {
      font-family: var(--font-display);
      font-weight: 900;
      font-size: 72px;
      line-height: 1;
      text-align: center;
      letter-spacing: -0.04em;
      margin: 4px 0;
    }
    .slip-svc {
      text-align: center;
      font-size: 16px;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }
    .slip-date {
      text-align: center;
      font-size: 12px;
      color: #666;
      margin-top: 2px;
    }
    .slip-row {
      display: flex; justify-content: space-between;
      padding: 4px 0;
      font-size: 13px;
      border-bottom: 1px dotted #ddd;
    }
    .slip-row span:first-child {
      color: #666;
      text-transform: uppercase;
      font-size: 10px;
      letter-spacing: 0.1em;
      font-weight: 700;
    }
    .slip-row span:last-child { font-weight: 600; text-align: right; }
    .slip-row small { color: #666; font-weight: 500; margin-left: 4px; }
    .slip-footer {
      text-align: center;
      font-size: 11px;
      color: #666;
      margin-top: 14px;
      letter-spacing: 0.08em;
    }

    /* When printing — hide everything except the slip */
    @media print {
      body * { visibility: hidden !important; }
      #print-slip, #print-slip * { visibility: visible !important; }
      #print-slip {
        display: block !important;
        position: absolute !important;
        inset: 0 !important;
        z-index: auto !important;
      }
      @page { margin: 6mm; }
    }

    /* Photo overlay — defined here too in case Available tab isn't loaded */
    .photo-overlay {
      display: none;
      position: fixed; inset: 0;
      background: rgba(0,0,0,.92);
      z-index: 300;
      align-items: center; justify-content: center;
      padding: 24px;
    }
    .photo-overlay.open { display: flex; }
    .photo-overlay img {
      max-width: 100%; max-height: 100%;
      border-radius: 8px;
    }
  `;
  const s = document.createElement('style');
  s.id = 'jobs-tab-css';
  s.textContent = css;
  document.head.appendChild(s);
}