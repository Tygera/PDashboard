/**
 * Shared helpers used across modules.
 */

let toastEl = null;
let toastTimer = null;

export function toast(msg) {
  if (!toastEl) toastEl = document.getElementById('toast');
  if (!toastEl) return;
  toastEl.textContent = msg;
  toastEl.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => toastEl.classList.remove('show'), 1600);
}

export function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

/** Returns "host" or "host:port", or "" on parse failure. */
export function hostOf(url) {
  try {
    const u = new URL(url);
    return u.port ? `${u.hostname}:${u.port}` : u.hostname;
  } catch {
    return '';
  }
}

/** Read a JSON-typed <script> tag's textContent and parse it. */
export function readJsonScript(id, fallback = null) {
  const el = document.getElementById(id);
  if (!el) return fallback;
  try { return JSON.parse(el.textContent || ''); }
  catch { return fallback; }
}
