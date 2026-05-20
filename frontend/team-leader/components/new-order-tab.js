// frontend/team-leader/components/new-order-tab.js
//
// Direct-booking wizard. TL fills 2 steps and submits — POST /api/tl/orders.
//
//   Step 1: Employee       code/name search → lock to a specific employee
//   Step 2: Details        plate (optional), location, photo, notes,
//                          payment method (cash/card/online/unpaid),
//                          review & submit. No customer fields collected.
//
// Patterns we're using:
//   • Single component owns all step states (no router)
//   • Forward/back via "Next" / "Back" buttons + step progress dots
//   • Validation enforced step-by-step — Next is disabled until valid
//   • Plate is uppercased on input (kept optional)
//   • Photo is captured via <input type="file" capture="environment">
//     and base64-encoded inline (no separate upload endpoint needed)
//   • Payment uuid_ref is generated client-side so a retried submission
//     never creates two payment rows
//
// Offline behavior:
//   • If online, POST /tl/orders directly. On success, show success screen
//     with order serial and "Print slip" + "+ New order".
//   • If offline mid-submit, queue the whole booking via the sync engine
//     (action_type='direct_book') and show a "Saved — will create when
//     reconnected" success state.

import * as api from '../api.js';
import * as state from '../state.js';
import { queueAction } from '../pwa/sync-engine.js';

const CURRENCY = 'AED';
const MAX_PHOTO_BYTES = 1_500_000;   // ~1.5MB after compression

let _root = null;

// Wizard state
let _step = 1;
let _data = freshData();
let _employee = null;       // resolved employee object after lookup
let _employeeBusy = false;
let _empMode = 'code';      // 'code' or 'name' — which lookup mode is showing
let _empResults = [];       // suggestions returned by name-search
let _empSearchBusy = false; // a name-search is in flight
let _submitting = false;
let _success = null;        // { order_id, daily_serial, ... } on success

// ─── Lifecycle ─────────────────────────────────────────────────────
export async function init(root) {
  _root = root;
  injectStyles();
  paint();
}

export function onShow() {
  // Reset only if we're not in the middle of something
  if (_success) {
    // user came back after a successful submit — reset to fresh
    resetWizard();
  }
  paint();
}

// ─── Data model ────────────────────────────────────────────────────
function freshData() {
  return {
    // Step 1 — Employee
    employee_code:        '',
    employee_id:          '',
    employee_name_query:  '',   // free-text used in name-search mode
    // Step 2 — Details (all optional except payment_method)
    vehicle_plate:    '',          // only collected for non-porter services
    image_path:       '',          // only collected for non-porter services
    notes:            '',
    payment_method:   'cash',
    // Split-payment state — used when payment_method = 'split'
    split_cash:       '',
    split_card:       '',
    payment_ref:      '',
    payment_uuid:     crypto.randomUUID(),
  };
}

function resetWizard() {
  _step = 1;
  _data = freshData();
  _employee = null;
  _success = null;
  _submitting = false;
  _empMode = 'code';
  _empResults = [];
  _empSearchBusy = false;
}

// ─── Top-level paint ───────────────────────────────────────────────
function paint() {
  if (_success) { paintSuccess(); return; }

  const user = state.get('user');
  const serviceName = user?.service_name || '—';

  _root.innerHTML = `
    <h1 class="tab-h">
      <small>On the spot · ${escape(serviceName)}</small>
      New order
    </h1>

    <div class="wiz-progress" id="wiz-progress">
      ${[1, 2].map((n) => `
        <div class="wiz-dot ${_step >= n ? 'done' : ''} ${_step === n ? 'active' : ''}">${n}</div>
        ${n < 2 ? '<div class="wiz-line ' + (_step > n ? 'done' : '') + '"></div>' : ''}
      `).join('')}
    </div>

    <div id="wiz-body"></div>
    <div id="wiz-footer" class="wiz-footer"></div>
  `;

  paintStep();
}

// ─── Step routing ──────────────────────────────────────────────────
function paintStep() {
  const body = _root.querySelector('#wiz-body');
  if (_step === 1) paintStepEmployee(body);
  if (_step === 2) paintStepDetails(body);
  paintFooter();
}

function paintFooter() {
  const f = _root.querySelector('#wiz-footer');
  const back = _step > 1
    ? `<button id="wiz-back" class="wiz-back">← Back</button>`
    : `<span></span>`;

  let next;
  if (_step < 2) {
    const can = validateStep(_step);
    next = `<button id="wiz-next" class="wiz-next" ${can ? '' : 'disabled'}>Next →</button>`;
  } else {
    const can = !_submitting && validateStep(2);
    next = `<button id="wiz-submit" class="wiz-submit" ${can ? '' : 'disabled'}>
              ${_submitting ? 'Submitting…' : 'Submit order'}
            </button>`;
  }
  f.innerHTML = back + next;

  _root.querySelector('#wiz-back')?.addEventListener('click', () => {
    if (_step > 1) { _step -= 1; paint(); }
  });
  _root.querySelector('#wiz-next')?.addEventListener('click', () => {
    if (validateStep(_step)) { _step += 1; paint(); }
  });
  _root.querySelector('#wiz-submit')?.addEventListener('click', submit);
}

// Helper — escape attr-safe path or empty
function empImgSrc(emp) {
  const p = emp?.image_path || '';
  if (!p) return '';
  if (/^https?:\/\//.test(p)) return p;
  return location.origin + (p.startsWith('/') ? p : '/' + p);
}
function empSilhouette() {
  return `<svg viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <circle cx="20" cy="15" r="6" fill="currentColor" opacity=".5"/>
    <path d="M8 36c1-7 6-11 12-11s11 4 12 11z" fill="currentColor" opacity=".5"/>
  </svg>`;
}

// ─── Step 1: Employee ──────────────────────────────────────────────
function paintStepEmployee(host) {
  // Already picked an employee — show confirmation + change button only
  if (_employee) {
    const photoUrl = empImgSrc(_employee);
    host.innerHTML = `
      <div class="wiz-step">
        <h2 class="wiz-h">Assign employee</h2>
        <div class="emp-found">
          <div class="emp-found-photo">
            ${photoUrl
              ? `<img src="${escapeAttr(photoUrl)}" alt="" onerror="this.replaceWith(Object.assign(document.createElement('span'),{className:'emp-photo-fallback',innerHTML:this.dataset.fallback}))" data-fallback="${escapeAttr(empSilhouette())}">`
              : `<span class="emp-photo-fallback">${empSilhouette()}</span>`}
          </div>
          <div class="emp-found-info">
            <div class="emp-found-name">${escape(_employee.name)}</div>
            <div class="emp-found-code tabular">${escape(_employee.employee_code)}</div>
            ${_employee.phone ? `<div class="emp-found-phone">${escape(_employee.phone)}</div>` : ''}
          </div>
          <button id="emp-clear" class="emp-clear">Change</button>
        </div>
      </div>`;

    host.querySelector('#emp-clear').addEventListener('click', () => {
      _employee     = null;
      _data.employee_id = '';
      _data.employee_code = '';
      _empResults   = [];
      paint();
    });
    return;
  }

  // Otherwise show the search UI with two modes: code / name
  const codePane = _empMode === 'code' ? `
    <p class="wiz-sub">Type the code on their ID badge.</p>
    <div class="emp-search-row">
      <input id="f-emp-code" class="emp-input tabular" type="text"
             autocapitalize="characters" autocomplete="off"
             placeholder="CW001"
             value="${escapeAttr(_data.employee_code)}">
      <button id="emp-go" class="emp-go" ${_employeeBusy ? 'disabled' : ''}>
        ${_employeeBusy ? '…' : 'Search'}
      </button>
    </div>
    ${_data.employee_code ? `
      <div class="hint hint-err" style="margin-top:8px">
        Press <strong>Search</strong> (or Enter) to look up this code.
      </div>` : `
      <div class="hint" style="margin-top:8px;color:var(--ink-mute)">
        Enter the code from the employee's ID badge.
      </div>`}` : '';

  const namePane = _empMode === 'name' ? `
    <p class="wiz-sub">Start typing the name — pick from the list.</p>
    <div class="emp-search-row">
      <input id="f-emp-name" class="emp-input" type="text"
             autocomplete="off" placeholder="e.g. Joice, Ahmed…"
             value="${escapeAttr(_data.employee_name_query || '')}">
      ${_empSearchBusy ? '<span class="emp-go" style="opacity:.6">…</span>' : ''}
    </div>
    <div id="emp-list" class="emp-list">
      ${renderNameResults()}
    </div>` : '';

  host.innerHTML = `
    <div class="wiz-step">
      <h2 class="wiz-h">Assign employee</h2>

      <div class="emp-mode-tabs">
        <button class="emp-mode-tab ${_empMode === 'code' ? 'active' : ''}" data-mode="code">By code</button>
        <button class="emp-mode-tab ${_empMode === 'name' ? 'active' : ''}" data-mode="name">By name</button>
      </div>

      ${codePane}
      ${namePane}
    </div>`;

  // Mode toggle
  host.querySelectorAll('[data-mode]').forEach((b) => {
    b.addEventListener('click', () => {
      if (_empMode === b.dataset.mode) return;
      _empMode = b.dataset.mode;
      paint();
    });
  });

  // Code mode wiring
  const codeInput = host.querySelector('#f-emp-code');
  if (codeInput) {
    codeInput.addEventListener('input', () => {
      codeInput.value = codeInput.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 20);
      _data.employee_code = codeInput.value;
      paintFooter();
    });
    codeInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') { e.preventDefault(); lookupEmployeeByCode(); }
    });
    host.querySelector('#emp-go').addEventListener('click', lookupEmployeeByCode);
  }

  // Name mode wiring (debounced search-as-you-type)
  const nameInput = host.querySelector('#f-emp-name');
  if (nameInput) {
    nameInput.focus();
    let searchTimer;
    nameInput.addEventListener('input', () => {
      _data.employee_name_query = nameInput.value;
      clearTimeout(searchTimer);
      const q = nameInput.value.trim();
      if (q.length < 2) {
        _empResults = [];
        refreshResultsList(host);
        return;
      }
      searchTimer = setTimeout(() => searchEmployeesByName(host, q), 250);
    });

    // Tapping a result picks that employee
    host.querySelector('#emp-list').addEventListener('click', (e) => {
      const row = e.target.closest('[data-pick]');
      if (!row) return;
      const id = row.dataset.pick;
      const emp = _empResults.find((x) => x.id === id);
      if (emp) pickEmployee(emp);
    });
  }
}

function renderNameResults() {
  if (_empSearchBusy && !_empResults.length) {
    return '<div class="emp-empty">Searching…</div>';
  }
  if (!_empResults.length) {
    const q = (_data.employee_name_query || '').trim();
    if (q.length >= 2) return '<div class="emp-empty">No matching employees on your team.</div>';
    return '';
  }
  return _empResults.map((e) => {
    const photoUrl = empImgSrc(e);
    return `
    <button class="emp-pick" data-pick="${escapeAttr(e.id)}">
      <div class="emp-pick-photo">
        ${photoUrl
          ? `<img src="${escapeAttr(photoUrl)}" alt="" onerror="this.replaceWith(Object.assign(document.createElement('span'),{className:'emp-photo-fallback',innerHTML:this.dataset.fallback}))" data-fallback="${escapeAttr(empSilhouette())}">`
          : `<span class="emp-photo-fallback">${empSilhouette()}</span>`}
      </div>
      <div class="emp-pick-info">
        <div class="emp-pick-name">${escape(e.name)}</div>
        <div class="emp-pick-meta">
          <span class="tabular">${escape(e.employee_code)}</span>
          ${e.phone ? '<span style="opacity:.6"> · </span><span>' + escape(e.phone) + '</span>' : ''}
        </div>
      </div>
    </button>`;
  }).join('');
}

function refreshResultsList(host) {
  const list = host.querySelector('#emp-list');
  if (list) list.innerHTML = renderNameResults();
}

async function searchEmployeesByName(host, q) {
  _empSearchBusy = true;
  refreshResultsList(host);
  try {
    const res = await api.get('/employees/search?q=' + encodeURIComponent(q));
    _empResults = Array.isArray(res?.data) ? res.data : [];
  } catch (e) {
    if (e.code === 'network' || e.status === 0) {
      toast('Cannot search while offline', 'error');
    }
    _empResults = [];
  } finally {
    _empSearchBusy = false;
    refreshResultsList(host);
  }
}

function pickEmployee(emp) {
  _employee = emp;
  _data.employee_id   = emp.id;
  _data.employee_code = emp.employee_code;
  _empResults = [];
  _data.employee_name_query = '';
  toast(`Picked: ${emp.name}`, 'success');
  paint();
}

async function lookupEmployeeByCode() {
  const code = (_data.employee_code || '').trim();
  if (!code) {
    toast('Enter an employee code first');
    return;
  }
  _employeeBusy = true;
  paint();
  try {
    const res = await api.get('/employees/lookup?code=' + encodeURIComponent(code));
    _employee = res?.data ?? null;
    _data.employee_id = _employee?.id ?? '';
    toast(`Found: ${_employee.name}`, 'success');
  } catch (e) {
    if (e.status === 404) toast('No employee in your team with that code', 'error');
    else if (e.code === 'network' || e.status === 0) toast('Cannot search while offline', 'error');
    else toast(e.message || 'Lookup failed', 'error');
    _employee = null;
    _data.employee_id = '';
  } finally {
    _employeeBusy = false;
    paint();
  }
}

// ─── Step 2: Details (everything else, all optional except payment) ──
function paintStepDetails(host) {
  const user = state.get('user');
  const servicePrice = parseFloat(user?.service_price || 0);

  // Split totals — Cash + Card only (Online dropped from split intake).
  const splitCash   = parseFloat(_data.split_cash   || 0);
  const splitCard   = parseFloat(_data.split_card   || 0);
  const splitSum    = (splitCash > 0 ? splitCash : 0)
                    + (splitCard > 0 ? splitCard : 0);
  const splitOK     = Math.abs(splitSum - servicePrice) < 0.005;

  // Order Summary card — checkout-style anchor at the top of the step.
  // Service + employee + total, period. Vehicle/location/notes are not
  // collected in this flow anymore.
  const empLine = _employee
    ? `${_employee.employee_code} · ${_employee.name}`
    : '—';

  host.innerHTML = `
    <div class="wiz-step wiz-checkout">

      <!-- Order summary card -->
      <div class="co-card co-summary">
        <div class="co-card-title">Order summary</div>
        <div class="co-row">
          <span class="co-row-lbl">Service</span>
          <span class="co-row-val">${escape(user?.service_name || '—')}</span>
        </div>
        <div class="co-row">
          <span class="co-row-lbl">Employee</span>
          <span class="co-row-val">${escape(empLine)}</span>
        </div>
        <div class="co-divider"></div>
        <div class="co-row co-total">
          <span class="co-row-lbl">Total</span>
          <span class="co-row-val tabular">${CURRENCY} ${formatPrice(servicePrice)}</span>
        </div>
      </div>

      <!-- Payment section -->
      <div class="co-card co-payment">
        <div class="co-card-title">Payment method</div>
        <div class="pay-methods" role="radiogroup">
          ${payOpt('cash',    '💵', 'Cash')}
          ${payOpt('card',    '💳', 'Card')}
          ${payOpt('online',  '🔗', 'Online')}
          ${payOpt('split',   '🔀', 'Split')}
          ${payOpt('unpaid',  '⏱',  'Unpaid')}
        </div>

        ${_data.payment_method === 'split' ? `
          <div class="split-box">
            <div class="split-hint">
              Enter how much was collected as cash and card.
              The two amounts must add up to
              <b>${CURRENCY} ${formatPrice(servicePrice)}</b>.
            </div>
            ${splitRow('cash',   '💵 Cash',   _data.split_cash)}
            ${splitRow('card',   '💳 Card',   _data.split_card)}
            <div class="split-sum ${splitOK ? 'ok' : (splitSum > servicePrice ? 'over' : 'under')}">
              <span class="split-sum-lbl">Sum</span>
              <span class="split-sum-val tabular">
                ${CURRENCY} ${formatPrice(splitSum)}
                <span class="split-sum-target"> / ${formatPrice(servicePrice)}</span>
              </span>
              <span class="split-sum-icon">${splitOK ? '✓' : (splitSum > servicePrice ? '!' : '…')}</span>
            </div>
          </div>
        ` : ''}

        ${(_data.payment_method === 'cash' || _data.payment_method === 'card') ? `
          <div class="field" style="margin-top:14px">
            <label>Transaction reference <span class="opt">(optional)</span></label>
            <input id="f-ref" type="text"
                   value="${escapeAttr(_data.payment_ref)}"
                   placeholder="${_data.payment_method === 'cash' ? 'Optional' : 'Approval code, txn id, etc.'}">
          </div>` : ''}

        ${_data.payment_method === 'unpaid' ? `
          <div class="co-balance-note">
            Order will be created as <b>unpaid</b>.
            Record payment from <b>My Jobs</b> when ready.
            The order completes automatically once paid in full.
          </div>` : ''}

        ${_data.payment_method === 'online' ? `
          <div class="co-balance-note">
            A Stripe payment link will be generated after placing the order.
            Share it with the customer — the order completes automatically once they pay.
          </div>` : ''}
      </div>

    </div>`;

  // Payment method buttons
  host.querySelectorAll('[data-pm]').forEach((b) => {
    b.addEventListener('click', () => {
      _data.payment_method = b.dataset.pm;
      paint();
    });
  });

  // Split amount inputs — three rows for cash/card/online amounts.
  // We DON'T full-repaint on each keystroke (steals focus); only the
  // sum-row and place-order footer update inline.
  host.querySelectorAll('[data-split]').forEach((inp) => {
    inp.addEventListener('input', () => {
      const v = parseFloat(inp.value);
      const safe = Number.isFinite(v) && v > 0 ? v : 0;
      _data[`split_${inp.dataset.split}`] = safe;
      updateSplitSum(host);
      paintFooter();
    });
  });

  // Transaction ref — only present when there's a real payment to reference
  host.querySelector('#f-ref')?.addEventListener('input', (e) => {
    _data.payment_ref = e.target.value;
  });
}

// Updates the sum-row + online hint inline without a full re-render
// (which would steal focus from the input the user is typing into).
function updateSplitSum(host) {
  const user = state.get('user');
  const total = parseFloat(user?.service_price || 0);
  const cash   = parseFloat(_data.split_cash   || 0);
  const card   = parseFloat(_data.split_card   || 0);
  const sum    = (cash > 0 ? cash : 0) + (card > 0 ? card : 0);
  const ok     = Math.abs(sum - total) < 0.005;

  const sumEl = host.querySelector('.split-sum');
  if (sumEl) {
    sumEl.classList.remove('ok', 'over', 'under');
    sumEl.classList.add(ok ? 'ok' : (sum > total ? 'over' : 'under'));
    sumEl.querySelector('.split-sum-val').innerHTML =
      `${CURRENCY} ${formatPrice(sum)}<span class="split-sum-target"> / ${formatPrice(total)}</span>`;
    sumEl.querySelector('.split-sum-icon').textContent =
      ok ? '✓' : (sum > total ? '!' : '…');
  }
}

function splitRow(method, label, value) {
  // value comes from _data.split_<method> — show empty string when 0 so
  // the placeholder shows instead of a literal "0".
  const v = (value && value > 0) ? value : '';
  return `
    <div class="split-row">
      <span class="split-row-lbl">${label}</span>
      <div class="split-row-input">
        <span class="split-row-prefix">${CURRENCY}</span>
        <input type="number" inputmode="decimal" step="0.01" min="0"
               data-split="${method}"
               value="${escapeAttr(String(v))}"
               placeholder="0.00">
      </div>
    </div>`;
}

function payOpt(method, icon, label) {
  const active = _data.payment_method === method;
  return `<button class="pm-btn ${active ? 'active' : ''}" data-pm="${method}">
    <span class="pm-icon">${icon}</span>
    <span class="pm-label">${label}</span>
  </button>`;
}

function paymentLabel(m) {
  return { cash: 'Cash', card: 'Card', online: 'Online', split: 'Split', unpaid: 'Unpaid' }[m] || m;
}

// ─── Validation ────────────────────────────────────────────────────
function validateStep(n) {
  if (n === 1) {
    // Employee is the only required field on Step 1
    return !!_data.employee_id && !!_employee;
  }
  if (n === 2) {
    // Payment method is required
    if (!['cash','card','online','split','unpaid'].includes(_data.payment_method)) return false;
    // For split: sum of the three amounts MUST equal the order total exactly.
    if (_data.payment_method === 'split') {
      const user = state.get('user');
      const total = parseFloat(user?.service_price || 0);
      const sum = (parseFloat(_data.split_cash   || 0))
                + (parseFloat(_data.split_card   || 0));
      if (Math.abs(sum - total) > 0.005) return false;
      // At least one row must be non-zero (otherwise sum = 0 ≠ total unless
      // the service is free which we don't support)
      if (sum <= 0) return false;
    }
    return true;
  }
  return false;
}

// ─── Submit ────────────────────────────────────────────────────────
async function submit() {
  if (_submitting) return;
  _submitting = true;
  paintFooter();

  // The wizard no longer collects vehicle plate, photo, location, bay, or
  // notes — those are stored as null on the order.
  const payload = {
    employee_id:      _data.employee_id,
    payment_method:   _data.payment_method,
    payment_ref:      _data.payment_ref || null,
    payment_uuid:     _data.payment_uuid,
  };

  // For split: Cash + Card rows only. Online was removed from split
  // because a fresh Stripe link can't be generated from this flow
  // anyway — if the customer wants to pay online, the New Order
  // wizard's "Online" method (not "Split") is the path.
  if (_data.payment_method === 'split') {
    const rows = [];
    const cash   = parseFloat(_data.split_cash   || 0);
    const card   = parseFloat(_data.split_card   || 0);
    if (cash > 0) rows.push({ method: 'cash', amount: cash });
    if (card > 0) rows.push({ method: 'card', amount: card });
    payload.split_payments = rows;
  }

  if (!navigator.onLine) {
    // Queue and show offline success
    await queueAction({
      entity_type: 'order',
      entity_id:   null,
      action_type: 'direct_book',
      payload,
    });
    _success = {
      offline: true,
      service_name:  state.get('user')?.service_name || '',
      employee_name: _employee?.name || '',
      payment_method: _data.payment_method,
    };
    _submitting = false;
    paint();
    return;
  }

  try {
    const res = await api.post('/tl/orders', payload);
    _success = Object.assign(
      { offline: false, employee_name: _employee?.name || '' },
      res?.data ?? {}
    );
  } catch (e) {
    if (e.code === 'network' || e.status === 0) {
      await queueAction({
        entity_type: 'order',
        entity_id:   null,
        action_type: 'direct_book',
        payload,
      });
      _success = {
        offline: true,
        service_name:  state.get('user')?.service_name || '',
        employee_name: _employee?.name || '',
        payment_method: _data.payment_method,
      };
    } else {
      toast(e.message || 'Could not submit order', 'error');
      _submitting = false;
      paintFooter();
      return;
    }
  }
  _submitting = false;
  paint();
}

// ─── Success screen ────────────────────────────────────────────────
function paintSuccess() {
  const s = _success;
  const serialBig = s.daily_serial
    ? `<div class="ok-serial tabular">#${parseInt(s.daily_serial, 10)}</div>`
    : `<div class="ok-serial offline">⌛</div>`;

  // Build the message based on payment method + outcome.
  let detail;
  if (s.offline) {
    detail = `<p class="ok-msg">Saved on this device.<br>Will create the order when you're back online.</p>`;
  } else if (s.payment_method === 'online') {
    detail = `<p class="ok-msg">Send the payment link below to the customer.<br>The order is <b>unpaid</b> until checkout completes — it will move to <b>completed</b> automatically once the customer pays.</p>`;
  } else if (s.payment_method === 'split' && s.link_amount > 0) {
    detail = `<p class="ok-msg">Cash and card portions recorded.<br>Send the Stripe link below for the <b>${CURRENCY} ${formatPrice(s.link_amount)}</b> online portion. The order completes automatically once the customer pays.</p>`;
  } else if (s.payment_method === 'split') {
    detail = `<p class="ok-msg">Split payment recorded, order marked completed.</p>`;
  } else if (s.payment_method === 'unpaid') {
    detail = `<p class="ok-msg">Order created as <b>unpaid</b>.<br>Record payment from <b>My Jobs</b>. The order completes automatically once it's paid in full.</p>`;
  } else {
    detail = `<p class="ok-msg">Order created, payment recorded, and marked completed.</p>`;
  }

  // Online-payment panel — only when we have a link.
  // Visible for plain Online OR Split-with-online (in both cases there's a link).
  // Prefer the short pay_url (/pay/go/<token>) — fits in SMS/WhatsApp
  // and looks like an actual airportvas.jrjapp.com link, not a Stripe one.
  const payLink = s.pay_url || s.checkout_url || '';
  const hasPayLink = (s.payment_method === 'online' || s.payment_method === 'split') && payLink;
  const payPanelLabel = (s.payment_method === 'split' && s.link_amount > 0)
    ? `Payment link · ${CURRENCY} ${formatPrice(s.link_amount)} (online portion)`
    : 'Payment link';
  const payPanel = hasPayLink
    ? `<div class="pay-panel">
         <div class="pay-panel-label">${escape(payPanelLabel)}</div>
         <div class="pay-panel-url">${escape(payLink)}</div>
         <div class="pay-panel-actions">
           <button id="pay-copy" class="ok-btn ok-btn-secondary">Copy</button>
           <button id="pay-share" class="ok-btn ok-btn-secondary" ${navigator.share ? '' : 'hidden'}>Share</button>
           <button id="pay-open"  class="ok-btn ok-btn-secondary">Open</button>
         </div>
       </div>`
    : (s.payment_method === 'online' && s.link_error)
    ? `<div class="pay-panel pay-panel-err">
         <div class="pay-panel-label">⚠ Payment link unavailable</div>
         <div class="pay-panel-url">${escape(s.link_error)}</div>
       </div>`
    : '';

  _root.innerHTML = `
    <div class="ok-wrap">
      <div class="ok-tick">✓</div>
      ${serialBig}
      <div class="ok-svc">${escape(s.service_name || '')}</div>
      ${detail}
      <div class="ok-meta">
        ${s.employee_name ? `<div class="ok-meta-row"><span>Employee</span><span>${escape(s.employee_name)}</span></div>` : ''}
        <div class="ok-meta-row"><span>Payment</span><span>${escape(paymentLabel(s.payment_method) || '—')}${s.payment_method === 'online' && !s.offline ? ' · <i>awaiting</i>' : ''}</span></div>
        ${s.amount ? `<div class="ok-meta-row total"><span>Amount</span><span class="tabular">${CURRENCY} ${formatPrice(s.amount)}</span></div>` : ''}
      </div>

      ${payPanel}

      <div class="ok-actions">
        ${!s.offline && s.order_service_id
          ? `<button id="ok-print" class="ok-btn ok-btn-secondary">🖨 Print slip</button>`
          : ''}
        <button id="ok-again" class="ok-btn">+ New order</button>
      </div>
    </div>`;

  _root.querySelector('#ok-again').addEventListener('click', () => {
    resetWizard();
    paint();
  });
  _root.querySelector('#ok-print')?.addEventListener('click', async () => {
    try {
      // Lazy-load the slip renderer from the jobs-tab module
      const { renderSlip } = await import('./jobs-tab.js');

      // The wizard owns the freshest order data — build the slip payload
      // from what we just submitted instead of waiting for jobs-tab to refresh.
      // Vehicle plate, location, and bay are no longer collected in the new
      // checkout-style wizard, so they're omitted from the slip.
      const slipData = {
        daily_serial:      s.daily_serial,
        serial_date:       s.serial_date,
        service_name:      s.service_name || state.get('user')?.service_name || '',
        vehicle_plate:     null,
        employee_name:     s.employee_name  || _employee?.name || '',
        employee_code:     _employee?.employee_code || '',
        team_leader_name:  state.get('user')?.name || '',
        order_number:      s.order_number,
        // Payment breakdown: total / paid / status — used by renderSlip
        // to show Total / Paid / Due rows and the at-a-glance stamp.
        total_amount:      parseFloat(s.amount ?? state.get('user')?.service_price ?? 0),
        paid_amount:       parseFloat(s.paid_amount ?? 0),
        paid_total:        parseFloat(s.paid_amount ?? 0),
        payment_status:    s.payment_status || s.payment_method,
        payment_methods:   s.payment_method || '',
        order_time:        new Date().toISOString(),
        currency:          CURRENCY,
        app_name:          'Airport VAS',
      };

      renderSlip(slipData);
      // Give the browser a tick to paint the hidden print-slip element
      setTimeout(() => window.print(), 50);
    } catch (e) {
      console.error('Slip print failed', e);
      toast('Could not open the slip — try again from My Jobs', 'error');
    }
  });

  // Payment-link actions (online orders only)
  _root.querySelector('#pay-copy')?.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(payLink);
      toast('Link copied', 'success');
    } catch {
      toast('Could not copy — long-press the URL to select it', 'error');
    }
  });
  _root.querySelector('#pay-share')?.addEventListener('click', async () => {
    if (!navigator.share) return;
    try {
      await navigator.share({
        title: 'AirVAS payment',
        text:  `Please complete your payment: ${payLink}`,
        url:   payLink,
      });
    } catch { /* user cancelled */ }
  });
  _root.querySelector('#pay-open')?.addEventListener('click', () => {
    window.open(payLink, '_blank', 'noopener');
  });
}

// ─── Image compression ─────────────────────────────────────────────
async function compressImage(file, maxDim, quality) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => {
      const img = new Image();
      img.onload = () => {
        const ratio = Math.min(1, maxDim / Math.max(img.width, img.height));
        const w = Math.round(img.width  * ratio);
        const h = Math.round(img.height * ratio);
        const canvas = document.createElement('canvas');
        canvas.width = w; canvas.height = h;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(img, 0, 0, w, h);
        let q = quality;
        let data = canvas.toDataURL('image/jpeg', q);
        // Shrink further if still too big
        while (data.length > MAX_PHOTO_BYTES && q > 0.35) {
          q -= 0.1;
          data = canvas.toDataURL('image/jpeg', q);
        }
        resolve(data);
      };
      img.onerror = reject;
      img.src = reader.result;
    };
    reader.onerror = reject;
    reader.readAsDataURL(file);
  });
}

// ─── Helpers ───────────────────────────────────────────────────────
function formatPrice(p) {
  const n = parseFloat(p);
  return Number.isFinite(n) ? n.toFixed(2) : '—';
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
  if (document.getElementById('new-order-tab-css')) return;
  const css = `
    /* Progress dots */
    .wiz-progress {
      display: flex; align-items: center;
      margin: 4px 8px 22px;
    }
    .wiz-dot {
      width: 28px; height: 28px;
      border-radius: 50%;
      background: var(--bg-soft);
      color: var(--ink-mute);
      border: 1.5px solid var(--line);
      display: flex; align-items: center; justify-content: center;
      font-family: var(--font-display);
      font-weight: 700;
      font-size: 13px;
      transition: all .2s;
    }
    .wiz-dot.done {
      background: var(--accent);
      color: #fff;
      border-color: var(--accent);
    }
    .wiz-dot.active {
      box-shadow: 0 0 0 4px var(--accent-pale);
    }
    .wiz-line {
      flex: 1; height: 2px;
      background: var(--line);
      margin: 0 4px;
      transition: background .2s;
    }
    .wiz-line.done { background: var(--accent); }

    .wiz-step { padding: 0 4px; }
    .wiz-h {
      font-family: var(--font-display);
      font-weight: 700;
      font-size: 22px;
      letter-spacing: -0.01em;
      margin: 0 0 14px;
    }
    .wiz-sub {
      font-size: 13px;
      color: var(--ink-mute);
      margin: -6px 0 16px;
    }

    .field { margin-bottom: 14px; }
    .field label {
      display: block;
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: 0.12em;
      font-weight: 700;
      color: var(--ink-mute);
      margin-bottom: 5px;
    }
    .field label .opt {
      font-weight: 500;
      text-transform: none;
      letter-spacing: 0;
      color: var(--ink-faint);
      margin-left: 4px;
    }
    .field input, .field select, .field textarea {
      width: 100%;
      padding: 13px 14px;
      border: 1.5px solid var(--line);
      border-radius: var(--r-md);
      background: var(--bg-elev);
      color: var(--ink);
      font-size: 16px;          /* prevents iOS zoom */
      transition: border-color .15s;
    }
    .field input:focus, .field select:focus, .field textarea:focus {
      outline: none;
      border-color: var(--accent);
    }
    .field textarea { resize: vertical; min-height: 50px; }
    .field .hint {
      font-size: 11px;
      color: var(--ink-mute);
      margin-top: 4px;
    }
    .field .hint.hint-err {
      color: var(--bad);
      font-weight: 600;
    }

    /* Employee lookup */
    .emp-search-row {
      display: flex; gap: 8px;
      margin-bottom: 14px;
    }
    .emp-input {
      flex: 1;
      padding: 14px 14px;
      border: 1.5px solid var(--line);
      border-radius: var(--r-md);
      background: var(--bg-elev);
      font-size: 17px;
      font-weight: 700;
      letter-spacing: 0.1em;
      text-transform: uppercase;
    }
    .emp-input:focus { outline: none; border-color: var(--accent); }
    .emp-input:disabled { background: var(--bg-soft); color: var(--ink-mute); }
    .emp-go {
      padding: 0 18px;
      background: var(--accent);
      color: #fff;
      border: none;
      border-radius: var(--r-md);
      font-weight: 700;
      font-size: 14px;
    }
    .emp-go:disabled { opacity: .5; }

    .emp-found {
      background: var(--good-pale);
      border: 1px solid var(--good);
      border-radius: var(--r-md);
      padding: 14px;
      padding-right: 80px;       /* room for the Change button */
      position: relative;
      display: flex;
      align-items: center;
      gap: 14px;
    }
    .emp-found-photo {
      width: 56px; height: 56px;
      border-radius: 50%;
      background: var(--bg-elev);
      flex: 0 0 56px;
      overflow: hidden;
      display: flex; align-items: center; justify-content: center;
      border: 1px solid var(--good);
      color: var(--ink-mute);
    }
    .emp-found-photo img,
    .emp-found-photo svg {
      width: 100%; height: 100%; object-fit: cover; display: block;
    }
    .emp-found-info { flex: 1; min-width: 0; }
    .emp-photo-fallback {
      display: flex; width: 100%; height: 100%;
      align-items: center; justify-content: center;
    }
    .emp-found-name {
      font-family: var(--font-display);
      font-weight: 700;
      font-size: 18px;
      color: var(--good);
    }
    .emp-found-code {
      font-size: 12px;
      letter-spacing: 0.1em;
      color: var(--ink-soft);
      margin-top: 2px;
    }
    .emp-found-phone {
      font-size: 12px;
      color: var(--ink-mute);
      margin-top: 2px;
    }
    .emp-clear {
      position: absolute; top: 10px; right: 10px;
      background: none;
      border: 1px solid var(--line);
      border-radius: 6px;
      padding: 4px 10px;
      font-size: 11px;
      font-weight: 600;
      color: var(--ink-soft);
    }

    /* Code / Name mode tabs */
    .emp-mode-tabs {
      display: flex;
      gap: 6px;
      margin-bottom: 14px;
      background: var(--bg-soft);
      padding: 4px;
      border-radius: var(--r-md);
    }
    .emp-mode-tab {
      flex: 1;
      padding: 9px 12px;
      background: transparent;
      border: none;
      border-radius: calc(var(--r-md) - 4px);
      font-size: 13px;
      font-weight: 600;
      color: var(--ink-soft);
      transition: background .15s, color .15s;
    }
    .emp-mode-tab.active {
      background: var(--bg-elev);
      color: var(--accent);
      box-shadow: var(--shadow-sm);
    }

    /* Name-search results list */
    .emp-list { margin-top: 12px; }
    .emp-empty {
      padding: 18px 14px;
      text-align: center;
      color: var(--ink-mute);
      font-size: 13px;
      background: var(--bg-soft);
      border-radius: var(--r-md);
    }
    .emp-pick {
      display: flex;
      align-items: center;
      gap: 12px;
      width: 100%;
      text-align: left;
      background: var(--bg-elev);
      border: 1.5px solid var(--line);
      border-radius: var(--r-md);
      padding: 10px 12px;
      margin-bottom: 8px;
      transition: border-color .15s, background .15s;
    }
    .emp-pick:hover { border-color: var(--accent); }
    .emp-pick:active { background: var(--accent-pale); }
    .emp-pick-photo {
      width: 44px; height: 44px;
      border-radius: 50%;
      background: var(--bg-soft);
      flex: 0 0 44px;
      overflow: hidden;
      display: flex; align-items: center; justify-content: center;
      color: var(--ink-mute);
    }
    .emp-pick-photo img,
    .emp-pick-photo svg {
      width: 100%; height: 100%; object-fit: cover; display: block;
    }
    .emp-pick-info { flex: 1; min-width: 0; }
    .emp-pick-name {
      font-size: 15px;
      font-weight: 700;
      color: var(--ink);
      margin-bottom: 2px;
    }
    .emp-pick-meta {
      font-size: 12px;
      color: var(--ink-mute);
      font-weight: 500;
    }

    /* Photo */
    .photo-pick {
      width: 100%;
      padding: 18px;
      background: var(--bg-soft);
      border: 1.5px dashed var(--line-strong);
      border-radius: var(--r-md);
      color: var(--ink-soft);
      font-weight: 600;
      font-size: 14px;
    }
    .photo-thumb {
      position: relative;
      display: inline-block;
    }
    .photo-thumb img {
      width: 120px; height: 120px;
      object-fit: cover;
      border-radius: var(--r-md);
      border: 1px solid var(--line);
    }
    .photo-clear {
      position: absolute; top: 6px; right: 6px;
      background: rgba(0,0,0,.7);
      color: #fff;
      border: none;
      border-radius: 4px;
      padding: 4px 8px;
      font-size: 11px;
      font-weight: 600;
    }

    /* Payment methods — 5 in a single row to keep Step 2 compact.
     * Each button is icon-above-label so the text doesn't truncate even
     * on narrow phones (~360px viewport → ~64px per cell). */
    .pay-methods {
      display: grid;
      grid-template-columns: repeat(5, 1fr);
      gap: 6px;
      margin-bottom: 16px;
    }
    .pm-btn {
      padding: 10px 4px;
      background: var(--bg-elev);
      border: 1.5px solid var(--line);
      border-radius: var(--r-md);
      font-size: 11px;
      font-weight: 600;
      color: var(--ink-soft);
      transition: all .15s;
      cursor: pointer;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 4px;
      line-height: 1.1;
      min-width: 0;
      text-align: center;
    }
    .pm-btn .pm-icon { font-size: 18px; line-height: 1; }
    .pm-btn:hover { border-color: var(--accent); }
    .pm-btn.active {
      background: var(--accent);
      color: #fff;
      border-color: var(--accent);
    }
    /* On wider screens, a touch more horizontal padding */
    @media (min-width: 480px) {
      .pm-btn { padding: 12px 6px; font-size: 12px; gap: 5px; }
      .pm-btn .pm-icon { font-size: 20px; }
    }

    /* ── Checkout-style cards (new Step 2 layout) ─────────────────── */
    .wiz-checkout { padding: 0 4px; }

    .co-card {
      background: var(--bg-elev);
      border: 1.5px solid var(--line);
      border-radius: var(--r-lg, 14px);
      padding: 18px 18px 16px;
      margin-bottom: 16px;
    }
    .co-card-title {
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--ink-mute);
      margin-bottom: 14px;
    }
    .co-row {
      display: flex; justify-content: space-between; align-items: baseline;
      padding: 8px 0;
      font-size: 14px;
      gap: 12px;
    }
    .co-row-lbl { color: var(--ink-mute); flex-shrink: 0; }
    .co-row-val { color: var(--ink); font-weight: 600; text-align: right; }
    .co-divider {
      height: 1px;
      background: var(--line);
      margin: 6px 0;
    }
    .co-total { padding-top: 12px; }
    .co-total .co-row-lbl { color: var(--ink); font-weight: 700; font-size: 15px; }
    .co-total .co-row-val {
      font-size: 22px;
      font-weight: 800;
      color: var(--accent);
      letter-spacing: -0.01em;
    }

    .co-summary { /* accent left edge to feel like a checkout receipt */
      border-left: 4px solid var(--accent);
    }

    .co-payment { /* dedicated card so the picker doesn't float on bg */
    }
    .co-payment .pay-methods { margin-bottom: 0; }

    .co-balance-note {
      margin-top: 14px;
      padding: 12px 14px;
      background: var(--gold-soft, #fff7ed);
      border: 1px solid var(--gold, #f59e0b);
      border-radius: var(--r-md);
      color: var(--ink);
      font-size: 13px;
      line-height: 1.5;
    }
    .co-balance-note b { color: var(--ink); }

    /* ── Split-payment block ────────────────────────────────────── */
    .split-box {
      margin-top: 12px;
      padding: 12px;
      background: var(--bg-soft);
      border: 1.5px solid var(--accent);
      border-radius: var(--r-md);
    }
    .split-hint {
      font-size: 12px;
      color: var(--ink-mute);
      margin-bottom: 10px;
      line-height: 1.45;
    }
    .split-hint b { color: var(--ink); font-weight: 700; }

    /* Compact one-line rows — label hugs left, input hugs right with
     * a fixed comfortable width instead of stretching across the panel. */
    .split-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      margin-bottom: 6px;
    }
    .split-row-lbl {
      font-size: 13px;
      font-weight: 600;
      color: var(--ink);
      flex-shrink: 0;
    }
    .split-row-input {
      position: relative;
      width: 140px;          /* fits "AED 9999.99" comfortably */
      flex-shrink: 0;
    }
    .split-row-prefix {
      position: absolute;
      left: 10px;
      top: 50%;
      transform: translateY(-50%);
      font-size: 11px;
      font-weight: 700;
      color: var(--ink-mute);
      pointer-events: none;
      letter-spacing: 0.04em;
    }
    .split-row-input input {
      width: 100%;
      padding: 8px 10px 8px 42px;
      border: 1.5px solid var(--line);
      border-radius: var(--r-sm);
      background: var(--bg-elev);
      font-size: 15px;
      font-weight: 600;
      color: var(--ink);
      font-variant-numeric: tabular-nums;
      text-align: right;
      transition: border-color .15s, box-shadow .15s;
      /* Hide number-input spinners — they steal width and feel clunky */
      appearance: textfield;
      -moz-appearance: textfield;
    }
    .split-row-input input::-webkit-outer-spin-button,
    .split-row-input input::-webkit-inner-spin-button {
      -webkit-appearance: none;
      margin: 0;
    }
    .split-row-input input:focus {
      outline: none;
      border-color: var(--accent);
      box-shadow: 0 0 0 3px var(--accent-pale, rgba(14,165,233,.15));
    }

    /* Sum-indicator row — three states colour-coded */
    .split-sum {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      margin-top: 10px;
      padding: 9px 12px;
      border-radius: var(--r-sm);
      font-size: 13px;
      font-weight: 700;
      background: var(--bg-elev);
      border: 1.5px solid var(--line);
      transition: background .15s, border-color .15s, color .15s;
    }
    .split-sum-lbl {
      color: var(--ink-mute);
      font-weight: 600;
      font-size: 11px;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      flex: 1;
    }
    .split-sum-val {
      font-family: var(--font-mono, monospace);
      font-weight: 800;
      color: var(--ink);
      font-size: 14px;
    }
    .split-sum-target { color: var(--ink-mute); font-weight: 500; font-size: 12px; }
    .split-sum-icon {
      width: 20px;
      height: 20px;
      border-radius: 50%;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 12px;
      font-weight: 800;
      background: var(--ink-mute);
      color: #fff;
    }
    .split-sum.ok {
      background: var(--good-pale);
      border-color: var(--good);
      color: var(--good);
    }
    .split-sum.ok .split-sum-icon { background: var(--good); }
    .split-sum.over {
      background: var(--bad-pale);
      border-color: var(--bad);
      color: var(--bad);
    }
    .split-sum.over .split-sum-icon { background: var(--bad); }
    .split-sum.under {
      background: var(--gold-soft, #fff7ed);
      border-color: var(--gold, #f59e0b);
      color: var(--gold, #b45309);
    }
    .split-sum.under .split-sum-icon { background: var(--gold, #f59e0b); }

    .muted { color: var(--ink-mute); font-weight: 500; }

    /* Review block */
    .review {
      background: var(--bg-soft);
      border-radius: var(--r-md);
      padding: 12px 14px;
    }
    .rv-row {
      display: flex; justify-content: space-between; align-items: baseline;
      padding: 4px 0;
      font-size: 13px;
    }
    .rv-row span:first-child {
      text-transform: uppercase;
      font-size: 10px;
      letter-spacing: 0.1em;
      font-weight: 700;
      color: var(--ink-mute);
    }
    .rv-row.total {
      border-top: 1px dashed var(--line-strong);
      margin-top: 6px;
      padding-top: 8px;
    }

    /* Footer (Back / Next / Submit) */
    .wiz-footer {
      display: flex; justify-content: space-between; gap: 8px;
      margin-top: 24px;
      padding: 0 4px;
    }
    .wiz-back, .wiz-next, .wiz-submit {
      padding: 14px 22px;
      border-radius: var(--r-md);
      font-weight: 700;
      font-size: 14px;
      transition: transform .1s, opacity .15s;
      border: none;
    }
    .wiz-back {
      background: var(--bg-soft);
      color: var(--ink-soft);
      border: 1px solid var(--line);
    }
    .wiz-next, .wiz-submit {
      background: var(--accent);
      color: #fff;
      min-width: 130px;
    }
    .wiz-submit:disabled, .wiz-next:disabled { opacity: .45; }
    .wiz-back:active, .wiz-next:active, .wiz-submit:active { transform: scale(.98); }

    /* Success screen */
    .ok-wrap {
      text-align: center;
      padding: 32px 16px;
    }
    .ok-tick {
      width: 64px; height: 64px;
      border-radius: 50%;
      background: var(--good);
      color: #fff;
      font-size: 36px;
      font-weight: 900;
      display: flex; align-items: center; justify-content: center;
      margin: 0 auto 18px;
    }
    .ok-serial {
      font-family: var(--font-display);
      font-weight: 900;
      font-size: 72px;
      color: var(--accent);
      letter-spacing: -0.04em;
      line-height: 1;
      margin: 8px 0;
    }
    .ok-serial.offline {
      font-size: 48px;
      color: var(--warn);
    }
    .ok-svc {
      font-size: 14px;
      text-transform: uppercase;
      letter-spacing: 0.12em;
      font-weight: 700;
      color: var(--ink-mute);
      margin-bottom: 16px;
    }
    .ok-msg {
      font-size: 14px;
      color: var(--ink-soft);
      margin: 0 auto 24px;
      max-width: 320px;
      line-height: 1.5;
    }
    .ok-meta {
      background: var(--bg-soft);
      border-radius: var(--r-md);
      padding: 12px 16px;
      text-align: left;
      max-width: 320px;
      margin: 0 auto 24px;
    }
    .ok-meta-row {
      display: flex; justify-content: space-between;
      padding: 5px 0;
      font-size: 13px;
    }
    .ok-meta-row span:first-child {
      text-transform: uppercase;
      font-size: 10px;
      letter-spacing: 0.1em;
      font-weight: 700;
      color: var(--ink-mute);
    }
    .ok-meta-row.total {
      border-top: 1px dashed var(--line-strong);
      margin-top: 6px;
      padding-top: 8px;
      font-weight: 700;
    }
    .ok-actions {
      display: flex; flex-direction: column; gap: 10px;
      max-width: 320px; margin: 0 auto;
    }
    .ok-btn {
      padding: 14px;
      background: var(--accent);
      color: #fff;
      border: none;
      border-radius: var(--r-md);
      font-weight: 700;
      font-size: 14px;
    }
    .ok-btn-secondary {
      background: var(--bg-soft);
      color: var(--ink);
      border: 1px solid var(--line);
    }

    /* Payment-link panel (online orders) */
    .pay-panel {
      max-width: 420px;
      margin: 16px auto 24px;
      background: var(--bg-elev);
      border: 1.5px solid var(--accent);
      border-radius: var(--r-md);
      padding: 14px;
      box-shadow: var(--shadow-sm);
      text-align: left;
    }
    .pay-panel.pay-panel-err {
      border-color: var(--bad);
    }
    .pay-panel-label {
      font-size: 10px;
      text-transform: uppercase;
      letter-spacing: 0.14em;
      font-weight: 700;
      color: var(--accent);
      margin-bottom: 8px;
    }
    .pay-panel-err .pay-panel-label {
      color: var(--bad);
    }
    .pay-panel-url {
      font-family: var(--font-mono);
      font-size: 12px;
      color: var(--ink);
      word-break: break-all;
      background: var(--bg-soft);
      padding: 10px 12px;
      border-radius: var(--r-sm);
      margin-bottom: 10px;
      user-select: all;
      -webkit-user-select: all;
    }
    .pay-panel-actions {
      display: flex; gap: 8px;
    }
    .pay-panel-actions .ok-btn {
      flex: 1;
      padding: 10px;
      font-size: 13px;
    }

    /* ─── Desktop layout (≥ 900 px) ──────────────────────────────── */
    /* The wizard is form-driven — readability is best in a centered column,
     * not stretched across the full width. Center at 720px. Step 2's
     * location/bay/notes fields pair into a 2-column grid; the payment
     * picker and review stay full-width inside the wizard column.
     *
     * The success screen centers more tightly, payment panel inside it. */
    @media (min-width: 900px) {
      /* The whole wizard sits centered in the tab pane */
      #tab-new-order > .tab-h,
      #tab-new-order > .wiz-progress,
      #tab-new-order > #wiz-body,
      #tab-new-order > #wiz-footer {
        max-width: 720px;
        margin-left: auto;
        margin-right: auto;
      }

      /* Step 2 was previously a multi-field form. With the checkout-style
       * rewrite it's already structured as full-width co-cards, so we only
       * apply the 2-column grid to the OTHER steps (mostly Step 1's
       * employee picker). */
      .wiz-step:not(.wiz-checkout) {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        column-gap: 16px;
        row-gap: 0;
        align-items: start;
      }
      .wiz-step:not(.wiz-checkout) > .wiz-h,
      .wiz-step:not(.wiz-checkout) > .wiz-sub,
      .wiz-step:not(.wiz-checkout) > .emp-mode-tabs,
      .wiz-step:not(.wiz-checkout) > .emp-search-row,
      .wiz-step:not(.wiz-checkout) > .emp-list,
      .wiz-step:not(.wiz-checkout) > .emp-found,
      .wiz-step:not(.wiz-checkout) > .emp-help,
      .wiz-step:not(.wiz-checkout) > #emp-list {
        grid-column: 1 / -1;
      }

      /* Checkout (step 2) — give it a comfortable max width on desktop so
       * the cards don't stretch edge-to-edge in a sea of whitespace. */
      .wiz-checkout {
        max-width: 560px;
        margin: 0 auto;
      }

      /* Footer buttons get a touch more weight */
      .wiz-footer {
        padding: 18px 0 28px;
      }
      .wiz-next, .wiz-submit, .wiz-back {
        padding: 14px 24px;
        font-size: 15px;
      }

      /* Success screen — tighter centered column */
      .ok-wrap {
        max-width: 540px;
        margin: 40px auto;
      }
      .pay-panel { max-width: 480px; }
    }
  `;
  const s = document.createElement('style');
  s.id = 'new-order-tab-css';
  s.textContent = css;
  document.head.appendChild(s);
}