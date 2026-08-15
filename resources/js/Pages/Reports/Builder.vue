<template>
  <AppLayout>
    <template #header>منشئ التقارير</template>

    <div class="rb-tool">
      <div class="rb-top">
        <p class="rb-sub">{{ subText }}</p>
        <span class="rb-stamp">لقطة {{ (D.snapshot || '').slice(0, 16) }}</span>
      </div>

      <div class="shell">
        <aside class="rail" aria-label="خيارات التقرير">
          <div class="grp">
            <p class="grp-t">محور التقرير</p>
            <div class="seg" role="group">
              <button type="button" :aria-pressed="S.axis === 'agent'" @click="S.axis = 'agent'">الوكلاء</button>
              <button type="button" :aria-pressed="S.axis === 'client'" @click="S.axis = 'client'">العملاء</button>
            </div>
          </div>

          <div class="grp">
            <p class="grp-t">الفترة</p>
            <div class="presets">
              <button v-for="p in PRESETS" :key="p.k" type="button" class="pill"
                :aria-pressed="S.preset === p.k" @click="applyPreset(p)">{{ p.l }}</button>
            </div>
            <div class="dates">
              <div><label for="rb-from">من</label>
                <input type="date" id="rb-from" v-model="S.from" @change="S.preset = ''"></div>
              <div><label for="rb-to">إلى</label>
                <input type="date" id="rb-to" v-model="S.to" @change="S.preset = ''"></div>
            </div>
          </div>

          <div class="grp">
            <p class="grp-t">الوكلاء</p>
            <div class="mini"><button type="button" @click="setAll('agents', D.agents)">الكل</button>
              <button type="button" @click="S.agents = new Set()">لا شيء</button></div>
            <input type="search" v-model="S.aq" placeholder="ابحث عن وكيل..." aria-label="بحث عن وكيل" style="margin-bottom:7px">
            <div class="listbox">
              <label v-for="a in agentList" :key="a.id" class="opt" :class="{ off: !countsAgent[a.id] }">
                <input type="checkbox" :checked="S.agents.has(a.id)" @change="toggle(S.agents, a.id, $event)">
                <span class="nm">{{ a.name }}</span><span class="ct">{{ countsAgent[a.id] ? fmt(countsAgent[a.id]) : '—' }}</span>
              </label>
              <div v-if="!agentList.length" style="padding:10px;font-size:12px;color:var(--muted)">لا نتائج</div>
            </div>
          </div>

          <div class="grp">
            <p class="grp-t">العملاء</p>
            <div class="mini"><button type="button" @click="setAll('clients', D.clients)">الكل</button>
              <button type="button" @click="S.clients = new Set()">لا شيء</button></div>
            <input type="search" v-model="S.cq" placeholder="ابحث عن عميل..." aria-label="بحث عن عميل" style="margin-bottom:7px">
            <div class="listbox">
              <label v-for="c in clientList" :key="c.id" class="opt" :class="{ off: !countsClient[c.id] }">
                <input type="checkbox" :checked="S.clients.has(c.id)" @change="toggle(S.clients, c.id, $event)">
                <span class="nm">{{ c.name }}</span><span class="ct">{{ countsClient[c.id] ? fmt(countsClient[c.id]) : '—' }}</span>
              </label>
              <div v-if="!clientList.length" style="padding:10px;font-size:12px;color:var(--muted)">لا نتائج</div>
            </div>
          </div>

          <div class="grp">
            <p class="grp-t">الخدمات</p>
            <div class="mini"><button type="button" @click="S.types = new Set(TYPE_IDS)">الكل</button>
              <button type="button" @click="S.types = new Set()">لا شيء</button></div>
            <div class="listbox">
              <label v-for="s in D.services" :key="s.id" class="opt" :class="{ off: !countsType[s.id] }">
                <input type="checkbox" :checked="S.types.has(s.id)" @change="toggle(S.types, s.id, $event)">
                <span class="nm">{{ s.name }}</span><span class="ct">{{ countsType[s.id] ? fmt(countsType[s.id]) : '—' }}</span>
              </label>
            </div>
          </div>

          <div class="grp">
            <p class="grp-t">الأقسام المعروضة</p>
            <label v-for="sec in SECTIONS" :key="sec[0]" class="chk">
              <input type="checkbox" :checked="S.sections.has(sec[0])" @change="toggle(S.sections, sec[0], $event)">{{ sec[1] }}
            </label>
          </div>

          <div class="grp">
            <p class="grp-t">أعمدة الجدول</p>
            <label v-for="col in COLS" :key="col[0]" class="chk">
              <input type="checkbox" :checked="S.cols.has(col[0])" @change="toggle(S.cols, col[0], $event)">{{ col[1] }}
            </label>
          </div>

          <div class="grp">
            <p class="grp-t">الترتيب</p>
            <select v-model="S.sort">
              <option value="qty">الكمية (تنازلي)</option>
              <option value="sell">المبيع (تنازلي)</option>
              <option value="margin">الهامش (تنازلي)</option>
              <option value="inv">عدد الفواتير (تنازلي)</option>
              <option value="name">الاسم (أبجدي)</option>
            </select>
          </div>

          <div class="act">
            <button type="button" class="primary" @click="exportCsv">⬇️ تصدير Excel</button>
            <button type="button" @click="printReport">🖨️ طباعة</button>
            <button type="button" @click="reset">إعادة تعيين</button>
          </div>
        </aside>

        <main class="report" v-html="reportHtml"></main>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { reactive, computed } from 'vue';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({ title: String, data: Object });
const D = props.data;
const RATE = D.rate, TODAY = D.today;

// ---- أنواع الخدمات من جدول services (المفتاح = service_id) ----
const TYPE_IDS = D.services.map(s => s.id);
const TL = {}, TCOLOR = {};
D.services.forEach((s, i) => { TL[s.id] = s.name; TCOLOR[s.id] = 's' + ((i % 7) + 1); });

const MIND = D.facts.length ? D.facts[0].d : TODAY;
const MAXD = D.facts.length ? D.facts[D.facts.length - 1].d : TODAY;
const BASE_QTY = D.facts.reduce((s, r) => s + r.q, 0);

const SECTIONS = [
  ['tiles', 'بطاقات الملخص', true], ['main', 'جدول التفصيل', true],
  ['types', 'توزيع الخدمات', true], ['monthly', 'الاتجاه الشهري', true],
  ['top', 'أكبر الفواتير', true], ['fin', 'الموقف المالي', true],
];
const COLS = [
  ['qty', 'الكمية', true], ['share', 'النسبة', true], ['bar', 'شريط الخدمات', true],
  ['inv', 'الفواتير', true], ['others', 'الأطراف المقابلة', true],
  ['cost', 'التكلفة (ر.س)', true], ['sell', 'المبيع (د.أ)', true],
  ['cpv', 'تكلفة/وحدة', false], ['spv', 'مبيع/وحدة', false], ['margin', 'الهامش/وحدة', true],
  ['bal', 'الرصيد (د.أ)', false],
];

const AG = {}; D.agents.forEach(a => AG[a.id] = a);
const CL = {}; D.clients.forEach(c => CL[c.id] = c);
const countsAgent = {}, countsClient = {}, countsType = {};
D.facts.forEach(r => {
  countsAgent[r.a] = (countsAgent[r.a] || 0) + r.q;
  countsClient[r.c] = (countsClient[r.c] || 0) + r.q;
  countsType[r.s] = (countsType[r.s] || 0) + r.q;
});

const S = reactive({
  axis: 'agent', from: MIND, to: MAXD, preset: 'all',
  agents: new Set(D.agents.map(a => a.id)),
  clients: new Set(D.clients.map(c => c.id)),
  types: new Set(TYPE_IDS),
  sections: new Set(SECTIONS.filter(s => s[2]).map(s => s[0])),
  cols: new Set(COLS.filter(c => c[2]).map(c => c[0])),
  sort: 'qty', cq: '', aq: '',
});

// ---- أدوات تنسيق ----
const fmt = n => Math.round(Number(n) || 0).toLocaleString('en-US');
const f1 = n => (Number(n) || 0).toLocaleString('en-US', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
const f2 = n => (Number(n) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const pc = (x, base) => base ? (x / base * 100).toFixed(1) + '%' : '—';
const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const addDays = (d, n) => { const x = new Date(d); x.setDate(x.getDate() + n); return x.toISOString().slice(0, 10); };

const PRESETS = [
  { k: 'all', l: 'كل الفترة', fn: () => [MIND, MAXD] },
  { k: 'm', l: 'الشهر الحالي', fn: () => [TODAY.slice(0, 8) + '01', TODAY] },
  { k: '30', l: 'آخر 30 يوماً', fn: () => [addDays(TODAY, -30), TODAY] },
  { k: '90', l: 'آخر 90 يوماً', fn: () => [addDays(TODAY, -90), TODAY] },
  { k: 'prev', l: 'الشهر الماضي', fn: () => {
      const d0 = new Date(TODAY); d0.setDate(1); d0.setMonth(d0.getMonth() - 1);
      const a = d0.toISOString().slice(0, 10);
      const d1 = new Date(d0); d1.setMonth(d1.getMonth() + 1); d1.setDate(0);
      return [a, d1.toISOString().slice(0, 10)];
    } },
];

const subText = computed(() =>
  `بيانات الإنتاج الحالية: ${fmt(D.facts.length)} بند خدمة · ${fmt(BASE_QTY)} وحدة · ${fmt(new Set(D.facts.map(r => r.ii)).size)} فاتورة معتمدة. غيّر أي خيار وسيُعاد بناء التقرير فوراً.`);

const agentList = computed(() => D.agents.filter(a => !S.aq || a.name.includes(S.aq) || a.code.includes(S.aq)));
const clientList = computed(() => D.clients.filter(c => !S.cq || c.name.includes(S.cq) || c.code.includes(S.cq)));

// ---- تفاعلات ----
function toggle(set, v, ev) { ev.target.checked ? set.add(v) : set.delete(v); }
function setAll(key, list) { S[key] = new Set(list.map(x => x.id)); }
function applyPreset(p) { const [a, b] = p.fn(); S.from = a; S.to = b; S.preset = p.k; }
function printReport() { window.print(); }

// ---- تصدير CSV (يفتحه Excel) — يحترم المحور والأعمدة والفلاتر الحالية ----
function csvCell(v) {
  const s = String(v == null ? '' : v);
  return /[",\n\r]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
}
function exportCsv() {
  const rows = filtered();
  const agg = aggregate(rows);
  const axisLbl = S.axis === 'agent' ? 'الوكيل' : 'العميل';
  const otherLbl = S.axis === 'agent' ? 'العملاء' : 'الوكلاء';
  const C = S.cols;
  const tot = {
    qty: rows.reduce((s, r) => s + r.q, 0), cost: rows.reduce((s, r) => s + r.co, 0),
    sell: rows.reduce((s, r) => s + r.se, 0), inv: new Set(rows.map(r => r.ii)).size,
  };

  // ترتيب الأعمدة حسب المُفعّل (مطابق للجدول المعروض، بدون العمود المرئي "شريط الخدمات")
  const cols = [['#', '#'], ['name', axisLbl], ['code', 'الكود']];
  if (C.has('qty')) cols.push(['qty', 'الكمية']);
  if (C.has('share')) cols.push(['share', 'النسبة %']);
  if (C.has('inv')) cols.push(['inv', 'الفواتير']);
  if (C.has('others')) cols.push(['others', otherLbl]);
  if (C.has('cost')) cols.push(['cost', 'التكلفة (ر.س)']);
  if (C.has('sell')) cols.push(['sell', 'المبيع (د.أ)']);
  if (C.has('cpv')) cols.push(['cpv', 'تكلفة/وحدة']);
  if (C.has('spv')) cols.push(['spv', 'مبيع/وحدة']);
  if (C.has('margin')) cols.push(['margin', 'الهامش/وحدة']);
  if (C.has('bal')) cols.push(['bal', 'الرصيد (د.أ)']);
  const typeCols = TYPE_IDS.filter(ti => S.types.has(ti));

  const cell = (e, i, k) => {
    if (k === '#') return i + 1;
    if (k === 'name') return e.name;
    if (k === 'code') return e.code;
    if (k === 'share') return tot.qty ? (e.qty / tot.qty * 100).toFixed(1) : '0';
    if (k === 'qty' || k === 'inv' || k === 'others') return e[k];
    if (k === 'cpv') return e.cpv.toFixed(1);
    return (e[k] || 0).toFixed(2);
  };

  const lines = [];
  lines.push([`تقرير الخدمات حسب ${axisLbl}`]);
  lines.push([`الفترة: ${S.from} إلى ${S.to}`]);
  lines.push([`الخدمات: ${typeCols.map(ti => TL[ti]).join(' · ')}`]);
  lines.push([]);
  lines.push([...cols.map(c => c[1]), ...typeCols.map(ti => TL[ti])]);
  agg.forEach((e, i) => {
    const row = cols.map(([k]) => cell(e, i, k));
    typeCols.forEach(ti => row.push(e.t[ti] || 0));
    lines.push(row);
  });
  // صف المجموع
  const totRow = cols.map(([k]) => {
    if (k === 'name') return 'المجموع';
    if (k === 'qty') return tot.qty;
    if (k === 'share') return '100';
    if (k === 'inv') return tot.inv;
    if (k === 'cost') return tot.cost.toFixed(2);
    if (k === 'sell') return tot.sell.toFixed(2);
    return '';
  });
  typeCols.forEach(() => totRow.push(''));
  lines.push(totRow);

  const csv = '﻿' + lines.map(r => r.map(csvCell).join(',')).join('\r\n');
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `report_${S.axis}_${S.from}_${S.to}.csv`;
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1500);
}
function reset() {
  S.axis = 'agent'; S.preset = 'all'; S.from = MIND; S.to = MAXD;
  S.agents = new Set(D.agents.map(a => a.id)); S.clients = new Set(D.clients.map(c => c.id));
  S.types = new Set(TYPE_IDS); S.sort = 'qty'; S.cq = ''; S.aq = '';
  S.sections = new Set(SECTIONS.filter(s => s[2]).map(s => s[0]));
  S.cols = new Set(COLS.filter(c => c[2]).map(c => c[0]));
}

// ---- التجميع ----
function filtered() {
  return D.facts.filter(r =>
    r.d >= S.from && r.d <= S.to &&
    S.agents.has(r.a) && S.clients.has(r.c) && S.types.has(r.s));
}
function aggregate(rows) {
  const key = S.axis === 'agent' ? 'a' : 'c';
  const other = S.axis === 'agent' ? 'c' : 'a';
  const g = new Map();
  rows.forEach(r => {
    let e = g.get(r[key]);
    if (!e) { e = { id: r[key], qty: 0, cost: 0, sell: 0, inv: new Set(), others: new Set(), t: {}, m: {} }; g.set(r[key], e); }
    e.qty += r.q; e.cost += r.co; e.sell += r.se;
    e.inv.add(r.ii); e.others.add(r[other]);
    e.t[r.s] = (e.t[r.s] || 0) + r.q;
  });
  const arr = [...g.values()].map(e => {
    const meta = S.axis === 'agent' ? AG[e.id] : CL[e.id];
    return { ...e, inv: e.inv.size, others: e.others.size,
      name: meta ? meta.name : '—', code: meta ? meta.code : '',
      bal: meta ? meta.bal : 0,
      cpv: e.qty ? e.cost / e.qty : 0, spv: e.qty ? e.sell / e.qty : 0,
      margin: e.qty ? (e.sell / e.qty) - (e.cost / e.qty * RATE) : 0 };
  });
  const cmp = {
    qty: (x, y) => y.qty - x.qty, sell: (x, y) => y.sell - x.sell, margin: (x, y) => y.margin - x.margin,
    inv: (x, y) => y.inv - x.inv, name: (x, y) => x.name.localeCompare(y.name, 'ar'),
  }[S.sort];
  return arr.sort(cmp);
}
function typeSegs(t, qty) {
  return TYPE_IDS.map(ti => t[ti]
    ? `<span class="bs" style="background:var(--${TCOLOR[ti]});width:${(t[ti] / qty * 100).toFixed(2)}%"></span>` : '').join('');
}
function typeLegend() {
  return TYPE_IDS.filter(ti => S.types.has(ti))
    .map(ti => `<span><i style="background:var(--${TCOLOR[ti]})"></i>${esc(TL[ti])}</span>`).join('');
}

// ---- بناء التقرير (يعيد HTML) ----
function buildReportHtml() {
  const rows = filtered();
  const agg = aggregate(rows);
  const tot = {
    qty: rows.reduce((s, r) => s + r.q, 0), cost: rows.reduce((s, r) => s + r.co, 0),
    sell: rows.reduce((s, r) => s + r.se, 0), inv: new Set(rows.map(r => r.ii)).size, items: rows.length,
  };
  tot.cpv = tot.qty ? tot.cost / tot.qty : 0;
  tot.spv = tot.qty ? tot.sell / tot.qty : 0;
  tot.margin = tot.spv - tot.cpv * RATE;

  const axisLbl = S.axis === 'agent' ? 'الوكيل' : 'العميل';
  const otherLbl = S.axis === 'agent' ? 'العملاء' : 'الوكلاء';
  let h = `<div class="rhead"><h2>تقرير الخدمات حسب ${axisLbl}</h2>
    <div class="rmeta">
      <span>الفترة: <b>${S.from} → ${S.to}</b></span>
      <span>${axisLbl}: <b>${agg.length}</b></span>
      <span>الفواتير: <b>${fmt(tot.inv)}</b></span>
      <span>البنود: <b>${fmt(tot.items)}</b></span>
      <span>الخدمات: <b>${S.types.size} من ${TYPE_IDS.length}</b></span>
    </div></div>`;

  if (!rows.length) {
    return h + `<div class="empty">لا توجد بيانات ضمن هذه الخيارات — وسّع الفترة أو أعد تفعيل بعض الوكلاء أو العملاء أو الخدمات.</div>`;
  }

  if (S.sections.has('tiles')) {
    h += `<section class="tiles">
      <div class="tile"><span class="tl">الكمية المباعة</span><span class="tv">${fmt(tot.qty)}</span>
        <span class="tn">${pc(tot.qty, BASE_QTY)} من إجمالي القاعدة (${fmt(BASE_QTY)})</span></div>
      <div class="tile"><span class="tl">${axisLbl}</span><span class="tv">${agg.length}</span>
        <span class="tn">أعلاهم ${esc(agg[0].name)} بـ${fmt(agg[0].qty)}</span></div>
      <div class="tile"><span class="tl">الفواتير</span><span class="tv">${fmt(tot.inv)}</span>
        <span class="tn">متوسط ${f1(tot.qty / tot.inv)} وحدة للفاتورة</span></div>
      <div class="tile"><span class="tl">المبيع المنسوب</span><span class="tv">${f2(tot.sell)}<span class="u"> د.أ</span></span>
        <span class="tn">تكلفة ${f2(tot.cost)} ر.س</span></div>
      <div class="tile"><span class="tl">هامش/وحدة</span><span class="tv ${tot.margin >= 0 ? 'good' : 'warn'}">${f2(tot.margin)}<span class="u"> د.أ</span></span>
        <span class="tn">${pc(tot.margin, tot.spv)} من سعر البيع</span></div>
    </section>`;
  }

  if (S.sections.has('main')) {
    const C = S.cols;
    let head = `<th class="n">#</th><th>${axisLbl}</th>`;
    if (C.has('qty')) head += '<th class="n">الكمية</th>';
    if (C.has('share')) head += '<th class="n">النسبة</th>';
    if (C.has('bar')) head += '<th>الخدمات</th>';
    if (C.has('inv')) head += '<th class="n">فواتير</th>';
    if (C.has('others')) head += `<th class="n">${otherLbl}</th>`;
    if (C.has('cost')) head += '<th class="n">التكلفة (ر.س)</th>';
    if (C.has('sell')) head += '<th class="n">المبيع (د.أ)</th>';
    if (C.has('cpv')) head += '<th class="n">تكلفة/وحدة</th>';
    if (C.has('spv')) head += '<th class="n">مبيع/وحدة</th>';
    if (C.has('margin')) head += '<th class="n">هامش/وحدة</th>';
    if (C.has('bal')) head += '<th class="n">الرصيد (د.أ)</th>';

    const body = agg.map((e, i) => {
      let r = `<tr><td class="n dim">${i + 1}</td>
        <td><div class="ent">${esc(e.name)}</div><div class="code">${esc(e.code)}</div></td>`;
      if (C.has('qty')) r += `<td class="n strong">${fmt(e.qty)}</td>`;
      if (C.has('share')) r += `<td class="n dim">${pc(e.qty, tot.qty)}</td>`;
      if (C.has('bar')) r += `<td><div class="bar" role="img" aria-label="${TYPE_IDS.map(ti => e.t[ti] ? TL[ti] + ' ' + e.t[ti] : null).filter(Boolean).join('، ')}">${typeSegs(e.t, e.qty)}</div></td>`;
      if (C.has('inv')) r += `<td class="n">${fmt(e.inv)}</td>`;
      if (C.has('others')) r += `<td class="n">${fmt(e.others)}</td>`;
      if (C.has('cost')) r += `<td class="n">${f2(e.cost)}</td>`;
      if (C.has('sell')) r += `<td class="n">${f2(e.sell)}</td>`;
      if (C.has('cpv')) r += `<td class="n dim">${f1(e.cpv)}</td>`;
      if (C.has('spv')) r += `<td class="n dim">${f2(e.spv)}</td>`;
      if (C.has('margin')) r += `<td class="n ${e.margin >= 0 ? 'good' : 'warn'}">${f2(e.margin)}</td>`;
      if (C.has('bal')) r += `<td class="n ${e.bal >= 0 ? '' : 'warn'}">${f2(e.bal)}</td>`;
      return r + '</tr>';
    }).join('');

    let foot = `<td></td><td>المجموع</td>`;
    if (C.has('qty')) foot += `<td class="n">${fmt(tot.qty)}</td>`;
    if (C.has('share')) foot += '<td class="n">100%</td>';
    if (C.has('bar')) foot += '<td></td>';
    if (C.has('inv')) foot += `<td class="n">${fmt(tot.inv)}</td>`;
    if (C.has('others')) foot += '<td></td>';
    if (C.has('cost')) foot += `<td class="n">${f2(tot.cost)}</td>`;
    if (C.has('sell')) foot += `<td class="n">${f2(tot.sell)}</td>`;
    if (C.has('cpv')) foot += `<td class="n">${f1(tot.cpv)}</td>`;
    if (C.has('spv')) foot += `<td class="n">${f2(tot.spv)}</td>`;
    if (C.has('margin')) foot += `<td class="n ${tot.margin >= 0 ? 'good' : 'warn'}">${f2(tot.margin)}</td>`;
    if (C.has('bal')) foot += '<td></td>';

    h += `<section class="sec">
      <div class="sec-h"><h3>التفصيل حسب ${axisLbl}</h3><span>${agg.length} صفاً · مرتّب حسب ${
        { qty: 'الكمية', sell: 'المبيع', margin: 'الهامش', inv: 'الفواتير', name: 'الاسم' }[S.sort]}</span></div>
      <div class="legend">${typeLegend()}</div>
      <div class="tw"><table><thead><tr>${head}</tr></thead><tbody>${body}</tbody>
        <tfoot><tr>${foot}</tr></tfoot></table></div></section>`;
  }

  if (S.sections.has('types')) {
    const byT = {};
    rows.forEach(r => { const t = byT[r.s] || (byT[r.s] = { q: 0, c: 0, s: 0, inv: new Set(), ent: new Set() });
      t.q += r.q; t.c += r.co; t.s += r.se; t.inv.add(r.ii); t.ent.add(S.axis === 'agent' ? r.a : r.c); });
    const tr = Object.keys(byT).map(Number).sort((a, b) => byT[b].q - byT[a].q).map(ti => {
      const t = byT[ti], cpv = t.c / t.q, spv = t.s / t.q, m = spv - cpv * RATE;
      return `<tr><td><i style="background:var(--${TCOLOR[ti] || 'zero'});width:10px;height:10px;border-radius:2px;display:inline-block;margin-left:7px"></i>${esc(TL[ti] || '—')}</td>
        <td class="n strong">${fmt(t.q)}</td><td class="n dim">${pc(t.q, tot.qty)}</td>
        <td class="n">${fmt(t.inv.size)}</td><td class="n">${fmt(t.ent.size)}</td>
        <td class="n">${f2(t.c)}</td><td class="n">${f2(t.s)}</td>
        <td class="n dim">${f1(cpv)}</td><td class="n dim">${f2(spv)}</td>
        <td class="n ${m >= 0 ? 'good' : 'warn'}">${f2(m)}</td></tr>`;
    }).join('');
    h += `<section class="sec"><div class="sec-h"><h3>توزيع الخدمات</h3><span>ضمن التحديد الحالي</span></div>
      <div class="tw"><table><thead><tr><th>الخدمة</th><th class="n">الكمية</th><th class="n">النسبة</th>
        <th class="n">فواتير</th><th class="n">${axisLbl}</th><th class="n">التكلفة (ر.س)</th><th class="n">المبيع (د.أ)</th>
        <th class="n">تكلفة/وحدة</th><th class="n">مبيع/وحدة</th><th class="n">هامش/وحدة</th></tr></thead>
        <tbody>${tr}</tbody></table></div></section>`;
  }

  if (S.sections.has('monthly')) {
    const mo = {};
    rows.forEach(r => { const k = r.d.slice(0, 7); const m = mo[k] || (mo[k] = { q: 0, inv: new Set(), ent: new Set() });
      m.q += r.q; m.inv.add(r.ii); m.ent.add(S.axis === 'agent' ? r.a : r.c); });
    const keys = Object.keys(mo).sort();
    const mx = Math.max(...keys.map(k => mo[k].q));
    h += `<section class="sec"><div class="sec-h"><h3>الاتجاه الشهري</h3><span>${keys.length} أشهر ضمن الفترة</span></div>
      <div class="months">${keys.map(k => `<div class="mo">
        <div class="mo-t"><span class="mo-n">${k}</span><span class="mo-v">${fmt(mo[k].q)}</span></div>
        <div class="mo-b"><div class="mo-f" style="width:${(mo[k].q / mx * 100).toFixed(1)}%"></div></div>
        <span class="tn">${mo[k].inv.size} فاتورة · ${mo[k].ent.size} ${axisLbl}</span></div>`).join('')}</div></section>`;
  }

  if (S.sections.has('top')) {
    const inv = new Map();
    rows.forEach(r => { let e = inv.get(r.ii);
      if (!e) { e = { n: r.n, d: r.d, a: r.a, c: r.c, q: 0, s: 0 }; inv.set(r.ii, e); }
      e.q += r.q; e.s += r.se; });
    const top = [...inv.values()].sort((x, y) => y.q - x.q).slice(0, 8);
    h += `<section class="sec"><div class="sec-h"><h3>أكبر الفواتير</h3><span>أعلى ${top.length} من حيث الكمية</span></div>
      <div class="tw"><table><thead><tr><th class="n">#</th><th class="n">الفاتورة</th><th>الوكيل</th><th>العميل</th>
        <th class="n">الكمية</th><th class="n">من التحديد</th><th class="n">المبيع (د.أ)</th><th class="n">التاريخ</th></tr></thead>
        <tbody>${top.map((r, i) => `<tr><td class="n dim">${i + 1}</td><td class="n">${esc(r.n)}</td>
          <td>${esc((AG[r.a] || {}).name || '—')}</td><td>${esc((CL[r.c] || {}).name || '—')}</td>
          <td class="n strong">${fmt(r.q)}</td><td class="n dim">${pc(r.q, tot.qty)}</td>
          <td class="n">${f2(r.s)}</td><td class="n dim">${r.d}</td></tr>`).join('')}</tbody></table></div></section>`;
  }

  if (S.sections.has('fin')) {
    if (S.axis === 'agent') {
      h += `<section class="sec"><div class="sec-h"><h3>الموقف المالي</h3>
        <span>تكلفة الخدمات ضمن الفترة · الرصيد تراكمي حتى اليوم من دفتر القيود</span></div>
        <div class="tw"><table><thead><tr><th>الوكيل</th><th class="n">فواتير</th>
          <th class="n">تكلفة الخدمات (ر.س)</th><th class="n">المبيع المنسوب (د.أ)</th>
          <th class="n">الرصيد الحالي (د.أ)</th></tr></thead><tbody>${
        agg.map(e => `<tr><td><div class="ent">${esc(e.name)}</div><div class="code">${esc(e.code)}</div></td>
            <td class="n">${fmt(e.inv)}</td><td class="n">${f2(e.cost)}</td><td class="n">${f2(e.sell)}</td>
            <td class="n strong ${e.bal >= 0 ? '' : 'warn'}">${f2(e.bal)}</td></tr>`).join('')
        }</tbody></table></div></section>`;
    } else {
      const rc = {};
      D.receipts.filter(r => r.d >= S.from && r.d <= S.to && S.clients.has(r.c))
        .forEach(r => { const x = rc[r.c] || (rc[r.c] = { n: 0, jod: 0 }); x.n++; x.jod += r.jod; });
      h += `<section class="sec"><div class="sec-h"><h3>الموقف المالي</h3>
        <span>المبيع المنسوب مقابل التحصيل ضمن الفترة · الرصيد تراكمي من دفتر القيود</span></div>
        <div class="tw"><table><thead><tr><th>العميل</th><th class="n">المبيع المنسوب (د.أ)</th>
          <th class="n">سندات قبض</th><th class="n">المحصّل (د.أ)</th><th class="n">الرصيد الحالي (د.أ)</th></tr></thead>
          <tbody>${agg.map(e => { const r = rc[e.id];
            return `<tr><td><div class="ent">${esc(e.name)}</div><div class="code">${esc(e.code)}</div></td>
              <td class="n">${f2(e.sell)}</td><td class="n">${r ? fmt(r.n) : '<span class="zero">—</span>'}</td>
              <td class="n">${r ? f2(r.jod) : '<span class="zero">—</span>'}</td>
              <td class="n strong ${e.bal >= 0 ? '' : 'warn'}">${f2(e.bal)}</td></tr>`; }).join('')}
          </tbody></table></div></section>`;
    }
  }

  h += `<div class="note"><b>ملاحظات دقّة:</b> المبيع <b>منسوب</b> لكل بند بحصّته من مبيع الفاتورة نسبةً إلى التكلفة
    (لأن سعر البيع يُخزَّن على مستوى الفاتورة لا البند) — بنفس منطق ترحيل القيد المحاسبي.
    الهامش/وحدة = (المبيع ÷ الكمية) − (التكلفة ÷ الكمية × ${RATE})؛ للمقارنة لا كصافي ربح.
    الأرصدة بالدينار من دفتر القيود المزدوج (نفس مصدر كشوف الحسابات). المصدر: بنود الخدمات في الفواتير المعتمدة فقط.</div>`;

  return h;
}

const reportHtml = computed(() => buildReportHtml());
</script>

<style>
.rb-tool{
  --paper:#f6f3ec;--card:#fdfcf8;--rail:#f1ede3;--ink:#17140f;--ink-2:#4a443a;--muted:#6b655a;
  --rule:#dfd8ca;--rule-soft:#ebe5d9;--brass:#8a6a1f;--brass-soft:#f0e6cb;
  --s1:#2a78d6;--s2:#eb6834;--s3:#1baf7a;--s4:#eda100;--s5:#7a6ba8;--s6:#3f7a75;--s7:#b04e72;
  --zero:#b3aa98;--good:#1baf7a;--warn:#eb6834;--focus:#2a78d6;
  --ar:"SF Arabic","Geeza Pro","Segoe UI","Noto Naskh Arabic","Dubai",Tahoma,sans-serif;
  --mono:ui-monospace,"SF Mono","Cascadia Mono",Consolas,"DejaVu Sans Mono",monospace;
  direction:rtl;text-align:right;color:var(--ink);font-family:var(--ar);font-size:15px;line-height:1.65;
}
.dark .rb-tool{
  --paper:#14120e;--card:#1c1913;--rail:#191610;--ink:#f1ede3;--ink-2:#c4bcab;--muted:#a49b8a;
  --rule:#2e2a22;--rule-soft:#242019;--brass:#d4af37;--brass-soft:#2b2416;
  --s1:#3987e5;--s2:#d95926;--s3:#199e70;--s4:#c98500;--s5:#8f7fc0;--s6:#4f8f89;--s7:#c86186;
  --zero:#5c5548;--good:#199e70;--warn:#d95926;--focus:#3987e5;
}
.rb-tool :focus-visible{outline:2px solid var(--focus);outline-offset:2px}
.rb-tool .rb-top{display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;gap:12px;margin-bottom:16px}
.rb-tool .rb-sub{font-size:13px;color:var(--muted);max-width:80ch;margin:0}
.rb-tool .rb-stamp{font-family:var(--mono);font-size:11px;color:var(--brass);border:1px solid var(--brass);border-radius:2px;padding:3px 9px;direction:ltr;white-space:nowrap}
.rb-tool .shell{display:grid;grid-template-columns:290px minmax(0,1fr);gap:20px;align-items:start}
@media (max-width:960px){.rb-tool .shell{grid-template-columns:1fr}}
.rb-tool .rail{background:var(--rail);border:1px solid var(--rule);border-radius:4px;position:sticky;top:14px;max-height:calc(100vh - 28px);overflow-y:auto}
@media (max-width:960px){.rb-tool .rail{position:static;max-height:none}}
.rb-tool .grp{border-bottom:1px solid var(--rule);padding:14px 16px}
.rb-tool .grp:last-child{border-bottom:0}
.rb-tool .grp-t{font-size:12px;font-weight:700;color:var(--muted);margin:0 0 9px}
.rb-tool .seg{display:flex;gap:1px;background:var(--rule);border:1px solid var(--rule);border-radius:3px;overflow:hidden}
.rb-tool .seg button{flex:1;border:0;background:var(--card);color:var(--ink-2);font-family:var(--ar);font-size:13px;padding:7px 4px;cursor:pointer}
.rb-tool .seg button[aria-pressed="true"]{background:var(--ink);color:var(--paper);font-weight:700}
.rb-tool .presets{display:flex;flex-wrap:wrap;gap:5px;margin-bottom:9px}
.rb-tool .pill{border:1px solid var(--rule);background:var(--card);color:var(--ink-2);border-radius:11px;font-family:var(--ar);font-size:12px;padding:3px 10px;cursor:pointer}
.rb-tool .pill[aria-pressed="true"]{background:var(--brass-soft);border-color:var(--brass);color:var(--brass);font-weight:700}
.rb-tool .dates{display:grid;grid-template-columns:1fr 1fr;gap:7px}
.rb-tool .dates label{font-size:11px;color:var(--muted);display:block;margin-bottom:3px}
.rb-tool input[type=date],.rb-tool input[type=search]{width:100%;padding:6px 8px;border:1px solid var(--rule);border-radius:3px;background:var(--card);color:var(--ink);font-family:var(--mono);font-size:12px;direction:ltr}
.rb-tool input[type=search]{font-family:var(--ar);direction:rtl;font-size:12.5px}
.rb-tool .listbox{max-height:190px;overflow-y:auto;border:1px solid var(--rule);background:var(--card);border-radius:3px}
.rb-tool .opt{display:flex;align-items:center;gap:8px;padding:6px 10px;cursor:pointer;font-size:12.5px;border-bottom:1px solid var(--rule-soft)}
.rb-tool .opt:last-child{border-bottom:0}
.rb-tool .opt:hover{background:var(--rule-soft)}
.rb-tool .opt input{margin:0;flex:none;accent-color:var(--focus)}
.rb-tool .opt .nm{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.rb-tool .opt .ct{font-family:var(--mono);font-size:11px;color:var(--muted);flex:none}
.rb-tool .opt.off .nm{color:var(--zero)}
.rb-tool .mini{display:flex;gap:6px;margin-bottom:7px}
.rb-tool .mini button{flex:1;border:1px solid var(--rule);background:var(--card);color:var(--ink-2);font-family:var(--ar);font-size:11.5px;padding:4px;border-radius:3px;cursor:pointer}
.rb-tool .mini button:hover{background:var(--rule-soft)}
.rb-tool .chk{display:flex;align-items:center;gap:8px;padding:4px 0;font-size:12.5px;cursor:pointer}
.rb-tool .chk input{margin:0;accent-color:var(--focus)}
.rb-tool select{width:100%;padding:6px 8px;border:1px solid var(--rule);border-radius:3px;background:var(--card);color:var(--ink);font-family:var(--ar);font-size:12.5px}
.rb-tool .act{display:flex;flex-wrap:wrap;gap:7px;padding:14px 16px}
.rb-tool .act button{flex:1 1 40%;min-width:88px;border:1px solid var(--rule);background:var(--card);color:var(--ink-2);font-family:var(--ar);font-size:12.5px;padding:8px;border-radius:3px;cursor:pointer;white-space:nowrap}
.rb-tool .act .primary{background:var(--ink);color:var(--paper);border-color:var(--ink);font-weight:700}
.rb-tool .report{display:flex;flex-direction:column;gap:24px;min-width:0}
.rb-tool .rhead{border-bottom:1px solid var(--rule);padding-bottom:12px}
.rb-tool .rhead h2{margin:0;font-size:20px;font-weight:700}
.rb-tool .rmeta{font-size:12.5px;color:var(--muted);margin-top:5px;display:flex;flex-wrap:wrap;gap:4px 18px}
.rb-tool .rmeta b{color:var(--ink-2)}
.rb-tool .tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:1px;background:var(--rule);border:1px solid var(--rule)}
.rb-tool .tile{background:var(--card);padding:15px 17px;display:flex;flex-direction:column;gap:2px}
.rb-tool .tl{font-size:12px;color:var(--muted)}
.rb-tool .tv{font-family:var(--mono);font-variant-numeric:tabular-nums;font-size:26px;font-weight:600;direction:ltr;text-align:right}
.rb-tool .tv .u{font-size:13px;color:var(--muted);font-weight:400}
.rb-tool .tn{font-size:11.5px;color:var(--muted)}
.rb-tool .sec{display:flex;flex-direction:column;gap:12px}
.rb-tool .sec-h{display:flex;align-items:baseline;gap:12px;flex-wrap:wrap;border-bottom:1px solid var(--rule);padding-bottom:8px}
.rb-tool .sec-h h3{margin:0;font-size:16px;font-weight:700}
.rb-tool .sec-h span{font-size:12px;color:var(--muted)}
.rb-tool .tw{overflow-x:auto;border:1px solid var(--rule);background:var(--card)}
.rb-tool table{width:100%;border-collapse:collapse;font-size:13.5px}
.rb-tool thead th{font-size:11.5px;font-weight:600;color:var(--muted);text-align:right;padding:9px 12px;border-bottom:1px solid var(--rule);background:var(--rule-soft);white-space:nowrap}
.rb-tool tbody td{padding:8px 12px;border-bottom:1px solid var(--rule-soft)}
.rb-tool tbody tr:last-child td{border-bottom:0}
.rb-tool tbody tr:hover td{background:var(--rule-soft)}
.rb-tool tfoot td{padding:10px 12px;border-top:2px solid var(--ink);font-weight:700;background:var(--rule-soft)}
.rb-tool .n{font-family:var(--mono);font-variant-numeric:tabular-nums;direction:ltr;text-align:left;white-space:nowrap}
.rb-tool th.n{text-align:left}
.rb-tool .strong{font-weight:600}.rb-tool .dim{color:var(--muted)}.rb-tool .zero{color:var(--zero)}
.rb-tool .good{color:var(--good)}.rb-tool .warn{color:var(--warn)}
.rb-tool .ent{font-weight:600}
.rb-tool .code{font-family:var(--mono);font-size:10.5px;color:var(--muted);direction:ltr}
.rb-tool .bar{display:flex;gap:2px;height:9px;background:var(--rule-soft);border-radius:2px;overflow:hidden;min-width:80px}
.rb-tool .bs{height:100%;min-width:2px}
.rb-tool .months{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:1px;background:var(--rule);border:1px solid var(--rule)}
.rb-tool .mo{background:var(--card);padding:12px 14px;display:flex;flex-direction:column;gap:7px}
.rb-tool .mo-t{display:flex;align-items:baseline;justify-content:space-between;gap:6px}
.rb-tool .mo-n{font-family:var(--mono);font-size:12.5px;color:var(--ink-2);direction:ltr}
.rb-tool .mo-v{font-family:var(--mono);font-variant-numeric:tabular-nums;font-size:17px;font-weight:600;direction:ltr}
.rb-tool .mo-b{height:5px;background:var(--rule-soft);border-radius:2px;overflow:hidden}
.rb-tool .mo-f{height:100%;background:var(--s1);border-radius:2px;min-width:2px}
.rb-tool .legend{display:flex;flex-wrap:wrap;gap:5px 18px;font-size:12.5px;color:var(--ink-2)}
.rb-tool .legend i{width:10px;height:10px;border-radius:2px;display:inline-block;margin-left:6px;vertical-align:-1px}
.rb-tool .empty{padding:40px 20px;text-align:center;color:var(--muted);border:1px dashed var(--rule);background:var(--card)}
.rb-tool .note{background:var(--card);border:1px solid var(--rule);border-right:3px solid var(--brass);padding:14px 17px;font-size:13px;color:var(--ink-2)}
.rb-tool .note b{color:var(--ink)}
@media print{
  .rb-tool .rail{display:none}
  .rb-tool .shell{grid-template-columns:1fr}
}
</style>
