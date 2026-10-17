#!/usr/bin/env node
// بوابة npm audit للحزم التشغيلية (E26.9). تقارن نتيجة npm audit --omit=dev --json بالخط الأساسي
// security/npm-audit-baseline.json وصيغته [{id, package, severity, reason, review_by}]:
//   0  لا جديد: كل ثغرة high أو critical في الخط الأساسي بسبب لم ينقضِ تاريخ مراجعته
//   1  ثغرة high أو critical جديدة (تُطبع بأسمائها)، أو بند انقضى review_by، أو خط أساسي معطوب
//   2  تعذّر التدقيق (لا شبكة مثلاً): المستدعي يقرر، وscripts/deploy.sh يكتفي بالتحذير
// التشغيل: node scripts/audit/npm-audit-gate.mjs [--report=audit.json] [--baseline=path]
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

export const BLOCKING = ['high', 'critical'];
const DATE = /^\d{4}-\d{2}-\d{2}$/;

/** معرّف الإرشاد: GHSA من رابطه، وإلا رقمه في سجل npm */
const advisoryId = (via) => (String(via.url || '').match(/GHSA-[0-9a-z]{4}-[0-9a-z]{4}-[0-9a-z]{4}/i)?.[0] ?? String(via.source ?? via.title ?? 'unknown'));

/** كل إرشاد high أو critical في تقرير npm audit (الصيغة 2)، بلا تكرار */
export function findings(report) {
    const out = new Map();
    for (const [name, vuln] of Object.entries(report?.vulnerabilities ?? {})) {
        for (const via of vuln?.via ?? []) {
            if (via && typeof via === 'object' && BLOCKING.includes(via.severity)) {
                const item = { id: advisoryId(via), package: via.name || name, severity: via.severity, title: via.title || '' };
                out.set(`${item.id}|${item.package}`, item);
            }
        }
    }
    return [...out.values()];
}

/** أخطاء صيغة الخط الأساسي: كل بند بمعرّف وحزمة وشدة وسبب وتاريخ مراجعة */
export function baselineErrors(baseline) {
    if (!Array.isArray(baseline)) return ['الخط الأساسي ليس مصفوفة'];
    return baseline.flatMap((entry, i) => {
        const missing = ['id', 'package', 'severity', 'reason', 'review_by'].filter((k) => typeof entry?.[k] !== 'string' || entry[k].trim() === '');
        if (missing.length) return [`البند ${i + 1}: ناقص ${missing.join('، ')}`];
        return DATE.test(entry.review_by) ? [] : [`البند ${i + 1} (${entry.id}): review_by ليس تاريخاً YYYY-MM-DD`];
    });
}

/**
 * fresh: ثغرة مانعة ليست في الخط الأساسي. expired: بند انقضى تاريخ مراجعته (ولو أُغلقت ثغرته فليُحذف).
 * stale: بند لم يعد يظهر في التدقيق، للعلم فقط (يُحذف عند المراجعة).
 */
export function evaluate(report, baseline, today = new Date()) {
    const day = today.toISOString().slice(0, 10);
    const known = new Set(baseline.map((b) => `${b.id}|${b.package}`));
    const found = findings(report);
    const reported = new Set(found.map((f) => `${f.id}|${f.package}`));

    return {
        fresh: found.filter((f) => !known.has(`${f.id}|${f.package}`)),
        expired: baseline.filter((b) => b.review_by < day),
        stale: baseline.filter((b) => !reported.has(`${b.id}|${b.package}`)),
    };
}

function runAudit(cwd) {
    const res = spawnSync('npm', ['audit', '--omit=dev', '--json'], { cwd, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
    // npm audit يخرج بـ 1 حين يجد ثغرات: العبرة بالـ JSON لا برمز الخروج
    try {
        const report = JSON.parse(res.stdout || '');
        return report && !report.error && report.vulnerabilities ? report : null;
    } catch {
        return null;
    }
}

function main(argv) {
    const root = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
    const arg = (name) => argv.find((a) => a.startsWith(`--${name}=`))?.slice(name.length + 3);

    let baseline;
    try {
        baseline = JSON.parse(readFileSync(arg('baseline') ?? resolve(root, 'security/npm-audit-baseline.json'), 'utf8'));
    } catch (e) {
        console.error(`❌ تعذّرت قراءة الخط الأساسي: ${e.message}`);
        return 1;
    }
    const errors = baselineErrors(baseline);
    if (errors.length) {
        errors.forEach((e) => console.error(`❌ ${e}`));
        return 1;
    }

    const report = arg('report') ? JSON.parse(readFileSync(arg('report'), 'utf8')) : runAudit(root);
    if (!report) {
        console.warn('⚠️ تعذّر تشغيل npm audit (الشبكة؟): لم يُفحص شيء');
        return 2;
    }

    const { fresh, expired, stale } = evaluate(report, baseline);
    const total = findings(report).length;
    console.log(`npm audit (تشغيلي): ${total} إرشاداً high/critical، منها ${total - fresh.length} في الخط الأساسي`);
    stale.forEach((b) => console.log(`ℹ️ لم يعد يظهر، احذفه من الخط الأساسي: ${b.id} (${b.package})`));
    fresh.forEach((f) => console.error(`❌ جديد: ${f.id} ${f.package} [${f.severity}] ${f.title}`));
    expired.forEach((b) => console.error(`❌ انقضت مراجعته (${b.review_by}): ${b.id} ${b.package} — ${b.reason}`));

    return fresh.length || expired.length ? 1 : 0;
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    process.exitCode = main(process.argv.slice(2));
}
