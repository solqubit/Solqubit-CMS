// Live preview of the real public page next to the editor. The server paints it with the unsaved form state.

export const DEVICE_WIDTHS = { desktop: 1280, mobile: 390 };

/** How much a page painted at deviceWidth must shrink to fit containerWidth; it is never enlarged. */
export function previewScale(containerWidth, deviceWidth) {
  if (!(containerWidth > 0) || !(deviceWidth > 0)) return 1;
  return Math.min(1, containerWidth / deviceWidth);
}

function button(label, onClick) {
  const element = document.createElement('button');
  element.type = 'button'; element.textContent = label; element.addEventListener('click', onClick);
  return element;
}

/**
 * Builds the preview inside container and keeps it in sync with the form.
 * readState returns the form data; readDocument returns the editor document or null.
 */
export function createLivePreview({ container, endpoint, context, recordId, readState, readDocument, csrf, onClose = null, delay = 400 }) {
  let device = 'desktop', mode = 'light', isOpen = false, timer = null, request = null, serial = 0;
  container.replaceChildren();
  const bar = document.createElement('div'); bar.className = 'sq-preview-toolbar';
  const status = document.createElement('span'); status.className = 'sq-preview-status'; status.setAttribute('role', 'status');
  const segmented = (options, current, onChange) => {
    const group = document.createElement('div'); group.className = 'sq-preview-segment';
    for (const [value, label] of options) {
      const item = button(label, () => { onChange(value); group.querySelectorAll('button').forEach(other => other.setAttribute('aria-pressed', String(other === item))); });
      item.setAttribute('aria-pressed', String(value === current)); group.append(item);
    }
    return group;
  };
  bar.append(
    segmented([['desktop', 'Escritorio'], ['mobile', 'Móvil']], device, value => { device = value; layout(); }),
    segmented([['light', 'Claro'], ['dark', 'Oscuro']], mode, value => { mode = value; refresh(); }),
    status,
  );
  if (onClose) { const close = button('Volver al documento', onClose); close.className = 'sq-preview-close'; bar.append(close); }
  const viewport = document.createElement('div'); viewport.className = 'sq-preview-viewport';
  const frame = document.createElement('iframe'); frame.title = 'Vista previa de la página con los cambios sin guardar'; frame.setAttribute('sandbox', 'allow-same-origin');
  viewport.append(frame); container.append(bar, viewport);

  function layout() {
    const width = DEVICE_WIDTHS[device], scale = previewScale(viewport.clientWidth, width);
    frame.style.width = width + 'px';
    frame.style.height = Math.max(200, viewport.clientHeight / scale) + 'px';
    frame.style.transform = 'scale(' + scale + ')';
    viewport.dataset.device = device;
  }

  async function refresh() {
    if (!isOpen) return;
    const version = ++serial; request?.abort(); const controller = new AbortController(); request = controller;
    const timeout = setTimeout(() => controller.abort(), 15000);
    status.textContent = 'Actualizando…';
    try {
      const fields = readState() || {};
      const body = { context, record_id: recordId || null, fields, mode };
      const document = readDocument?.();
      if (document) body.document = document;
      const response = await fetch(endpoint, { method: 'POST', credentials: 'same-origin', signal: controller.signal,
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify(body) });
      const result = await response.json();
      if (!response.ok) throw new Error(response.status === 422 ? Object.values(result.errors || {}).flat()[0] || 'Revisa los campos del contenido.' : 'No se pudo actualizar la vista previa.');
      if (version !== serial) return;
      const scroll = frame.contentWindow?.scrollY || 0;
      frame.addEventListener('load', () => frame.contentWindow?.scrollTo(0, scroll), { once: true });
      frame.srcdoc = result.html; status.textContent = 'Cambios sin guardar';
    } catch (error) {
      if (version === serial) status.textContent = error.name === 'AbortError' ? 'La vista previa tardó demasiado.' : error.message;
    } finally { clearTimeout(timeout); }
  }

  function schedule() { if (!isOpen) return; clearTimeout(timer); timer = setTimeout(refresh, delay); }
  const resize = () => isOpen && layout();
  window.addEventListener('resize', resize);

  return {
    open() { isOpen = true; container.hidden = false; layout(); refresh(); },
    close() { isOpen = false; clearTimeout(timer); ++serial; request?.abort(); frame.removeAttribute('srcdoc'); },
    refresh, schedule,
    destroy() { this.close(); window.removeEventListener('resize', resize); container.replaceChildren(); },
  };
}

/** Wires every [data-live-preview] container on the page to its Livewire form. */
function boot() {
  for (const container of document.querySelectorAll('[data-live-preview]:not([data-live-preview-ready])')) {
    container.dataset.livePreviewReady = '1';
    const host = container.closest('[wire\\:id]') || document.querySelector('form[wire\\:submit]')?.closest('[wire\\:id]') || document.querySelector('[wire\\:id]');
    const component = () => window.Livewire?.find(host?.getAttribute('wire:id'));
    const context = container.dataset.livePreview;
    const readState = () => component()?.$get('data') || {};
    const toggle = container.dataset.previewToggle;
    const preview = createLivePreview({
      container, context, endpoint: container.dataset.endpoint, recordId: container.dataset.recordId,
      csrf: document.querySelector('meta[name="csrf-token"]')?.content || '', readState,
      readDocument: () => (context === 'home' ? null : readState().content || null),
      onClose: toggle ? () => window.dispatchEvent(new CustomEvent('sq-live-preview', { detail: { open: false } })) : null,
    });
    const changed = event => { if (!container.contains(event.target)) preview.schedule(); };
    document.addEventListener('input', changed); document.addEventListener('change', changed);
    window.addEventListener('sq-document-changed', () => preview.schedule());
    if (toggle) {
      window.addEventListener('sq-live-preview', event => {
        const open = event.detail?.open ?? !document.body.classList.contains('sq-preview-open');
        document.body.classList.toggle('sq-preview-open', open);
        if (open) preview.open(); else preview.close();
      });
    } else {
      preview.open();
    }
  }
}

if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
  document.addEventListener('livewire:navigated', boot);
}
