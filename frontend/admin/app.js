// frontend/admin/app.js
// Orchestrator for the admin panel.

import * as api from './api.js';
import { toast } from './utils.js';

// ─── Auth ──────────────────────────────────────────────────────────
api.on('logout', () => showLogin());

// Block mouse-wheel value changes on number inputs. See the team-leader
// app.js for the rationale. Same protection for amount fields in the
// admin handover modals, employee forms, etc.
document.addEventListener('wheel', (e) => {
  const t = e.target;
  if (t && t.tagName === 'INPUT' && t.type === 'number' && t === document.activeElement) {
    t.blur();
  }
}, { passive: true });

const elLogin   = document.getElementById('loginScreen');
const elSidebar = document.getElementById('sidebar');
const elMain    = document.getElementById('mainContent');

function showLogin() {
  elLogin.classList.add('active');
  elSidebar.style.display = 'none';
  elMain.style.display    = 'none';
}
function showApp(user) {
  elLogin.classList.remove('active');
  elSidebar.style.display = 'flex';
  elMain.style.display    = 'flex';
  document.getElementById('adminName').textContent = user.name || 'Admin';
  document.getElementById('adminAvatar').textContent =
    (user.name || 'A').slice(0, 1).toUpperCase();
}

async function tryAutoLogin() {
  if (!api.getToken()) return false;
  try {
    const me = await api.get('/auth/me');
    const u = me?.data;
    if (!u || u.role !== 'admin') { api.clearToken(); return false; }
    showApp(u);
    return true;
  } catch {
    api.clearToken();
    return false;
  }
}

document.getElementById('loginBtn').addEventListener('click', doLogin);
document.getElementById('loginEmail').addEventListener('keydown', (e) => {
  if (e.key === 'Enter') document.getElementById('loginPass').focus();
});
document.getElementById('loginPass').addEventListener('keydown', (e) => {
  if (e.key === 'Enter') doLogin();
});

async function doLogin() {
  const email = document.getElementById('loginEmail').value.trim();
  const pwd   = document.getElementById('loginPass').value;
  const err   = document.getElementById('loginError');
  const btn   = document.getElementById('loginBtn');
  err.textContent = '';
  if (!email || !pwd) { err.textContent = 'Enter email and password'; return; }

  btn.disabled = true; btn.textContent = 'Signing in…';
  try {
    const r = await api.post('/auth/login', { email, password: pwd });
    if (r.user?.role !== 'admin') {
      err.textContent = `This is a ${r.user?.role} account. Use the ${r.user?.role} portal.`;
      return;
    }
    api.setToken(r.token);
    showApp(r.user);
    activatePage(window._activePage || 'dashboard');
  } catch (ex) {
    err.textContent = ex.message || 'Sign in failed';
  } finally {
    btn.disabled = false; btn.textContent = 'Sign in';
  }
}

document.getElementById('logoutBtn').addEventListener('click', async () => {
  try { await api.post('/auth/logout', {}); } catch {}
  api.clearToken();
  location.reload();
});

// ─── Page routing (lazy modules) ───────────────────────────────────
const pageTitles = {
  'dashboard':         'Dashboard',
  'orders':            'Orders',
  'team-leaders':      'Team Leaders',
  'employees':         'Employees',
  'handovers':         'Payment Handovers',
  'handovers-history': 'Handover History',
  'payments':          'Payments',
  'reports':           'Reports',
  'services':          'Services',
  'locations':         'Parking Locations',
};

const loaders = {
  'dashboard':         () => import('./pages/dashboard.js'),
  'orders':            () => import('./pages/orders.js'),
  'team-leaders':      () => import('./pages/team-leaders.js'),
  'employees':         () => import('./pages/employees.js'),
  'handovers':         () => import('./pages/handovers.js'),
  'handovers-history': () => import('./pages/handovers-history.js'),
  'payments':          () => import('./pages/payments.js?v=2026-05-18b'),
  'reports':           () => import('./pages/reports.js'),
  'services':          () => import('./pages/services.js'),
  'locations':         () => import('./pages/locations.js'),
};
const loaded = {};
const modules = {};

async function activatePage(name) {
  window._activePage = name;
  document.querySelectorAll('.page').forEach((p) => {
    p.classList.toggle('active', p.id === 'page-' + name);
  });
  document.querySelectorAll('.nav-item').forEach((n) => {
    n.classList.toggle('active', n.dataset.page === name);
  });
  document.getElementById('pageTitle').textContent = pageTitles[name] || name;

  if (!loaders[name]) return;
  if (!loaded[name]) {
    try {
      modules[name] = await loaders[name]();
      loaded[name] = true;
      if (typeof modules[name].init === 'function') {
        await modules[name].init(document.getElementById('page-' + name));
      }
    } catch (e) {
      console.error('Page load failed:', name, e);
      document.getElementById('page-' + name).innerHTML =
        `<div class="panel"><div class="panel-body" style="padding:32px;text-align:center;color:var(--muted)">
          <div style="font-family:var(--font-head);font-size:32px;margin-bottom:6px">!</div>
          <div>This page is not available yet.</div>
        </div></div>`;
    }
  } else if (typeof modules[name]?.onShow === 'function') {
    modules[name].onShow();
  }
}

document.querySelectorAll('.nav-item[data-page]').forEach((item) => {
  item.addEventListener('click', () => activatePage(item.dataset.page));
});

document.getElementById('refreshBtn').addEventListener('click', () => {
  const name = window._activePage;
  if (modules[name]?.refresh)      modules[name].refresh();
  else if (modules[name]?.onShow)  modules[name].onShow();
  toast('Refreshed');
});

// ─── Boot ──────────────────────────────────────────────────────────
(async function boot() {
  const ok = await tryAutoLogin();
  if (ok) activatePage('dashboard');
  else    showLogin();
})();

// Expose toast on window so pages can use it
window.toast = toast;