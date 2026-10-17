// node --test "tests/js/*.test.mjs" — بلا حزم إضافية
import test from 'node:test';
import assert from 'node:assert/strict';
import { escapeCsvCell, toCsv } from '../../resources/js/utils/csv.js';

test('formula-like text is neutralised with a leading apostrophe', () => {
  for (const v of ['=HYPERLINK("http://x")', '+1+1', '-2+3', '@SUM(A1)', '  =1+1', '\t=1', '\r=1']) {
    assert.ok(escapeCsvCell(v).replace(/^"/, '').startsWith("'"), v);
  }
});

test('numbers and numeric text stay numbers', () => {
  assert.equal(escapeCsvCell(-12.5), '-12.5');
  assert.equal(escapeCsvCell('-12.50'), '-12.50');
  assert.equal(escapeCsvCell('42'), '42');
  assert.equal(escapeCsvCell(0), '0');
});

test('plain text, empty values and quoting', () => {
  assert.equal(escapeCsvCell('عمرة رمضان'), 'عمرة رمضان');
  assert.equal(escapeCsvCell(null), '');
  assert.equal(escapeCsvCell(undefined), '');
  assert.equal(escapeCsvCell('a,b'), '"a,b"');
  assert.equal(escapeCsvCell('say "hi"'), '"say ""hi"""');
  assert.equal(escapeCsvCell('=1,2'), '"\'=1,2"');
});

test('toCsv adds a BOM and CRLF line breaks', () => {
  assert.equal(toCsv([['a', 1], ['=x', '-3.5']]), '﻿a,1\r\n\'=x,-3.5');
});
