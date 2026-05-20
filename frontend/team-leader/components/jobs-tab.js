// frontend/team-leader/components/jobs-tab.js
//
// My Jobs tab — the TL's main workhorse.
//
// Features:
//   • Status chips (Active / Accepted / Completed) with counts
//   • Filters: employee dropdown, date range, free-text search
//   • Each job card shows: serial, plate, customer, location, employee,
//     payment block, status badge, and Record Payment / Print Slip buttons
//   • Orders auto-complete the moment they are fully paid — no Start /
//     Mark Complete buttons. The backend's recomputeOrderPayment helper
//     flips the order_services row to "completed" as soon as paid_amount
//     reaches total_amount.
//   • Record Payment: opens a modal (centered desktop, bottom-sheet mobile)
//     that supports single-method or split-payment entry.
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
let _payLinks = new Map();    // job id → { url, amount, created_at }  (Stripe links for balance Online)

// Employee dropdown source (TL's own team)
let _employees = [];

// Modal element refs (lazy)
let _slipEl = null;
let _payModalEl = null;     // <div> that hosts the record-payment modal/sheet

// One-shot guard so we don't attach the date-popover outside-click handler
// every time the filter bar repaints.
let _dateOutsideClickAttached = false;

// Mobile-only: tracks whether the collapsible secondary controls (date,
// search, employee, clear) are visible. Desktop CSS overrides this so
// the controls are always shown.
let _filtersOpen = false;

// ─── Lifecycle ─────────────────────────────────────────────────────
export async function init(root) {
  _root = root;
  injectStyles();

  // Restore last-used filter — but DON'T restore from/to. The TL almost
  // always wants today's jobs when they reopen the app; remembering a
  // stale range from yesterday creates a confusing "where are my jobs"
  // moment. Status, employee, search are still persisted because those
  // are intentional, not time-based.
  try {
    const saved = await kvGet('jobs_filter');
    if (saved) {
      _filter.status      = saved.status      || _filter.status;
      _filter.employee_id = saved.employee_id || '';
      _filter.q           = saved.q           || '';
    }
  } catch {}
  // Legacy: the "Accepted" chip was dropped; map it to Active so TLs
  // who had it remembered don't end up on a missing tab.
  if (_filter.status === 'accepted') _filter.status = 'active';
  // Date filter ALWAYS defaults to today on load. The user can change
  // it for the current session via the popover; it won't stick across
  // reloads.
  {
    const t = todayIso();
    _filter.from = t;
    _filter.to   = t;
  }

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
    // Drop stale pay-links — once Stripe webhook flipped the order to paid,
    // the link is no longer useful (and customer paying for already-paid
    // amount would be a problem).
    for (const id of Array.from(_payLinks.keys())) {
      const j = _items.find((x) => x.id === id);
      if (!j) { _payLinks.delete(id); continue; }
      if (j.payment_status === 'paid') _payLinks.delete(id);
    }
    paintCount();
    paintList();

    // Persist for offline
    if (!append) {
      try { await dbReplaceAll('jobs_cache', _items); } catch {}
    }

    // Badge updates are now driven by the global poller in app.js,
    // which fetches an authoritative count from /tl/badges. The
    // previous local update read from `_items` AFTER filtering, so
    // the badge silently went to 0 whenever the TL had a non-default
    // filter active.
    window.refreshBadges?.();
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
  // ACCUMULATE employees across every batch of items we've ever seen,
  // never collapse back. Otherwise filtering by employee X makes the
  // resulting _items only contain X, which would shrink the dropdown
  // to {All, X} and lock the TL out of switching to anyone else.
  //
  // We seed `seen` from the current _employees list so previously-known
  // entries survive even when the current page contains none of them
  // (e.g. when status=Paid filter excludes all of a TL's recent work).
  const seen = new Map();
  for (const e of _employees) {
    if (e.id) seen.set(e.id, e);
  }
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

  const dateLabel = formatDateRangeLabel(_filter.from, _filter.to);
  // "Extras" = anything beyond the always-visible status chips. Used
  // to size the unread-counter badge on the mobile filter toggle.
  const activeExtras =
    (_filter.q ? 1 : 0) +
    (_filter.from || _filter.to ? 1 : 0) +
    (_filter.employee_id ? 1 : 0);
  const hasAnyExtras = activeExtras > 0;

  host.innerHTML = `
    <div class="jobs-filterbar">
      <!-- Primary row: chips on the left + (mobile-only) filter toggle on the right -->
      <div class="filt-primary">
        <div class="status-chips" role="tablist">
          ${chip('active',      'Unpaid')}
          ${chip('completed',   'Paid')}
        </div>

        <!-- Mobile-only gear: opens the secondary controls below.
             Hidden via CSS on desktop where the controls inline naturally. -->
        <button id="filt-toggle" class="filt-toggle ${hasAnyExtras ? 'has-active' : ''}"
                type="button" aria-expanded="${_filtersOpen ? 'true' : 'false'}"
                aria-controls="filt-collapsible">
          <span aria-hidden="true">⚙</span>
          <span>Filters</span>
          ${activeExtras ? `<span class="filt-toggle-count">${activeExtras}</span>` : ''}
        </button>
      </div>

      <!-- Secondary controls — date trigger, search, employee, clear.
           On desktop these flow inline next to the chips (CSS media
           query lifts them out of the collapsible). On mobile they
           hide by default and animate open when the gear is tapped. -->
      <div id="filt-collapsible" class="filt-collapsible ${_filtersOpen ? 'open' : ''}">
        <button id="filt-date-trigger" class="filt-date-trigger ${(_filter.from || _filter.to) ? 'active' : ''}" type="button">
          <span aria-hidden="true">▤</span>
          <span class="filt-date-label">${escape(dateLabel)}</span>
        </button>

        <div class="filt-search">
          <span class="filt-search-icon" aria-hidden="true">⌕</span>
          <input id="filt-q" type="search"
                 placeholder="Order #, serial…"
                 value="${escapeAttr(_filter.q || '')}">
          <button class="filt-search-clear" id="filt-q-clear"
                  type="button" aria-label="Clear search"
                  ${_filter.q ? '' : 'hidden'}>×</button>
        </div>

        <!-- Employee select is always in the DOM (so we never need to
             repaint the bar mid-typing to reveal it), with display:none
             toggled via the data-empty attribute when the team has no
             known employees yet. -->
        <select id="filt-emp" class="filt-emp ${_filter.employee_id ? 'active' : ''}"
                ${_employees.length ? '' : 'data-empty'}>
          <option value="">All employees</option>
          ${empOptions}
        </select>

        <button id="filt-clear" class="filt-clear" type="button"
                ${hasAnyExtras ? '' : 'hidden'}>Clear</button>
      </div>

      <!-- Date popover sits below the primary row, anchored under the trigger -->
      <div id="filt-date-pop" class="filt-date-pop" hidden>
        <div class="filt-date-presets">
          <button data-preset="today"  type="button">Today</button>
          <button data-preset="7d"     type="button">Last 7 days</button>
          <button data-preset="30d"    type="button">Last 30 days</button>
          <button data-preset="month"  type="button">This month</button>
          <button data-preset="lmonth" type="button">Last month</button>
          <button data-preset="any"    type="button">Any date</button>
        </div>
        <div class="filt-date-custom">
          <label>
            <span>From</span>
            <input id="filt-from" type="date" value="${escapeAttr(_filter.from || '')}">
          </label>
          <label>
            <span>To</span>
            <input id="filt-to" type="date" value="${escapeAttr(_filter.to || '')}">
          </label>
        </div>
        <div class="filt-date-pop-actions">
          <button id="filt-date-clear" type="button">Clear</button>
          <button id="filt-date-close" type="button">Done</button>
        </div>
      </div>
    </div>
  `;

  // Chips — change status and repaint (repaint is fine here: clicking
  // a chip is a single discrete action, not a continuous one).
  host.querySelectorAll('.chip').forEach((c) => {
    c.addEventListener('click', () => {
      _filter.status = c.dataset.status;
      persistFilter();
      _page = 1; fetchPage(false);
      paintFilterBar();
    });
  });

  // Mobile toggle — flips the .open class on the collapsible.
  host.querySelector('#filt-toggle').addEventListener('click', () => {
    _filtersOpen = !_filtersOpen;
    const collapsible = host.querySelector('#filt-collapsible');
    const toggle      = host.querySelector('#filt-toggle');
    collapsible.classList.toggle('open', _filtersOpen);
    toggle.setAttribute('aria-expanded', _filtersOpen ? 'true' : 'false');
  });

  // Search input — DO NOT repaint the bar on input. Repainting destroys
  // the input element, which moves focus and erases the cursor position
  // every keystroke. Just update the dependent surface bits directly:
  //   - the clear (×) button's visibility
  //   - the Clear button's visibility
  //   - the mobile toggle's "has-active" tint + counter
  // Then debounce a list refetch.
  const qInput = host.querySelector('#filt-q');
  let qTimer;
  qInput.addEventListener('input', (e) => {
    const v = e.target.value;
    _filter.q = v.trim();
    updateClearButtons(host);
    updateToggleBadge(host);
    clearTimeout(qTimer);
    qTimer = setTimeout(() => {
      persistFilter(); _page = 1; fetchPage(false);
      // No repaint here. fetchPage() will refresh the LIST via paintList(),
      // which is a different DOM subtree and doesn't disturb the search input.
    }, 350);
  });
  host.querySelector('#filt-q-clear').addEventListener('click', () => {
    _filter.q = '';
    qInput.value = '';
    qInput.focus();
    updateClearButtons(host);
    updateToggleBadge(host);
    persistFilter(); _page = 1; fetchPage(false);
  });

  // Employee filter — always present in DOM. Change triggers a list
  // refetch, no bar repaint needed.
  host.querySelector('#filt-emp').addEventListener('change', (e) => {
    _filter.employee_id = e.target.value;
    e.target.classList.toggle('active', !!_filter.employee_id);
    updateClearButtons(host);
    updateToggleBadge(host);
    persistFilter(); _page = 1; fetchPage(false);
  });

  // Date-range popover trigger
  const trigger = host.querySelector('#filt-date-trigger');
  const pop = host.querySelector('#filt-date-pop');
  trigger.addEventListener('click', () => {
    const willOpen = pop.hasAttribute('hidden');
    pop.toggleAttribute('hidden');
    trigger.setAttribute('aria-expanded', String(willOpen));
  });
  // Close on outside click
  if (!_dateOutsideClickAttached) {
    document.addEventListener('click', (e) => {
      const open = _root?.querySelector('.filt-date-pop:not([hidden])');
      if (!open) return;
      if (!e.target.closest('.filt-date-pop') && !e.target.closest('#filt-date-trigger')) {
        open.setAttribute('hidden', '');
        _root?.querySelector('#filt-date-trigger')?.setAttribute('aria-expanded', 'false');
      }
    });
    _dateOutsideClickAttached = true;
  }

  // Date inputs (custom range)
  host.querySelector('#filt-from').addEventListener('change', (e) => {
    _filter.from = e.target.value;
    persistFilter(); _page = 1; fetchPage(false);
    paintFilterBar();
  });
  host.querySelector('#filt-to').addEventListener('change', (e) => {
    _filter.to = e.target.value;
    persistFilter(); _page = 1; fetchPage(false);
    paintFilterBar();
  });

  // Date presets
  host.querySelectorAll('[data-preset]').forEach((b) => {
    b.addEventListener('click', () => {
      applyDatePreset(b.dataset.preset);
      persistFilter(); _page = 1; fetchPage(false);
      paintFilterBar();
    });
  });

  // Date popover actions
  host.querySelector('#filt-date-clear').addEventListener('click', () => {
    _filter.from = ''; _filter.to = '';
    persistFilter(); _page = 1; fetchPage(false);
    paintFilterBar();
  });
  host.querySelector('#filt-date-close').addEventListener('click', () => {
    pop.setAttribute('hidden', '');
  });

  // Universal Clear button — wipes search + date + employee, keeps status chip
  host.querySelector('#filt-clear')?.addEventListener('click', () => {
    _filter = { status: _filter.status, employee_id: '', from: '', to: '', q: '' };
    persistFilter(); _page = 1; fetchPage(false);
    paintFilterBar();
  });
}

// Applies a named preset to _filter.from/_filter.to. All dates ISO YYYY-MM-DD.
function applyDatePreset(preset) {
  const fmt = (d) => d.toISOString().slice(0, 10);
  const today = new Date();
  let from = '', to = '';
  if (preset === 'today') {
    from = to = fmt(today);
  } else if (preset === '7d') {
    const start = new Date(today); start.setDate(today.getDate() - 6);
    from = fmt(start); to = fmt(today);
  } else if (preset === '30d') {
    const start = new Date(today); start.setDate(today.getDate() - 29);
    from = fmt(start); to = fmt(today);
  } else if (preset === 'month') {
    const start = new Date(today.getFullYear(), today.getMonth(), 1);
    from = fmt(start); to = fmt(today);
  } else if (preset === 'lmonth') {
    const start = new Date(today.getFullYear(), today.getMonth() - 1, 1);
    const end   = new Date(today.getFullYear(), today.getMonth(), 0);
    from = fmt(start); to = fmt(end);
  } else if (preset === 'any') {
    from = ''; to = '';
  }
  _filter.from = from;
  _filter.to   = to;
}

// Human label for the date trigger button
function formatDateRangeLabel(from, to) {
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
  if (to)         return `Until ${short(to)}`;
  return 'Any date';
}

function chip(value, label) {
  const active = _filter.status === value;
  return `<button class="chip ${active ? 'active' : ''}" data-status="${value}">${label}</button>`;
}

// Update the search-clear (×) and universal Clear button visibility
// WITHOUT repainting the bar (which would destroy the search input
// and steal focus on every keystroke).
function updateClearButtons(host) {
  const xBtn = host.querySelector('#filt-q-clear');
  if (xBtn) {
    if (_filter.q) xBtn.removeAttribute('hidden');
    else           xBtn.setAttribute('hidden', '');
  }
  const clearBtn = host.querySelector('#filt-clear');
  if (clearBtn) {
    const hasAnyExtras = !!(_filter.q || _filter.from || _filter.to || _filter.employee_id);
    if (hasAnyExtras) clearBtn.removeAttribute('hidden');
    else              clearBtn.setAttribute('hidden', '');
  }
}

// Update the mobile filter-toggle's active tint + count badge, again
// without repainting the bar.
function updateToggleBadge(host) {
  const toggle = host.querySelector('#filt-toggle');
  if (!toggle) return;
  const n = (_filter.q ? 1 : 0)
          + (_filter.from || _filter.to ? 1 : 0)
          + (_filter.employee_id ? 1 : 0);
  toggle.classList.toggle('has-active', n > 0);
  let counter = toggle.querySelector('.filt-toggle-count');
  if (n > 0) {
    if (!counter) {
      counter = document.createElement('span');
      counter.className = 'filt-toggle-count';
      toggle.appendChild(counter);
    }
    counter.textContent = String(n);
  } else if (counter) {
    counter.remove();
  }
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
  // Refresh the employee dropdown if new employees appeared in data.
  // The <select> is always in the DOM (with data-empty when nothing's
  // there yet), so we update its options surgically rather than
  // repainting the whole filter bar — that would destroy the search
  // input mid-keystroke.
  refreshEmployeeList();
  const empSel = _root.querySelector('#filt-emp');
  if (empSel) {
    const expectedCount = _employees.length + 1; // +1 for "All employees"
    if (empSel.options.length !== expectedCount) {
      const optsHtml = ['<option value="">All employees</option>']
        .concat(_employees.map((e) => {
          const label = escape(e.code ? `${e.code} — ${e.name}` : e.name);
          const sel   = _filter.employee_id === e.id ? ' selected' : '';
          return `<option value="${escapeAttr(e.id)}"${sel}>${label}</option>`;
        }))
        .join('');
      empSel.innerHTML = optsHtml;
    }
    // Toggle visibility: hide when team has no known employees yet,
    // show as soon as we've seen at least one in the job data.
    if (_employees.length) empSel.removeAttribute('data-empty');
    else                   empSel.setAttribute('data-empty', '');
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

  host.querySelectorAll('[data-slip]').forEach((b) =>
    b.addEventListener('click', () => doPrintSlip(b.dataset.slip)));

  // Record-payment trigger — opens the modal. The modal mounts to
  // document.body and wires its own internal handlers; we don't query
  // inside the card list for any payform inputs anymore.
  host.querySelectorAll('[data-recordpay]').forEach((b) =>
    b.addEventListener('click', () => openRecordPay(b.dataset.recordpay)));

  // Pay-link panel actions (shown after a balance-Online record)
  host.querySelectorAll('[data-paylink-copy]').forEach((b) =>
    b.addEventListener('click', () => copyPayLink(b.dataset.paylinkCopy)));
  host.querySelectorAll('[data-paylink-share]').forEach((b) =>
    b.addEventListener('click', () => sharePayLink(b.dataset.paylinkShare)));
  host.querySelectorAll('[data-paylink-open]').forEach((b) =>
    b.addEventListener('click', () => openPayLink(b.dataset.paylinkOpen)));
  host.querySelectorAll('[data-paylink-dismiss]').forEach((b) =>
    b.addEventListener('click', () => dismissPayLink(b.dataset.paylinkDismiss)));

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
  const isBusy   = _busy.has(j.id);
  const showVehicle = state.needsVehicleDetails();

  // Identity. The TL works one service only, so the service name is
  // redundant noise on every card — we don't show it. The order_number
  // is the big identifier (matches what the customer sees on their
  // tracking page and admin sees in the Orders table). If the legacy
  // wizard happened to capture a plate, it goes on a small subline.
  const hasPlate = showVehicle && !!(j.vehicle_plate && j.vehicle_plate.trim());
  const identityBlock = `
    <div class="jobs-id">
      <div class="jobs-plate jobs-plate-num tabular">#${escape(String(j.order_number || '—'))}</div>
      ${hasPlate
        ? `<div class="jobs-svc">${escape(j.vehicle_plate)}</div>`
        : ''}
    </div>`;

  const total   = parseFloat(j.total_amount || 0);
  const paid    = parseFloat(j.paid_amount  || 0);
  const balance = Math.max(0, total - paid);
  const isPaid  = j.payment_status === 'paid';
  const isActive = j.status === 'accepted' || j.status === 'in_progress';

  // Pending Stripe pay-link for this order (after TL recorded balance as Online).
  // While a link is active, the inline record form is closed and the link panel
  // takes its place. The link auto-disappears once the order flips to paid.
  const payLink = _payLinks.get(j.id);

  // Record-payment button — slim version (icon + "Record payment", no
  // inline amount; the AED total still lives in the modal that opens).
  const recordPayBtn = (isActive && balance > 0.005 && !payLink)
    ? `<button class="btn-record-pay" data-recordpay="${escapeAttr(j.id)}">
         <span class="btn-record-pay-icon" aria-hidden="true">+</span>
         <span class="btn-record-pay-label">Record payment</span>
       </button>`
    : '';

  // The record-payment form used to render inline here, which stretched
  // the grid row whenever it was open. It's now a centered modal mounted
  // on document.body (see openPayModal / renderPayModal / closePayModal).
  const payForm = '';

  // Stripe pay-link panel — shown after a balance-Online "Record" request
  // succeeds. Mirrors the UX of the wizard's success screen.
  const payLinkPanel = payLink
    ? `<div class="jobs-paylink">
         <div class="jobs-paylink-head">
           <span class="jobs-paylink-label">🔗 Payment link · ${CURRENCY} ${formatPrice(payLink.amount)}</span>
           <button class="jobs-paylink-dismiss" data-paylink-dismiss="${escapeAttr(j.id)}" aria-label="Dismiss">×</button>
         </div>
         <div class="jobs-paylink-url">${escape(payLink.url)}</div>
         <div class="jobs-paylink-actions">
           <button class="jobs-paylink-btn" data-paylink-copy="${escapeAttr(j.id)}">Copy</button>
           <button class="jobs-paylink-btn" data-paylink-share="${escapeAttr(j.id)}" ${navigator.share ? '' : 'hidden'}>Share</button>
           <button class="jobs-paylink-btn jobs-paylink-btn-primary" data-paylink-open="${escapeAttr(j.id)}">Open</button>
         </div>
         <div class="jobs-paylink-hint">
           Customer pays via this link. Order updates when Stripe confirms.
         </div>
       </div>`
    : '';

  // Meta strip — Employee. Location was removed (no longer
  // collected or surfaced anywhere in the system).
  const metaRows = [];
  if (j.employee_name) {
    const empText = j.employee_code
      ? `${j.employee_code} · ${j.employee_name}`
      : j.employee_name;
    metaRows.push(`<div class="jobs-meta-row">
      <span class="jobs-meta-icon" aria-hidden="true">◐</span>
      <span class="jobs-meta-val">${escape(empText)}</span>
    </div>`);
  }
  const metaText = metaRows.length
    ? `<div class="jobs-meta-text">${metaRows.join('')}</div>`
    : '';
  const metaBlock = metaText
    ? `<div class="jobs-meta">${metaText}</div>`
    : '';

  // Slip button — printed when the job is in a state worth printing
  // (unpaid → a customer-receipt; paid → an itemized receipt with
  // collected amount). Cancelled or zero-info states get no button.
  const slipBtn = (j.payment_status === 'unpaid' || j.payment_status === 'paid')
    ? `<button class="btn-slip" data-slip="${escapeAttr(j.id)}">🖨 Slip</button>`
    : '';

  // The action row layout depends on which list we're in:
  //   - Unpaid (active + balance > 0): [Record payment]  [Slip]
  //   - Paid (completed): "✓ Completed <date>" stamp + [Slip] aligned right
  //   - Cancelled: "Cancelled" stamp only
  //   - Linger paid: "✓ Paid" stamp + [Slip]
  let actionRow = '';
  if (j.status === 'completed') {
    actionRow = `
      <div class="jobs-actions jobs-actions-paid">
        <div class="jobs-done-stamp">✓ Completed ${escape(formatTime(j.completed_at))}</div>
        ${slipBtn}
      </div>`;
  } else if (j.status === 'cancelled') {
    actionRow = `
      <div class="jobs-actions">
        <div class="jobs-done-stamp jobs-done-stamp-bad">Cancelled</div>
      </div>`;
  } else if (isActive && isPaid) {
    actionRow = `
      <div class="jobs-actions jobs-actions-paid">
        <div class="jobs-done-stamp">✓ Paid</div>
        ${slipBtn}
      </div>`;
  } else if (isActive && balance > 0.005 && !payLink) {
    // Unpaid: Record payment + Slip side by side. Record-pay gets
    // most of the row; Slip is a compact secondary action.
    actionRow = `
      <div class="jobs-actions jobs-actions-unpaid">
        ${recordPayBtn}
        ${slipBtn}
      </div>`;
  }

  return `
    <article class="card jobs-card jobs-status-${j.status}" data-id="${escapeAttr(j.id)}">
      <div class="jobs-header">
        <span class="serial-badge tabular">#${parseInt(j.daily_serial, 10) || '—'}</span>
        ${identityBlock}
        <div class="jobs-header-right">
          <span class="jobs-status-pill jobs-status-pill-${j.status}">
            <span class="jobs-status-dot" aria-hidden="true"></span>
            ${escape(statusLabel(j.status))}
          </span>
          <div class="jobs-price tabular">${CURRENCY} ${formatPrice(j.price)}</div>
        </div>
      </div>

      ${metaBlock}

      ${payForm}
      ${payLinkPanel}

      ${actionRow}
    </article>`;
}

function statusLabel(s) {
  // Job-status labels. Use the operator's mental model: a job that's
  // accepted/in_progress is one where payment hasn't been collected
  // yet ("Unpaid"); a completed job has been paid ("Paid"). Because
  // orders auto-complete the moment they hit paid status, these
  // labels stay accurate — the TL never sees a paid-but-not-completed
  // job because OrderClaim::maybeAutoComplete flips it on the spot.
  return {
    pending:     'Pending',
    assigned:    'Assigned',
    accepted:    'Unpaid',
    in_progress: 'Unpaid',
    completed:   'Paid',
    cancelled:   'Cancelled',
    rejected:    'Rejected',
  }[s] || s;
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
  renderPayModal(svcId);
}

function closeRecordPay(svcId) {
  _payOpen.delete(svcId);
  _payDraft.delete(svcId);
  closePayModal();
}

// ─── Split-payment helpers (used by the Record Payment modal) ──────
// A row in the split-payment grid: label + AED-prefixed amount input.
function splitRowJob(method, label, value, svcId) {
  // Show empty string when value is 0 so the placeholder is visible
  const v = (value && value > 0) ? value : '';
  return `
    <div class="jobs-split-row">
      <span class="jobs-split-row-lbl">${label}</span>
      <div class="jobs-split-row-input">
        <span class="jobs-split-row-prefix">${CURRENCY}</span>
        <input type="number" inputmode="decimal" step="0.01" min="0"
               data-payform-split="${escapeAttr(svcId)}"
               data-method="${method}"
               value="${escapeAttr(String(v))}"
               placeholder="0.00"
               autocomplete="off">
      </div>
    </div>`;
}

// Inline update of the sum-row + save-button enablement, called from each
// split input's `input` handler. Avoids a full modal re-render that would
// steal focus from the field the user is typing into.
function updatePayModalSplitSum(el, svcId, balance) {
  const d = _payDraft.get(svcId) || {};
  const cash = parseFloat(d.split_cash || 0);
  const card = parseFloat(d.split_card || 0);
  const sum  = (cash > 0 ? cash : 0) + (card > 0 ? card : 0);
  const ok   = Math.abs(sum - balance) < 0.005 && sum > 0;
  const state = ok ? 'ok'
              : (sum > balance + 0.005 ? 'over' : 'under');

  const sumEl = el.querySelector('.jobs-split-sum');
  if (sumEl) {
    sumEl.classList.remove('jobs-split-sum-ok', 'jobs-split-sum-over', 'jobs-split-sum-under');
    sumEl.classList.add('jobs-split-sum-' + state);
    sumEl.querySelector('.jobs-split-sum-val').innerHTML =
      `${CURRENCY} ${formatPrice(sum)}<span class="jobs-split-sum-target"> / ${formatPrice(balance)}</span>`;
    sumEl.querySelector('.jobs-split-sum-icon').textContent =
      ok ? '✓' : (sum > balance + 0.005 ? '!' : '…');
  }

  // Enable/disable the Save button based on whether sum matches balance
  const saveBtn = el.querySelector('[data-payform-save]');
  if (saveBtn) saveBtn.disabled = !ok;
}

// ─── Pay-modal rendering ───────────────────────────────────────────
// The modal lives on document.body so it floats above the grid and
// doesn't disturb the card layout. On mobile (< 700px) it presents as
// a bottom sheet; on desktop as a centered card.
function ensurePayModalEl() {
  if (_payModalEl) return _payModalEl;
  _payModalEl = document.createElement('div');
  _payModalEl.id = 'tl-pay-modal';
  _payModalEl.className = 'tl-modal';
  _payModalEl.setAttribute('aria-hidden', 'true');
  document.body.appendChild(_payModalEl);
  // Backdrop click closes
  _payModalEl.addEventListener('click', (e) => {
    if (e.target === _payModalEl) closePayModal();
  });
  // Escape key closes
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && _payModalEl?.classList.contains('open')) {
      closePayModal();
    }
  });
  return _payModalEl;
}

function renderPayModal(svcId) {
  const el = ensurePayModalEl();
  const j = _items.find((x) => x.id === svcId);
  if (!j) { closePayModal(); return; }

  const total   = parseFloat(j.total_amount || 0);
  const paid    = parseFloat(j.paid_amount  || 0);
  const balance = Math.max(0, total - paid);
  const isBusy  = _busy.has(svcId);
  const draft = _payDraft.get(svcId) || {
    method:       'cash',
    amount:       balance,
    ref:          '',
    split_cash:   0,
    split_card:   0,
  };

  // Subtitle: identify the order so TL knows what they're recording for
  const idText = j.vehicle_plate || j.service_name || '—';
  const subtitle = `#${parseInt(j.daily_serial, 10) || '—'} · ${escape(idText)}`;

  // Split sum + match state (for live indicator + Submit gate).
  // Online not allowed inside Record-Payment split — that flow needs a fresh
  // Stripe session and is out of scope; backend will 422 if attempted.
  const splitCash = parseFloat(draft.split_cash || 0);
  const splitCard = parseFloat(draft.split_card || 0);
  const splitSum  = (splitCash > 0 ? splitCash : 0) + (splitCard > 0 ? splitCard : 0);
  const splitOK   = Math.abs(splitSum - balance) < 0.005 && splitSum > 0;
  const splitState = splitOK ? 'ok'
                  : (splitSum > balance + 0.005 ? 'over' : 'under');

  // Submit-enable / button-label logic. For split, button is disabled until
  // sum equals balance exactly. For online, label changes to "Generate link".
  const canSave = draft.method === 'split'
                ? splitOK && !isBusy
                : !isBusy;
  const saveLabel = isBusy
                ? 'Saving…'
                : (draft.method === 'online' ? 'Generate link →'
                : (draft.method === 'split'  ? 'Record split payment'
                : 'Record payment'));

  el.innerHTML = `
    <div class="tl-modal-card" role="dialog" aria-modal="true" aria-labelledby="payform-title">
      <header class="tl-modal-head">
        <div class="tl-modal-head-text">
          <h2 id="payform-title" class="tl-modal-title">Record payment</h2>
          <p class="tl-modal-sub">${subtitle}</p>
        </div>
        <button class="tl-modal-close" data-payform-cancel="${escapeAttr(svcId)}" aria-label="Close">×</button>
      </header>

      <div class="tl-modal-body">
        <!-- Order total — single line. With partial payments removed,
             paid is always 0 and balance always equals total, so the
             old 3-row Total/Paid/Balance block was just noise. -->
        <div class="jobs-payform-summary">
          <div class="jobs-payform-summary-row jobs-payform-summary-row-emph">
            <span>Order total</span>
            <span class="tabular">${CURRENCY} ${formatPrice(total)}</span>
          </div>
        </div>

        <div class="jobs-payform-field">
          <div class="jobs-payform-label">Method</div>
          <div class="jobs-payform-row jobs-payform-row-4">
            <button class="pm-mini ${draft.method === 'cash'   ? 'active' : ''}" data-payform-method="cash"   data-job="${escapeAttr(svcId)}">💵 Cash</button>
            <button class="pm-mini ${draft.method === 'card'   ? 'active' : ''}" data-payform-method="card"   data-job="${escapeAttr(svcId)}">💳 Card</button>
            <button class="pm-mini ${draft.method === 'online' ? 'active' : ''}" data-payform-method="online" data-job="${escapeAttr(svcId)}">🔗 Online</button>
            <button class="pm-mini ${draft.method === 'split'  ? 'active' : ''}" data-payform-method="split"  data-job="${escapeAttr(svcId)}">🔀 Split</button>
          </div>
        </div>

        ${draft.method === 'split' ? `
          <div class="jobs-split-box">
            <div class="jobs-split-hint">
              Enter how much was collected in each method.
              Total must equal <b>${CURRENCY} ${formatPrice(balance)}</b>.
            </div>
            ${splitRowJob('cash', '💵 Cash', draft.split_cash, svcId)}
            ${splitRowJob('card', '💳 Card', draft.split_card, svcId)}
            <div class="jobs-split-sum jobs-split-sum-${splitState}">
              <span class="jobs-split-sum-lbl">Sum</span>
              <span class="jobs-split-sum-val tabular">
                ${CURRENCY} ${formatPrice(splitSum)}
                <span class="jobs-split-sum-target"> / ${formatPrice(balance)}</span>
              </span>
              <span class="jobs-split-sum-icon">${splitOK ? '✓' : (splitSum > balance + 0.005 ? '!' : '…')}</span>
            </div>
          </div>
        ` : `
          ${(draft.method === 'cash' || draft.method === 'card') ? `
            <div class="jobs-payform-field">
              <div class="jobs-payform-label">Reference <span class="jobs-payform-hint">· optional</span></div>
              <input class="jobs-payform-ref" type="text"
                     value="${escapeAttr(draft.ref || '')}"
                     data-payform-ref="${escapeAttr(svcId)}"
                     placeholder="Transaction id, approval code…"
                     autocomplete="off">
            </div>
          ` : ''}

          ${draft.method === 'online' ? `
            <div class="jobs-payform-online-hint">
              A Stripe payment link will be generated for the full
              ${CURRENCY} ${formatPrice(balance)} amount. The customer
              pays via the link — the order updates when Stripe confirms.
            </div>` : ''}
        `}
      </div>

      <footer class="tl-modal-actions">
        <button class="btn-record-cancel" data-payform-cancel="${escapeAttr(svcId)}">Cancel</button>
        <button class="btn-record-save" data-payform-save="${escapeAttr(svcId)}" ${canSave ? '' : 'disabled'}>
          ${saveLabel}
        </button>
      </footer>
    </div>
  `;

  // Wire handlers (re-bound on every render since markup is rebuilt)
  el.querySelectorAll('[data-payform-cancel]').forEach((b) =>
    b.addEventListener('click', () => closeRecordPay(b.dataset.payformCancel)));
  el.querySelectorAll('[data-payform-save]').forEach((b) =>
    b.addEventListener('click', () => saveRecordPay(b.dataset.payformSave)));
  el.querySelectorAll('[data-payform-method]').forEach((b) =>
    b.addEventListener('click', () => {
      const id = b.dataset.job;
      const d = _payDraft.get(id) || {};
      const newMethod = b.dataset.payformMethod;
      // When switching INTO split, seed the cash field with the full balance
      // so the TL has a one-tap "all cash" starting point.
      if (newMethod === 'split' && d.method !== 'split') {
        d.split_cash = parseFloat(d.amount || balance) || balance;
        d.split_card = 0;
      }
      // When switching AWAY from split, restore single-method default
      if (newMethod !== 'split' && d.method === 'split') {
        d.amount = balance;
      }
      d.method = newMethod;
      _payDraft.set(id, d);
      renderPayModal(id);   // re-render to show split box / hint / button changes
    }));
  el.querySelectorAll('[data-payform-ref]').forEach((inp) =>
    inp.addEventListener('input', () => {
      const id = inp.dataset.payformRef;
      const d = _payDraft.get(id) || {};
      d.ref = inp.value;
      _payDraft.set(id, d);
    }));
  // Split-row inputs — update sum + submit-enable inline (no full re-render
  // so focus stays in the input the user is typing into).
  el.querySelectorAll('[data-payform-split]').forEach((inp) =>
    inp.addEventListener('input', () => {
      const id  = inp.dataset.payformSplit;
      const key = inp.dataset.method;     // 'cash' or 'card'
      const v   = parseFloat(inp.value);
      const safe = Number.isFinite(v) && v > 0 ? v : 0;
      const d = _payDraft.get(id) || {};
      d[`split_${key}`] = safe;
      _payDraft.set(id, d);
      updatePayModalSplitSum(el, id, balance);
    }));

  el.classList.add('open');
  el.setAttribute('aria-hidden', 'false');
  document.body.classList.add('tl-modal-open');

  // Focus the first split input if split is active, otherwise the
  // optional Reference field — Cash/Card/Online single methods have
  // no required input, so the Save button is the natural next target.
  requestAnimationFrame(() => {
    const inp = el.querySelector('[data-payform-split], .jobs-payform-ref');
    if (inp) { inp.focus(); }
  });
}

function closePayModal() {
  if (!_payModalEl) return;
  _payModalEl.classList.remove('open');
  _payModalEl.setAttribute('aria-hidden', 'true');
  document.body.classList.remove('tl-modal-open');
  // Clear inner markup so any focused inputs release focus
  _payModalEl.innerHTML = '';
}

// ─── Pay-link panel helpers ────────────────────────────────────────
// These operate on the link stored in _payLinks for the given service-id.
function copyPayLink(svcId) {
  const link = _payLinks.get(svcId);
  if (!link) return;
  try {
    navigator.clipboard.writeText(link.url);
    toast('Link copied');
  } catch {
    // Older browsers — fallback
    const ta = document.createElement('textarea');
    ta.value = link.url;
    document.body.appendChild(ta);
    ta.select(); document.execCommand('copy');
    document.body.removeChild(ta);
    toast('Link copied');
  }
}
async function sharePayLink(svcId) {
  const link = _payLinks.get(svcId);
  if (!link || !navigator.share) return;
  try {
    await navigator.share({
      title: 'Airport VAS — Payment',
      text:  'Please complete your payment:',
      url:   link.url,
    });
  } catch {
    // User dismissed — no-op
  }
}
function openPayLink(svcId) {
  const link = _payLinks.get(svcId);
  if (!link) return;
  window.open(link.url, '_blank', 'noopener');
}
function dismissPayLink(svcId) {
  // Just removes the panel locally; the Stripe session stays valid on the
  // server and the customer can still complete the payment via the URL.
  _payLinks.delete(svcId);
  paintList();
}

async function saveRecordPay(svcId) {
  if (_busy.has(svcId)) return;

  const j = _items.find((x) => x.id === svcId);
  if (!j) { toast('Job not found', 'error'); return; }

  const draft = _payDraft.get(svcId) || {};
  const method = draft.method || 'cash';
  const balance = Math.max(0, parseFloat(j.total_amount || 0) - parseFloat(j.paid_amount || 0));

  // Validate based on method
  if (!['cash','card','online','split'].includes(method)) {
    toast('Pick a payment method', 'error'); return;
  }

  let amount;          // single-method amount (cash/card/online)
  let splitRows;       // array of {method, amount} for split
  let splitSum = 0;

  if (method === 'split') {
    const cash = parseFloat(draft.split_cash || 0);
    const card = parseFloat(draft.split_card || 0);
    splitRows = [];
    if (cash > 0) splitRows.push({ method: 'cash', amount: cash });
    if (card > 0) splitRows.push({ method: 'card', amount: card });
    splitSum = cash + card;
    if (splitRows.length === 0) {
      toast('Enter at least one split amount', 'error'); return;
    }
    if (Math.abs(splitSum - balance) > 0.005) {
      toast(`Split total must equal balance (${CURRENCY} ${formatPrice(balance)})`, 'error');
      return;
    }
  } else {
    amount = parseFloat(draft.amount || 0);
    if (!(amount > 0)) {
      toast('Enter an amount greater than zero', 'error'); return;
    }
    if (amount > balance + 0.005) {
      toast(`Amount can't exceed balance (${CURRENCY} ${formatPrice(balance)})`, 'error'); return;
    }
  }

  _busy.add(svcId);
  paintList();

  // ── ONLINE branch ─────────────────────────────────────────────────
  // For Online, we don't record a payment locally — we ask Stripe for a
  // checkout link and present it to the TL. The customer pays via Stripe;
  // a webhook (or /pay/success verification) flips paid_amount + status.
  if (method === 'online') {
    if (!navigator.onLine) {
      _busy.delete(svcId);
      paintList();
      toast('Need to be online to generate a payment link', 'error');
      return;
    }
    try {
      const res = await api.post(`/orders/${j.order_id}/payment-links`, { amount });
      // Prefer the short pay_url (/pay/go/<token>) — it redirects to
      // Stripe via our backend, fits in SMS/WhatsApp without truncation,
      // and survives Stripe session refreshes (we reuse the existing
      // open session or create a new one on each visit). Fall back to
      // the raw Stripe URL only if the backend didn't return a short one.
      const url = res?.data?.pay_url || res?.data?.checkout_url;
      if (!url) throw new Error('No payment link returned');
      _payLinks.set(svcId, {
        url,
        amount,
        created_at: Date.now(),
      });
      _payOpen.delete(svcId);
      _payDraft.delete(svcId);
      closePayModal();
      toast('Payment link ready — share with customer');
    } catch (e) {
      toast(e.message || 'Could not generate link', 'error');
    } finally {
      _busy.delete(svcId);
      paintList();
    }
    return;
  }

  // ── CASH / CARD / SPLIT branch (instant record) ──────────────────
  // Build the payload in the shape the server expects.
  //   single method → { payment_method, amount, transaction_ref, uuid_ref }
  //   split         → { payment_method: 'split', split_payments: [...], uuid_ref }
  const payload = method === 'split'
    ? {
        payment_method: 'split',
        split_payments: splitRows,
        uuid_ref:       crypto.randomUUID(),
      }
    : {
        payment_method:  method,
        amount:          amount,
        transaction_ref: draft.ref || null,
        uuid_ref:        crypto.randomUUID(),
      };

  // The "amount that just landed" for the local optimistic update + toast
  const landedAmount = method === 'split' ? splitSum : amount;

  // Offline: queue and update locally so the UI reflects the new balance
  if (!navigator.onLine) {
    await queueAction({
      entity_type: 'payment',
      entity_id:   j.order_id,
      action_type: 'record',
      payload,
    });
    // Optimistic update — local card reflects the new state
    const newPaid = parseFloat(j.paid_amount || 0) + landedAmount;
    j.paid_amount = newPaid;
    j.payment_status = newPaid >= parseFloat(j.total_amount || 0) ? 'paid' : 'partial';
    j.payments = Array.isArray(j.payments) ? j.payments : [];
    if (method === 'split') {
      splitRows.forEach((r) => j.payments.push({
        method: r.method,
        amount: r.amount,
        status: 'success',
        created_at: new Date().toISOString(),
      }));
    } else {
      j.payments.push({
        method, amount,
        transaction_ref: draft.ref || null,
        status: 'success',
        created_at: new Date().toISOString(),
      });
    }
    toast('Payment queued — will sync when reconnected');
    _payOpen.delete(svcId);
    _payDraft.delete(svcId);
    closePayModal();
    _busy.delete(svcId);
    paintList();
    return;
  }

  try {
    const res = await api.post(`/orders/${j.order_id}/payments`, payload);
    // Server-truth values — apply locally first for instant feedback
    const newPaid   = parseFloat(res?.data?.paid_amount    ?? j.paid_amount);
    const newStatus = res?.data?.payment_status            ?? j.payment_status;
    j.paid_amount     = newPaid;
    j.payment_status  = newStatus;
    j.payments = Array.isArray(j.payments) ? j.payments : [];
    if (method === 'split') {
      splitRows.forEach((r) => j.payments.push({
        method: r.method,
        amount: r.amount,
        status: 'success',
        created_at: new Date().toISOString(),
      }));
    } else {
      j.payments.push({
        method, amount,
        transaction_ref: draft.ref || null,
        status: 'success',
        created_at: new Date().toISOString(),
      });
    }

    const balanceRemaining = Math.max(0, parseFloat(j.total_amount) - newPaid);
    toast(
      newStatus === 'paid'
        ? (method === 'split'
            ? `Split payment recorded — ${CURRENCY} ${formatPrice(landedAmount)} closed out`
            : 'Payment recorded — order fully paid')
        : `Payment recorded — ${CURRENCY} ${formatPrice(balanceRemaining)} balance remains`,
      'success'
    );

    _payOpen.delete(svcId);
    _payDraft.delete(svcId);
    closePayModal();

    // Force-refresh from the server now that the write has committed.
    // The local mutation above shows the new state instantly; this
    // re-fetch ensures everything (payments array, status, recomputed
    // values) is in sync with the server, and prevents an in-flight
    // 20-second poll that started BEFORE our write from clobbering the
    // UI with stale data once it returns.
    silentRefresh();
    // A paid order means the My-Jobs badge count drops by one; refresh
    // it immediately rather than waiting for the next 30s poll tick.
    window.refreshBadges?.();
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
      closePayModal();
    } else {
      toast(e.message || 'Could not record payment', 'error');
    }
  } finally {
    _busy.delete(svcId);
    paintList();
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

  // Payment breakdown — Total / Paid so far / Balance due.
  // The slip is what the customer walks away with, so it should clearly show
  // any outstanding balance. Three states:
  //   paid    → Total + Paid in full (no Due row)
  //   partial → Total + Paid (with methods) + Due (highlighted)
  //   unpaid  → Total + Due (no Paid row; nothing paid yet)
  const total = parseFloat(s.total_amount ?? s.price ?? 0);
  const paid  = parseFloat(s.paid_total ?? s.paid_amount ?? 0);
  const due   = Math.max(0, total - paid);
  const status = s.payment_status
              || (paid <= 0       ? 'unpaid'
              :  paid >= total - 0.005 ? 'paid'
              :  'partial');
  const cur = escape(s.currency || 'AED');
  const methodsSm = s.payment_methods
                  ? ` <small>(${escape(s.payment_methods)})</small>` : '';

  let paymentBlock = '';
  if (status === 'paid') {
    paymentBlock = `
      <div class="slip-row"><span>Total</span>
        <span class="tabular">${cur} ${formatPrice(total)}</span></div>
      <div class="slip-row"><span>Paid</span>
        <span class="tabular"><b>${cur} ${formatPrice(paid)}</b>${methodsSm}</span></div>
      <div class="slip-paystamp slip-paystamp-ok">✓ Paid in full</div>`;
  } else if (status === 'partial') {
    paymentBlock = `
      <div class="slip-row"><span>Total</span>
        <span class="tabular">${cur} ${formatPrice(total)}</span></div>
      <div class="slip-row"><span>Paid</span>
        <span class="tabular">${cur} ${formatPrice(paid)}${methodsSm}</span></div>
      <div class="slip-row slip-row-due"><span>Balance due</span>
        <span class="tabular"><b>${cur} ${formatPrice(due)}</b></span></div>
      <div class="slip-paystamp slip-paystamp-warn">◐ Partial payment</div>`;
  } else {
    paymentBlock = `
      <div class="slip-row"><span>Total</span>
        <span class="tabular">${cur} ${formatPrice(total)}</span></div>
      <div class="slip-row slip-row-due"><span>Balance due</span>
        <span class="tabular"><b>${cur} ${formatPrice(due)}</b></span></div>
      <div class="slip-paystamp slip-paystamp-bad">⏱ Unpaid</div>`;
  }

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
      ${s.employee_name ? `<div class="slip-row"><span>Employee</span><span>${escape(s.employee_code ? `${s.employee_code} · ${s.employee_name}` : s.employee_name)}</span></div>` : ''}
      ${s.team_leader_name ? `<div class="slip-row"><span>Team leader</span><span>${escape(s.team_leader_name)}</span></div>` : ''}
      ${s.order_number ? `<div class="slip-row"><span>Order #</span><span class="tabular"><b>${escape(String(s.order_number))}</b></span></div>` : ''}
      <div class="slip-row"><span>Time</span><span>${escape(formatTime(s.order_time))}</span></div>

      <div class="slip-divider dashed"></div>
      ${paymentBlock}

      <div class="slip-footer">Thank you · شكراً</div>
    </div>
  `;
}

// ─── DOM helpers ───────────────────────────────────────────────────
async function persistFilter() {
  try { await kvSet('jobs_filter', _filter); } catch {}
}

// ─── Format helpers ────────────────────────────────────────────────
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
// Today's date in Dubai timezone (matches how serial_date is stored on
// the backend so the filter aligns with serial_date column comparisons).
function todayIso() {
  const now = new Date();
  // Cheap approximation: build the YYYY-MM-DD from local parts.
  // Server is also in Asia/Dubai; client is a phone in UAE; the two
  // agree to within a few hours either side of midnight which is fine
  // for "show me today's jobs" usability.
  const y = now.getFullYear();
  const m = String(now.getMonth() + 1).padStart(2, '0');
  const d = String(now.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
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

    /* ─── Filter bar ─────────────────────────────────────────────── */
    .jobs-filterbar { position: relative; margin-bottom: 14px; }

    /* Primary row: status chips + (mobile) filter toggle on the right.
     * On desktop, the filter toggle is hidden via media query and the
     * collapsible secondary controls inline next to the chips. */
    .filt-primary {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 8px;
    }

    .status-chips {
      display: flex;
      gap: 6px;
      flex: 1 1 auto;
      min-width: 0;
      overflow-x: auto;
      scrollbar-width: none;
    }
    .status-chips::-webkit-scrollbar { display: none; }
    .chip {
      flex: 0 0 auto;
      padding: 8px 16px;
      border-radius: 999px;
      border: 1px solid var(--line);
      background: var(--bg-elev);
      color: var(--ink-soft);
      font-size: 13px;
      font-weight: 600;
      letter-spacing: 0.01em;
      transition: background .15s, color .15s, border-color .15s;
      cursor: pointer;
    }
    .chip.active {
      background: var(--accent);
      color: #fff;
      border-color: var(--accent);
    }

    /* Filter-toggle button (mobile only) */
    .filt-toggle {
      flex: 0 0 auto;
      padding: 8px 12px;
      border-radius: 999px;
      border: 1px solid var(--line);
      background: var(--bg-elev);
      color: var(--ink-soft);
      font-size: 13px;
      font-weight: 600;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      cursor: pointer;
      transition: background .15s, color .15s, border-color .15s;
    }
    .filt-toggle.has-active {
      background: var(--accent-pale);
      color: var(--accent);
      border-color: var(--accent);
    }
    .filt-toggle[aria-expanded="true"] {
      background: var(--accent);
      color: #fff;
      border-color: var(--accent);
    }
    .filt-toggle-count {
      min-width: 18px; height: 18px;
      padding: 0 4px;
      background: var(--accent);
      color: #fff;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 700;
      display: inline-flex;
      align-items: center; justify-content: center;
      line-height: 1;
    }
    .filt-toggle[aria-expanded="true"] .filt-toggle-count {
      background: #fff;
      color: var(--accent);
    }

    /* Collapsible secondary controls — date / search / employee / clear */
    .filt-collapsible {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      align-items: center;
      /* Mobile collapsed state */
      max-height: 0;
      overflow: visible;
      opacity: 0;
      transform: translateY(-4px);
      pointer-events: none;
      transition: max-height .25s ease, opacity .15s, transform .2s;
    }
    .filt-collapsible.open {
      max-height: 200px;
      opacity: 1;
      transform: none;
      pointer-events: auto;
      margin-bottom: 4px;
    }

    /* Date trigger button */
    .filt-date-trigger {
      padding: 8px 12px;
      border: 1px solid var(--line);
      border-radius: var(--r-md);
      background: var(--bg-elev);
      color: var(--ink);
      font-size: 13px;
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 6px;
      cursor: pointer;
      transition: border-color .15s, background .15s;
      flex: 0 0 auto;
      white-space: nowrap;
    }
    .filt-date-trigger.active {
      border-color: var(--accent);
      background: var(--accent-pale);
      color: var(--accent);
    }
    .filt-date-label {
      max-width: 160px;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    /* Compact search box — flex-grows but capped so it doesn't bully the chips */
    .filt-search {
      position: relative;
      flex: 1 1 200px;
      min-width: 140px;
      max-width: 280px;
    }
    .filt-search-icon {
      position: absolute;
      top: 50%; left: 10px;
      transform: translateY(-50%);
      color: var(--ink-mute);
      font-size: 14px;
      pointer-events: none;
    }
    .filt-search input {
      width: 100%;
      padding: 8px 28px 8px 28px;
      border: 1px solid var(--line);
      border-radius: var(--r-md);
      background: var(--bg-elev);
      color: var(--ink);
      font-size: 13px;
    }
    .filt-search input:focus {
      outline: none;
      border-color: var(--accent);
      box-shadow: 0 0 0 3px var(--accent-pale);
    }
    .filt-search-clear {
      position: absolute;
      top: 50%; right: 6px;
      transform: translateY(-50%);
      width: 20px; height: 20px;
      background: var(--bg-soft);
      border: none;
      border-radius: 50%;
      color: var(--ink-mute);
      font-size: 14px;
      line-height: 1;
      display: flex; align-items: center; justify-content: center;
      cursor: pointer;
    }
    .filt-search-clear:active { background: var(--line); }
    .filt-search-clear[hidden] { display: none; }

    .filt-emp {
      appearance: none;
      -webkit-appearance: none;
      padding: 8px 26px 8px 12px;
      border: 1px solid var(--line);
      border-radius: var(--r-md);
      background-color: var(--bg-elev);
      color: var(--ink);
      font-size: 13px;
      font-weight: 600;
      background-image:
        linear-gradient(45deg, transparent 50%, currentColor 50%),
        linear-gradient(135deg, currentColor 50%, transparent 50%);
      background-position: calc(100% - 14px) 14px, calc(100% - 9px) 14px;
      background-size: 5px 5px, 5px 5px;
      background-repeat: no-repeat;
      flex: 0 0 auto;
      cursor: pointer;
    }
    .filt-emp.active {
      border-color: var(--accent);
      background-color: var(--accent-pale);
      color: var(--accent);
    }
    /* Hide the select when the team has no known employees yet (the
     * dropdown would just have "All employees" with nothing else). */
    .filt-emp[data-empty] { display: none; }

    .filt-clear {
      padding: 8px 12px;
      border: 1px solid var(--line);
      border-radius: var(--r-md);
      background: var(--bg-soft);
      color: var(--ink-mute);
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
      flex: 0 0 auto;
    }
    .filt-clear:hover { color: var(--accent); border-color: var(--accent); }
    .filt-clear[hidden] { display: none; }

    /* Date popover (anchored absolutely under the trigger) */
    .filt-date-pop {
      position: absolute;
      z-index: 30;
      top: calc(100% + 6px);
      left: 0;
      width: min(360px, calc(100vw - 28px));
      padding: 12px;
      background: var(--bg-elev);
      border: 1px solid var(--line);
      border-radius: var(--r-md);
      box-shadow: 0 12px 32px rgba(0,0,0,.12);
      animation: filt-pop-in .12s ease-out;
    }
    @keyframes filt-pop-in {
      from { opacity: 0; transform: translateY(-4px); }
      to   { opacity: 1; transform: translateY(0); }
    }
    .filt-date-presets {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 6px;
      margin-bottom: 10px;
    }
    .filt-date-presets button {
      padding: 8px 6px;
      background: var(--bg-soft);
      border: 1px solid var(--line);
      border-radius: var(--r-sm);
      color: var(--ink-soft);
      font-size: 12px;
      font-weight: 600;
      cursor: pointer;
    }
    .filt-date-presets button:hover,
    .filt-date-presets button:active {
      background: var(--accent-pale);
      color: var(--accent);
      border-color: var(--accent);
    }
    .filt-date-custom {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 8px;
      padding: 8px 0;
      border-top: 1px dashed var(--line);
      margin-bottom: 10px;
    }
    .filt-date-custom label {
      display: flex;
      flex-direction: column;
      gap: 3px;
    }
    .filt-date-custom span {
      font-size: 10px;
      text-transform: uppercase;
      letter-spacing: 0.1em;
      font-weight: 700;
      color: var(--ink-mute);
    }
    .filt-date-custom input {
      padding: 8px 10px;
      border: 1px solid var(--line);
      border-radius: var(--r-sm);
      background: var(--bg-elev);
      color: var(--ink);
      font-size: 13px;
    }
    .filt-date-pop-actions {
      display: flex;
      gap: 8px;
      justify-content: space-between;
    }
    .filt-date-pop-actions button {
      flex: 1;
      padding: 8px;
      border-radius: var(--r-sm);
      font-size: 13px;
      font-weight: 700;
      cursor: pointer;
    }
    #filt-date-clear {
      background: var(--bg-soft);
      border: 1px solid var(--line);
      color: var(--ink-soft);
    }
    #filt-date-close {
      background: var(--accent);
      border: none;
      color: #fff;
    }

    /* ─── Cards ──────────────────────────────────────────────────── */
    .jobs-card {
      padding: 18px;
      position: relative;
      overflow: hidden;
      transition: box-shadow .15s, border-color .15s;
      /* Flex column so action row can be pushed to the bottom when the
       * grid stretches all cards in a row to the same height. */
      display: flex;
      flex-direction: column;
    }
    .jobs-card:hover {
      border-color: var(--line-strong, var(--line));
    }
    /* Action row pinned to the bottom — margin-top:auto pushes it down
     * when the card has extra vertical space (because a sibling card in
     * the same row is taller). Keeps action buttons aligned across the row. */
    .jobs-card .jobs-actions {
      margin-top: auto;
    }
    /* Left status stripe — colour matches the status */
    .jobs-card::before {
      content: '';
      position: absolute;
      top: 0; left: 0; bottom: 0;
      width: 3px;
      background: var(--line);
    }
    .jobs-status-accepted::before    { background: var(--accent); }
    .jobs-status-in_progress::before { background: var(--gold); }
    .jobs-status-completed::before   { background: var(--good); }
    .jobs-status-cancelled::before   { background: var(--bad); }
    .jobs-status-disputed::before    { background: var(--bad); }

    /* Header zone — serial + identity + status/price */
    .jobs-header {
      display: grid;
      grid-template-columns: auto 1fr auto;
      column-gap: 12px;
      align-items: start;
      margin-bottom: 14px;
    }
    .jobs-id { min-width: 0; }
    .jobs-plate {
      font-family: var(--font-display);
      font-weight: 800;
      font-size: 22px;
      letter-spacing: 0.02em;
      line-height: 1.1;
      color: var(--ink);
      word-break: break-word;
    }
    .jobs-plate-svc {
      /* Service used as the big slot when no plate (legacy) */
      font-size: 18px;
      letter-spacing: -0.01em;
      font-weight: 700;
    }
    .jobs-plate-num {
      /* Order-number identity — slightly lighter than a plate so it doesn't
       * scream like an alphanumeric registration. */
      font-family: var(--font-display);
      font-size: 22px;
      font-weight: 800;
      letter-spacing: -0.01em;
      color: var(--ink);
    }
    .jobs-svc {
      font-size: 12px;
      color: var(--ink-mute);
      margin-top: 3px;
      font-weight: 600;
    }
    .jobs-header-right {
      text-align: right;
      display: flex;
      flex-direction: column;
      align-items: flex-end;
      gap: 6px;
      min-width: 0;
    }
    .jobs-price {
      font-family: var(--font-display);
      font-weight: 700;
      font-size: 18px;
      color: var(--ink);
      letter-spacing: -0.01em;
      line-height: 1;
    }

    /* Status pill — softer, lowercase, dot-led. Replaces the shouty .tag. */
    .jobs-status-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 3px 10px 3px 8px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 600;
      letter-spacing: 0;
      text-transform: none;
      background: var(--bg-soft);
      color: var(--ink-soft);
      line-height: 1.4;
      white-space: nowrap;
    }
    .jobs-status-dot {
      width: 6px; height: 6px;
      border-radius: 50%;
      background: currentColor;
      flex: 0 0 6px;
    }
    .jobs-status-pill-accepted    { background: var(--accent-pale); color: var(--accent); }
    .jobs-status-pill-in_progress { background: var(--gold-soft);   color: var(--gold); }
    .jobs-status-pill-completed   { background: var(--good-pale);   color: var(--good); }
    .jobs-status-pill-cancelled   { background: var(--bad-pale);    color: var(--bad); }
    .jobs-status-pill-disputed    { background: var(--bad-pale);    color: var(--bad); }
    .jobs-status-pill-pending     { background: var(--warn-pale, var(--gold-soft)); color: var(--warn, var(--gold)); }

    /* Add a subtle pulse to in-progress so the active job stands out */
    @keyframes jobs-pulse {
      0%, 100% { opacity: 1; }
      50%      { opacity: .55; }
    }
    .jobs-status-pill-in_progress .jobs-status-dot {
      animation: jobs-pulse 1.6s ease-in-out infinite;
    }
    @media (prefers-reduced-motion: reduce) {
      .jobs-status-pill-in_progress .jobs-status-dot { animation: none; }
    }

    /* Meta zone — employee */
    .jobs-meta {
      display: grid;
      grid-template-columns: 1fr auto;
      gap: 12px;
      align-items: center;
      padding: 10px 12px;
      background: var(--bg-soft);
      border-radius: var(--r-sm);
      margin-bottom: 10px;
    }
    .jobs-meta-text { min-width: 0; }
    .jobs-meta-row {
      display: flex;
      gap: 8px;
      align-items: baseline;
      font-size: 13px;
      color: var(--ink-soft);
      padding: 1px 0;
    }
    .jobs-meta-row + .jobs-meta-row { margin-top: 3px; }
    .jobs-meta-icon {
      width: 14px;
      text-align: center;
      color: var(--ink-mute);
      flex: 0 0 14px;
      font-size: 11px;
    }
    .jobs-meta-val {
      color: var(--ink);
      font-weight: 500;
      word-break: break-word;
    }

    /* Record-payment button — slim, sits inside .jobs-actions-unpaid
     * next to the Slip button (no inline amount). */
    .btn-record-pay {
      flex: 1;
      padding: 10px 14px;
      background: var(--accent);
      color: #fff;
      border: none;
      border-radius: var(--r-md);
      font-size: 13px;
      font-weight: 700;
      letter-spacing: 0.01em;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      transition: background .15s, transform .1s;
      min-width: 0;
    }
    .btn-record-pay:hover  { background: var(--accent-strong, var(--accent)); }
    .btn-record-pay:active { transform: scale(.99); }
    .btn-record-pay-icon {
      width: 18px; height: 18px;
      border-radius: 50%;
      background: rgba(255,255,255,.25);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 13px;
      font-weight: 700;
      line-height: 1;
      flex: 0 0 18px;
    }
    .btn-record-pay-label { font-size: 13px; }

    /* Inline record-payment form — labeled fields for clarity */
    .jobs-payform {
      padding: 14px;
      margin-bottom: 12px;
      background: var(--bg-soft);
      border: 1px solid var(--line);
      border-radius: var(--r-md);
    }
    .jobs-payform-field {
      margin-bottom: 10px;
    }
    .jobs-payform-field:last-of-type {
      margin-bottom: 0;
    }
    .jobs-payform-label {
      font-size: 10px;
      text-transform: uppercase;
      letter-spacing: 0.1em;
      font-weight: 700;
      color: var(--ink-mute);
      margin-bottom: 5px;
    }
    .jobs-payform-hint {
      text-transform: none;
      letter-spacing: 0;
      font-weight: 500;
      color: var(--ink-mute);
      opacity: .7;
    }
    .jobs-payform-row {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 6px;
    }
    /* When Split is added the modal needs 4 buttons across — packs to 2x2
     * on narrow screens, single-row at 480 px+ */
    .jobs-payform-row-4 {
      grid-template-columns: repeat(2, 1fr);
    }
    @media (min-width: 480px) {
      .jobs-payform-row-4 { grid-template-columns: repeat(4, 1fr); }
    }
    .pm-mini {
      padding: 11px 4px;
      background: var(--bg-elev);
      border: 1px solid var(--line);
      border-radius: var(--r-sm);
      font-size: 12px;
      font-weight: 600;
      color: var(--ink-soft);
      cursor: pointer;
      transition: background .12s, color .12s, border-color .12s;
    }
    .pm-mini:hover { border-color: var(--accent); color: var(--accent); }
    .pm-mini.active {
      background: var(--accent);
      color: #fff;
      border-color: var(--accent);
    }

    /* ─── Split-payment block (record-payment modal) ─── */
    .jobs-split-box {
      margin-top: 12px;
      padding: 14px;
      background: var(--bg-soft);
      border: 1.5px solid var(--accent);
      border-radius: var(--r-md);
    }
    .jobs-split-hint {
      font-size: 12px;
      color: var(--ink-mute);
      line-height: 1.5;
      margin-bottom: 12px;
    }
    .jobs-split-hint b { color: var(--ink); font-weight: 700; }

    .jobs-split-row {
      display: grid;
      grid-template-columns: 90px 1fr;
      gap: 10px;
      align-items: center;
      margin-bottom: 8px;
    }
    .jobs-split-row-lbl {
      font-size: 13px;
      font-weight: 600;
      color: var(--ink);
    }
    .jobs-split-row-input {
      position: relative;
      display: flex;
      align-items: center;
    }
    .jobs-split-row-prefix {
      position: absolute;
      left: 10px;
      font-size: 11px;
      font-weight: 700;
      color: var(--ink-mute);
      pointer-events: none;
      letter-spacing: 0.04em;
    }
    .jobs-split-row-input input {
      width: 100%;
      padding: 10px 12px 10px 40px;
      border: 1px solid var(--line);
      border-radius: var(--r-sm);
      background: var(--bg-elev);
      font-size: 15px;
      font-weight: 600;
      font-variant-numeric: tabular-nums;
      color: var(--ink);
      text-align: right;
      transition: border-color .15s, box-shadow .15s;
    }
    .jobs-split-row-input input:focus {
      outline: none;
      border-color: var(--accent);
      box-shadow: 0 0 0 3px var(--accent-pale, rgba(14,165,233,0.15));
    }

    .jobs-split-sum {
      margin-top: 10px;
      padding: 12px 14px;
      display: grid;
      grid-template-columns: 70px 1fr auto;
      align-items: center;
      gap: 10px;
      border-radius: var(--r-md);
      font-weight: 700;
      border: 1.5px solid transparent;
      transition: background .2s, color .2s;
    }
    .jobs-split-sum-lbl {
      font-size: 11px;
      letter-spacing: 0.1em;
      text-transform: uppercase;
    }
    .jobs-split-sum-val {
      font-size: 15px;
      text-align: right;
      font-variant-numeric: tabular-nums;
    }
    .jobs-split-sum-target { color: var(--ink-mute); font-weight: 500; font-size: 12px; }
    .jobs-split-sum-icon {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 22px;
      height: 22px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 800;
      color: #fff;
    }
    .jobs-split-sum-under {
      background: var(--gold-soft, #fff7ed);
      color: var(--gold, #b45309);
      border-color: var(--gold, #f59e0b);
    }
    .jobs-split-sum-under .jobs-split-sum-icon { background: var(--gold, #f59e0b); }
    .jobs-split-sum-over {
      background: var(--bad-pale);
      color: var(--bad);
      border-color: var(--bad);
    }
    .jobs-split-sum-over .jobs-split-sum-icon { background: var(--bad); }
    .jobs-split-sum-ok {
      background: var(--good-pale);
      color: var(--good);
      border-color: var(--good);
    }
    .jobs-split-sum-ok .jobs-split-sum-icon { background: var(--good); }

    .jobs-split-foot-hint {
      margin-top: 10px;
      font-size: 11px;
      color: var(--ink-mute);
      line-height: 1.45;
      padding: 8px 10px;
      background: var(--bg-elev);
      border-radius: var(--r-sm);
      border-left: 2px solid var(--line-strong);
    }

    .jobs-payform-amount,
    .jobs-payform-ref {
      width: 100%;
      padding: 10px 12px;
      border: 1px solid var(--line);
      border-radius: var(--r-sm);
      font-size: 14px;
      background: var(--bg-elev);
      color: var(--ink);
      transition: border-color .15s, box-shadow .15s;
    }
    .jobs-payform-amount:focus,
    .jobs-payform-ref:focus {
      outline: none;
      border-color: var(--accent);
      box-shadow: 0 0 0 3px var(--accent-pale);
    }
    .jobs-payform-amount {
      font-family: var(--font-display);
      font-weight: 700;
      font-size: 16px;
    }
    .jobs-payform-actions {
      display: flex;
      gap: 8px;
      margin-top: 14px;
    }
    .btn-record-cancel, .btn-record-save {
      padding: 11px 14px;
      border-radius: var(--r-md);
      font-weight: 700;
      font-size: 13px;
      border: none;
      cursor: pointer;
      transition: background .12s, transform .1s;
    }
    .btn-record-cancel {
      flex: 0 0 auto;
      background: transparent;
      color: var(--ink-soft);
      border: 1px solid var(--line);
    }
    .btn-record-cancel:hover { background: var(--bg-elev); }
    .btn-record-save {
      flex: 1;
      background: var(--accent);
      color: #fff;
    }
    .btn-record-save:hover  { background: var(--accent-strong, var(--accent)); }
    .btn-record-save:active { transform: scale(.99); }
    .btn-record-save:disabled { opacity: .55; }

    /* Stripe pay-link panel — shown after a balance-Online record.
     * Same visual language as the wizard's success-screen pay-panel. */
    .jobs-paylink {
      margin-bottom: 10px;
      padding: 12px;
      background: var(--accent-pale);
      border: 1px solid var(--accent);
      border-radius: var(--r-md);
    }
    .jobs-paylink-head {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 8px;
      margin-bottom: 8px;
    }
    .jobs-paylink-label {
      font-weight: 700;
      color: var(--accent);
      font-size: 13px;
    }
    .jobs-paylink-dismiss {
      width: 24px; height: 24px;
      border: none;
      background: transparent;
      color: var(--accent);
      font-size: 18px;
      line-height: 1;
      cursor: pointer;
      border-radius: 50%;
      flex: 0 0 24px;
    }
    .jobs-paylink-dismiss:active { background: rgba(0,0,0,.06); }
    .jobs-paylink-url {
      padding: 8px 10px;
      background: var(--bg-elev);
      border: 1px solid var(--accent-pale);
      border-radius: var(--r-sm);
      font-family: ui-monospace, monospace;
      font-size: 11px;
      color: var(--ink-soft);
      word-break: break-all;
      margin-bottom: 10px;
      max-height: 60px;
      overflow: auto;
    }
    .jobs-paylink-actions {
      display: flex;
      gap: 6px;
      margin-bottom: 8px;
    }
    .jobs-paylink-btn {
      flex: 1;
      padding: 8px 10px;
      background: var(--bg-elev);
      border: 1px solid var(--accent);
      color: var(--accent);
      border-radius: var(--r-sm);
      font-weight: 700;
      font-size: 12px;
      cursor: pointer;
    }
    .jobs-paylink-btn-primary {
      background: var(--accent);
      color: #fff;
    }
    .jobs-paylink-btn:active { transform: scale(.98); }
    .jobs-paylink-hint {
      font-size: 11px;
      color: var(--accent);
      font-style: italic;
    }

    /* Block-Complete-while-unpaid hint */
    .jobs-blocked-hint {
      margin: 0 0 12px;
      padding: 8px 12px;
      font-size: 12px;
      color: var(--bad);
      background: var(--bad-pale);
      border-radius: var(--r-sm);
      font-weight: 500;
      border-left: 3px solid var(--bad);
    }

    /* Action row */
    .jobs-actions {
      display: flex;
      gap: 8px;
      align-items: center;
    }
    /* Unpaid: Record payment grows, Slip is compact secondary */
    .jobs-actions-unpaid .btn-slip { flex: 0 0 auto; padding: 10px 14px; }
    /* Paid: stamp on the left, Slip on the right */
    .jobs-actions-paid { justify-content: space-between; }
    .jobs-actions-paid .jobs-done-stamp { flex: 1; }
    .jobs-actions-paid .btn-slip { flex: 0 0 auto; padding: 10px 14px; }

    .btn-slip {
      border: 1px solid var(--line);
      background: var(--bg-elev);
      color: var(--ink-soft);
      border-radius: var(--r-md);
      font-weight: 600;
      font-size: 13px;
      cursor: pointer;
      transition: transform .1s, background .15s, opacity .15s;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .btn-slip:hover  { background: var(--bg-soft); color: var(--ink); }
    .btn-slip:active { transform: scale(.98); }
    .btn-slip:disabled { opacity: .45; cursor: not-allowed; transform: none; }

    .jobs-done-stamp {
      padding: 10px 14px;
      background: var(--good-pale);
      border-radius: var(--r-md);
      color: var(--good);
      font-weight: 700;
      font-size: 13px;
      text-align: center;
      letter-spacing: 0.01em;
    }
    .jobs-done-stamp-bad {
      background: var(--bad-pale);
      color: var(--bad);
    }

    /* On very narrow screens (< 380 px), collapse the meta grid to single column */
    @media (max-width: 380px) {
      .jobs-meta { grid-template-columns: 1fr; }
    }

    /* ─── Filter bar — desktop layout (≥ 700 px) ─────────────────
     * Chips on the left; the rest (date / search / employee / clear)
     * flow to their right on the same row. We use a plain flex layout
     * with the two existing wrappers as direct siblings — NOT
     * display:contents, which had the side-effect of leaking the
     * mobile-collapsed pointer-events:none down into the children. */
    @media (min-width: 700px) {
      .filt-toggle { display: none; }
      .jobs-filterbar {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
      }
      .filt-primary {
        flex: 0 0 auto;
        margin-bottom: 0;
      }
      .filt-collapsible {
        flex: 1 1 auto;
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 8px;
        /* Reset every mobile-collapsed property so children are
         * fully interactive on desktop regardless of _filtersOpen. */
        max-height: none;
        overflow: visible;
        opacity: 1;
        transform: none;
        pointer-events: auto;
        margin: 0;
      }
      .status-chips { flex: 0 0 auto; overflow: visible; }
      .filt-search { flex: 1 1 220px; min-width: 180px; max-width: 320px; }
    }

    /* ─── Desktop grid (≥ 1000 px) ──────────────────────────────────
     * Two columns at typical desktop widths, three columns on wider
     * monitors. Cards stretch to match the tallest in their row so
     * action rows line up across the grid. */
    @media (min-width: 1000px) {
      #jobs-list {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 16px;
        align-items: stretch;
      }
      /* Empty-state + loading spinner span both columns so they read
       * as page-level messages, not as a half-width card. */
      #jobs-list > .empty-state,
      #jobs-list > .loading {
        grid-column: 1 / -1;
      }
    }
    @media (min-width: 1500px) {
      #jobs-list { grid-template-columns: repeat(3, minmax(0, 1fr)); }
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
    /* Balance-due row is emphasized so the customer sees what they still owe */
    .slip-row-due span:first-child { color: #b45309; }
    .slip-row-due span:last-child  { color: #b45309; font-size: 15px; }
    .slip-row-due b { font-size: 15px; }

    /* Stamp shown below the payment block — at-a-glance status indicator
     * for the slip. Bold and centered so it reads even on tiny thermal paper. */
    .slip-paystamp {
      margin-top: 8px;
      padding: 6px 10px;
      text-align: center;
      font-weight: 800;
      font-size: 12px;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      border: 1.5px solid;
      border-radius: 4px;
    }
    .slip-paystamp-ok   { color: #166534; border-color: #166534; background: #f0fdf4; }
    .slip-paystamp-warn { color: #b45309; border-color: #b45309; background: #fffbeb; }
    .slip-paystamp-bad  { color: #b91c1c; border-color: #b91c1c; background: #fef2f2; }

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

    /* ─── Record-payment modal ──────────────────────────────────────
     * Centered card on desktop, bottom sheet on mobile (≤ 640 px).
     * Lives in document.body so it never disturbs the grid layout. */
    .tl-modal {
      position: fixed;
      inset: 0;
      z-index: 200;
      background: rgba(15, 23, 42, .5);
      display: none;
      align-items: center;
      justify-content: center;
      padding: 16px;
      animation: tl-modal-fade .15s ease-out;
    }
    .tl-modal.open { display: flex; }
    @keyframes tl-modal-fade {
      from { opacity: 0; }
      to   { opacity: 1; }
    }
    /* Prevent background scroll when modal is open */
    body.tl-modal-open { overflow: hidden; }

    .tl-modal-card {
      background: var(--bg-elev);
      border-radius: 16px;
      box-shadow: 0 24px 64px rgba(0,0,0,.18), 0 8px 16px rgba(0,0,0,.06);
      width: 100%;
      max-width: 460px;
      max-height: calc(100vh - 32px);
      display: flex;
      flex-direction: column;
      overflow: hidden;
      animation: tl-modal-pop .2s cubic-bezier(.16, 1, .3, 1);
    }
    @keyframes tl-modal-pop {
      from { opacity: 0; transform: scale(.95) translateY(8px); }
      to   { opacity: 1; transform: scale(1) translateY(0); }
    }

    .tl-modal-head {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 12px;
      padding: 18px 20px 14px;
      border-bottom: 1px solid var(--line);
    }
    .tl-modal-head-text { min-width: 0; }
    .tl-modal-title {
      font-family: var(--font-display);
      font-size: 18px;
      font-weight: 700;
      letter-spacing: -0.01em;
      color: var(--ink);
      margin: 0 0 2px;
      line-height: 1.2;
    }
    .tl-modal-sub {
      font-size: 12px;
      color: var(--ink-mute);
      margin: 0;
      font-weight: 600;
    }
    .tl-modal-close {
      width: 32px; height: 32px;
      border: none;
      background: var(--bg-soft);
      border-radius: 50%;
      color: var(--ink-soft);
      font-size: 20px;
      line-height: 1;
      cursor: pointer;
      flex: 0 0 32px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      transition: background .12s;
    }
    .tl-modal-close:hover { background: var(--line); color: var(--ink); }

    .tl-modal-body {
      padding: 16px 20px;
      overflow-y: auto;
      flex: 1;
    }

    .tl-modal-actions {
      padding: 14px 20px 18px;
      border-top: 1px solid var(--line);
      display: flex;
      gap: 8px;
      background: var(--bg-elev);
    }

    /* Balance summary at top of body */
    .jobs-payform-summary {
      background: var(--bg-soft);
      border-radius: var(--r-md);
      padding: 12px 14px;
      margin-bottom: 18px;
    }
    .jobs-payform-summary-row {
      display: flex;
      justify-content: space-between;
      align-items: baseline;
      font-size: 13px;
      color: var(--ink-soft);
      padding: 2px 0;
    }
    .jobs-payform-summary-row-emph {
      margin-top: 6px;
      padding-top: 8px;
      border-top: 1px dashed var(--line);
      color: var(--ink);
      font-weight: 700;
      font-size: 15px;
    }
    .jobs-payform-summary-row-emph span:last-child {
      font-family: var(--font-display);
      color: var(--accent);
    }

    /* Quick-amount chips below the amount input */
    .jobs-payform-quickamts {
      display: flex;
      gap: 6px;
      margin-top: 6px;
    }
    .quickamt {
      padding: 5px 10px;
      background: var(--bg-soft);
      border: 1px solid var(--line);
      border-radius: 999px;
      font-size: 11px;
      font-weight: 600;
      color: var(--ink-soft);
      cursor: pointer;
      transition: background .12s, color .12s, border-color .12s;
    }
    .quickamt:hover {
      background: var(--accent-pale);
      color: var(--accent);
      border-color: var(--accent);
    }

    /* Online sub-method hint shown in the modal */
    .jobs-payform-online-hint {
      margin-top: 14px;
      padding: 10px 12px;
      background: var(--accent-pale);
      border-left: 3px solid var(--accent);
      border-radius: var(--r-sm);
      font-size: 12px;
      color: var(--accent);
      line-height: 1.4;
    }

    /* Mobile bottom-sheet (≤ 640 px) — slide up from bottom */
    @media (max-width: 640px) {
      .tl-modal {
        align-items: flex-end;
        padding: 0;
      }
      .tl-modal-card {
        max-width: 100%;
        max-height: 88vh;
        border-radius: 18px 18px 0 0;
        animation: tl-modal-slide-up .25s cubic-bezier(.16, 1, .3, 1);
      }
      @keyframes tl-modal-slide-up {
        from { transform: translateY(100%); }
        to   { transform: translateY(0); }
      }
      /* Drag handle at the top, native bottom-sheet feel */
      .tl-modal-head {
        padding-top: 22px;
        position: relative;
      }
      .tl-modal-head::before {
        content: '';
        position: absolute;
        top: 8px; left: 50%;
        transform: translateX(-50%);
        width: 40px; height: 4px;
        background: var(--line);
        border-radius: 2px;
      }
    }
  `;
  const s = document.createElement('style');
  s.id = 'jobs-tab-css';
  s.textContent = css;
  document.head.appendChild(s);
}