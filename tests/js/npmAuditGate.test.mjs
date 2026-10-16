// node --test "tests/js/*.test.mjs" — بوابة npm audit (E26.9)
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { evaluate, findings, baselineErrors } from '../../scripts/audit/npm-audit-gate.mjs';

const report = {
    auditReportVersion: 2,
    vulnerabilities: {
        axios: { severity: 'high', via: [
            { source: 1, name: 'axios', severity: 'high', title: 'A', url: 'https://github.com/advisories/GHSA-aaaa-bbbb-cccc' },
            { source: 2, name: 'axios', severity: 'moderate', title: 'B', url: 'https://github.com/advisories/GHSA-dddd-eeee-ffff' },
        ] },
        'form-data': { severity: 'critical', via: [{ source: 3, name: 'form-data', severity: 'critical', title: 'C', url: 'https://github.com/advisories/GHSA-gggg-hhhh-iiii' }] },
        vue: { severity: 'high', via: ['@vue/server-renderer'] },
    },
};
const entry = (id, pkg, review_by = '2099-01-01') => ({ id, package: pkg, severity: 'high', reason: 'سبب', review_by });

test('only high and critical advisories block, by GHSA id', () => {
    assert.deepEqual(findings(report).map((f) => `${f.id} ${f.package} ${f.severity}`), [
        'GHSA-aaaa-bbbb-cccc axios high',
        'GHSA-gggg-hhhh-iiii form-data critical',
    ]);
});

test('a new advisory outside the baseline fails, a known one passes', () => {
    const known = [entry('GHSA-aaaa-bbbb-cccc', 'axios')];
    const result = evaluate(report, known, new Date('2026-10-16'));
    assert.deepEqual(result.fresh.map((f) => f.id), ['GHSA-gggg-hhhh-iiii']);
    assert.deepEqual(result.expired, []);

    const all = [...known, entry('GHSA-gggg-hhhh-iiii', 'form-data')];
    assert.deepEqual(evaluate(report, all, new Date('2026-10-16')).fresh, []);
});

test('an expired review date fails even when the advisory is gone, and gone ones are reported as stale', () => {
    const baseline = [entry('GHSA-aaaa-bbbb-cccc', 'axios', '2026-10-01'), entry('GHSA-zzzz-zzzz-zzzz', 'old', '2099-01-01')];
    const result = evaluate(report, baseline, new Date('2026-10-16'));
    assert.deepEqual(result.expired.map((b) => b.id), ['GHSA-aaaa-bbbb-cccc']);
    assert.deepEqual(result.stale.map((b) => b.id), ['GHSA-zzzz-zzzz-zzzz']);
});

test('baseline entries need a reason and a review date', () => {
    assert.deepEqual(baselineErrors([entry('GHSA-aaaa-bbbb-cccc', 'axios')]), []);
    assert.equal(baselineErrors([{ id: 'x', package: 'p', severity: 'high', review_by: '2026-11-01' }]).length, 1);
    assert.equal(baselineErrors([{ ...entry('x', 'p'), review_by: 'next month' }]).length, 1);
    assert.equal(baselineErrors({}).length, 1);
});

test('the shipped baseline is well formed and reviewed within 90 days', () => {
    const baseline = JSON.parse(readFileSync(new URL('../../security/npm-audit-baseline.json', import.meta.url), 'utf8'));
    assert.deepEqual(baselineErrors(baseline), []);
    for (const b of baseline) {
        assert.ok(['high', 'critical'].includes(b.severity), b.id);
        assert.ok(b.review_by <= '2027-01-14', `${b.id}: review_by أبعد من 90 يوماً`);
    }
});
