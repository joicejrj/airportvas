// frontend/team-leader/components/profile-tab.js
//
// Profile tab. Closes out the TL PWA with:
//
//   1. Identity card        — name, service, email, phone
//   2. Today's stats        — orders, revenue, cash/card/online split
//                              (GET /api/tl/stats/today)
//   3. Sync queue inspector — shows pending offline actions, lets the TL
//                              manually trigger a flush. Transparency
//                              matters here: TLs are accountable for cash,
//                              so they should be able to see what hasn't
//                              been synced yet.
//   4. Account              — Version + Sign out
//
// Cache-first paint so the tab is useful even offline.

import * as api    from '../api.js';
import * as state  from '../state.js';
import * as sync   from '../pwa/sync-engine.js';
import { kvGet, kvSet } from '../pwa/db.js';

const CURRENCY = 'AED';
const APP_VERSION = 'v1.0.0';

let _root  = null;
let _stats = null;
let _queue = [];

// ─── Lifecycle ─────────────────────────────────────────────────────
export async function init(root) {
  _root = root;
  injectStyles();
  paintShell();

  // Re-paint on user change (auto-login etc.)
  state.subscribe('user', () => paint());

  // Listen to sync events to refresh the queue list
  sync.on('success',  () => loadQueue());
  sync.on('conflict', () => loadQueue());
  sync.on('failed',   () => loadQueue());

  // Cache-first stats
  try {
    const cached = await kvGet('stats_today');
    if (cached) { _stats = cached; }
  } catch {}

  await loadStats();
  await loadQueue();
}

export async function onShow() {
  await loadStats();
  await loadQueue();
}

// ─── Data ──────────────────────────────────────────────────────────
async function loadStats() {
  if (!navigator.onLine) { paint(); return; }
  try {
    const res = await api.get('/tl/stats/today');
    _stats = res?.data ?? null;
    try { await kvSet('stats_today', _stats); } catch {}
  } catch (e) {
    console.warn('[profile] stats failed:', e.message);
  }
  paint();
}

async function loadQueue() {
  try {
    _queue = await sync.listAll();
  } catch {
    _queue = [];
  }
  paintQueue();
}

// ─── Render ────────────────────────────────────────────────────────
function paintShell() {
  _root.innerHTML = `
    <div id="prof-hero"></div>
    <div id="prof-stats"></div>
    <div id="prof-queue"></div>
    <div id="prof-account"></div>
  `;
  paint();
}

function paint() {
  paintHero();
  paintStats();
  paintQueue();
  paintAccount();
}

function paintHero() {
  const host = _root.querySelector('#prof-hero');
  if (!host) return;
  const u = state.get('user');
  if (!u) {
    host.innerHTML = `
      <div class="card prof-hero"><div class="loading"><div class="spinner"></div>Loading…</div></div>`;
    return;
  }

  const name = u.name || '—';
  const initials = name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w[0])
    .join('')
    .toUpperCase() || '?';

  host.innerHTML = `
    <div class="prof-hero">
      <div class="prof-hero-avatar">${escape(initials)}</div>
      <div class="prof-hero-text">
        <div class="prof-hero-name">${escape(name)}</div>
        <div class="prof-hero-role">
          <span class="prof-chip">Team Leader</span>
          ${u.service_name ? `<span class="prof-chip prof-chip-svc">${escape(u.service_name)}</span>` : ''}
        </div>
      </div>
      <div class="prof-hero-contact">
        ${u.email ? `<a class="prof-contact" href="mailto:${escape(u.email)}" title="${escape(u.email)}">✉ ${escape(u.email)}</a>` : ''}
        ${u.phone ? `<a class="prof-contact tabular" href="tel:${escape(u.phone)}">☎ ${escape(u.phone)}</a>` : ''}
      </div>
    </div>`;
}

function paintStats() {
  const host = _root.querySelector('#prof-stats');
  if (!host) return;

  const date = _stats?.date ? formatDateNice(_stats.date) : 'Today';

  if (!_stats) {
    if (!navigator.onLine) {
      host.innerHTML = `
        <h2 class="prof-section-h">Today</h2>
        <div class="card stats-card stats-offline">
          Offline — connect to load today's stats.
        </div>`;
    } else {
      host.innerHTML = `
        <h2 class="prof-section-h">Today</h2>
        <div class="card stats-card"><div class="loading"><div class="spinner"></div>Loading…</div></div>`;
    }
    return;
  }

  const orders      = parseInt(_stats.orders      || 0, 10);
  const completed   = parseInt(_stats.completed   || 0, 10);
  const inProgress  = parseInt(_stats.in_progress || 0, 10);
  const accepted    = parseInt(_stats.accepted    || 0, 10);
  // revenue here is money actually earned today (completed jobs only).
  // unpaid is money owed for in-flight jobs (accepted + in_progress).
  // The two never overlap, so revenue + unpaid = today's expected
  // total earnings if every in-flight job ends up completed.
  const revenue     = parseFloat(_stats.revenue        || 0);
  const unpaid      = parseFloat(_stats.unpaid_amount  || 0);
  const cash        = parseFloat(_stats.cash_total     || 0);
  const card        = parseFloat(_stats.card_total     || 0);
  const online      = parseFloat(_stats.online_total   || 0);

  host.innerHTML = `
    <h2 class="prof-section-h">Today · ${escape(date)}</h2>

    <div class="stats-grid">
      <div class="stat-card primary">
        <div class="stat-label">Revenue</div>
        <div class="stat-value tabular">
          <span class="stat-cur">${CURRENCY}</span> ${formatPrice(revenue)}
        </div>
        <div class="stat-sub">Completed jobs</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Orders</div>
        <div class="stat-value tabular">${orders}</div>
      </div>
    </div>

    <div class="stats-breakdown">
      <div class="brk-h">Status breakdown</div>
      <div class="brk-row">
        <span class="tag accepted">Unpaid</span>
        <span class="brk-row-end">
          <span class="brk-amt tabular">${CURRENCY} ${formatPrice(unpaid)}</span>
          <span class="tabular brk-count">${accepted + inProgress}</span>
        </span>
      </div>
      <div class="brk-row">
        <span class="tag completed">Paid</span>
        <span class="brk-row-end">
          <span class="brk-amt tabular">${CURRENCY} ${formatPrice(revenue)}</span>
          <span class="tabular brk-count">${completed}</span>
        </span>
      </div>
    </div>

    <div class="stats-breakdown">
      <div class="brk-h">Payment split</div>
      <div class="brk-row">
        <span class="pay-pill pay-pill-cash">💵 Cash</span>
        <span class="tabular">${CURRENCY} ${formatPrice(cash)}</span>
      </div>
      <div class="brk-row">
        <span class="pay-pill pay-pill-card">💳 Card</span>
        <span class="tabular">${CURRENCY} ${formatPrice(card)}</span>
      </div>
      <div class="brk-row">
        <span class="pay-pill pay-pill-online">🔗 Online</span>
        <span class="tabular">${CURRENCY} ${formatPrice(online)}</span>
      </div>
    </div>
  `;
}

function paintQueue() {
  const host = _root.querySelector('#prof-queue');
  if (!host) return;

  const pending  = _queue.filter((a) => a.sync_status === 'pending');
  const failed   = _queue.filter((a) => a.sync_status === 'failed');
  const conflict = _queue.filter((a) => a.sync_status === 'conflict');
  const showAny  = pending.length || failed.length || conflict.length;

  if (!showAny) {
    host.innerHTML = `
      <div class="sync-chip-ok">
        <span class="sync-tick">✓</span>
        Everything is synced
      </div>`;
    return;
  }

  host.innerHTML = `
    <h2 class="prof-section-h">Sync</h2>
    <div class="card sync-card">
      ${pending.length ? `
        <div class="sync-row">
          <span class="sync-dot pending"></span>
          <span class="sync-text">${pending.length} pending</span>
          <button id="flush-now" class="sync-btn" ${!navigator.onLine ? 'disabled' : ''}>
            ${navigator.onLine ? 'Retry now' : 'Offline'}
          </button>
        </div>` : ''}
      ${conflict.length ? `
        <div class="sync-row">
          <span class="sync-dot conflict"></span>
          <span class="sync-text">${conflict.length} conflict${conflict.length > 1 ? 's' : ''}</span>
          <button class="sync-btn sync-btn-danger" id="discard-conflict">Discard</button>
        </div>` : ''}
      ${failed.length ? `
        <div class="sync-row">
          <span class="sync-dot failed"></span>
          <span class="sync-text">${failed.length} failed</span>
          <button class="sync-btn sync-btn-danger" id="discard-failed">Discard</button>
        </div>` : ''}

      <details class="sync-details">
        <summary>Show items</summary>
        <div class="sync-list">
          ${_queue.slice().sort((a, b) => b.created_at - a.created_at)
            .map(syncItem).join('')}
        </div>
      </details>
    </div>
  `;

  _root.querySelector('#flush-now')?.addEventListener('click', async () => {
    if (!navigator.onLine) return;
    const btn = _root.querySelector('#flush-now');
    btn.disabled = true; btn.textContent = '…';
    await sync.flush();
    await loadQueue();
  });
  _root.querySelector('#discard-conflict')?.addEventListener('click', () => discardByStatus('conflict'));
  _root.querySelector('#discard-failed')?.addEventListener('click', () => discardByStatus('failed'));
}

async function discardByStatus(status) {
  const targets = _queue.filter((a) => a.sync_status === status);
  if (!targets.length) return;
  if (!confirm(`Discard ${targets.length} ${status} action${targets.length > 1 ? 's' : ''}? They will not be retried.`)) return;
  for (const a of targets) {
    try { await sync.discard(a.id); } catch {}
  }
  await loadQueue();
  toast('Discarded');
}

function syncItem(a) {
  const created = new Date(a.created_at).toLocaleTimeString('en-GB', {
    hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Dubai',
  });
  const labels = {
    accept:      'Accept',
    reject:      'Reject',
    start:       'Start',
    complete:    'Complete',
    direct_book: 'New order',
  };
  return `
    <div class="sync-item">
      <span class="sync-item-action">${escape(labels[a.action_type] || a.action_type)}</span>
      <span class="sync-item-target">${escape(shortId(a.entity_id))}</span>
      <span class="sync-item-time">${created}</span>
      <span class="sync-item-status status-${escape(a.sync_status)}">${escape(a.sync_status)}${a.retry_count ? ' · try ' + a.retry_count : ''}</span>
    </div>`;
}

function shortId(id) {
  if (!id) return '—';
  return String(id).slice(0, 8) + '…';
}

function paintAccount() {
  const host = _root.querySelector('#prof-account');
  if (!host) return;

  host.innerHTML = `
    <button id="logout-btn" class="logout-btn">Sign out</button>
    <div class="prof-footer">AirVAS ${APP_VERSION}</div>
  `;

  document.getElementById('logout-btn').addEventListener('click', signOut);
}

async function signOut() {
  // Warn if there are pending actions
  const pending = _queue.filter((a) => a.sync_status === 'pending').length;
  if (pending > 0) {
    if (!confirm(`You have ${pending} pending sync action${pending > 1 ? 's' : ''}. Sign out anyway?`)) return;
  }
  try { await api.post('/auth/logout', {}); } catch {}
  api.clearToken();
  // Clear caches for safety
  try {
    const dbReq = indexedDB.deleteDatabase('airvas-tl');
    dbReq.onsuccess = () => location.reload();
    dbReq.onerror   = () => location.reload();
    // Fallback timeout in case the request hangs
    setTimeout(() => location.reload(), 500);
  } catch {
    location.reload();
  }
}

// ─── Helpers ───────────────────────────────────────────────────────
function formatPrice(p) {
  const n = parseFloat(p);
  return Number.isFinite(n) ? n.toFixed(2) : '0.00';
}
function formatDateNice(s) {
  if (!s) return '';
  if (s.length === 10) {
    const [y, m, d] = s.split('-').map(Number);
    return new Date(Date.UTC(y, m - 1, d)).toLocaleDateString('en-GB', {
      day: '2-digit', month: 'short',
    });
  }
  return s;
}
function escape(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
  ));
}
function toast(msg, kind = '') {
  if (window.toast) window.toast(msg, kind);
  else console.log('[toast]', kind, msg);
}

// ─── Component-scoped CSS ──────────────────────────────────────────
function injectStyles() {
  if (document.getElementById('profile-tab-css')) return;
  const css = `
    .prof-section-h {
      font-family: var(--font-display);
      font-weight: 700;
      font-size: 13px;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      margin: 18px 4px 8px;
      color: var(--ink-mute);
    }

    /* ─── Hero card ─────────────────────────────────────────────── */
    .prof-hero {
      display: grid;
      grid-template-columns: 64px 1fr;
      grid-template-rows: auto auto;
      grid-template-areas:
        "ava text"
        "contact contact";
      gap: 4px 14px;
      align-items: center;
      padding: 18px 18px 16px;
      background: var(--bg-elev);
      border: 1px solid var(--line);
      border-radius: var(--r-lg, 14px);
      margin-bottom: 6px;
    }
    .prof-hero-avatar {
      grid-area: ava;
      width: 64px; height: 64px;
      border-radius: 50%;
      background:
        radial-gradient(120% 100% at top right, rgba(255,255,255,.18), transparent 60%),
        var(--accent);
      color: #fff;
      font-family: var(--font-display);
      font-size: 24px;
      font-weight: 800;
      letter-spacing: -0.01em;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 2px 6px rgba(14, 165, 233, .25);
    }
    .prof-hero-text { grid-area: text; min-width: 0; }
    .prof-hero-name {
      font-family: var(--font-display);
      font-size: 20px;
      font-weight: 800;
      letter-spacing: -0.02em;
      color: var(--ink);
      line-height: 1.15;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .prof-hero-role {
      margin-top: 6px;
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
    }
    .prof-chip {
      display: inline-block;
      padding: 3px 10px;
      border-radius: 999px;
      font-size: 10px;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      background: var(--accent-pale);
      color: var(--accent);
    }
    .prof-chip-svc {
      background: var(--bg);
      color: var(--ink-soft);
      border: 1px solid var(--line);
    }
    .prof-hero-contact {
      grid-area: contact;
      display: flex;
      flex-wrap: wrap;
      gap: 6px 18px;
      margin-top: 10px;
      padding-top: 12px;
      border-top: 1px dotted var(--line);
    }
    .prof-contact {
      font-size: 12px;
      color: var(--ink-soft);
      text-decoration: none;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      max-width: 100%;
    }
    .prof-contact:active { color: var(--accent); }

    /* Legacy classes — preserved in case any external styles hook them */
    .prof-card { padding: 16px 18px; }
    .prof-row {
      display: flex; justify-content: space-between; align-items: baseline;
      gap: 12px;
      padding: 8px 0;
      border-bottom: 1px dotted var(--line);
      font-size: 14px;
    }
    .prof-row:last-child { border-bottom: none; }
    .prof-row .label { flex: 0 0 auto; font-size: 11px; }
    .prof-val { color: var(--ink); text-align: right; }

    /* ─── Stats ─────────────────────────────────────────────────── */
    .stats-grid {
      display: grid;
      grid-template-columns: 2fr 1fr;
      gap: 10px;
      margin-bottom: 12px;
    }
    .stat-card {
      background: var(--bg-elev);
      border: 1px solid var(--line);
      border-radius: var(--r-md);
      padding: 16px 18px;
    }
    .stat-card.primary {
      background:
        radial-gradient(120% 100% at top right, rgba(255,255,255,.16), transparent 60%),
        var(--accent);
      border-color: var(--accent);
      color: #fff;
    }
    .stat-card.primary .stat-label { color: rgba(255,255,255,.7); }
    .stat-card.primary .stat-value { color: #fff; }
    .stat-card.primary .stat-cur   { color: rgba(255,255,255,.7); }
    /* Same treatment for the subtitle (e.g. "Completed jobs" under
     * the Revenue total) — without this it inherits the global
     * .stat-sub color which is dark gray and becomes unreadable on
     * the blue gradient. */
    .stat-card.primary .stat-sub   { color: rgba(255,255,255,.7); }
    .stat-label {
      font-size: 10px;
      letter-spacing: 0.14em;
      text-transform: uppercase;
      font-weight: 700;
      color: var(--ink-mute);
      margin-bottom: 6px;
    }
    .stat-value {
      font-family: var(--font-display);
      font-weight: 800;
      font-size: 28px;
      line-height: 1.05;
      letter-spacing: -0.02em;
      color: var(--ink);
    }
    .stat-cur {
      font-size: 0.55em;
      font-weight: 700;
      color: var(--ink-mute);
      letter-spacing: 0;
      margin-right: 2px;
    }

    .stats-breakdown {
      background: var(--bg-elev);
      border: 1px solid var(--line);
      border-radius: var(--r-md);
      padding: 12px 16px;
      margin-bottom: 10px;
    }
    .brk-h {
      font-size: 10px;
      letter-spacing: 0.14em;
      text-transform: uppercase;
      font-weight: 700;
      color: var(--ink-mute);
      margin-bottom: 6px;
    }
    .brk-row {
      display: flex; justify-content: space-between; align-items: center;
      padding: 5px 0;
      font-size: 13px;
      border-bottom: 1px dotted var(--line);
    }
    .brk-row:last-child { border-bottom: none; }

    /* Right side of a brk-row: amount + count chip, both right-aligned.
     * Used in the Status Breakdown block so each row shows both
     * "how much" and "how many" without crowding. */
    .brk-row-end {
      display: inline-flex;
      align-items: baseline;
      gap: 10px;
    }
    .brk-amt {
      font-weight: 600;
      color: var(--ink);
    }
    .brk-count {
      min-width: 22px;
      padding: 1px 8px;
      background: var(--bg-soft);
      color: var(--ink-mute);
      border-radius: 999px;
      font-size: 11px;
      font-weight: 700;
      text-align: center;
    }

    /* Subtle subtitle below a stat card value (e.g. "Completed jobs"
     * under the Revenue total). Keeps the headline number prominent. */
    .stat-sub {
      font-size: 11px;
      color: var(--ink-mute);
      margin-top: 2px;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    .pay-pill {
      display: inline-block;
      font-size: 12px;
      font-weight: 600;
    }
    /* Per-method label tints — text-color only (no background or border)
     * so we don't conflict with the global .card panel style. */
    .pay-pill-cash   { color: var(--good); }
    .pay-pill-card   { color: var(--accent); }
    .pay-pill-online { color: var(--gold, #b45309); }

    /* Status-breakdown tags — text only, no box */
    .tag {
      display: inline-block;
      font-size: 12px;
      font-weight: 600;
    }
    .tag.accepted  { color: var(--gold, #b45309); }
    .tag.completed { color: var(--good); }

    .stats-offline {
      padding: 24px;
      text-align: center;
      color: var(--ink-mute);
      font-size: 13px;
    }

    /* ─── Sync inspector ────────────────────────────────────────── */
    /* All-clear: slim inline chip instead of a full card */
    .sync-chip-ok {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      margin: 8px 4px 4px;
      padding: 6px 12px 6px 8px;
      background: var(--good-pale);
      color: var(--good);
      border-radius: 999px;
      font-size: 12px;
      font-weight: 700;
    }
    .sync-chip-ok .sync-tick {
      width: 18px; height: 18px; flex: 0 0 18px;
      border-radius: 50%;
      background: var(--good);
      color: #fff;
      display: flex; align-items: center; justify-content: center;
      font-weight: 900; font-size: 11px;
    }

    .sync-card { padding: 12px 16px; }
    .sync-row {
      display: flex; align-items: center; gap: 10px;
      padding: 6px 0;
      border-bottom: 1px dotted var(--line);
    }
    .sync-row:last-child { border-bottom: none; }
    .sync-dot {
      width: 9px; height: 9px;
      border-radius: 50%;
      flex: 0 0 9px;
    }
    .sync-dot.pending  { background: var(--warn); animation: pulse 1.4s ease-in-out infinite; }
    .sync-dot.conflict { background: var(--bad); }
    .sync-dot.failed   { background: var(--bad); }
    @keyframes pulse {
      0%, 100% { opacity: 1; }
      50%      { opacity: .35; }
    }
    .sync-text {
      flex: 1;
      font-size: 13px;
      font-weight: 600;
      color: var(--ink-soft);
    }
    .sync-btn {
      padding: 6px 12px;
      background: var(--accent);
      color: #fff;
      border: none;
      border-radius: 6px;
      font-size: 12px;
      font-weight: 600;
    }
    .sync-btn:disabled { opacity: .4; }
    .sync-btn-danger { background: var(--bad); }

    .sync-details summary {
      cursor: pointer;
      font-size: 12px;
      font-weight: 600;
      color: var(--accent);
      padding-top: 10px;
      list-style: none;
    }
    .sync-details summary::-webkit-details-marker { display: none; }
    .sync-details summary::before { content: '+ '; font-weight: 700; }
    .sync-details[open] summary::before { content: '− '; }
    .sync-list {
      margin-top: 8px;
      max-height: 240px;
      overflow-y: auto;
    }
    .sync-item {
      display: grid;
      grid-template-columns: 1fr auto auto auto;
      gap: 6px;
      padding: 6px 0;
      font-size: 11px;
      border-top: 1px dotted var(--line);
      align-items: center;
    }
    .sync-item-action {
      font-weight: 700;
      color: var(--ink);
    }
    .sync-item-target {
      color: var(--ink-mute);
      font-family: ui-monospace, monospace;
      font-size: 10px;
    }
    .sync-item-time {
      color: var(--ink-mute);
      font-size: 11px;
    }
    .sync-item-status {
      font-size: 10px;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      font-weight: 700;
      padding: 2px 6px;
      border-radius: 4px;
    }
    .status-pending  { background: var(--warn-pale); color: var(--warn); }
    .status-conflict { background: var(--bad-pale);  color: var(--bad); }
    .status-failed   { background: var(--bad-pale);  color: var(--bad); }
    .status-success  { background: var(--good-pale); color: var(--good); }

    /* ─── Account / footer ──────────────────────────────────────── */
    .logout-btn {
      width: 100%;
      margin-top: 24px;
      padding: 14px;
      background: var(--bg-elev);
      border: 1.5px solid var(--bad);
      border-radius: var(--r-md);
      color: var(--bad);
      font-weight: 700;
      font-size: 14px;
      letter-spacing: 0.02em;
      transition: background .15s;
    }
    .logout-btn:active { background: var(--bad-pale); }
    .prof-footer {
      text-align: center;
      margin-top: 14px;
      padding-bottom: 8px;
      color: var(--ink-faint, var(--ink-mute));
      font-size: 11px;
      letter-spacing: 0.06em;
    }

    /* ─── Desktop layout (≥ 900 px) ──────────────────────────────── */
    /* Single column — the content is naturally narrow (one hero + stats
     * + occasional sync queue), so a two-column split just creates dead
     * space on the left. The main content already centers via the parent
     * <main> max-width of 720px. */
    @media (min-width: 900px) {
      .logout-btn {
        max-width: 280px;
        margin-left: auto;
        margin-right: auto;
        display: block;
      }
    }
  `;
  const s = document.createElement('style');
  s.id = 'profile-tab-css';
  s.textContent = css;
  document.head.appendChild(s);
}