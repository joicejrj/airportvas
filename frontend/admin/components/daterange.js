// admin/components/daterange.js
//
// A reusable date-range popover used by admin filter bars:
//   - Payment Handovers history
//   - Orders
//   - Payments
//   - Reports (all sub-tabs)
//
// Why a component (instead of inlining the markup four times):
//   - Same look + behavior wherever it appears (preset grid + custom inputs)
//   - One place to fix bugs (anchor math, outside-click leak, etc.)
//   - Less CSS duplication (the popover styles live with the component)
//
// API:
//   import { mountDateRange, fmtRangeLabel } from '../components/daterange.js';
//   const ctrl = mountDateRange({
//     anchor:     hostElement,         // where to mount (a flex container is fine)
//     value:      { from: '', to: '' },// initial range
//     onChange:   (range) => { ... },  // called on every change (preset or custom)
//     label:      'Any date',          // optional starting text for the trigger
//     presets:    null,                // optional, override default preset list
//   });
//
// The component returns an object with:
//   - el        : the trigger button (so the caller can place it precisely)
//   - update(v) : re-set the value externally and re-render the label
//   - destroy() : remove popover + document listener (call this when the
//                 page unmounts so we don't leak handlers)
//
// Style note: the popover is mounted in document.body so it always
// escapes overflow-hidden table wrappers and other parents. We track
// the trigger's screen position on open and reposition on scroll so
// it stays anchored.

const DEFAULT_PRESETS = [
  { id: 'today',  label: 'Today'        },
  { id: '7d',     label: 'Last 7 days'  },
  { id: '30d',    label: 'Last 30 days' },
  { id: 'month',  label: 'This month'   },
  { id: 'lmonth', label: 'Last month'   },
  { id: 'any',    label: 'Any date'     },
];

let _injectedCss = false;

export function mountDateRange({ anchor, value = {}, onChange, label, presets } = {}) {
  if (!anchor) throw new Error('mountDateRange: anchor element required');
  ensureCss();

  let _value = { from: value.from || '', to: value.to || '' };
  const _presets = presets || DEFAULT_PRESETS;
  let _pop = null;       // popover element (mounted to body when open)
  let _outsideHandler = null;
  let _scrollHandler  = null;

  // Trigger button
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'dr-trigger';
  btn.setAttribute('aria-haspopup', 'dialog');
  btn.setAttribute('aria-expanded', 'false');
  renderLabel();
  btn.addEventListener('click', (e) => {
    e.stopPropagation();
    if (_pop) close(); else open();
  });
  anchor.appendChild(btn);

  function renderLabel() {
    const text = fmtRangeLabel(_value.from, _value.to, label);
    btn.innerHTML = `<span aria-hidden="true">▤</span> <span class="dr-label">${esc(text)}</span>`;
    if (_value.from || _value.to) btn.classList.add('active');
    else btn.classList.remove('active');
  }

  function open() {
    _pop = document.createElement('div');
    _pop.className = 'dr-pop';
    _pop.innerHTML = `
      <div class="dr-presets">
        ${_presets.map((p) => `<button type="button" data-preset="${p.id}">${esc(p.label)}</button>`).join('')}
      </div>
      <div class="dr-custom">
        <label><span>From</span><input type="date" data-from value="${esc(_value.from)}"></label>
        <label><span>To</span><input type="date" data-to value="${esc(_value.to)}"></label>
      </div>
      <div class="dr-actions">
        <button type="button" data-action="clear">Clear</button>
        <button type="button" data-action="close" class="dr-primary">Done</button>
      </div>`;
    document.body.appendChild(_pop);
    positionPop();
    btn.setAttribute('aria-expanded', 'true');

    // Preset clicks
    _pop.querySelectorAll('[data-preset]').forEach((b) => {
      b.addEventListener('click', () => {
        applyPreset(b.dataset.preset);
        emit();
        renderLabel();
        // Sync the inputs visually
        const fromI = _pop.querySelector('[data-from]');
        const toI   = _pop.querySelector('[data-to]');
        if (fromI) fromI.value = _value.from;
        if (toI)   toI.value   = _value.to;
      });
    });
    // Custom inputs
    _pop.querySelector('[data-from]').addEventListener('change', (e) => {
      _value.from = e.target.value; emit(); renderLabel();
    });
    _pop.querySelector('[data-to]').addEventListener('change', (e) => {
      _value.to = e.target.value; emit(); renderLabel();
    });
    // Footer actions
    _pop.querySelector('[data-action="clear"]').addEventListener('click', () => {
      _value = { from: '', to: '' };
      const fromI = _pop.querySelector('[data-from]');
      const toI   = _pop.querySelector('[data-to]');
      if (fromI) fromI.value = '';
      if (toI)   toI.value   = '';
      emit(); renderLabel();
    });
    _pop.querySelector('[data-action="close"]').addEventListener('click', close);

    // Outside-click & viewport changes
    _outsideHandler = (e) => {
      if (e.target.closest('.dr-pop') || e.target === btn || btn.contains(e.target)) return;
      close();
    };
    _scrollHandler = () => positionPop();
    document.addEventListener('click', _outsideHandler, { capture: true });
    window.addEventListener('scroll', _scrollHandler, { passive: true, capture: true });
    window.addEventListener('resize', _scrollHandler);
  }

  function close() {
    if (!_pop) return;
    _pop.remove();
    _pop = null;
    btn.setAttribute('aria-expanded', 'false');
    if (_outsideHandler) document.removeEventListener('click', _outsideHandler, { capture: true });
    if (_scrollHandler) {
      window.removeEventListener('scroll', _scrollHandler, { capture: true });
      window.removeEventListener('resize', _scrollHandler);
    }
    _outsideHandler = null;
    _scrollHandler  = null;
  }

  function positionPop() {
    if (!_pop) return;
    const rect = btn.getBoundingClientRect();
    const popW = 360;
    const margin = 8;
    let left = rect.left;
    // Don't run off the right edge of the viewport
    if (left + popW > window.innerWidth - margin) {
      left = Math.max(margin, window.innerWidth - popW - margin);
    }
    _pop.style.position = 'fixed';
    _pop.style.top      = (rect.bottom + 6) + 'px';
    _pop.style.left     = left + 'px';
    _pop.style.width    = popW + 'px';
  }

  function applyPreset(id) {
    const today = new Date();
    const fmt = (d) => {
      const y = d.getFullYear();
      const m = String(d.getMonth() + 1).padStart(2, '0');
      const day = String(d.getDate()).padStart(2, '0');
      return `${y}-${m}-${day}`;
    };
    if (id === 'today') {
      _value.from = _value.to = fmt(today);
    } else if (id === '7d') {
      const s = new Date(today); s.setDate(today.getDate() - 6);
      _value.from = fmt(s); _value.to = fmt(today);
    } else if (id === '30d') {
      const s = new Date(today); s.setDate(today.getDate() - 29);
      _value.from = fmt(s); _value.to = fmt(today);
    } else if (id === 'month') {
      const s = new Date(today.getFullYear(), today.getMonth(), 1);
      _value.from = fmt(s); _value.to = fmt(today);
    } else if (id === 'lmonth') {
      const s = new Date(today.getFullYear(), today.getMonth() - 1, 1);
      const e = new Date(today.getFullYear(), today.getMonth(), 0);
      _value.from = fmt(s); _value.to = fmt(e);
    } else { // 'any'
      _value.from = ''; _value.to = '';
    }
  }

  function emit() {
    if (typeof onChange === 'function') {
      onChange({ from: _value.from, to: _value.to });
    }
  }

  return {
    el: btn,
    update(v) {
      _value.from = v.from || '';
      _value.to   = v.to   || '';
      renderLabel();
    },
    getValue() {
      return { from: _value.from, to: _value.to };
    },
    destroy() {
      close();
      btn.remove();
    },
  };
}

// Human-readable label for a date range. Exported for callers that want
// to display the same wording elsewhere (e.g. a result summary line).
export function fmtRangeLabel(from, to, emptyText) {
  if (!from && !to) return emptyText || 'Any date';
  const short = (iso) => {
    if (!iso) return '';
    const [y, m, d] = iso.split('-');
    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return `${parseInt(d, 10)} ${months[parseInt(m, 10) - 1]}`;
  };
  if (from && to && from === to) return short(from);
  if (from && to) return `${short(from)} – ${short(to)}`;
  if (from)       return `From ${short(from)}`;
  return `Until ${short(to)}`;
}

function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
  ));
}

// Inject CSS once per page lifetime
function ensureCss() {
  if (_injectedCss) return;
  _injectedCss = true;
  const css = `
    .dr-trigger {
      font: inherit;
      font-size: 13px;
      padding: 7px 12px;
      border-radius: 8px;
      border: 1px solid var(--border, #e2e8f0);
      background: var(--bg-elev, #fff);
      color: var(--ink, #0f172a);
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      white-space: nowrap;
      font-weight: 600;
    }
    .dr-trigger:hover { border-color: var(--accent, #0ea5e9); }
    .dr-trigger.active {
      border-color: var(--accent, #0ea5e9);
      background: var(--accent-pale, rgba(14,165,233,0.08));
      color: var(--accent, #0ea5e9);
    }
    .dr-label { font-weight: 600; }

    .dr-pop {
      z-index: 1000;   /* above table headers, sidebars, etc. */
      padding: 12px;
      background: var(--bg-elev, #fff);
      border: 1px solid var(--border, #e2e8f0);
      border-radius: 10px;
      box-shadow: 0 12px 32px rgba(0,0,0,0.12);
      animation: dr-pop-in .12s ease-out;
    }
    @keyframes dr-pop-in {
      from { opacity: 0; transform: translateY(-4px); }
      to   { opacity: 1; transform: translateY(0); }
    }
    .dr-presets {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 6px;
      margin-bottom: 10px;
    }
    .dr-presets button {
      padding: 8px 6px;
      background: var(--bg-soft, #f8fafc);
      border: 1px solid var(--border, #e2e8f0);
      border-radius: 6px;
      color: var(--ink-soft, #475569);
      font-size: 12px;
      font-weight: 600;
      cursor: pointer;
    }
    .dr-presets button:hover {
      background: var(--accent-pale, rgba(14,165,233,0.1));
      color: var(--accent, #0ea5e9);
      border-color: var(--accent, #0ea5e9);
    }
    .dr-custom {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 8px;
      padding: 8px 0;
      border-top: 1px dashed var(--border, #e2e8f0);
      margin-bottom: 10px;
    }
    .dr-custom label {
      display: flex;
      flex-direction: column;
      gap: 3px;
    }
    .dr-custom span {
      font-size: 10px;
      text-transform: uppercase;
      letter-spacing: 0.1em;
      font-weight: 700;
      color: var(--muted, #94a3b8);
    }
    .dr-custom input {
      padding: 8px 10px;
      border: 1px solid var(--border, #e2e8f0);
      border-radius: 6px;
      background: var(--bg-elev, #fff);
      color: var(--ink, #0f172a);
      font-size: 13px;
    }
    .dr-actions {
      display: flex;
      gap: 8px;
    }
    .dr-actions button {
      flex: 1;
      padding: 8px;
      border-radius: 6px;
      font-size: 13px;
      font-weight: 700;
      cursor: pointer;
      border: 1px solid var(--border, #e2e8f0);
      background: var(--bg-soft, #f8fafc);
      color: var(--ink-soft, #475569);
    }
    .dr-actions .dr-primary {
      background: var(--accent, #0ea5e9);
      border-color: var(--accent, #0ea5e9);
      color: #fff;
    }
  `;
  const s = document.createElement('style');
  s.id = 'admin-daterange-css';
  s.textContent = css;
  document.head.appendChild(s);
}