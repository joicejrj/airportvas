import { initSync, queueStatusChange, syncQueue } from './pwa/sync-engine.js';
import { dbGetAll, dbPut, dbGetByIndex } from './pwa/indexeddb/db.js';

let jobs   = [];
let timers = {};

async function boot() {
  await initSync();
  await loadJobs();
  setupNetwork();
  setupSync();
  setInterval(loadJobs, 15_000);
}

async function loadJobs() {
  try {
    if (navigator.onLine) {
      const r = await fetch('./api/provider/jobs', {
        headers: { Authorization: `Bearer ${getToken()}` }
      });
      if (r.ok) {
        const d = await r.json();
        jobs = d.data ?? [];
        for (const j of jobs) await dbPut('job_cache', j);
      }
    } else {
      jobs = await dbGetAll('job_cache');
    }
  } catch {
    jobs = await dbGetAll('job_cache');
  }
  renderJobs();
  loadStats();
}

function renderJobs() {
  const activeStatuses = ['assigned', 'accepted', 'in_progress'];
  const activeJobs     = jobs.filter(j => activeStatuses.includes(j.status));
  const container      = document.getElementById('activeJobs');

  if (!activeJobs.length) {
    container.innerHTML = `
      <div class="empty-state">
        <div class="empty-icon">🟢</div>
        <div style="font-size:16px;font-weight:600;color:var(--text2);">No active jobs</div>
        <div style="font-size:14px;margin-top:6px;">You're up to date!</div>
      </div>`;
    return;
  }

  container.innerHTML = activeJobs.map(j => jobCard(j)).join('');
  activeJobs.filter(j => j.status === 'in_progress').forEach(j => startTimer(j));
}

function jobCard(j) {
  const actions = (() => {
    switch (j.status) {
      case 'assigned':
        return `<div class="job-actions">
          <button class="btn-accept" onclick="acceptJob('${j.id}', ${j.version})">✓ Accept</button>
          <button class="btn-reject" onclick="rejectJob('${j.id}', ${j.version})">Reject</button>
        </div>`;
      case 'accepted':
        return `<div class="job-actions">
          <button class="btn-start" onclick="startJob('${j.id}', ${j.version})">▶ Start</button>
        </div>`;
      case 'in_progress':
        return `
          <div class="job-timer" id="timer-${j.id}">⏱ 00:00</div>
          <div class="job-actions">
            <button class="btn-complete" onclick="completeJob('${j.id}', ${j.version})">✓ Complete</button>
          </div>`;
      default:
        return '';
    }
  })();

  return `
    <div class="job-card" id="job-${j.id}">
      <div class="job-header">
        <div>
          <div class="job-plate">${j.vehicle_plate ?? j.order?.vehicle_plate ?? '—'}</div>
          <div class="job-svc-name">${j.service_name ?? '—'}</div>
        </div>
        <span class="status-chip sc-${j.status}">${j.status.replace('_', ' ')}</span>
      </div>
      <div class="job-location">
        📍 ${j.location_details ?? 'Location not specified'}
      </div>
      ${actions}
    </div>`;
}

function startTimer(job) {
  if (timers[job.id]) return;
  const startedAt = job.started_at ? new Date(job.started_at) : new Date();
  timers[job.id] = setInterval(() => {
    const elapsed = Math.floor((Date.now() - startedAt) / 1000);
    const m = String(Math.floor(elapsed / 60)).padStart(2, '0');
    const s = String(elapsed % 60).padStart(2, '0');
    const el = document.getElementById(`timer-${job.id}`);
    if (el) el.innerHTML = `⏱ ${m}:${s}`;
    else clearInterval(timers[job.id]);
  }, 1000);
}

async function changeStatus(svcId, status, version) {
  await queueStatusChange(svcId, status, version);
  const job = jobs.find(j => j.id === svcId);
  if (job) {
    job.status  = status;
    job.version = (version || 1) + 1;
    await dbPut('job_cache', job);
  }
  renderJobs();
}

window.acceptJob   = (id, v) => changeStatus(id, 'accepted', v);
window.rejectJob   = (id, v) => changeStatus(id, 'rejected', v);
window.startJob    = (id, v) => changeStatus(id, 'in_progress', v);
window.completeJob = (id, v) => {
  clearInterval(timers[id]);
  delete timers[id];
  changeStatus(id, 'completed', v);
};

function loadStats() {
  const completed = jobs.filter(j => j.status === 'completed');
  const active    = jobs.filter(j => ['assigned','accepted','in_progress'].includes(j.status));
  const today     = completed.filter(j => j.completed_at?.startsWith(new Date().toISOString().slice(0, 10)));
  document.getElementById('statToday').textContent  = today.length;
  document.getElementById('statTotal').textContent  = completed.length;
  document.getElementById('statActive').textContent = active.length;
}

window.toggleAvailability = () => {
  const pill = document.getElementById('providerStatus');
  const isBusy = pill.classList.toggle('busy');
  pill.textContent = isBusy ? '● Busy' : '● Available';
};

window.switchTab = (tab) => {
  document.querySelectorAll('.tab').forEach((t, i) => {
    const names = ['active', 'history', 'stats'];
    t.classList.toggle('active', names[i] === tab);
  });
  document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
  document.getElementById(`view-${tab}`)?.classList.add('active');

  if (tab === 'history') {
    const container = document.getElementById('historyJobs');
    const done = jobs.filter(j => ['completed', 'cancelled'].includes(j.status));
    container.innerHTML = done.length
      ? done.map(j => `
          <div class="job-card" style="opacity:.7;">
            <div class="job-header">
              <div>
                <div class="job-plate">${j.vehicle_plate ?? '—'}</div>
                <div class="job-svc-name">${j.service_name ?? '—'}</div>
              </div>
              <span class="status-chip sc-${j.status}">${j.status}</span>
            </div>
            <div class="job-location" style="margin-top:8px;">
              Completed: ${j.completed_at ? new Date(j.completed_at).toLocaleString() : '—'}
            </div>
          </div>`).join('')
      : '<div class="empty-state"><div class="empty-icon">📋</div><div>No history yet</div></div>';
  }
};

function setupNetwork() {
  const update = () => {
    document.getElementById('offlineBanner').classList.toggle('show', !navigator.onLine);
  };
  window.addEventListener('online', () => { update(); loadJobs(); });
  window.addEventListener('offline', update);
  update();
}

function setupSync() {
  window.addEventListener('sync:complete', loadJobs);
}

function getToken() {
  return localStorage.getItem('ap_token') ?? '';
}

boot();