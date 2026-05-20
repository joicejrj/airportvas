// frontend/team-leader/state.js
//
// Minimal observable state. Used by tab modules to subscribe to:
//   • user           — current logged-in user (from /api/auth/me)
//   • online         — boolean network state
//   • activeTab      — which tab is showing
//   • availableCount — counter shown as nav-badge
//   • jobsCount      — counter shown as nav-badge

const _state = {
  user: null,
  online: navigator.onLine,
  activeTab: 'available',
  availableCount: 0,
  jobsCount: 0,
};

const _subs = {};

export function get(key) { return _state[key]; }

export function set(key, value) {
  if (_state[key] === value) return;
  _state[key] = value;
  for (const fn of _subs[key] || []) {
    try { fn(value); } catch (e) { console.error(e); }
  }
}

export function subscribe(key, fn) {
  (_subs[key] ||= []).push(fn);
  // Immediately fire with the current value
  try { fn(_state[key]); } catch {}
  return () => {
    _subs[key] = (_subs[key] || []).filter((f) => f !== fn);
  };
}

// ─── Service-type helpers ────────────────────────────────────────
// The wizard hides vehicle plate + photo fields for porter TLs (no car
// involved) and shows them for everyone else (mainly car wash). These
// helpers centralize that check so it can be tweaked in one place if
// you ever add more services or move to a proper `slug` column.
export function isPorterService() {
  const name = (_state.user?.service_name || '').toLowerCase();
  return name.includes('porter');
}
export function needsVehicleDetails() {
  return !isPorterService();
}