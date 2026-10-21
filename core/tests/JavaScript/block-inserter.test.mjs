import test from 'node:test';
import assert from 'node:assert/strict';
import { tileMatches } from '../../../site/editor/block-inserter.js';

test('the block panel search ignores accents and case', () => {
  assert.equal(tileMatches('video', 'Video propio Multimedia'), true);
  assert.equal(tileMatches('codigo', 'Código Destacados'), true);
  assert.equal(tileMatches('', 'Párrafo Texto'), true);
  assert.equal(tileMatches('tabla', 'Imagen Multimedia'), false);
});

test('the block panel starts open on wide screens unless the writer closed it', async () => {
  const { startsOpen } = await import('../../../site/editor/block-inserter.js');
  assert.equal(startsOpen(null, 1440), true);
  assert.equal(startsOpen('open', 1440), true);
  assert.equal(startsOpen('closed', 1440), false);
  assert.equal(startsOpen(null, 1200), false);
});
