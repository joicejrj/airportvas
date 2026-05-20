// frontend/team-leader/app.js
//
// Main orchestrator. Responsibilities:
//   • Boot the service worker
//   • Decide login vs main shell on load
//   • Wire login form, top-bar info, bottom-nav tab switching
//   • Online/offline indicator
//   • Lazy-load each tab module on first activation
//
// The 5 tab modules are imported lazily so first paint stays fast.

import * as api    from './api.js';
import * as state  from './state.js';
import * as sync   from './pwa/sync-engine.js';
import { kvSet, kvGet } from './pwa/db.js';

// ─── 1. Service worker registration ─────────────────────────────────
if ('serviceWorker' in navigator) {
  // Unregister stale SWs from sibling apps (admin, customer, etc.)
  navigator.serviceWorker.getRegistrations().then((regs) => {
    for (const r of regs) {
      if (!r.scope.includes('/team-leader/')) r.unregister().catch(() => {});
    }
  });
  navigator.serviceWorker.register('/team-leader/sw.js', { scope: '/team-leader/' })
    .catch((e) => console.warn('[sw] register failed:', e));
}

// ─── 2. Online/offline indicator ────────────────────────────────────
const netDot = () => document.getElementById('net-dot');
function setOnline(online) {
  state.set('online', online);
  netDot()?.classList.toggle('offline', !online);
  netDot()?.setAttribute('title', online ? 'Online' : 'Offline — actions will sync later');

  // Slim offline banner across the top
  let banner = document.getElementById('offline-banner');
  if (!online) {
    if (!banner) {
      banner = document.createElement('div');
      banner.id = 'offline-banner';
      banner.textContent = '⚡ Offline — your work is saved locally and will sync when reconnected.';
      document.body.appendChild(banner);
    }
    banner.classList.add('show');
  } else if (banner) {
    banner.classList.remove('show');
  }
}
setOnline(navigator.onLine);
window.addEventListener('online',  () => setOnline(true));
window.addEventListener('offline', () => setOnline(false));

// ─── Block mouse-wheel value changes on number inputs ──────────────
// By default browsers increment/decrement an <input type="number"> when
// the user scrolls a wheel while the input is focused. This causes
// surprise edits (open the Record Payment modal → focus the Cash
// input → scroll down to see the Sum row → the Cash value silently
// changes). We blur the input on wheel so the value is stable, while
// still letting the wheel event bubble up so the modal/page can scroll
// normally. Captures globally so it works for every form in every tab
// (record-pay modal, split rows, new-order wizard, etc).
document.addEventListener('wheel', (e) => {
  const t = e.target;
  if (t && t.tagName === 'INPUT' && t.type === 'number' && t === document.activeElement) {
    t.blur();
  }
}, { passive: true });

// ─── 3. Auth flow ───────────────────────────────────────────────────
api.on('logout', () => {
  showLogin();
});

async function tryAutoLogin() {
  if (!api.getToken()) return false;
  try {
    const me = await api.get('/auth/me');
    state.set('user', me?.data || null);
    await kvSet('user', me?.data || null);
    return true;
  } catch (e) {
    api.clearToken();
    return false;
  }
}

function showLogin() {
  document.getElementById('login-screen').classList.add('active');
  document.getElementById('app-shell').classList.remove('active');
  // Stop polling — no user, nothing to count
  stopBadgePolling();
}

function showApp() {
  document.getElementById('login-screen').classList.remove('active');
  document.getElementById('app-shell').classList.add('active');

  const u = state.get('user');
  if (u) {
    document.getElementById('service-chip').textContent =
      u.service_name || 'No service';
  }
  // Activate the default tab
  activateTab(state.get('activeTab') || 'available');

  // Kick off the badge poller. The TL nav-bar badges (`#badge-available`
  // and `#badge-jobs`) are kept in sync against the server every 30s
  // regardless of which tab is currently active, so the user always
  // sees an accurate count of work waiting.
  startBadgePolling();
}

// ─── Badge polling ────────────────────────────────────────────────
// Live updates for the left-nav "available" + "jobs" badges. Without
// this poller the badges only updated when the user landed on their
// respective tab — meaning the counts went stale the moment the user
// switched tabs. With this in place a TL can sit on Profile, accept a
// payment from an employee, and see the My-Jobs badge tick down right
// away.
const BADGE_POLL_MS = 30_000;
let _badgeTimer = null;

async function refreshBadges() {
  // Don't bother polling when offline or when the document is hidden
  // (background tab). Hidden tabs also wake up via the visibilitychange
  // listener below, which calls refreshBadges() to catch them up.
  if (!navigator.onLine || document.hidden) return;
  try {
    const res = await api.get('/tl/badges');
    const d = res?.data || {};
    setBadge('badge-available', d.available || 0);
    setBadge('badge-jobs',      d.jobs      || 0);
  } catch (e) {
    // Silent: badges aren't worth toast-spamming the user over a
    // transient network blip. They'll catch up on the next tick.
  }
}

function setBadge(id, n) {
  const el = document.getElementById(id);
  if (!el) return;
  if (n > 0) {
    el.textContent = n > 99 ? '99+' : String(n);
    el.hidden = false;
  } else {
    el.hidden = true;
  }
}

function startBadgePolling() {
  stopBadgePolling();
  refreshBadges();
  _badgeTimer = setInterval(refreshBadges, BADGE_POLL_MS);
}
function stopBadgePolling() {
  if (_badgeTimer) { clearInterval(_badgeTimer); _badgeTimer = null; }
}

// When the user comes back to the tab from another window, refresh
// immediately rather than waiting up to 30s for the next poll tick.
document.addEventListener('visibilitychange', () => {
  if (!document.hidden) refreshBadges();
});
// When the network comes back, same — catch up right away.
window.addEventListener('online', refreshBadges);

// Expose for tab modules to call after actions (record payment,
// accept job, etc.) so the badges tick immediately instead of
// waiting for the next poll.
window.refreshBadges = refreshBadges;

document.getElementById('login-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const email = document.getElementById('li-email').value.trim();
  const pwd   = document.getElementById('li-password').value;
  const submit = document.getElementById('li-submit');
  const err = document.getElementById('li-err');
  err.textContent = '';
  submit.disabled = true;
  submit.textContent = 'Signing in…';
  try {
    const resp = await api.post('/auth/login', { email, password: pwd });
    if (resp?.user?.role !== 'team_leader') {
      throw new Error('This portal is for team leaders only.');
    }
    api.setToken(resp.token);
    state.set('user', resp.user);
    await kvSet('user', resp.user);
    showApp();
  } catch (ex) {
    err.textContent = ex.message || 'Sign in failed';
  } finally {
    submit.disabled = false;
    submit.textContent = 'Sign in';
  }
});

// ─── 4. Tab routing ─────────────────────────────────────────────────
const tabLoaders = {
  available: () => import('./components/available-tab.js'),
  jobs:      () => import('./components/jobs-tab.js?v=2026-05-18f'),
  'new-order': () => import('./components/new-order-tab.js'),
  handover:  () => import('./components/handover-tab.js'),
  profile:   () => import('./components/profile-tab.js?v=2026-05-18e'),
};
const tabLoaded = {};
const tabMods   = {};

async function activateTab(name) {
  const previous = state.get('activeTab');

  // Notify the outgoing tab so it can stop timers etc.
  if (previous && previous !== name && tabMods[previous]
      && typeof tabMods[previous].onHide === 'function') {
    try { tabMods[previous].onHide(); } catch (e) { console.error(e); }
  }

  // Highlight nav button
  document.querySelectorAll('.nav-btn').forEach((b) => {
    b.classList.toggle('active', b.dataset.tab === name);
  });
  // Show pane
  document.querySelectorAll('.tab-pane').forEach((p) => {
    p.classList.toggle('active', p.id === 'tab-' + name);
  });
  state.set('activeTab', name);

  // Lazy-load
  if (!tabLoaded[name]) {
    try {
      tabMods[name] = await tabLoaders[name]();
      tabLoaded[name] = true;
      if (typeof tabMods[name].init === 'function') {
        await tabMods[name].init(document.getElementById('tab-' + name));
      }
    } catch (e) {
      console.error('Tab load failed:', name, e);
      document.getElementById('tab-' + name).innerHTML =
        '<div class="empty-state"><div class="empty-state-glyph">!</div>' +
        '<div class="empty-state-msg">Failed to load this section.</div></div>';
    }
  } else if (typeof tabMods[name]?.onShow === 'function') {
    tabMods[name].onShow();
  }
}

document.querySelectorAll('.nav-btn').forEach((btn) => {
  btn.addEventListener('click', () => activateTab(btn.dataset.tab));
});

// ─── 5. Boot ────────────────────────────────────────────────────────
(async function boot() {
  sync.initSync();

  // Sync engine listeners → toast feedback
  sync.on('conflict', (action, info) => {
    if (action.action_type === 'accept') {
      toast('Order was claimed by another team leader', 'error');
      // Refresh the available list
      if (tabMods.available?.refresh) tabMods.available.refresh();
    }
  });
  sync.on('failed', (action) => {
    toast('Could not sync ' + action.action_type, 'error');
  });

  const ok = await tryAutoLogin();
  if (ok) showApp();
  else    showLogin();
})();

// ─── 6. Toast helper exported via window so tab modules can use it ──
function toast(msg, kind = '') {
  const host = document.getElementById('toast-host');
  const div  = document.createElement('div');
  div.className = 'toast ' + kind;
  div.textContent = msg;
  host.appendChild(div);
  setTimeout(() => {
    div.style.opacity = '0';
    div.style.transition = 'opacity .25s';
    setTimeout(() => div.remove(), 300);
  }, 3500);
}
window.toast = toast;