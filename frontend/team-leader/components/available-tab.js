// frontend/team-leader/components/available-tab.js
//
// Available Orders tab.
//
// What this does:
//   • Polls GET /api/tl/available every POLL_MS (10s) while the tab is visible.
//   • Caches the last list to IndexedDB so offline opens still show content.
//   • Renders each order as a card with: serial, order number,
//     AED price, "Accept" / "Reject" buttons.
//   • Accept: tries the network straight away. On 409 ("already_taken") it
//     refreshes the list with a clean toast. Network errors get queued via
//     the sync engine so they retry when connectivity returns.
//   • Reject: hides the card optimistically, then PATCHes /reject. Queued
//     offline if needed (and the rejected_by JSON array prevents the order
//     reappearing in this TL's list).
//   • Updates the bottom-nav badge with the live count.

import * as api      from '../api.js';
import * as state    from '../state.js';
import { dbReplaceAll, dbGetAll } from '../pwa/db.js';
import { queueAction } from '../pwa/sync-engine.js';

const POLL_MS  = 10_000;
const CURRENCY = 'AED';

// Live "waiting time" thresholds (in seconds) for the age label colour.
// Under 5 min → muted, 5-15 → amber, ≥ 15 → red + gentle pulse.
const AGE_WARN_S   = 5  * 60;
const AGE_URGENT_S = 15 * 60;

let _root         = null;
let _timer        = null;        // 10s polling timer
let _tickTimer    = null;        // 1s timer that re-paints the live "age" badges
let _items        = [];          // current list in memory
let _busy         = new Set();   // service IDs currently being acted on

// ─── Lifecycle ─────────────────────────────────────────────────────
export async function init(root) {
  _root = root;
  injectStyles();
  paintShell();
  attachVisibility();

  // Cache-first paint (works offline)
  try {
    const cached = await dbGetAll('available_cache');
    if (cached?.length) {
      _items = cached;
      paintList();
      updateBadge(_items.length);
    }
  } catch { /* ignore */ }

  refresh();
  startPolling();
}

export function onShow() {
  refresh();
  startPolling();
}

// Called when the user navigates away from this tab — stop the timers so
// we don't run a 1-second interval forever in the background.
export function onHide() {
  stopPolling();
}

export async function refresh() {
  if (!navigator.onLine) return;
  try {
    const res = await api.get('/tl/available');
    _items = res?.data?.items ?? [];
    paintList();
    state.set('availableCount', _items.length);
    updateBadge(_items.length);
    try { await dbReplaceAll('available_cache', _items); } catch {}
  } catch (e) {
    console.warn('[available] refresh failed:', e.message);
  }
}

// ─── Polling control ───────────────────────────────────────────────
function startPolling() {
  stopPolling();
  _timer = setInterval(() => refresh(), POLL_MS);
  startTicker();
}
function stopPolling() {
  if (_timer) { clearInterval(_timer); _timer = null; }
  stopTicker();
}
function attachVisibility() {
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) stopPolling();
    else { refresh(); startPolling(); }
  });
  window.addEventListener('online', () => refresh());
}

// ─── Live "age" ticker ─────────────────────────────────────────────
// Updates each card's age label every second. The tick walks all DOM
// nodes with [data-since] inside the tab and rewrites their text +
// data-state attr (calm | warn | urgent) which the CSS uses for colour.
function startTicker() {
  if (_tickTimer) return;
  _tickTimer = setInterval(updateAges, 1000);
  updateAges();   // paint immediately
}
function stopTicker() {
  if (_tickTimer) { clearInterval(_tickTimer); _tickTimer = null; }
}
function updateAges() {
  if (!_root) return;
  const nodes = _root.querySelectorAll('.avail-age[data-since]');
  if (!nodes.length) return;
  const now = Date.now();
  nodes.forEach((el) => {
    const since = parseInt(el.dataset.since, 10);
    if (!since) return;
    const secs = Math.max(0, Math.floor((now - since) / 1000));
    el.textContent = formatAge(secs);
    const next = secs >= AGE_URGENT_S ? 'urgent'
               : secs >= AGE_WARN_S   ? 'warn'
               : 'calm';
    if (el.dataset.state !== next) el.dataset.state = next;
  });
}
// "0:05"  (< 1 min)
// "12:34" (mm:ss, < 1 hr)
// "1h 23m" (≥ 1 hr — seconds don't matter at this scale)
function formatAge(secs) {
  if (secs < 60) return `0:${String(secs).padStart(2, '0')}`;
  if (secs < 3600) {
    const m = Math.floor(secs / 60);
    const s = secs % 60;
    return `${m}:${String(s).padStart(2, '0')}`;
  }
  const h = Math.floor(secs / 3600);
  const m = Math.floor((secs % 3600) / 60);
  return `${h}h ${m}m`;
}

function updateBadge(n) {
  const badge = document.getElementById('badge-available');
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
    <h1 class="tab-h">New jobs</h1>
    <div id="avail-list"></div>
  `;
  // Tapping the heading triggers a manual refresh — handy when waiting
  _root.querySelector('.tab-h').addEventListener('click', () => refresh());
}

function paintList() {
  const host = _root.querySelector('#avail-list');
  if (!host) return;

  if (!_items.length) {
    host.innerHTML = `
      <div class="empty-state">
        <div class="empty-state-glyph">◉</div>
        <div class="empty-state-msg">
          No orders right now.<br>
          New ones appear here as soon as customers pay.
        </div>
      </div>`;
    return;
  }

  host.innerHTML = _items.map(card).join('');

  host.querySelectorAll('[data-accept]').forEach((btn) => {
    btn.addEventListener('click', () => doAccept(btn.dataset.accept));
  });
  host.querySelectorAll('[data-reject]').forEach((btn) => {
    btn.addEventListener('click', () => doReject(btn.dataset.reject));
  });

  // Tick the age labels immediately so freshly-rendered cards never show
  // the placeholder "0:00" while waiting for the next 1-second ticker.
  updateAges();
}

function card(o) {
  const isBusy = _busy.has(o.id);
  const showVehicle = state.needsVehicleDetails();

  // Booking timestamp in ms — used by the live age ticker that ticks every
  // second to show how long the job has been waiting. MySQL DATETIME comes
  // over as "2026-05-14 09:23:11"; treat it as UTC by replacing space with T
  // and appending Z.
  const sinceMs = (() => {
    if (!o.created_at) return null;
    const d = new Date(String(o.created_at).replace(' ', 'T') + 'Z');
    return Number.isNaN(d.getTime()) ? null : d.getTime();
  })();

  // Identity. TL works one service so the service name is redundant noise.
  // The order_number is the big identifier; plate (legacy wizard) goes
  // underneath if present.
  const hasPlate = showVehicle && !!(o.vehicle_plate && o.vehicle_plate.trim());
  const identityBlock = `
    <div class="avail-id">
      <div class="avail-plate avail-plate-num tabular">#${escape(String(o.order_number || '—'))}</div>
      ${hasPlate
        ? `<div class="avail-sub">${escape(o.vehicle_plate)}</div>`
        : ''}
    </div>`;

  // No meta zone — photos and location have been removed.
  const metaBlock = '';

  return `
    <article class="card avail-card" data-id="${escapeAttr(o.id)}">
      <div class="avail-header">
        <span class="serial-badge tabular">#${parseInt(o.daily_serial, 10) || '—'}</span>
        ${identityBlock}
        <div class="avail-header-right">
          <div class="avail-price tabular">${CURRENCY} ${formatPrice(o.price)}</div>
          ${sinceMs
            ? `<div class="avail-age tabular" data-since="${sinceMs}" data-state="calm"
                    title="Waiting time since booking">0:00</div>`
            : `<div class="avail-age">—</div>`}
        </div>
      </div>

      ${metaBlock}

      ${o.employee_id ? `
        <div class="avail-customer-pick">
          <span class="avail-customer-pick-body">${escape(o.employee_name || 'Your employee')} was selected by the customer.</span>
        </div>
        <div class="avail-actions avail-actions-single">
          <button class="btn-accept" data-accept="${escapeAttr(o.id)}" ${isBusy ? 'disabled' : ''}>
            ${isBusy ? '…' : '✓ Accept'}
          </button>
        </div>
      ` : `
        <div class="avail-actions">
          <button class="btn-accept" data-accept="${escapeAttr(o.id)}" ${isBusy ? 'disabled' : ''}>
            ${isBusy ? '…' : '✓ Accept'}
          </button>
          <button class="btn-reject" data-reject="${escapeAttr(o.id)}" ${isBusy ? 'disabled' : ''}>
            Reject
          </button>
        </div>
      `}
    </article>`;
}

// ─── Actions ───────────────────────────────────────────────────────
async function doAccept(svcId) {
  if (_busy.has(svcId)) return;
  _busy.add(svcId);
  setRowDisabled(svcId, true);

  if (!navigator.onLine) {
    await queueAction({
      entity_type: 'service',
      entity_id:   svcId,
      action_type: 'accept',
    });
    removeLocal(svcId);
    toast('Saved — will accept when back online');
    _busy.delete(svcId);
    return;
  }

  try {
    const res = await api.patch(`/tl/jobs/${svcId}/accept`);
    toast(res?.message || 'Job accepted', 'success');
    removeLocal(svcId);
    // Accepted job moves out of Available and into My Jobs — refresh
    // both badges so the user sees the count tick over immediately.
    window.refreshBadges?.();
  } catch (e) {
    if (e.status === 409 || e.code === 'already_taken') {
      toast('Already taken by another team leader');
      removeLocal(svcId);
    } else if (e.code === 'network' || e.status === 0) {
      await queueAction({
        entity_type: 'service',
        entity_id:   svcId,
        action_type: 'accept',
      });
      removeLocal(svcId);
      toast('Saved — will accept when reconnected');
    } else {
      toast(e.message || 'Could not accept', 'error');
      setRowDisabled(svcId, false);
    }
  } finally {
    _busy.delete(svcId);
  }
}

async function doReject(svcId) {
  if (_busy.has(svcId)) return;
  if (!confirm('Reject this order? It will disappear from your list.')) return;
  _busy.add(svcId);
  setRowDisabled(svcId, true);

  // Optimistic remove — feels instant
  removeLocal(svcId);

  if (!navigator.onLine) {
    await queueAction({
      entity_type: 'service',
      entity_id:   svcId,
      action_type: 'reject',
    });
    toast('Saved — will reject when back online');
    _busy.delete(svcId);
    return;
  }

  try {
    await api.patch(`/tl/jobs/${svcId}/reject`);
    toast('Order rejected');
  } catch (e) {
    if (e.code === 'network' || e.status === 0) {
      await queueAction({
        entity_type: 'service',
        entity_id:   svcId,
        action_type: 'reject',
      });
      toast('Saved — will reject when reconnected');
    } else {
      toast(e.message || 'Reject failed; refreshing list', 'error');
      await refresh();
    }
  } finally {
    _busy.delete(svcId);
  }
}

// ─── DOM helpers ───────────────────────────────────────────────────
function removeLocal(svcId) {
  _items = _items.filter((o) => o.id !== svcId);
  state.set('availableCount', _items.length);
  updateBadge(_items.length);
  dbReplaceAll('available_cache', _items).catch(() => {});
  paintList();
}

function setRowDisabled(svcId, disabled) {
  const card = _root.querySelector(`[data-id="${cssEscape(svcId)}"]`);
  if (!card) return;
  card.querySelectorAll('button').forEach((b) => { b.disabled = disabled; });
  const accept = card.querySelector('[data-accept]');
  if (accept) accept.textContent = disabled ? '…' : '✓ Accept';
}

// ─── Format helpers ────────────────────────────────────────────────
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
  if (document.getElementById('avail-tab-css')) return;
  const css = `
    .avail-card {
      padding: 16px;
      position: relative;
      overflow: hidden;
    }
    /* Left accent stripe to match jobs cards' visual language */
    .avail-card::before {
      content: '';
      position: absolute;
      top: 0; left: 0; bottom: 0;
      width: 3px;
      background: var(--accent);
    }

    /* Header zone — serial + identity + price/age */
    .avail-header {
      display: grid;
      grid-template-columns: auto 1fr auto;
      column-gap: 12px;
      align-items: start;
      margin-bottom: 10px;
    }
    .avail-id { min-width: 0; }
    .avail-plate {
      font-family: var(--font-display);
      font-weight: 800;
      font-size: 22px;
      letter-spacing: 0.02em;
      color: var(--ink);
      line-height: 1.05;
      word-break: break-word;
    }
    .avail-plate-svc {
      /* Legacy — service used to fill the big slot. */
      font-size: 18px;
      letter-spacing: -0.01em;
      font-weight: 700;
    }
    .avail-plate-num {
      /* Order-number identity */
      font-family: var(--font-display);
      font-size: 22px;
      font-weight: 800;
      letter-spacing: -0.01em;
      color: var(--ink);
    }
    .avail-sub {
      font-size: 12px;
      color: var(--ink-mute);
      margin-top: 3px;
      font-weight: 600;
    }
    .avail-header-right {
      text-align: right;
      display: flex;
      flex-direction: column;
      align-items: flex-end;
      gap: 4px;
    }
    .avail-price {
      font-family: var(--font-display);
      font-weight: 700;
      font-size: 18px;
      color: var(--accent);
      letter-spacing: -0.01em;
    }
    /* Live waiting-time label — ticks once per second in the SAME spot
     * the old static "N min ago" sat. The ticker updates data-state to
     * one of calm / warn / urgent; the CSS picks the colour from there.
     * Tabular figures so digits don't jitter as 1 swaps with 2 etc. */
    .avail-age {
      font-size: 12px;
      font-weight: 700;
      color: var(--ink-mute);
      font-variant-numeric: tabular-nums;
      font-feature-settings: 'tnum';
      letter-spacing: 0.02em;
      transition: color .2s;
    }
    .avail-age[data-state="calm"]   { color: var(--ink-mute); }
    .avail-age[data-state="warn"]   { color: var(--warn, var(--gold)); }
    .avail-age[data-state="urgent"] {
      color: var(--bad);
      animation: avail-age-pulse 1.6s ease-in-out infinite;
    }
    @keyframes avail-age-pulse {
      0%, 100% { opacity: 1; }
      50%      { opacity: .55; }
    }
    @media (prefers-reduced-motion: reduce) {
      .avail-age { animation: none !important; }
    }

    .avail-actions {
      display: flex; gap: 8px;
      margin-top: 12px;
    }
    /* Single-action variant — Accept takes the full row when there's no
     * Reject button (customer-with-employee orders). */
    .avail-actions-single .btn-accept { flex: 1; padding: 14px; }

    /* Hint card shown above Accept on customer-with-employee orders, so
     * the TL knows why Reject isn't offered. */
    .avail-customer-pick {
      margin-top: 12px;
      padding: 10px 12px;
      background: var(--accent-pale, rgba(14,165,233,0.07));
      border-left: 3px solid var(--accent);
      border-radius: var(--r-sm);
    }
    .avail-customer-pick-body {
      font-size: 13px;
      color: var(--ink-soft);
      line-height: 1.4;
    }

    .btn-accept, .btn-reject {
      border: none;
      padding: 12px 14px;
      border-radius: var(--r-md);
      font-weight: 700;
      font-size: 14px;
      letter-spacing: 0.02em;
      transition: transform .1s, opacity .15s;
    }
    .btn-accept {
      flex: 2;
      background: var(--accent);
      color: #fff;
    }
    .btn-accept:active { transform: scale(.98); background: var(--accent-soft); }
    .btn-reject {
      flex: 1;
      background: var(--bg-soft);
      color: var(--ink-soft);
      border: 1px solid var(--line);
    }
    .btn-reject:active { transform: scale(.98); }
    .btn-accept:disabled, .btn-reject:disabled { opacity: .5; }

    /* ─── Desktop: 2-column grid (≥ 1000 px) ─────────────────────── */
    @media (min-width: 1000px) {
      #avail-list {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 14px;
      }
      #avail-list > .empty-state,
      #avail-list > .loading {
        grid-column: 1 / -1;
      }
    }
    @media (min-width: 1500px) {
      #avail-list { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    }
  `;
  const style = document.createElement('style');
  style.id = 'avail-tab-css';
  style.textContent = css;
  document.head.appendChild(style);
}