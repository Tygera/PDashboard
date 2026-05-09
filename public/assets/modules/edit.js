/**
 * Edit mode: inline editor, drag-reorder, add/delete, save,
 * theme sliders, favicon refresh.
 */

import { toast, escapeHtml, hostOf, readJsonScript } from './util.js';

export function init() {
  const csrf      = document.body.dataset.csrf || '';
  const list      = document.getElementById('editList');
  const addBtn    = document.getElementById('addCardBtn');
  const saveBtn   = document.getElementById('saveBtn');
  const saveForm  = document.getElementById('saveForm');
  const payloadIn = document.getElementById('payloadField');
  const themeIn   = document.getElementById('themeField');
  const toolbar   = document.getElementById('editToolbar');
  const cardCount = document.getElementById('cardCount');

  let data = readJsonScript('initial-data', []);
  let favs = readJsonScript('initial-favicons', {});
  let dirty = false;

  function markDirty() { dirty = true; toolbar.classList.add('dirty'); }
  function clearDirty() { dirty = false; toolbar.classList.remove('dirty'); }

  /* ---- icon preview helpers ---- */
  function previewHtml(row) {
    const manual = (row.icon || '').trim();
    if (manual) return escapeHtml(manual);
    const host = hostOf(row.url || '');
    if (host && favs[host]) return `<img src="${escapeHtml(favs[host])}" alt="">`;
    const parts = (row.name || '?').trim().split(/\s+/);
    let init = '';
    for (const p of parts) {
      if (!p) continue;
      init += p[0];
      if (init.length >= 2) break;
    }
    return `<span style="font-size:13px">${escapeHtml((init || '?').toUpperCase())}</span>`;
  }

  /* ---- row construction ---- */
  function makeRow(row) {
    const div = document.createElement('div');
    div.className = 'card edit-card';
    div.innerHTML = `
      <div class="handle" title="Drag to reorder" draggable="true">⋮⋮</div>
      <div class="ic-stack">
        <input class="ic-input" data-field="icon" type="text" maxlength="3" placeholder="·">
        <div class="preview" title="Preview"></div>
        <button class="refresh-fav" type="button" title="Re-fetch favicon">↻ icon</button>
      </div>
      <div class="body">
        <div class="row">
          <input class="cat-input"  data-field="category" type="text" placeholder="Category">
          <input class="name-input" data-field="name"     type="text" placeholder="Name" required>
          <div class="actions"><button class="btn btn-sm btn-ghost btn-danger" type="button" data-act="del" title="Delete">✕</button></div>
        </div>
        <div class="row"><input class="url-input"  data-field="url"  type="url"  placeholder="https://…"></div>
        <div class="row"><input class="desc-input" data-field="desc" type="text" placeholder="Short description (optional)"></div>
      </div>
    `;
    div.querySelector('.ic-input').value             = row.icon     ?? '';
    div.querySelector('[data-field=category]').value = row.category ?? '';
    div.querySelector('[data-field=name]').value     = row.name     ?? '';
    div.querySelector('[data-field=url]').value      = row.url      ?? '';
    div.querySelector('[data-field=desc]').value     = row.desc     ?? '';
    updatePreview(div);
    return div;
  }

  function readRow(div) {
    return {
      icon:     div.querySelector('.ic-input').value.trim(),
      category: div.querySelector('[data-field=category]').value.trim() || 'Other',
      name:     div.querySelector('[data-field=name]').value.trim(),
      url:      div.querySelector('[data-field=url]').value.trim(),
      desc:     div.querySelector('[data-field=desc]').value.trim(),
    };
  }
  function readAll()  { return Array.from(list.querySelectorAll('.edit-card')).map(readRow); }
  function updateCount() { cardCount.textContent = list.querySelectorAll('.edit-card').length; }
  function updatePreview(div) {
    const row = readRow(div);
    div.querySelector('.preview').innerHTML = previewHtml(row);
  }

  /* ---- render: group by category alphabetically ---- */
  function render() {
    list.innerHTML = '';
    const cats = new Map();
    data.forEach(r => {
      const c = r.category || 'Other';
      if (!cats.has(c)) cats.set(c, []);
      cats.get(c).push(r);
    });
    const sortedCats = Array.from(cats.keys()).sort((a, b) => a.localeCompare(b));
    sortedCats.forEach(cat => {
      const section = document.createElement('section');
      section.className = 'category';
      section.innerHTML = `<div class="category-head"><h2>${escapeHtml(cat)}</h2><span class="count">${cats.get(cat).length}</span></div><div class="grid"></div>`;
      const grid = section.querySelector('.grid');
      cats.get(cat).forEach(row => grid.appendChild(makeRow(row)));
      list.appendChild(section);
    });
    wireRows();
    updateCount();
  }

  /* ---- wire up listeners on every row ---- */
  function wireRows() {
    list.querySelectorAll('.edit-card').forEach(div => {
      div.querySelectorAll('input').forEach(inp => {
        inp.addEventListener('input', () => { markDirty(); updatePreview(div); });
      });
      div.querySelector('[data-act=del]').addEventListener('click', () => {
        div.remove();
        markDirty();
        updateCount();
      });
      div.querySelector('.refresh-fav').addEventListener('click', () => refreshFavicon(div));

      const handle = div.querySelector('.handle');
      handle.addEventListener('dragstart', (e) => {
        div.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', '');
        e.dataTransfer.setDragImage(div, 20, 20);
        window._draggedCard = div;
      });
      handle.addEventListener('dragend', () => {
        div.classList.remove('dragging');
        list.querySelectorAll('.drop-target').forEach(d => d.classList.remove('drop-target'));
        window._draggedCard = null;
      });
    });

    list.querySelectorAll('.edit-card').forEach(div => {
      div.addEventListener('dragover', (e) => {
        if (!window._draggedCard || window._draggedCard === div) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        list.querySelectorAll('.drop-target').forEach(d => d.classList.remove('drop-target'));
        div.classList.add('drop-target');
      });
      div.addEventListener('drop', (e) => {
        e.preventDefault();
        const dragged = window._draggedCard;
        if (!dragged || dragged === div) return;
        const targetSection = div.closest('.category');
        const targetCat = targetSection?.querySelector('h2')?.textContent || 'Other';
        dragged.querySelector('[data-field=category]').value = targetCat;
        div.parentNode.insertBefore(dragged, div);
        div.classList.remove('drop-target');
        markDirty();
      });
    });
  }

  function refreshFavicon(div) {
    const url = div.querySelector('[data-field=url]').value.trim();
    if (!url) { toast('Enter a URL first'); return; }
    const btn = div.querySelector('.refresh-fav');
    const old = btn.textContent;
    btn.textContent = '…'; btn.disabled = true;
    const fd = new FormData();
    fd.append('action', 'refresh_favicon');
    fd.append('csrf', csrf);
    fd.append('url', url);
    fetch('?', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(r => r.json())
      .then(({ favicon }) => {
        const host = hostOf(url);
        if (host && favicon) {
          favs[host] = favicon + '?t=' + Date.now();
          updatePreview(div);
          toast('Icon updated');
        } else {
          toast('No favicon found');
        }
      })
      .catch(() => toast('Fetch failed'))
      .finally(() => { btn.textContent = old; btn.disabled = false; });
  }

  /* ---- add / save ---- */
  addBtn.addEventListener('click', () => {
    let firstGrid = list.querySelector('.category .grid');
    if (!firstGrid) {
      data = [{ category: 'Other', name: '', url: '', icon: '', desc: '' }];
      render();
      list.querySelector('[data-field=name]')?.focus();
      markDirty();
      return;
    }
    const cat = firstGrid.closest('.category').querySelector('h2').textContent;
    const newRow = makeRow({ category: cat, name: '', url: '', icon: '', desc: '' });
    firstGrid.appendChild(newRow);
    wireRows();
    updateCount();
    markDirty();
    newRow.querySelector('[data-field=name]').focus();
  });

  saveBtn.addEventListener('click', () => {
    payloadIn.value = JSON.stringify(readAll());
    const memeEl = document.getElementById('memeInterval');
    themeIn.value   = JSON.stringify({
      theme_hue:          rHue.value,
      theme_sat:          rSat.value,
      theme_accent_shift: rAcc.value,
      theme_bg_bri:       rBgBri.value,
      theme_fg_bri:       rFgBri.value,
      theme_card_top:     rCTop.value,
      theme_card_bottom:  rCBot.value,
      meme_interval:      memeEl ? memeEl.value : 30,
    });
    saveForm.submit();
  });

  document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
      e.preventDefault();
      saveBtn.click();
    }
  });

  /* ---- theme sliders ---- */
  const rHue   = document.getElementById('rHue');
  const rSat   = document.getElementById('rSat');
  const rAcc   = document.getElementById('rAcc');
  const rBgBri = document.getElementById('rBgBri');
  const rFgBri = document.getElementById('rFgBri');
  const rCTop  = document.getElementById('rCTop');
  const rCBot  = document.getElementById('rCBot');

  const vHue   = document.getElementById('vHue');
  const vSat   = document.getElementById('vSat');
  const vAcc   = document.getElementById('vAcc');
  const vBgBri = document.getElementById('vBgBri');
  const vFgBri = document.getElementById('vFgBri');
  const vCTop  = document.getElementById('vCTop');
  const vCBot  = document.getElementById('vCBot');

  const swatchHue = document.getElementById('swatchHue');

  function fmtSign(n) { return (n >= 0 ? '+' : '') + n; }

  function applyTheme() {
    const h     = +rHue.value;
    const s     = +rSat.value;
    const a     = +rAcc.value;
    const bgBri = +rBgBri.value;
    const fgBri = +rFgBri.value;
    const cTop  = +rCTop.value;
    const cBot  = +rCBot.value;
    const ah    = ((h + a) % 360 + 360) % 360;

    const root = document.documentElement.style;
    root.setProperty('--h',        h);
    root.setProperty('--s',        s + '%');
    root.setProperty('--acc-h',    ah);
    root.setProperty('--bg-bri',   bgBri);
    root.setProperty('--fg-bri',   fgBri);
    root.setProperty('--card-top', cTop + '%');
    root.setProperty('--card-bot', cBot + '%');

    vHue.textContent   = h + '°';
    vSat.textContent   = s + '%';
    vAcc.textContent   = fmtSign(a) + '°';
    vBgBri.textContent = fmtSign(bgBri);
    vFgBri.textContent = fmtSign(fgBri);
    vCTop.textContent  = cTop + '%';
    vCBot.textContent  = cBot + '%';

    // Hue swatch — show how the bg ramp looks at current hue/sat with bg-bri offset
    swatchHue.innerHTML = '';
    [4, 9, 14, 22].forEach(L => {
      const sp = document.createElement('span');
      sp.style.background = `hsl(${h}, ${s}%, ${L + bgBri}%)`;
      swatchHue.appendChild(sp);
    });
  }

  [rHue, rSat, rAcc, rBgBri, rFgBri, rCTop, rCBot].forEach(r => {
    r.addEventListener('input', () => { applyTheme(); markDirty(); });
  });
  applyTheme();

  document.getElementById('themeToggle').addEventListener('click', () => {
    document.getElementById('themePanel').classList.toggle('open');
  });

  render();
  clearDirty();
}
