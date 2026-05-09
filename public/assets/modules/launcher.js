/**
 * Ctrl+K / Cmd+K command palette.
 *
 * Builds an in-memory index from the rendered cards in the DOM, so the
 * launcher works on whatever's currently visible in view mode without
 * needing a separate data feed.
 */

import { escapeHtml } from './util.js';

let overlay = null;
let input   = null;
let listEl  = null;
let items   = [];          // [{name, host, category, desc, href, iconHtml}, …]
let filtered = [];
let active  = 0;

export function init() {
  buildIndex();
  buildOverlay();
  bindGlobalShortcut();
}

function buildIndex() {
  const cards = document.querySelectorAll('#board .card');
  items = Array.from(cards).map(card => {
    const name     = card.querySelector('.name span:last-child')?.textContent.trim() || '';
    const desc     = card.querySelector('.desc')?.textContent.trim() || '';
    const host     = card.dataset.host || '';
    const category = card.closest('.category')?.dataset.category || '';
    const ic       = card.querySelector('.ic');
    const iconHtml = ic ? ic.innerHTML : '';
    return { name, desc, host, category, href: card.href, iconHtml };
  });
}

function buildOverlay() {
  overlay = document.createElement('div');
  overlay.id = 'launcher';
  overlay.className = 'launcher-bg';
  overlay.innerHTML = `
    <div class="launcher" role="dialog" aria-label="Quick launcher">
      <div class="launcher-input">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
        <input type="text" id="launcherQ" placeholder="Jump to…" autocomplete="off" spellcheck="false">
        <kbd>esc</kbd>
      </div>
      <div class="launcher-list" id="launcherList"></div>
      <div class="launcher-foot">
        <span><kbd>↑</kbd> <kbd>↓</kbd> navigate</span>
        <span><kbd>↵</kbd> open</span>
        <span><kbd>⇧</kbd>+<kbd>↵</kbd> new tab</span>
      </div>
    </div>
  `;
  overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
  document.body.appendChild(overlay);
  input  = overlay.querySelector('#launcherQ');
  listEl = overlay.querySelector('#launcherList');
  input.addEventListener('input', () => render());
  input.addEventListener('keydown', onKey);
  listEl.addEventListener('click', (e) => {
    const row = e.target.closest('.l-row');
    if (!row) return;
    const i = +row.dataset.i;
    if (Number.isFinite(i)) open(i, e.shiftKey || e.ctrlKey || e.metaKey);
  });
}

function bindGlobalShortcut() {
  document.addEventListener('keydown', (e) => {
    // Ctrl/Cmd + K opens
    if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
      // don't fight in-input typing of literal Ctrl+K
      if (overlay?.classList.contains('open')) return;
      e.preventDefault();
      openOverlay();
    }
  });
}

function openOverlay() {
  overlay.classList.add('open');
  input.value = '';
  active = 0;
  render();
  // Focus next tick so the keyboard event that opened us doesn't leak
  setTimeout(() => input.focus({ preventScroll: true }), 0);
}

function close() {
  overlay.classList.remove('open');
}

/* ---------- fuzzy scoring ---------- */
/* Lightweight: substring + token-prefix + field-weighted. Plenty for ~50 items. */
function score(item, q) {
  if (!q) return 1; // everything matches when query empty
  const Q = q.toLowerCase();
  const tokens = Q.split(/\s+/).filter(Boolean);
  let total = 0;
  for (const t of tokens) {
    const tScore = Math.max(
      fieldScore(item.name,     t, 4),
      fieldScore(item.host,     t, 2),
      fieldScore(item.category, t, 1.5),
      fieldScore(item.desc,     t, 1)
    );
    if (tScore === 0) return 0;
    total += tScore;
  }
  return total;
}

function fieldScore(field, t, weight) {
  if (!field) return 0;
  const f = field.toLowerCase();
  if (f === t)              return weight * 5;     // exact
  if (f.startsWith(t))      return weight * 4;     // prefix
  // word boundary prefix
  const words = f.split(/[\s\-_./:]+/);
  for (const w of words) if (w.startsWith(t)) return weight * 3;
  if (f.includes(t))        return weight * 2;     // substring
  return 0;
}

/* ---------- render results ---------- */
function render() {
  const q = input.value.trim();
  filtered = items
    .map(it => ({ it, s: score(it, q) }))
    .filter(x => x.s > 0)
    .sort((a, b) => b.s - a.s)
    .slice(0, 10)
    .map(x => x.it);

  if (active >= filtered.length) active = Math.max(0, filtered.length - 1);

  listEl.innerHTML = filtered.map((it, i) => `
    <div class="l-row ${i === active ? 'active' : ''}" data-i="${i}">
      <div class="l-ic">${it.iconHtml}</div>
      <div class="l-body">
        <div class="l-name">${escapeHtml(it.name)}</div>
        <div class="l-meta">
          <span class="l-cat">${escapeHtml(it.category)}</span>
          <span class="l-host">${escapeHtml(it.host)}</span>
        </div>
      </div>
    </div>
  `).join('');
  if (!filtered.length) {
    listEl.innerHTML = '<div class="l-empty">No matches.</div>';
  }
}

function setActive(i) {
  active = Math.max(0, Math.min(filtered.length - 1, i));
  Array.from(listEl.children).forEach((el, idx) => el.classList.toggle('active', idx === active));
  const row = listEl.children[active];
  if (row) row.scrollIntoView({ block: 'nearest' });
}

function onKey(e) {
  if (e.key === 'Escape')    { e.preventDefault(); close(); return; }
  if (e.key === 'ArrowDown') { e.preventDefault(); setActive(active + 1); return; }
  if (e.key === 'ArrowUp')   { e.preventDefault(); setActive(active - 1); return; }
  if (e.key === 'Enter') {
    e.preventDefault();
    if (filtered[active]) open(active, e.shiftKey || e.ctrlKey || e.metaKey);
  }
}

function open(i, newTab) {
  const it = filtered[i];
  if (!it) return;
  if (newTab) window.open(it.href, '_blank', 'noopener');
  else        window.location.href = it.href;
}
