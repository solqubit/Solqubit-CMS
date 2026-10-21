import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createContext, runInContext } from 'node:vm';

const source = readFileSync(new URL('../../../site/monetization.js', import.meta.url), 'utf8');

function advertisingPage({ configuration = { publisher: 'ca-pub-1234567890123456', format: 'manual' }, count = 2 } = {}) {
  const scripts = [];
  const placements = Array.from({ length: count }, () => {
    const slot = { dataset: {} };
    return { hidden: false, querySelector: selector => selector === '.adsbygoogle' ? slot : null };
  });
  const context = createContext({
    document: {
      querySelector: selector => selector === 'script[data-solqubit-ads]' && configuration ? { dataset: configuration } : null,
      querySelectorAll: selector => selector === '.ad-placement' ? placements : [],
      getElementById: id => scripts.find(script => script.id === id),
      createElement: tag => {
        assert.equal(tag, 'script');
        return { events: {}, addEventListener(event, callback) { this.events[event] = callback; } };
      },
      head: { append: script => scripts.push(script) },
    },
    window: {},
  });
  return { scripts, placements, window: context.window, run: () => runInContext(source, context) };
}

test('No solicita publicidad sin configuración, con una cuenta inválida o sin ubicaciones manuales', () => {
  for (const options of [
    { configuration: null },
    { configuration: { publisher: 'https://otro-servidor.test/script', format: 'automatic' } },
    { count: 0 },
  ]) {
    const page = advertisingPage(options);
    page.run();
    assert.equal(page.scripts.length, 0);
    assert.equal(page.window.adsbygoogle, undefined);
  }
});

test('Los bloques manuales se solicitan una vez con un único script oficial asíncrono', () => {
  const page = advertisingPage();
  page.run();
  page.run();
  assert.equal(page.scripts.length, 1);
  assert.equal(page.window.adsbygoogle.length, 2);
  assert.equal(page.scripts[0].src, 'https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-1234567890123456');
  assert.equal(page.scripts[0].async, true);
  assert.equal(page.scripts[0].crossOrigin, 'anonymous');
});

test('Los anuncios automáticos cargan el script sin crear bloques manuales', () => {
  const page = advertisingPage({ configuration: { publisher: 'ca-pub-1234567890123456', format: 'automatic' }, count: 0 });
  page.run();
  assert.equal(page.scripts.length, 1);
  assert.equal(page.window.adsbygoogle.length, 0);
});

test('Un error al cargar publicidad oculta sus espacios sin afectar al contenido', () => {
  const page = advertisingPage();
  page.run();
  page.scripts[0].events.error();
  assert.ok(page.placements.every(placement => placement.hidden));
});
