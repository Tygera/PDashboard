/**
 * Compact mode.
 *
 * Each category has two independent properties:
 *   - state:  is it currently compact?  (boolean)
 *   - locked: is it pinned, ignoring all toggles? (boolean)
 *
 * Three controls:
 *   - global toggle (header)         flips state for every UNLOCKED category
 *   - compact toggle (per category)  flips state for this category — disabled when locked
 *   - lock toggle    (per category)  flips locked for this category
 *
 * State persists in localStorage:
 *   dash:compact:global              -> '0' | '1'
 *   dash:compact:state:<category>    -> '0' | '1'  (absent = follow global)
 *   dash:compact:locked:<category>   -> '1'        (absent = unlocked)
 */

const KEY_GLOBAL = 'dash:compact:global';
const KEY_STATE  = (cat) => 'dash:compact:state:'  + cat;
const KEY_LOCK   = (cat) => 'dash:compact:locked:' + cat;

function readGlobal()    { return localStorage.getItem(KEY_GLOBAL) === '1'; }
function writeGlobal(on) { localStorage.setItem(KEY_GLOBAL, on ? '1' : '0'); }

function readState(cat) {
  const v = localStorage.getItem(KEY_STATE(cat));
  return v === '1' ? true : v === '0' ? false : null;
}
function writeState(cat, on) { localStorage.setItem(KEY_STATE(cat), on ? '1' : '0'); }

function readLocked(cat)     { return localStorage.getItem(KEY_LOCK(cat)) === '1'; }
function writeLocked(cat, on) {
  if (on) localStorage.setItem(KEY_LOCK(cat), '1');
  else    localStorage.removeItem(KEY_LOCK(cat));
}

/** Effective compact state for a category. */
function resolved(cat) {
  const s = readState(cat);
  return s === null ? readGlobal() : s;
}

export function init() {
  const board     = document.getElementById('board');
  const globalBtn = document.getElementById('compactToggle');
  if (!board) return;

  const cats = Array.from(board.querySelectorAll('.category'));

  function apply() {
    const g = readGlobal();
    cats.forEach(cat => {
      const name   = cat.dataset.category;
      const isOn   = resolved(name);
      const locked = readLocked(name);

      cat.classList.toggle('compact', isOn);
      cat.classList.toggle('locked',  locked);

      const compactBtn = cat.querySelector('.cat-compact');
      if (compactBtn) {
        compactBtn.dataset.state = isOn ? 'on' : 'off';
        compactBtn.disabled = locked;
        compactBtn.title = locked
          ? 'Locked — unlock to change compact state'
          : (isOn ? 'Compact view (click for comfortable)'
                  : 'Comfortable view (click for compact)');
      }

      const lockBtn = cat.querySelector('.cat-lock');
      if (lockBtn) {
        lockBtn.dataset.state = locked ? 'locked' : 'unlocked';
        lockBtn.innerHTML = locked ? LOCKED_SVG : UNLOCKED_SVG;
        lockBtn.title = locked
          ? 'Locked (click to unlock — global toggle will affect this category)'
          : 'Unlocked (click to lock — pin current state, ignore global)';
      }
    });
    if (globalBtn) {
      globalBtn.dataset.state = g ? 'on' : 'off';
      globalBtn.title = g
        ? 'Compact view (click for comfortable)'
        : 'Comfortable view (click for compact)';
    }
  }

  /* Global toggle: flip every unlocked category to the new global state. */
  if (globalBtn) {
    globalBtn.addEventListener('click', () => {
      const next = !readGlobal();
      writeGlobal(next);
      cats.forEach(cat => {
        const name = cat.dataset.category;
        if (!readLocked(name)) writeState(name, next);
      });
      apply();
    });
  }

  /* Per-category controls. */
  board.addEventListener('click', (e) => {
    const compactBtn = e.target.closest('.cat-compact');
    const lockBtn    = e.target.closest('.cat-lock');
    if (!compactBtn && !lockBtn) return;

    e.preventDefault();
    const cat = (compactBtn || lockBtn).closest('.category');
    if (!cat) return;
    const name = cat.dataset.category;

    if (compactBtn) {
      if (readLocked(name)) return;          // hard no while locked
      writeState(name, !resolved(name));
    } else if (lockBtn) {
      writeLocked(name, !readLocked(name));  // lock/unlock — state unchanged
    }
    apply();
  });

  apply();
}

/* ---------- icons swapped atomically with state ---------- */
const UNLOCKED_SVG = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg>';
const LOCKED_SVG   = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
