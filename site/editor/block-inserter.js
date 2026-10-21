// The block panel on the left of the editor, like WordPress: search, sections and a card per block.

const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

/** Whether a card whose label, hint and section are `text` matches the search. */
export function tileMatches(query, text) {
  return normalize(text).includes(normalize(query.trim()));
}

const storageKey = 'solqubit-panel-bloques';

/** Open by default when the panel, the document and the settings all fit, like WordPress; a writer who closed it finds it closed next time. */
export function startsOpen(stored, width) {
  return width >= 1400 && stored !== 'closed';
}

function readStored() { try { return localStorage.getItem(storageKey); } catch { return null; } }
function writeStored(value) { try { localStorage.setItem(storageKey, value); } catch {} }

function boot() {
  const panel = document.getElementById('sq-block-inserter');
  const toggle = document.querySelector('[data-inserter-toggle]');
  if (!panel || !toggle || panel.dataset.ready) return;
  panel.dataset.ready = '1';
  const search = panel.querySelector('[data-inserter-search]');
  const empty = panel.querySelector('[data-inserter-empty]');
  const narrow = () => window.innerWidth < 1100;

  function setOpen(open) {
    document.body.classList.toggle('sq-inserter-open', open);
    toggle.setAttribute('aria-expanded', String(open));
    if (open && !narrow()) search?.focus({ preventScroll: true });
  }

  toggle.addEventListener('click', () => {
    const open = !document.body.classList.contains('sq-inserter-open');
    setOpen(open);
    if (!narrow()) writeStored(open ? 'open' : 'closed');
  });
  if (startsOpen(readStored(), window.innerWidth)) {
    document.body.classList.add('sq-inserter-open');
    toggle.setAttribute('aria-expanded', 'true');
  }
  panel.addEventListener('keydown', event => { if (event.key === 'Escape') { setOpen(false); toggle.focus(); } });
  search?.addEventListener('input', () => {
    let visible = 0;
    for (const section of panel.querySelectorAll('[data-inserter-section]')) {
      let shown = 0;
      for (const tile of section.querySelectorAll('[data-command]')) {
        const match = tileMatches(search.value, tile.dataset.search || tile.textContent);
        tile.hidden = !match;
        shown += match ? 1 : 0;
      }
      section.hidden = shown === 0;
      visible += shown;
    }
    if (empty) empty.hidden = visible > 0;
  });
  // Keep the editor's selection: the click must not move focus before the block is inserted.
  panel.addEventListener('mousedown', event => { if (event.target.closest('[data-command]')) event.preventDefault(); });
  panel.addEventListener('click', event => {
    const tile = event.target.closest('[data-command]');
    if (!tile) return;
    window.dispatchEvent(new CustomEvent('sq-insert-block', { detail: { command: tile.dataset.command } }));
    if (narrow()) setOpen(false);
  });
}

if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
  document.addEventListener('livewire:navigated', boot);
}
