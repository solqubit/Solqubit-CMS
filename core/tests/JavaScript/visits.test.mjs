import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createContext, runInContext } from 'node:vm';

const source = readFileSync(new URL('../../../site/js/visitas.js', import.meta.url), 'utf8');

function visitPage({ dataset = { endpoint: '/visita', returning: '1' }, stored = {}, visibility = 'visible', webdriver = false, search = '' } = {}) {
  const beacons = [];
  const listeners = {};
  const items = { ...stored };
  const document = {
    visibilityState: visibility,
    referrer: 'https://www.google.com/',
    querySelector: selector => selector === 'script[data-solqubit-visits]' && dataset ? { dataset } : null,
    addEventListener: (event, callback) => { listeners[event] = callback; },
    removeEventListener: event => { delete listeners[event]; },
  };
  const context = createContext({
    document,
    location: { pathname: '/entrada/linux', search },
    navigator: { webdriver, sendBeacon: (url, body) => { beacons.push({ url, body: Object.fromEntries(body) }); return true; } },
    window: { localStorage: { getItem: key => items[key] ?? null, setItem: (key, value) => { items[key] = String(value); }, removeItem: key => { delete items[key]; } } },
    URLSearchParams,
    Date,
    String,
  });
  return { beacons, items, document, listeners, run: () => runInContext(source, context) };
}

test('Envía la página, la procedencia y la campaña sin datos personales', () => {
  const page = visitPage({ search: '?utm_source=boletin&q=privado' });
  page.run();
  assert.equal(page.beacons.length, 1);
  assert.equal(page.beacons[0].url, '/visita');
  assert.deepEqual(page.beacons[0].body, { p: '/entrada/linux', r: 'https://www.google.com/', n: '0', u: 'boletin' });
  assert.match(page.items['solqubit-primera-visita'], /^\d{4}-\d{2}-\d{2}$/);
});

test('Reconoce a quien ya vino otro día y olvida la fecha si se desactiva', () => {
  const returning = visitPage({ stored: { 'solqubit-primera-visita': '2020-01-01' } });
  returning.run();
  assert.equal(returning.beacons[0].body.n, '1');

  const disabled = visitPage({ dataset: { endpoint: '/visita', returning: '0' }, stored: { 'solqubit-primera-visita': '2020-01-01' } });
  disabled.run();
  assert.equal(disabled.beacons[0].body.n, '0');
  assert.equal(disabled.items['solqubit-primera-visita'], undefined);
});

test('No cuenta a quien tiene sesión, a los navegadores automatizados ni sin configuración', () => {
  const signedIn = visitPage({ dataset: { endpoint: '/visita', returning: '1', ignore: '' } });
  signedIn.run();
  assert.equal(signedIn.beacons.length, 0);
  assert.equal(signedIn.items['solqubit-sin-estadisticas'], '1');

  for (const options of [{ stored: { 'solqubit-sin-estadisticas': '1' } }, { webdriver: true }, { dataset: null }]) {
    const page = visitPage(options);
    page.run();
    assert.equal(page.beacons.length, 0);
  }
});

test('Una pestaña en segundo plano solo cuenta cuando se muestra', () => {
  const page = visitPage({ visibility: 'hidden' });
  page.run();
  assert.equal(page.beacons.length, 0);
  page.document.visibilityState = 'visible';
  page.listeners.visibilitychange();
  assert.equal(page.beacons.length, 1);
  assert.equal(page.listeners.visibilitychange, undefined);
});
