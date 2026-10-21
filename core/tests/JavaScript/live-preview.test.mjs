import test from 'node:test';
import assert from 'node:assert/strict';
import { previewScale, DEVICE_WIDTHS } from '../../../site/editor/live-preview.js';

test('the preview page is scaled down to fit and never enlarged', () => {
  assert.equal(previewScale(640, 1280), 0.5);
  assert.equal(previewScale(500, 390), 1);
  assert.equal(previewScale(0, 1280), 1);
  assert.deepEqual(DEVICE_WIDTHS, { desktop: 1280, mobile: 390 });
});
