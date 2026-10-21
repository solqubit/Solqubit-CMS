(() => {
  'use strict';
  const configuration = document.querySelector('script[data-solqubit-visits]');
  if (!configuration) return;
  const firstVisitKey = 'solqubit-primera-visita';
  const ignoreKey = 'solqubit-sin-estadisticas';
  const storage = (() => { try { return window.localStorage; } catch { return null; } })();
  const read = key => { try { return storage ? storage.getItem(key) : null; } catch { return null; } };
  const write = (key, value) => { try { if (storage) value === null ? storage.removeItem(key) : storage.setItem(key, value); } catch { /* Sin almacenamiento disponible. */ } };

  // Quien entra con sesión iniciada marca este navegador para no contarse tampoco al salir.
  if (configuration.dataset.ignore !== undefined) { write(ignoreKey, '1'); return; }
  if (read(ignoreKey) || navigator.webdriver) return;

  const now = new Date();
  const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
  let returning = false;
  if (configuration.dataset.returning === '1') {
    const firstVisit = read(firstVisitKey);
    if (firstVisit && /^\d{4}-\d{2}-\d{2}$/.test(firstVisit) && firstVisit < today) returning = true;
    else if (!firstVisit || firstVisit > today) write(firstVisitKey, today);
  } else {
    write(firstVisitKey, null);
  }

  const body = new URLSearchParams({ p: location.pathname, r: document.referrer || '', n: returning ? '1' : '0' });
  const campaign = new URLSearchParams(location.search).get('utm_source');
  if (campaign) body.set('u', campaign.slice(0, 60));
  const endpoint = configuration.dataset.endpoint;
  const send = () => {
    if (navigator.sendBeacon && navigator.sendBeacon(endpoint, body)) return;
    if (window.fetch) fetch(endpoint, { method: 'POST', body, keepalive: true, credentials: 'omit' }).catch(() => {});
  };

  // Las páginas abiertas en segundo plano o precargadas solo cuentan cuando alguien las mira.
  if (document.visibilityState !== 'hidden' && document.visibilityState !== 'prerender') { send(); return; }
  const onVisible = () => {
    if (document.visibilityState !== 'visible') return;
    document.removeEventListener('visibilitychange', onVisible);
    send();
  };
  document.addEventListener('visibilitychange', onVisible);
})();
