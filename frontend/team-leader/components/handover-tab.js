// frontend/team-leader/components/handover-tab.js
//
// Payment Handover tab.
//
// Top section — "Today's cash" panel:
//   • Big AED amount summed server-side from this TL's completed cash orders
//   • Order count + a collapsible list of contributing orders (serials + amounts)
//   • Optional notes textarea
//   • Submit button → POST /api/tl/handovers → server emails admin
//   • Empty/zero state: friendly message, button disabled
//   • Pull-to-refresh: tap the heading to reload
//
// Bottom section — handover history:
//   • Recent submissions with date, AED amount, order count, status badge
//   • Tap a row to expand notes and confirmation timestamp
//
// Status badges:
//   • pending    — gold/warn pill, "Awaiting admin"
//   • confirmed  — green pill, "Confirmed" + by/at
//   • disputed   — red pill, "Disputed" + reason
//
// Network behavior:
//   • Online: real-time fetch from /tl/handovers/today and /tl/handovers
//   • Offline: today's-cash panel shows last-seen state from kv cache
//   • Submit while offline: blocked with a clear message (handovers MUST
//     reach the server immediately because they trigger an email). We
//     don't queue handovers — that would make the email arrive late.

import * as api from '../api.js';
import * as state from '../state.js';
import { kvGet, kvSet } from '../pwa/db.js';

const CURRENCY = 'AED';

let _root      = null;
let _today     = null;     // { amount, order_count, orders[], date }
let _history   = null;     // { items[], meta }
let _historyExpanded = new Set();
let _submitting = false;
let _notes     = '';
let _refreshing = false;
let _ordersExpanded = false;
let _blocker   = null;     // { id, status, dispute_reason, amount, ... } or null
let _unpaidCount = 0;      // # of TL-originated in-flight orders not yet paid
let _historyFilter = { status: '', from: '', to: '' };
let _historyPage   = 1;
let _outsideCloseHandler = null;   // tracks the document click listener
                                   // so we can remove it before rebinding

// ─── Lifecycle ─────────────────────────────────────────────────────
export async function init(root) {
  _root = root;
  injectStyles();
  paintShell();

  // Cache-first paint
  try {
    const cached = await kvGet('handover_today');
    if (cached) {
      _today = cached;
      paintToday();
    }
  } catch {}
  try {
    const cachedH = await kvGet('handover_history');
    if (cachedH) {
      _history = cachedH;
      paintHistory();
    }
  } catch {}

  await Promise.all([refreshStatus(), refreshToday(), refreshHistory()]);
}

export async function onShow() {
  await Promise.all([refreshStatus(), refreshToday(), refreshHistory()]);
}

// ─── Data fetching ─────────────────────────────────────────────────
async function refreshStatus() {
  if (!navigator.onLine) return;
  try {
    const res = await api.get('/tl/handovers/status');
    _blocker     = res?.data?.blocking ?? null;
    _unpaidCount = res?.data?.unpaid_count ?? 0;
  } catch (e) {
    console.warn('[handover] status failed:', e.message);
    _blocker = null;
    _unpaidCount = 0;
  }
  paintToday();
}

// ─── Data fetching ─────────────────────────────────────────────────
async function refreshToday() {
  if (!navigator.onLine) { paintToday(); return; }
  try {
    const res = await api.get('/tl/handovers/today');
    _today = res?.data ?? null;
    try { await kvSet('handover_today', _today); } catch {}
  } catch (e) {
    console.warn('[handover] today failed:', e.message);
  }
  paintToday();
}

async function refreshHistory() {
  if (!navigator.onLine) { paintHistory(); return; }
  try {
    const qs = new URLSearchParams();
    qs.set('limit', '10');
    qs.set('page',  String(_historyPage));
    if (_historyFilter.status) qs.set('status', _historyFilter.status);
    if (_historyFilter.from)   qs.set('from',   _historyFilter.from);
    if (_historyFilter.to)     qs.set('to',     _historyFilter.to);
    const res = await api.get('/tl/handovers?' + qs.toString());
    _history = res?.data ?? null;
    try { await kvSet('handover_history', _history); } catch {}
  } catch (e) {
    console.warn('[handover] history failed:', e.message);
  }
  paintHistory();
}

// ─── Rendering ─────────────────────────────────────────────────────
function paintShell() {
  _root.innerHTML = `
    <h1 class="tab-h" id="hand-h">Payment handover</h1>
    <div id="hand-today"></div>
    <div id="hand-history-wrap">
      <div class="hand-history-head">
        <h2 class="hand-section-h">History</h2>
        <div class="hand-history-filters">
          <select id="hh-status" class="hh-filter">
            <option value=""          ${_historyFilter.status === ''          ? 'selected' : ''}>All status</option>
            <option value="pending"   ${_historyFilter.status === 'pending'   ? 'selected' : ''}>Pending</option>
            <option value="confirmed" ${_historyFilter.status === 'confirmed' ? 'selected' : ''}>Approved</option>
            <option value="disputed"  ${_historyFilter.status === 'disputed'  ? 'selected' : ''}>Rejected</option>
          </select>
          <button id="hh-date-trigger" class="hh-date-trigger ${(_historyFilter.from || _historyFilter.to) ? 'active' : ''}" type="button" aria-haspopup="dialog" aria-expanded="false">
            <span aria-hidden="true">▤</span>
            <span class="hh-date-label">${escape(formatHistoryRangeLabel(_historyFilter.from, _historyFilter.to))}</span>
          </button>
          ${(_historyFilter.status || _historyFilter.from || _historyFilter.to)
            ? `<button id="hh-clear" class="hh-clear" type="button">Clear</button>` : ''}
        </div>
        <div id="hh-date-pop" class="hh-date-pop" hidden>
          <div class="hh-date-presets">
            <button data-preset="today"  type="button">Today</button>
            <button data-preset="7d"     type="button">Last 7 days</button>
            <button data-preset="30d"    type="button">Last 30 days</button>
            <button data-preset="month"  type="button">This month</button>
            <button data-preset="lmonth" type="button">Last month</button>
            <button data-preset="any"    type="button">Any date</button>
          </div>
          <div class="hh-date-custom">
            <label>
              <span>From</span>
              <input id="hh-from" type="date" value="${escapeAttr(_historyFilter.from || '')}">
            </label>
            <label>
              <span>To</span>
              <input id="hh-to" type="date" value="${escapeAttr(_historyFilter.to || '')}">
            </label>
          </div>
          <div class="hh-date-pop-actions">
            <button id="hh-date-clear" type="button">Clear</button>
            <button id="hh-date-close" type="button">Done</button>
          </div>
        </div>
      </div>
      <div id="hand-history"></div>
      <div id="hand-history-pager"></div>
    </div>
  `;
  _root.querySelector('#hand-h').addEventListener('click', () => {
    if (_refreshing) return;
    _refreshing = true;
    Promise.all([refreshStatus(), refreshToday(), refreshHistory()]).finally(() => {
      _refreshing = false;
    });
  });

  // ── Status filter ─────────────────────────────────────────────
  _root.querySelector('#hh-status').addEventListener('change', (e) => {
    _historyFilter.status = e.target.value;
    _historyPage = 1;
    refreshHistory();
    paintShell();   // repaint so Clear button shows/hides
  });

  // ── Date-range popover ────────────────────────────────────────
  const trigger = _root.querySelector('#hh-date-trigger');
  const pop     = _root.querySelector('#hh-date-pop');
  trigger.addEventListener('click', (e) => {
    e.stopPropagation();
    const hidden = pop.hasAttribute('hidden');
    if (hidden) {
      pop.removeAttribute('hidden');
      trigger.setAttribute('aria-expanded', 'true');
    } else {
      pop.setAttribute('hidden', '');
      trigger.setAttribute('aria-expanded', 'false');
    }
  });
  // Outside-click closes the popover. We must remove any prior listener
  // first because paintShell can run multiple times per page-lifetime
  // (every filter change repaints), and document-level listeners stack.
  if (_outsideCloseHandler) {
    document.removeEventListener('click', _outsideCloseHandler, { capture: true });
  }
  _outsideCloseHandler = (e) => {
    if (pop.hasAttribute('hidden')) return;
    if (e.target.closest('#hh-date-pop') || e.target.closest('#hh-date-trigger')) return;
    pop.setAttribute('hidden', '');
    trigger.setAttribute('aria-expanded', 'false');
  };
  document.addEventListener('click', _outsideCloseHandler, { capture: true });

  // Presets
  pop.querySelectorAll('[data-preset]').forEach((b) => {
    b.addEventListener('click', () => {
      applyHistoryPreset(b.dataset.preset);
      _historyPage = 1;
      refreshHistory();
      paintShell();
    });
  });
  // Custom date inputs
  _root.querySelector('#hh-from').addEventListener('change', (e) => {
    _historyFilter.from = e.target.value;
    _historyPage = 1;
    refreshHistory();
    paintShell();
  });
  _root.querySelector('#hh-to').addEventListener('change', (e) => {
    _historyFilter.to = e.target.value;
    _historyPage = 1;
    refreshHistory();
    paintShell();
  });
  // Popover footer
  _root.querySelector('#hh-date-clear').addEventListener('click', () => {
    _historyFilter.from = '';
    _historyFilter.to   = '';
    _historyPage = 1;
    refreshHistory();
    paintShell();
  });
  _root.querySelector('#hh-date-close').addEventListener('click', () => {
    pop.setAttribute('hidden', '');
    trigger.setAttribute('aria-expanded', 'false');
  });

  // Universal Clear button (visible when any filter is active)
  _root.querySelector('#hh-clear')?.addEventListener('click', () => {
    _historyFilter = { status: '', from: '', to: '' };
    _historyPage = 1;
    refreshHistory();
    paintShell();
  });
}

// Date-preset application. Mirrors my-jobs's applyDatePreset for consistency.
function applyHistoryPreset(preset) {
  const fmt = (d) => {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
  };
  const today = new Date();
  let from = '', to = '';
  if (preset === 'today') {
    from = to = fmt(today);
  } else if (preset === '7d') {
    const s = new Date(today); s.setDate(today.getDate() - 6);
    from = fmt(s); to = fmt(today);
  } else if (preset === '30d') {
    const s = new Date(today); s.setDate(today.getDate() - 29);
    from = fmt(s); to = fmt(today);
  } else if (preset === 'month') {
    const s = new Date(today.getFullYear(), today.getMonth(), 1);
    from = fmt(s); to = fmt(today);
  } else if (preset === 'lmonth') {
    const s = new Date(today.getFullYear(), today.getMonth() - 1, 1);
    const e = new Date(today.getFullYear(), today.getMonth(), 0);
    from = fmt(s); to = fmt(e);
  } else if (preset === 'any') {
    from = ''; to = '';
  }
  _historyFilter.from = from;
  _historyFilter.to   = to;
}

// Human label shown on the date-trigger button
function formatHistoryRangeLabel(from, to) {
  if (!from && !to) return 'Any date';
  const short = (iso) => {
    if (!iso) return '';
    const [y, m, d] = iso.split('-');
    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return `${parseInt(d,10)} ${months[parseInt(m,10) - 1]}`;
  };
  if (from && to && from === to) return short(from);
  if (from && to) return `${short(from)} – ${short(to)}`;
  if (from)       return `From ${short(from)}`;
  return `Until ${short(to)}`;
}

function paintToday() {
  const host = _root.querySelector('#hand-today');
  if (!host) return;

  if (!navigator.onLine && !_today) {
    host.innerHTML = `
      <div class="hand-panel">
        <div class="hand-offline">
          <div>Offline.</div>
          <div class="muted">Reconnect to load the outstanding totals.</div>
        </div>
      </div>`;
    return;
  }

  if (!_today) {
    host.innerHTML = `
      <div class="hand-panel">
        <div class="loading"><div class="spinner"></div>Loading…</div>
      </div>`;
    return;
  }

  const total       = parseFloat(_today.amount || 0);
  const orderCount  = parseInt(_today.order_count || 0, 10);
  const paymentCount = parseInt(_today.payment_count || 0, 10);
  const byMethod    = _today.by_method || { cash: 0, card: 0, online: 0 };
  const cashAmt     = parseFloat(byMethod.cash   || 0);
  const cardAmt     = parseFloat(byMethod.card   || 0);
  const onlineAmt   = parseFloat(byMethod.online || 0);
  const orders      = Array.isArray(_today.payments) && _today.payments.length
                    ? _today.payments
                    : (Array.isArray(_today.orders) ? _today.orders : []);

  // Empty / zero state — show blocker first if any, then a friendlier
  // "you're all caught up" message.
  if (!orderCount || total <= 0) {
    const blockerBanner = _blocker ? renderBlockerBanner(_blocker) : '';
    host.innerHTML = `
      ${blockerBanner}
      <div class="hand-panel hand-empty">
        <div class="hand-empty-head">${_blocker ? 'No new payments to hand over' : 'Nothing to hand over'}</div>
        <div class="hand-empty-body">
          ${_blocker
            ? 'New completed orders will be available once the previous handover is approved.'
            : 'All your completed orders have already been handed over.<br>New payments will appear here once orders are completed.'}
        </div>
      </div>`;
    return;
  }

  // Active panel — reconciliation report with per-method breakdown
  const blockerBanner = _blocker ? renderBlockerBanner(_blocker) : '';
  const unpaidBanner  = _unpaidCount > 0 ? renderUnpaidBanner(_unpaidCount) : '';
  // A PENDING handover blocks new submissions outright. A REJECTED one
  // does NOT block — the TL is expected to resubmit; their next submit
  // automatically supersedes the rejected row.
  // Unpaid TL-originated jobs ALSO block — TL must collect on those
  // first so the day's books reconcile cleanly.
  const blocked      = (!!_blocker && _blocker.status === 'pending') || _unpaidCount > 0;
  const resubmit     = !!_blocker && _blocker.status === 'disputed';
  const submitLbl    = _submitting ? 'Submitting…' : (resubmit ? 'Resubmit handover' : 'Submit handover');
  host.innerHTML = `
    ${blockerBanner}
    ${unpaidBanner}
    <div class="hand-panel hand-panel-report">
      <div class="hand-report-head">
        <div class="hand-report-eyebrow">Outstanding for handover</div>
        <div class="hand-report-total tabular">
          <span class="hand-report-currency">${CURRENCY}</span>
          <span class="hand-report-amount">${formatPrice(total)}</span>
        </div>
        <div class="hand-report-sub">
          ${orderCount} ${orderCount === 1 ? 'order' : 'orders'} · ${paymentCount} ${paymentCount === 1 ? 'payment' : 'payments'}
        </div>
      </div>

      <div class="hand-breakdown">
        <div class="hand-method-row">
          <span class="hand-method-icon hand-method-cash">💵</span>
          <span class="hand-method-lbl">Cash</span>
          <span class="hand-method-val tabular">${CURRENCY} ${formatPrice(cashAmt)}</span>
        </div>
        <div class="hand-method-row">
          <span class="hand-method-icon hand-method-card">💳</span>
          <span class="hand-method-lbl">Card</span>
          <span class="hand-method-val tabular">${CURRENCY} ${formatPrice(cardAmt)}</span>
        </div>
        <div class="hand-method-row">
          <span class="hand-method-icon hand-method-online">🔗</span>
          <span class="hand-method-lbl">Online</span>
          <span class="hand-method-val tabular">${CURRENCY} ${formatPrice(onlineAmt)}</span>
        </div>
        <div class="hand-method-row hand-method-grand">
          <span class="hand-method-icon"></span>
          <span class="hand-method-lbl">Total sale</span>
          <span class="hand-method-val tabular">${CURRENCY} ${formatPrice(total)}</span>
        </div>
      </div>

      <button id="orders-toggle" class="hand-orders-toggle">
        ${_ordersExpanded ? '− Hide payment list' : '+ Show ' + paymentCount + ' payments'}
      </button>

      <div id="orders-list" class="hand-orders-list" ${_ordersExpanded ? '' : 'hidden'}>
        ${orders.map(orderRow).join('')}
      </div>

      ${blocked ? '' : `
        <div class="field" style="margin-top:18px">
          <label>Notes <span class="opt">(optional)</span></label>
          <textarea id="hand-notes" rows="2" placeholder="Anything the admin should know">${escape(_notes)}</textarea>
        </div>

        <button id="hand-submit" class="hand-submit ${resubmit ? 'hand-submit-resubmit' : ''}" ${_submitting || !navigator.onLine ? 'disabled' : ''}>
          ${submitLbl}
        </button>
        ${!navigator.onLine ? `<div class="hand-warn">Reconnect to submit — handovers email the admin in real time.</div>` : ''}
      `}
    </div>`;

  host.querySelector('#orders-toggle').addEventListener('click', () => {
    _ordersExpanded = !_ordersExpanded;
    paintToday();
  });
  const notesEl = host.querySelector('#hand-notes');
  if (notesEl) notesEl.addEventListener('input', (e) => { _notes = e.target.value; });
  const submitEl = host.querySelector('#hand-submit');
  if (submitEl) submitEl.addEventListener('click', submitHandover);
}

// Banner: shows the prior handover that's blocking new submissions.
function renderBlockerBanner(b) {
  const isRejected = b.status === 'disputed';
  const title = isRejected
    ? 'Previous handover rejected'
    : 'Previous handover awaiting approval';
  const sub = isRejected
    ? 'Admin rejected your last handover. Review the reason below, then submit a corrected handover — the rejected one will be replaced.'
    : 'Your last handover is being reviewed by admin. You\'ll be able to submit a new one once it\'s approved.';
  const reasonBlock = isRejected && b.dispute_reason
    ? `<div class="hand-blocker-reason"><strong>Reason:</strong> ${escape(b.dispute_reason)}</div>`
    : '';
  const created = b.created_at ? new Date(b.created_at).toLocaleString() : '';
  return `
    <div class="hand-blocker hand-blocker-${isRejected ? 'rejected' : 'pending'}">
      <div class="hand-blocker-head">
        <span class="hand-blocker-icon">${isRejected ? '⚠' : '⧖'}</span>
        <div class="hand-blocker-text">
          <div class="hand-blocker-title">${title}</div>
          <div class="hand-blocker-sub">${sub}</div>
        </div>
      </div>
      <div class="hand-blocker-meta">
        <span><b class="tabular">${CURRENCY} ${formatPrice(parseFloat(b.amount || 0))}</b></span>
        <span class="muted">·</span>
        <span>${b.order_count || 0} ${b.order_count == 1 ? 'order' : 'orders'}</span>
        ${created ? `<span class="muted">·</span><span>${escape(created)}</span>` : ''}
      </div>
      ${reasonBlock}
    </div>`;
}

// Banner: shows when the TL has TL-originated jobs that haven't been
// paid yet. These block handover submission because admin needs the
// day's books to fully reconcile — a TL with money still to collect
// can't hand over yet. Customer-self orders without payment are
// EXCLUDED from this count by the backend (those have their own
// Stripe collection flow).
function renderUnpaidBanner(n) {
  const noun = n === 1 ? 'job' : 'jobs';
  return `
    <div class="hand-blocker hand-blocker-unpaid">
      <div class="hand-blocker-head">
        <span class="hand-blocker-icon">!</span>
        <div class="hand-blocker-text">
          <div class="hand-blocker-title">${n} unpaid ${noun} in progress</div>
          <div class="hand-blocker-sub">
            Collect payment on all open jobs before submitting a handover.
            Tap "My Jobs" → "Unpaid" to see what's pending.
          </div>
        </div>
      </div>
    </div>`;
}

function orderRow(o) {
  const amt = parseFloat(o.amount || 0);
  // Order number is the universal identifier across the system. Plate
  // is no longer collected for most orders (customer flow + new TL
  // wizard both skip it), so showing "—" everywhere was useless. The
  // order_number is what admin sees too, which makes cross-referencing
  // easy when a TL needs to flag something.
  const methodIcon = { cash: '💵', card: '💳', online: '🔗' }[o.payment_method] || '';
  return `
    <div class="hand-order-row">
      <span class="hand-order-serial tabular">#${parseInt(o.daily_serial, 10) || '—'}</span>
      <span class="hand-order-num tabular">${escape(o.order_number || '—')}</span>
      <span class="hand-order-method" title="${escape(o.payment_method || '')}">${methodIcon}</span>
      <span class="hand-order-amt tabular">${CURRENCY} ${formatPrice(amt)}</span>
    </div>`;
}

function paintHistory() {
  const host  = _root.querySelector('#hand-history');
  const pager = _root.querySelector('#hand-history-pager');
  if (!host) return;

  const items = _history?.items ?? [];
  const meta  = _history?.meta  ?? { page: 1, total_pages: 1, total: 0 };
  const hasFilters = !!(_historyFilter.status || _historyFilter.from || _historyFilter.to);

  if (!items.length) {
    host.innerHTML = `
      <div class="empty-state" style="padding:32px 12px">
        <div class="empty-state-glyph" style="font-size:42px">∅</div>
        <div class="empty-state-msg">
          ${hasFilters ? 'No handovers match these filters' : 'No previous handovers'}
        </div>
      </div>`;
    if (pager) pager.innerHTML = '';
    return;
  }

  host.innerHTML = items.map(historyRow).join('');

  host.querySelectorAll('.hand-hist').forEach((el) => {
    el.addEventListener('click', () => {
      const id = el.dataset.id;
      if (_historyExpanded.has(id)) _historyExpanded.delete(id);
      else _historyExpanded.add(id);
      paintHistory();
    });
  });

  // Pager — only when there's more than one page
  if (pager) {
    const totalPages = meta.total_pages || 1;
    const page = meta.page || _historyPage;
    if (totalPages <= 1) {
      pager.innerHTML = '';
    } else {
      pager.innerHTML = `
        <div class="hand-hist-pager">
          <button class="hh-pg-btn" id="hh-pg-prev" ${page <= 1 ? 'disabled' : ''}>← Prev</button>
          <span class="hh-pg-info">Page <b>${page}</b> of <b>${totalPages}</b> · ${meta.total || 0} handovers</span>
          <button class="hh-pg-btn" id="hh-pg-next" ${page >= totalPages ? 'disabled' : ''}>Next →</button>
        </div>`;
      pager.querySelector('#hh-pg-prev').addEventListener('click', () => {
        if (_historyPage <= 1) return;
        _historyPage -= 1;
        refreshHistory();
      });
      pager.querySelector('#hh-pg-next').addEventListener('click', () => {
        if (_historyPage >= totalPages) return;
        _historyPage += 1;
        refreshHistory();
      });
    }
  }
}

function historyRow(h) {
  const expanded = _historyExpanded.has(h.id);
  const dateLabel = formatDateNice(h.handover_date);
  const submittedAt = formatTimeNice(h.created_at);
  const confirmedAt = h.confirmed_at ? formatTimeNice(h.confirmed_at) : null;

  const statusLabel = {
    pending:   'Awaiting admin',
    confirmed: 'Approved',
    disputed:  'Rejected',
  }[h.status] || h.status;

  const cashAmt   = parseFloat(h.cash_amount   || 0);
  const cardAmt   = parseFloat(h.card_amount   || 0);
  const onlineAmt = parseFloat(h.online_amount || 0);
  const hasBreakdown = (cashAmt + cardAmt + onlineAmt) > 0.005;

  const detail = expanded ? `
    <div class="hand-hist-detail">
      ${hasBreakdown ? `
        <div class="hand-hist-methods">
          <div class="hand-hist-method"><span>💵 Cash</span>   <span class="tabular">${CURRENCY} ${formatPrice(cashAmt)}</span></div>
          <div class="hand-hist-method"><span>💳 Card</span>   <span class="tabular">${CURRENCY} ${formatPrice(cardAmt)}</span></div>
          <div class="hand-hist-method"><span>🔗 Online</span> <span class="tabular">${CURRENCY} ${formatPrice(onlineAmt)}</span></div>
        </div>
      ` : ''}
      <div class="rv-row"><span>Submitted</span><span>${escape(submittedAt)}</span></div>
      ${confirmedAt ? `<div class="rv-row"><span>${h.status === 'disputed' ? 'Rejected' : 'Approved'} at</span><span>${escape(confirmedAt)}</span></div>` : ''}
      ${h.notes ? `<div class="rv-row"><span>Your notes</span><span>${escape(h.notes)}</span></div>` : ''}
      ${h.dispute_reason ? `<div class="rv-row" style="color:var(--bad)"><span>Reason</span><span>${escape(h.dispute_reason)}</span></div>` : ''}
    </div>` : '';

  return `
    <div class="hand-hist" data-id="${escapeAttr(h.id)}">
      <div class="hand-hist-row">
        <div>
          <div class="hand-hist-date">${escape(dateLabel)}</div>
          <div class="hand-hist-count">${h.order_count} ${h.order_count === 1 ? 'order' : 'orders'}</div>
        </div>
        <div style="text-align:right">
          <div class="hand-hist-amt tabular">${CURRENCY} ${formatPrice(h.amount)}</div>
          <span class="tag ${escape(h.status)}">${escape(statusLabel)}</span>
        </div>
      </div>
      ${detail}
    </div>`;
}

// ─── Submit ────────────────────────────────────────────────────────
async function submitHandover() {
  if (_submitting) return;
  if (!navigator.onLine) {
    toast('Cannot submit while offline', 'error');
    return;
  }

  const amount = parseFloat(_today?.amount || 0);
  const count  = parseInt(_today?.order_count || 0, 10);
  if (!count || amount <= 0) {
    toast('No cash to hand over', 'error');
    return;
  }

  if (!confirm(`Submit handover of ${CURRENCY} ${formatPrice(amount)} for ${count} ${count === 1 ? 'order' : 'orders'}?\n\nThe admin will be emailed for confirmation.`)) {
    return;
  }

  _submitting = true;
  paintToday();

  try {
    const res = await api.post('/tl/handovers', { notes: _notes || null });
    const d = res?.data ?? {};
    toast(`Submitted — ${CURRENCY} ${formatPrice(d.amount || amount)}`, 'success');
    _notes = '';
    _ordersExpanded = false;
    // Status now becomes "blocking pending" since admin hasn't approved yet
    await refreshStatus();
    await refreshToday();
    await refreshHistory();
  } catch (e) {
    if (e.status === 409) {
      // Backend gating fired — sync UI to the blocker it just told us about
      toast(e.message || 'A previous handover is blocking', 'error');
      await refreshStatus();
    } else if (e.status === 422 && /no cash|outstanding/i.test(e.message || '')) {
      toast('No new payments to hand over', 'error');
      await refreshToday();
    } else {
      toast(e.message || 'Submission failed', 'error');
    }
  } finally {
    _submitting = false;
    paintToday();
  }
}

// ─── Format helpers ────────────────────────────────────────────────
function formatPrice(p) {
  const n = parseFloat(p);
  return Number.isFinite(n) ? n.toFixed(2) : '0.00';
}
function formatDateNice(s) {
  if (!s) return '';
  if (s.length === 10) {
    // YYYY-MM-DD — Dubai date
    const [y, m, d] = s.split('-').map(Number);
    const dt = new Date(Date.UTC(y, m - 1, d));
    return dt.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
  }
  const dt = new Date(String(s).replace(' ', 'T') + (String(s).endsWith('Z') ? '' : 'Z'));
  if (Number.isNaN(dt.getTime())) return s;
  return dt.toLocaleDateString('en-GB', {
    day: '2-digit', month: 'short', year: 'numeric', timeZone: 'Asia/Dubai',
  });
}
function formatTimeNice(s) {
  if (!s) return '—';
  const dt = new Date(String(s).replace(' ', 'T') + (String(s).endsWith('Z') ? '' : 'Z'));
  if (Number.isNaN(dt.getTime())) return s;
  return dt.toLocaleString('en-GB', {
    day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit',
    timeZone: 'Asia/Dubai', hour12: false,
  });
}

function escape(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
  ));
}
function escapeAttr(s) { return escape(s); }
function toast(msg, kind = '') {
  if (window.toast) window.toast(msg, kind);
  else console.log('[toast]', kind, msg);
}

// ─── Component-scoped CSS ──────────────────────────────────────────
function injectStyles() {
  if (document.getElementById('handover-tab-css')) return;
  const css = `
    /* Main panel */
    .hand-panel {
      background: var(--bg-elev);
      border: 1px solid var(--line);
      border-radius: var(--r-lg);
      padding: 22px 20px;
      box-shadow: var(--shadow-sm);
      margin-bottom: 20px;
      position: relative;
      overflow: hidden;
    }
    .hand-panel::before {
      content: '';
      position: absolute;
      top: 0; right: 0;
      width: 120px; height: 120px;
      border-radius: 0 var(--r-lg) 0 100%;
      background: radial-gradient(circle at top right, var(--accent-pale), transparent 70%);
      pointer-events: none;
    }
    .hand-label {
      font-size: 11px;
      letter-spacing: 0.14em;
      text-transform: uppercase;
      font-weight: 700;
      color: var(--ink-mute);
      margin-bottom: 8px;
      position: relative;
    }
    .hand-amount {
      font-family: var(--font-display);
      font-weight: 900;
      font-size: 52px;
      line-height: 1;
      letter-spacing: -0.03em;
      color: var(--accent);
      position: relative;
      display: flex;
      align-items: baseline;
      gap: 8px;
    }
    .hand-amount > span:first-of-type { font-size: 0.5em; opacity: .9; font-weight: 700; }
    .hand-count {
      margin-top: 6px;
      font-size: 13px;
      font-weight: 600;
      color: var(--ink-soft);
      position: relative;
    }

    /* ─── Report-style outstanding panel (new design) ─── */
    .hand-panel-report {
      padding-top: 22px;
    }
    .hand-report-head { margin-bottom: 18px; }
    .hand-report-eyebrow {
      font-size: 11px;
      letter-spacing: 0.14em;
      text-transform: uppercase;
      font-weight: 700;
      color: var(--ink-mute);
      margin-bottom: 6px;
    }
    .hand-report-total {
      font-family: var(--font-display);
      color: var(--accent);
      display: flex;
      align-items: baseline;
      gap: 8px;
      line-height: 1;
      letter-spacing: -0.03em;
    }
    .hand-report-currency {
      /* AED label — small, muted, sits next to the big amount as a prefix */
      font-size: 14px;
      font-weight: 700;
      opacity: 0.75;
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }
    .hand-report-amount {
      /* The actual money — dominant element */
      font-size: 52px;
      font-weight: 900;
    }
    .hand-report-sub {
      margin-top: 6px;
      font-size: 12px;
      font-weight: 500;
      color: var(--ink-mute);
    }

    /* Per-method breakdown — 3 rows + grand-total */
    .hand-breakdown {
      background: var(--bg-soft);
      border-radius: var(--r-md);
      padding: 4px 12px;
      margin-bottom: 4px;
    }
    .hand-method-row {
      display: grid;
      grid-template-columns: 28px 1fr auto;
      align-items: center;
      gap: 12px;
      padding: 11px 0;
      border-bottom: 1px solid var(--line);
      font-size: 14px;
    }
    .hand-method-row:last-child { border-bottom: none; }
    .hand-method-icon {
      width: 28px; height: 28px;
      display: inline-flex; align-items: center; justify-content: center;
      border-radius: 8px;
      font-size: 14px;
    }
    .hand-method-icon.hand-method-cash   { background: rgba(22,163,74,0.10); }
    .hand-method-icon.hand-method-card   { background: rgba(14,165,233,0.10); }
    .hand-method-icon.hand-method-online { background: rgba(124,58,237,0.10); }
    .hand-method-lbl {
      font-size: 13px;
      font-weight: 600;
      color: var(--ink-soft);
    }
    .hand-method-val {
      font-weight: 700;
      color: var(--ink);
      font-variant-numeric: tabular-nums;
    }
    .hand-method-grand {
      border-top: 2px solid var(--line-strong, var(--line)) !important;
      padding-top: 13px !important;
      margin-top: 2px;
    }
    .hand-method-grand .hand-method-lbl {
      font-size: 13px;
      font-weight: 800;
      color: var(--ink);
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    .hand-method-grand .hand-method-val {
      font-size: 16px;
      color: var(--accent);
      font-weight: 800;
    }

    .hand-order-method {
      font-size: 14px;
      text-align: center;
      width: 22px;
    }
    .hand-orders-toggle {
      margin-top: 16px;
      background: none;
      border: 1px solid var(--line);
      border-radius: var(--r-sm);
      padding: 8px 12px;
      font-size: 12px;
      font-weight: 600;
      color: var(--ink-soft);
      width: 100%;
    }
    .hand-orders-list {
      margin-top: 12px;
      background: var(--bg-soft);
      border-radius: var(--r-sm);
      padding: 8px 12px;
    }
    .hand-order-row {
      display: grid;
      grid-template-columns: 50px 1fr 22px auto;
      gap: 10px;
      align-items: baseline;
      padding: 8px 0;
      font-size: 13px;
      border-bottom: 1px dotted var(--line-strong);
    }
    .hand-order-row:last-child { border-bottom: none; }
    .hand-order-serial {
      font-family: var(--font-display);
      font-weight: 700;
      color: var(--accent);
    }
    .hand-order-num {
      font-weight: 600;
      color: var(--ink-soft);
      font-size: 12px;
      letter-spacing: 0.02em;
      font-variant-numeric: tabular-nums;
    }
    /* Legacy classes — no longer rendered, kept for any external CSS
     * that may have hooked on them. Safe to remove on a future sweep. */
    .hand-order-plate { font-weight: 700; color: var(--ink); }
    .hand-order-svc   { color: var(--ink); font-weight: 600; }
    .hand-order-amt {
      color: var(--ink-soft);
      font-weight: 600;
    }

    .hand-panel .field { margin-top: 18px; margin-bottom: 0; }
    .hand-panel textarea {
      width: 100%;
      padding: 12px 14px;
      border: 1.5px solid var(--line);
      border-radius: var(--r-md);
      background: var(--bg);
      color: var(--ink);
      font-size: 16px;
      font-family: inherit;
      resize: vertical;
      min-height: 60px;
    }
    .hand-panel textarea:focus { outline: none; border-color: var(--accent); }

    .hand-submit {
      width: 100%;
      margin-top: 16px;
      padding: 16px;
      background: var(--accent);
      color: #fff;
      border: none;
      border-radius: var(--r-md);
      font-weight: 700;
      font-size: 15px;
      letter-spacing: 0.02em;
      transition: transform .1s, background .15s, opacity .2s;
      position: relative;
    }
    .hand-submit:active { transform: scale(.98); background: var(--accent-soft); }
    .hand-submit:disabled { opacity: .5; }

    .hand-warn {
      margin-top: 12px;
      padding: 10px 12px;
      background: var(--warn-pale);
      border-radius: var(--r-sm);
      color: var(--warn);
      font-size: 12px;
      font-weight: 600;
      text-align: center;
    }

    /* Empty state */
    .hand-empty { padding: 28px 20px; text-align: center; }
    .hand-empty-head {
      font-family: var(--font-display);
      font-weight: 700;
      font-size: 19px;
      letter-spacing: -0.01em;
      color: var(--ink-mute);
      margin-bottom: 6px;
    }
    .hand-empty-body {
      font-size: 13px;
      color: var(--ink-mute);
      line-height: 1.5;
    }
    .hand-offline {
      padding: 32px 20px;
      text-align: center;
      color: var(--ink-mute);
    }
    .hand-offline .muted { margin-top: 4px; font-size: 12px; }

    /* History */
    .hand-section-h {
      font-family: var(--font-display);
      font-weight: 700;
      font-size: 16px;
      letter-spacing: -0.01em;
      margin: 28px 4px 12px;
      color: var(--ink-soft);
    }
    .hand-hist {
      background: var(--bg-elev);
      border: 1px solid var(--line);
      border-radius: var(--r-md);
      padding: 14px;
      margin-bottom: 8px;
      cursor: pointer;
      transition: border-color .15s;
    }
    .hand-hist:hover { border-color: var(--line-strong); }
    .hand-hist-row {
      display: flex; justify-content: space-between; align-items: flex-start;
      gap: 12px;
    }
    .hand-hist-date {
      font-family: var(--font-display);
      font-weight: 700;
      font-size: 17px;
      letter-spacing: -0.01em;
    }
    .hand-hist-count {
      font-size: 12px;
      color: var(--ink-mute);
      margin-top: 2px;
    }
    .hand-hist-amt {
      font-family: var(--font-display);
      font-weight: 700;
      font-size: 18px;
      color: var(--accent);
      margin-bottom: 4px;
    }
    .hand-hist-detail {
      margin-top: 12px;
      padding-top: 10px;
      border-top: 1px dashed var(--line-strong);
    }
    .hand-hist-detail .rv-row {
      display: flex; justify-content: space-between; gap: 8px;
      padding: 3px 0;
      font-size: 12px;
    }
    .hand-hist-detail .rv-row span:first-child {
      text-transform: uppercase;
      letter-spacing: 0.1em;
      font-weight: 700;
      color: var(--ink-mute);
      font-size: 10px;
    }
    .hand-hist-methods {
      background: var(--bg-soft);
      border-radius: var(--r-sm);
      padding: 8px 12px;
      margin-bottom: 10px;
    }
    .hand-hist-method {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 4px 0;
      font-size: 12px;
      color: var(--ink-soft);
      font-weight: 600;
    }
    .hand-hist-method:not(:last-child) {
      border-bottom: 1px dotted var(--line);
    }

    /* ─── Blocker banner: previous handover pending / rejected ─── */
    .hand-blocker {
      border-radius: var(--r-md);
      padding: 14px 16px;
      margin-bottom: 14px;
      border-left: 4px solid;
    }
    .hand-blocker-pending {
      background: var(--warn-pale, #fff7ed);
      border-color: var(--warn, #d97706);
      color: #7c2d12;
    }
    .hand-blocker-rejected {
      background: var(--bad-pale, #fef2f2);
      border-color: var(--bad, #dc2626);
      color: #7f1d1d;
    }
    /* Distinct tone for the unpaid-jobs gate. Uses an info-amber rather
     * than the red of "rejected handover" so the TL doesn't think
     * they've done anything wrong — it's just a prerequisite reminder. */
    .hand-blocker-unpaid {
      background: var(--info-pale, #eff6ff);
      border-color: var(--info, #2563eb);
      color: #1e3a8a;
    }
    .hand-blocker-head {
      display: flex;
      align-items: flex-start;
      gap: 12px;
    }
    .hand-blocker-icon {
      font-size: 22px;
      line-height: 1;
      flex: 0 0 auto;
    }
    .hand-blocker-text { flex: 1; min-width: 0; }
    .hand-blocker-title {
      font-weight: 800;
      font-size: 15px;
      margin-bottom: 4px;
      letter-spacing: -0.01em;
    }
    .hand-blocker-sub {
      font-size: 13px;
      line-height: 1.45;
      opacity: 0.85;
    }
    .hand-blocker-meta {
      margin-top: 10px;
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      align-items: baseline;
      font-size: 13px;
      padding-top: 10px;
      border-top: 1px dotted currentColor;
      opacity: 0.85;
    }
    .hand-blocker-meta .muted { opacity: 0.5; }
    .hand-blocker-reason {
      margin-top: 10px;
      padding: 10px 12px;
      background: rgba(255,255,255,0.55);
      border-radius: 6px;
      font-size: 13px;
      line-height: 1.45;
    }

    /* ─── History head with filters ─── */
    .hand-history-head {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 10px 16px;
      margin: 28px 4px 12px;
      position: relative;        /* anchors the date popover */
    }
    .hand-history-head .hand-section-h { margin: 0; }
    .hand-history-filters {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin-left: auto;
    }
    .hh-filter {
      font: inherit;
      font-size: 12px;
      padding: 6px 8px;
      border-radius: 8px;
      border: 1px solid var(--line);
      background: var(--bg-elev);
      color: var(--ink);
      min-width: 110px;
    }
    .hh-filter:focus { outline: none; border-color: var(--accent); }

    /* Date trigger button + popover (same pattern as my-jobs filter) */
    .hh-date-trigger {
      font: inherit;
      font-size: 12px;
      padding: 6px 10px;
      border-radius: 8px;
      border: 1px solid var(--line);
      background: var(--bg-elev);
      color: var(--ink);
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      min-width: 130px;
      white-space: nowrap;
    }
    .hh-date-trigger.active {
      border-color: var(--accent);
      color: var(--accent);
      background: var(--accent-pale, rgba(14,165,233,0.08));
    }
    .hh-date-trigger:hover { border-color: var(--accent); }
    .hh-date-label { font-weight: 600; }

    .hh-clear {
      font: inherit;
      font-size: 12px;
      padding: 6px 10px;
      border-radius: 8px;
      border: 1px solid var(--line);
      background: var(--bg-soft);
      color: var(--ink-soft);
      cursor: pointer;
    }
    .hh-clear:hover { color: var(--accent); border-color: var(--accent); }

    .hh-date-pop {
      position: absolute;
      z-index: 30;
      top: calc(100% + 4px);
      right: 4px;
      width: min(360px, calc(100vw - 28px));
      padding: 12px;
      background: var(--bg-elev);
      border: 1px solid var(--line);
      border-radius: 10px;
      box-shadow: 0 12px 32px rgba(0,0,0,0.12);
      animation: hh-pop-in .12s ease-out;
    }
    @keyframes hh-pop-in {
      from { opacity: 0; transform: translateY(-4px); }
      to   { opacity: 1; transform: translateY(0); }
    }
    .hh-date-presets {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 6px;
      margin-bottom: 10px;
    }
    .hh-date-presets button {
      padding: 8px 6px;
      background: var(--bg-soft);
      border: 1px solid var(--line);
      border-radius: 6px;
      color: var(--ink-soft);
      font-size: 12px;
      font-weight: 600;
      cursor: pointer;
    }
    .hh-date-presets button:hover,
    .hh-date-presets button:active {
      background: var(--accent-pale, rgba(14,165,233,0.1));
      color: var(--accent);
      border-color: var(--accent);
    }
    .hh-date-custom {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 8px;
      padding: 8px 0;
      border-top: 1px dashed var(--line);
      margin-bottom: 10px;
    }
    .hh-date-custom label {
      display: flex;
      flex-direction: column;
      gap: 3px;
    }
    .hh-date-custom span {
      font-size: 10px;
      text-transform: uppercase;
      letter-spacing: 0.1em;
      font-weight: 700;
      color: var(--ink-mute, #94a3b8);
    }
    .hh-date-custom input {
      padding: 8px 10px;
      border: 1px solid var(--line);
      border-radius: 6px;
      background: var(--bg-elev);
      color: var(--ink);
      font-size: 13px;
    }
    .hh-date-pop-actions {
      display: flex;
      gap: 8px;
      justify-content: space-between;
    }
    .hh-date-pop-actions button {
      flex: 1;
      padding: 8px;
      border-radius: 6px;
      font-size: 13px;
      font-weight: 700;
      cursor: pointer;
    }
    #hh-date-clear {
      background: var(--bg-soft);
      border: 1px solid var(--line);
      color: var(--ink-soft);
    }
    #hh-date-close {
      background: var(--accent);
      border: 1px solid var(--accent);
      color: #fff;
    }

    /* ─── Pager ─── */
    .hand-hist-pager {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      margin-top: 12px;
      padding: 8px 10px;
      background: var(--bg-soft);
      border-radius: var(--r-md);
      font-size: 12px;
      color: var(--ink-soft);
    }
    .hh-pg-btn {
      font: inherit;
      padding: 6px 12px;
      background: var(--bg-elev);
      border: 1px solid var(--line);
      border-radius: 6px;
      font-size: 12px;
      font-weight: 600;
      color: var(--ink);
    }
    .hh-pg-btn:hover:not(:disabled) { border-color: var(--accent); color: var(--accent); }
    .hh-pg-btn:disabled { opacity: 0.4; cursor: default; }
    .hh-pg-info b { color: var(--ink); font-weight: 700; }

    /* ─── Desktop layout (≥ 900 px) ──────────────────────────────── */
    /* Today's cash + handover form on the left, History on the right.
     * On mobile they stack as before. */
    @media (min-width: 900px) {
      #tab-handover.active {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        column-gap: 20px;
        row-gap: 16px;
        align-items: start;
      }
      #tab-handover > .tab-h            { grid-column: 1 / -1; }
      #tab-handover > #hand-today       { grid-column: 1; }
      #tab-handover > #hand-history-wrap { grid-column: 2; }
      .hand-section-h { margin-top: 0; }
    }
  `;
  const s = document.createElement('style');
  s.id = 'handover-tab-css';
  s.textContent = css;
  document.head.appendChild(s);
}