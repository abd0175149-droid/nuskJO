// node --test "tests/js/*.test.mjs"
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { formatSize, statusLabel, mediaUrl, fetchError } from '../../resources/js/utils/media.js';

test('sizes read naturally', () => {
  assert.equal(formatSize(900), '900 B');
  assert.equal(formatSize(300 * 1024), '300 KB');
  assert.equal(formatSize(1.5 * 1024 * 1024), '1.5 MB');
  assert.equal(formatSize(40 * 1024 * 1024), '40 MB');
  assert.equal(formatSize(null), '');
});

test('every archive state has an Arabic label and stored needs none', () => {
  for (const s of ['pending', 'too_large', 'failed', 'expired', 'rejected', 'purged', 'missing', 'skipped_quota', 'skipped_consent']) {
    assert.ok(statusLabel({ status: s }), s);
  }
  assert.equal(statusLabel({ status: 'stored' }), null);
  assert.equal(statusLabel({ status: null }), null);
  assert.match(statusLabel({ status: 'failed', error: '131052' }), /100MB/);
  assert.match(statusLabel({ status: 'too_large', size: 40 * 1024 * 1024 }), /40 MB/);
});

test('urls carry no extension and errors are explicit', () => {
  assert.equal(mediaUrl(12), '/api/whatsapp/messages/12/media');
  assert.equal(mediaUrl(12, true), '/api/whatsapp/messages/12/media?download=1');
  assert.match(fetchError(403), /صلاحية/);
  assert.match(fetchError(404), /حُذف/);
});

test('the chip and the viewer never render raw html', () => {
  for (const f of ['MediaChip.vue', 'MediaViewer.vue']) {
    const src = readFileSync(new URL(`../../resources/js/Components/WhatsApp/${f}`, import.meta.url), 'utf8');
    assert.ok(!src.includes('v-html'), f);
    assert.ok(!/localStorage|indexedDB|caches\./.test(src), `${f}: لا تخزين للملف في المتصفح`);
  }
});
