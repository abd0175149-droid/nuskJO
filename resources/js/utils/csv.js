// CSV يفتحه Excel دون أن ينفّذ ما فيه — القاعدة نفسها في app/Support/Csv.php:
// خلية نصية تبدأ (بعد المسافات) بـ = أو + أو - أو @ أو محرف تحكم تُسبق بفاصلة عليا، والنص الرقمي (-12.50) يبقى رقماً.
const NUMERIC = /^-?\d+(\.\d+)?$/;
const DANGEROUS = /^ *[=+\-@\t\r\n]/;

export function escapeCsvCell(v) {
  if (v == null) return '';
  if (typeof v === 'number') return String(v);
  let s = String(v);
  if (!NUMERIC.test(s) && DANGEROUS.test(s)) s = "'" + s;
  return /[",\n\r]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
}

export function toCsv(rows) {
  return '﻿' + rows.map(r => r.map(escapeCsvCell).join(',')).join('\r\n');
}
