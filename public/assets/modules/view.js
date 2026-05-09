/**
 * View mode: search + keyboard nav + copy buttons + lazy health.
 */

import { toast } from './util.js';

export function init() {
  const q     = document.getElementById('q');
  const board = document.getElementById('board');
  if (!board) return;
  const empty = document.getElementById('empty');
  const cards = Array.from(board.querySelectorAll('.card'));
  const cats  = Array.from(board.querySelectorAll('.category'));

  /* ---- copy buttons ---- */
  board.addEventListener('click', (e) => {
    const btn = e.target.closest('.copy-btn');
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();
    const text = btn.dataset.copy;
    navigator.clipboard.writeText(text).then(() => {
      btn.classList.add('ok');
      toast('Copied ' + text);
      setTimeout(() => btn.classList.remove('ok'), 1200);
    });
  });

  /* ---- search + keyboard nav ---- */
  let active = -1;
  let visible = cards.slice();

  function applyFilter() {
    const term = q.value.trim().toLowerCase();
    visible = [];
    cards.forEach(c => {
      const match = !term || c.dataset.search.includes(term);
      c.classList.toggle('hidden', !match);
      if (match) visible.push(c);
    });
    cats.forEach(cat => cat.classList.toggle('hidden', !cat.querySelector('.card:not(.hidden)')));
    empty.style.display = visible.length ? 'none' : '';
    setActive(visible.length ? 0 : -1, false);
  }

  function setActive(i, scroll = true) {
    cards.forEach(c => c.classList.remove('kb-active'));
    active = i;
    if (i >= 0 && i < visible.length) {
      visible[i].classList.add('kb-active');
      if (scroll) visible[i].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
  }

  function move(d) {
    if (!visible.length) return;
    let n = active + d;
    if (n < 0) n = 0;
    if (n >= visible.length) n = visible.length - 1;
    setActive(n);
  }

  function openCard(el, newTab) {
    if (newTab) window.open(el.href, '_blank', 'noopener');
    else        window.location.href = el.href;
  }

  document.addEventListener('keydown', (e) => {
    const inSearch = document.activeElement === q;

    if (e.key === '/' && !inSearch) {
      e.preventDefault();
      q.focus();
      q.select();
      return;
    }
    if (e.key === 'Escape') {
      if (inSearch) {
        if (q.value) { q.value = ''; applyFilter(); }
        else q.blur();
      }
      return;
    }
    if (inSearch) {
      if (e.key === 'Enter' && active >= 0 && visible[active]) {
        e.preventDefault();
        openCard(visible[active], e.shiftKey || e.ctrlKey || e.metaKey);
      } else if (e.key === 'ArrowDown') { e.preventDefault(); move(1); }
      else  if (e.key === 'ArrowUp')   { e.preventDefault(); move(-1); }
      return;
    }
    if (e.key === 'j' || e.key === 'ArrowDown') { e.preventDefault(); move(1); }
    else if (e.key === 'k' || e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
    else if (e.key === 'Enter' && active >= 0) {
      e.preventDefault();
      openCard(visible[active], e.shiftKey || e.ctrlKey || e.metaKey);
    }
  });

  q.addEventListener('input', applyFilter);
  setActive(0, false);

  /* ---- meme subtitle rotator ---- */
  const memeScript = document.getElementById('meme-data');
  if (memeScript) {
    try {
      const { memes, interval } = JSON.parse(memeScript.textContent);
      const sub = document.getElementById('subtitle');
      if (sub && memes.length > 1) {
        // Pick a different meme from the one already shown
        function nextMeme(current) {
          const others = memes.filter(m => m !== current);
          return others[Math.floor(Math.random() * others.length)];
        }
        setInterval(() => {
          const next = nextMeme(sub.textContent);
          sub.style.opacity = '0';
          setTimeout(() => {
            sub.textContent = next;
            sub.style.opacity = '1';
          }, 350);
        }, interval * 1000);
      }
    } catch {}
  }

  /* ---- lazy health refresh ---- */
  // Trigger after page paint so it doesn't block initial render
  if ('requestIdleCallback' in window) requestIdleCallback(refreshHealth);
  else setTimeout(refreshHealth, 100);

  function refreshHealth() {
    fetch('?action=health_refresh', { credentials: 'same-origin' })
      .then(r => r.json())
      .then(({ health }) => {
        if (!health) return;
        cards.forEach(c => {
          const host = c.dataset.host;
          const dot  = c.querySelector('.dot[data-health]');
          const info = health[host];
          if (!dot || !info) return;
          dot.classList.remove('ok','warn','down','unknown');
          dot.classList.add(info.status);
          const ms   = info.ms ? ` · ${info.ms}ms` : '';
          const code = info.http_code ? ` (HTTP ${info.http_code})` : '';
          dot.title = `status: ${info.status}${code}${ms}`;
        });
      })
      .catch(() => {});
  }
}
