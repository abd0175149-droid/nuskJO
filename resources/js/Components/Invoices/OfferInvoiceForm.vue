<template>
    <div class="space-y-5">
        <!-- العميل والبائع -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label for="oi-name" :class="lbl">اسم العميل *</label>
                <input id="oi-name" v-model="st.client_name" list="oi-clients" autocomplete="off" :class="inp" @input="onName"/>
                <datalist id="oi-clients"><option v-for="c in clients" :key="c.id" :value="c.name"></option></datalist>
            </div>
            <div>
                <label for="oi-phone" :class="lbl">هاتف العميل *</label>
                <input id="oi-phone" v-model="st.client_phone" inputmode="tel" dir="ltr" placeholder="07XXXXXXXX" :class="inp + ' font-mono'"/>
            </div>
            <div>
                <label :class="lbl">البائع (تُنسب إليه الأرباح)</label>
                <SearchableSelect v-model="st.sold_by" :options="employeeOptions" placeholder="اختر الموظف البائع" search-placeholder="ابحث عن موظف..."/>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2 text-xs min-h-[22px]">
            <template v-if="phoneDigits.length >= 9">
                <span v-if="matched" class="px-2 py-0.5 rounded-full font-bold bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300">✓ عميل مسجّل</span>
                <span v-else class="px-2 py-0.5 rounded-full font-bold bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">عميل جديد</span>
                <span class="text-gray-500">{{ matched ? matched.name + ' · ' + matched.code + (st.client_name.trim() && matched.name !== st.client_name.trim() ? ' · الاسم في السجل يختلف' : '') : 'يُنشأ عند الحفظ بهذا الاسم والرقم.' }}</span>
            </template>
            <span v-else class="text-gray-400">أدخل الهاتف ليُبحث عن العميل.</span>
        </div>
        <div v-if="note" class="p-3 rounded-xl border text-sm bg-blue-50 border-blue-200 text-blue-800 dark:bg-blue-900/20 dark:border-blue-800 dark:text-blue-200">{{ note }}</div>

        <!-- تاريخ الرحلة -->
        <div class="space-y-2">
            <h4 :class="h4">تاريخ الرحلة <span class="font-normal text-xs text-gray-400">التواريخ التي فيها عروض جاهزة، أو أي تاريخ آخر</span></h4>
            <div v-if="locked" class="flex items-center gap-2"><span class="px-3 py-1 rounded-full text-sm font-bold bg-gold-100 text-gold-800 font-mono" dir="ltr">{{ st.trip_date }}</span><span class="text-xs text-gray-500">لا يتغير العرض في التعديل.</span></div>
            <div v-else class="flex flex-wrap gap-2">
                <button v-for="d in dates" :key="d.date" type="button" @click="pickDate(d.date)" :aria-pressed="st.trip_date === d.date"
                        class="px-3 py-1.5 rounded-xl border text-start" :class="st.trip_date === d.date ? 'border-gold-500 bg-gold-50 dark:bg-gold-900/30' : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 hover:border-gold-400'">
                    <b class="block text-sm font-mono" dir="ltr">{{ d.date }}</b><span class="text-[11px] text-gray-500">{{ d.count }} {{ d.count > 2 && d.count < 11 ? 'عروض' : 'عرض' }}</span>
                </button>
                <label for="oi-date" class="flex items-center gap-2 px-3 py-1.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-xs text-gray-500">تاريخ آخر
                    <input id="oi-date" v-model="st.trip_date" type="date" dir="ltr" class="px-2 py-1 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm" @change="onDate"/>
                </label>
            </div>
        </div>

        <!-- العروض في التاريخ -->
        <div class="space-y-2">
            <h4 :class="h4">العروض في هذا التاريخ <span class="font-normal text-xs text-gray-400">{{ st.trip_date ? onDateList.length + ' · الجاهزة بتاريخها، والمفتوحة إن كانت صالحة فيه' : 'اختر التاريخ أولاً' }}</span></h4>
            <div v-if="onDateList.length" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                <button v-for="o in onDateList" :key="o.id" type="button" @click="selectOffer(o)" :disabled="(!!blockFor(o) && st.offer_id !== o.id) || locked" :aria-pressed="st.offer_id === o.id"
                        class="p-3 rounded-xl border text-start space-y-1 disabled:cursor-not-allowed"
                        :class="st.offer_id === o.id ? 'border-gold-500 bg-gold-50 dark:bg-gold-900/30 ring-1 ring-gold-500' : (blockFor(o) ? 'border-gray-200 dark:border-gray-700 opacity-60' : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 hover:border-gold-400')">
                    <b class="block text-sm text-gray-800 dark:text-gray-100">{{ o.title }}</b>
                    <span class="flex flex-wrap gap-1 text-[11px]">
                        <span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ o.category_label }}</span>
                        <span v-if="o.is_ready" class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ o.travel_mode === 'air' ? '✈️' : '🚌' }} {{ o.travel_mode_label }}</span>
                        <span v-else class="px-2 py-0.5 rounded-full bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">تاريخ مفتوح</span>
                    </span>
                    <span class="block text-xs text-gray-500">{{ o.is_ready ? '📍 ' + (o.destination || '') + ' · ' : '' }}{{ o.total_seats ? 'المتاح ' + o.available + ' من ' + o.total_seats : 'بلا حد مقاعد' }}{{ o.price_from ? ' · يبدأ من ' + money(o.price_from) : '' }}</span>
                    <span v-if="blockFor(o)" class="inline-block px-2 py-0.5 rounded-full text-[11px] font-bold bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300">{{ blockFor(o) }}</span>
                </button>
            </div>
            <p v-else-if="st.trip_date" class="p-3 rounded-xl text-sm text-gray-500 bg-gray-50 dark:bg-gray-800/40">لا عروض في هذا التاريخ.</p>
        </div>

        <!-- سطور الحجز -->
        <div v-if="offer" class="space-y-3">
            <h4 :class="h4">سطور الحجز <span class="font-normal text-xs text-gray-400">قائمة منسدلة لكل ما فيه خيار{{ offer.has_stay ? '، وسعة الغرفة' : '' }}، والمقاعد لكل سطر. السعر من أسعار البيع في العرض</span></h4>
            <div v-for="(l, i) in st.lines" :key="i" class="rounded-xl border p-3 space-y-2 border-gray-200 dark:border-gray-700 bg-gray-50/60 dark:bg-gray-800/40">
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2"><b class="text-sm">السطر {{ i + 1 }}</b><span v-if="preview?.lines?.[i]?.kept" class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-blue-100 text-blue-700">سعر البيع محفوظ كما بيع</span></div>
                    <button type="button" @click="removeLine(i)" :disabled="st.lines.length < 2" class="px-2 text-lg text-gray-400 hover:text-red-500 disabled:opacity-30" aria-label="حذف السطر">&times;</button>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2">
                    <div v-for="g in offer.groups" :key="g.id">
                        <label :for="'oi-l' + i + '-g' + g.id" :class="mini">{{ g.label }}</label>
                        <select :id="'oi-l' + i + '-g' + g.id" v-model.number="l.sel[g.id]" :class="inpSm" @change="fixRoom(l)">
                            <option v-for="opt in g.options.filter((x) => x.is_active || x.id === l.sel[g.id])" :key="opt.id" :value="opt.id">{{ optText(g, opt, l) }}</option>
                        </select>
                    </div>
                    <div v-if="offer.has_stay">
                        <label :for="'oi-l' + i + '-room'" :class="mini">سعة الغرفة</label>
                        <select :id="'oi-l' + i + '-room'" v-model="l.room" :class="inpSm">
                            <option v-for="(rl, rk) in roomTypes" :key="rk" :value="rk" :disabled="!roomsFor(l.sel).includes(rk)">{{ rl }}{{ roomsFor(l.sel).includes(rk) ? '' : ' — غير متاحة' }}</option>
                        </select>
                    </div>
                    <div>
                        <label :for="'oi-l' + i + '-pax'" :class="mini">المسافرون (مقاعد)</label>
                        <input :id="'oi-l' + i + '-pax'" v-model.number="l.pax" type="number" min="1" dir="ltr" :class="inpSm + ' font-mono'"/>
                    </div>
                </div>
                <p v-if="preview?.lines?.[i]" class="text-xs text-gray-500">
                    سعر الفرد <b class="font-mono" dir="ltr">{{ money(preview.lines[i].sell_pp) }}</b>
                    <span v-if="preview.lines[i].kept && preview.lines[i].sell_now !== null && Math.abs(preview.lines[i].sell_now - preview.lines[i].sell_pp) > 0.0005" class="text-amber-600"> · سعر العرض الآن {{ money(preview.lines[i].sell_now) }} (فعّل «إعادة التسعير» لاعتماده)</span>
                </p>
            </div>
            <button v-if="offer.groups.length" type="button" @click="st.lines.push(defaultLine(offer))" class="px-3 py-1.5 rounded-lg text-xs font-bold text-gray-700 dark:text-gray-200 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200">+ سطر حجز آخر (سعة أو فنادق مختلفة)</button>

            <!-- بنود التكلفة: الوكيل والتكلفة لهذه الفاتورة -->
            <div class="space-y-2">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h4 :class="h4">بنود التكلفة <span class="font-normal text-xs text-gray-400">الوكيل والتكلفة الفعلية لهذه الفاتورة. المظلّلة مقترحة من اختيار العميل ولا تُحذف</span></h4>
                    <span class="px-2 py-0.5 rounded-full text-[11px] font-bold" :class="missingCount ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300' : 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300'">{{ missingCount ? missingCount + ' ' + (missingCount > 2 && missingCount < 11 ? 'بنود ناقصة' : 'بند ناقص') : 'التكاليف مكتملة' }}</span>
                </div>
                <div class="space-y-2">
                    <div v-for="(r, i) in st.costs" :key="r._k" class="rounded-xl border p-3 space-y-2"
                         :class="[r.fixed ? 'bg-gray-50/80 dark:bg-gray-800/40' : 'bg-white dark:bg-gray-900', rowDone(r) ? 'border-gray-200 dark:border-gray-700' : 'border-amber-300 dark:border-amber-700']">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <template v-if="r.fixed">
                                    <b class="text-sm text-gray-800 dark:text-gray-100">{{ r.description }}</b>
                                    <span class="ms-1 text-xs text-gray-500">· <b class="font-mono">{{ r.qty }}</b> {{ r.kind === 'hotel' ? 'مسافر × ' + r.nights + ' ' + nightsWord(r.nights) : 'مسافر' }}{{ st.lines.length > 1 ? ' · السطر ' + (r.line + 1) : '' }}</span>
                                    <button v-if="suggest(r) && !rowDone(r)" type="button" @click="applySuggest(r)" class="block mt-0.5 text-xs font-bold text-gold-700 dark:text-gold-300 hover:underline text-start">↺ آخر تكلفة: {{ agentName(suggest(r).agent_id) }} · <span dir="ltr" class="font-mono">{{ suggest(r).unit }}</span> {{ curL(suggest(r).currency) }}{{ r.kind === 'hotel' ? ' للفرد/ليلة' : '' }} ({{ suggest(r).number }})</button>
                                    <button v-else-if="stayAgent(r) && !r.agent_id" type="button" @click="applyStayAgent(r)" class="block mt-0.5 text-xs font-bold text-gold-700 dark:text-gold-300 hover:underline text-start">↺ آخر وكيل لهذا الفندق: {{ agentName(stayAgent(r).agent_id) }} ({{ stayAgent(r).number }}) — التكلفة لهذه السعة تُدخل يدوياً</button>
                                </template>
                                <template v-else>
                                    <label :for="'oi-c' + r._k + '-d'" :class="mini">البيان *</label>
                                    <input :id="'oi-c' + r._k + '-d'" v-model="r.description" :class="inpSm" placeholder="مثال: تأشيرة عمرة"/>
                                </template>
                            </div>
                            <div class="flex items-center gap-1 shrink-0 pt-0.5">
                                <span class="text-sm font-mono tabular-nums" dir="ltr" :class="rowDone(r) ? 'font-bold text-gray-800 dark:text-gray-100' : 'text-gray-400'">{{ rowDone(r) ? money(rowTotal(r)) : '—' }}</span><span class="text-[11px] text-gray-400">د.أ</span>
                                <button v-if="!r.fixed" type="button" @click="st.costs.splice(i, 1)" class="px-1.5 text-lg leading-none text-gray-400 hover:text-red-500" aria-label="حذف البند">&times;</button>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-2 items-end" :class="r.fixed ? 'md:grid-cols-[minmax(0,2fr)_120px_96px_minmax(0,1.2fr)]' : 'md:grid-cols-[minmax(0,2fr)_80px_120px_96px_minmax(0,1.2fr)_100px]'">
                            <div class="col-span-2 md:col-span-1">
                                <span :class="mini">الوكيل *</span>
                                <SearchableSelect v-model="r.agent_id" :options="agentOptions" placeholder="اختر الوكيل" search-placeholder="ابحث..." :drop-up="i > 2"/>
                            </div>
                            <div v-if="!r.fixed"><label :for="'oi-c' + r._k + '-q'" :class="mini">العدد</label><input :id="'oi-c' + r._k + '-q'" v-model.number="r.qty" type="number" min="1" dir="ltr" :class="inpSm + ' font-mono'"/></div>
                            <div><label :for="'oi-c' + r._k + '-u'" :class="mini">{{ r.kind === 'hotel' ? 'تكلفة الفرد/ليلة *' : 'تكلفة الوحدة *' }}</label><input :id="'oi-c' + r._k + '-u'" v-model.number="r.unit" type="number" step="0.001" min="0" dir="ltr" :placeholder="r.kind === 'hotel' ? 'للفرد/ليلة' : 'للوحدة'" :class="inpSm + ' font-mono'"/></div>
                            <div><label :for="'oi-c' + r._k + '-cur'" :class="mini">العملة</label><select :id="'oi-c' + r._k + '-cur'" v-model="r.currency" :class="inpSm"><option value="SAR">ريال</option><option value="JOD">دينار</option></select></div>
                            <div>
                                <label :for="'oi-c' + r._k + '-s'" :class="mini">خدمة الإيراد</label>
                                <select :id="'oi-c' + r._k + '-s'" v-model="r.service_id" :class="inpSm">
                                    <option :value="null">تلقائي</option>
                                    <option v-for="s in services" :key="s.id" :value="s.id">{{ s.name }}</option>
                                </select>
                            </div>
                            <div v-if="!r.fixed"><label :for="'oi-c' + r._k + '-sell'" :class="mini">بيع للعميل</label><input :id="'oi-c' + r._k + '-sell'" v-model.number="r.sell" type="number" step="0.001" min="0" dir="ltr" placeholder="0" :class="inpSm + ' font-mono'"/></div>
                        </div>
                    </div>
                    <p v-if="!st.costs.length" class="p-4 rounded-xl border border-dashed text-center text-sm text-gray-400 border-gray-300 dark:border-gray-600">أضف بنود التكلفة: التأشيرة، النقل، الزيارات…</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="button" @click="addManual" class="px-3 py-1.5 rounded-lg text-xs font-bold text-blue-700 bg-blue-50 hover:bg-blue-100 dark:bg-blue-900/30 dark:text-blue-300">+ بند تكلفة (تأشيرة، طيران، زيارات…)</button>
                    <button v-if="offer.last?.manual" type="button" @click="copyManual" class="px-3 py-1.5 rounded-lg text-xs font-bold text-gray-700 dark:text-gray-200 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200">↺ البنود اليدوية من آخر فاتورة ({{ offer.last.manual.number }})</button>
                </div>
                <p class="text-[11px] text-gray-400">تكلفة الفندق للفرد لليلة الواحدة حسب سعة الغرفة، وتُضرب في الليالي والمسافرين. «بيع للعميل» للبند اليدوي الذي يُباع خارج العرض (ليلة إضافية مثلاً)، واتركه فارغاً إن كان مشمولاً في سعر العرض.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label for="oi-disc" :class="lbl">خصم على الفاتورة (د.أ)</label>
                    <input id="oi-disc" v-model.number="st.discount" type="number" step="0.001" min="0" dir="ltr" placeholder="0.000" :class="inp + ' font-mono'"/>
                </div>
                <div v-if="editing" class="flex items-end">
                    <label for="oi-refresh" class="flex items-center gap-2 text-sm cursor-pointer pb-2"><input id="oi-refresh" v-model="st.refresh" type="checkbox" class="w-4 h-4 rounded text-gold-500"/> إعادة التسعير بأسعار البيع الحالية في العرض</label>
                </div>
            </div>

            <!-- الملخص من الخادم -->
            <div v-if="preview" class="space-y-3">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div class="rounded-xl border p-3 border-gray-200 dark:border-gray-700"><span class="block text-xs text-gray-500">البيع على العميل</span><b class="font-mono text-lg" dir="ltr">{{ money(preview.sell) }}</b> <small class="text-gray-400">{{ preview.discount ? 'بعد خصم ' + money(preview.discount) : 'د.أ' }}</small></div>
                    <div class="rounded-xl border p-3 border-gray-200 dark:border-gray-700"><span class="block text-xs text-gray-500">التكلفة المُدخلة</span><b class="font-mono text-lg" dir="ltr">{{ money(preview.cost) }}</b> <small :class="preview.missing ? 'text-amber-600' : 'text-gray-400'">{{ preview.missing ? preview.missing + ' ناقصة' : 'مكتملة' }}</small></div>
                    <div class="rounded-xl border p-3 border-gray-200 dark:border-gray-700"><span class="block text-xs text-gray-500">الربح</span>
                        <template v-if="preview.missing"><b class="font-mono text-lg text-gray-400">—</b> <small class="text-gray-400">بعد اكتمال التكاليف</small></template>
                        <template v-else><b class="font-mono text-lg" dir="ltr" :class="preview.profit >= 0 ? 'text-green-600' : 'text-red-600'">{{ money(preview.profit) }}</b> <small class="text-gray-400">{{ preview.sell ? ((preview.profit / preview.sell) * 100).toFixed(1) + '%' : '' }}</small></template>
                    </div>
                    <div class="rounded-xl border p-3 border-gray-200 dark:border-gray-700"><span class="block text-xs text-gray-500">المقاعد</span><b class="font-mono text-lg" :class="preview.available !== null && preview.pax > preview.available ? 'text-red-600' : ''">{{ preview.pax }}</b> <small class="text-gray-400">{{ preview.available !== null ? 'المتاح ' + preview.available + ' → يبقى ' + (preview.available - preview.pax) : 'بلا حد' }}</small></div>
                </div>
                <p v-if="!preview.missing && preview.profit < 0" class="p-3 rounded-xl border text-sm bg-amber-50 border-amber-200 text-amber-800 dark:bg-amber-900/20 dark:border-amber-800 dark:text-amber-200">⚠️ الفاتورة بخسارة {{ money(-preview.profit) }} د.أ. تُحفظ وتُعتمد إن كان ذلك مقصوداً، والقيد يسجّل الخسارة.</p>
                <details v-if="preview.agents.length" class="rounded-xl border p-3 border-gray-200 dark:border-gray-700">
                    <summary class="cursor-pointer text-sm font-bold">ما سيُقيَّد على كل وكيل عند الاعتماد</summary>
                    <table class="w-full text-xs mt-2">
                        <thead><tr class="bg-gray-50 dark:bg-gray-800/50 text-gray-500"><th class="px-2 py-1.5 text-right">الوكيل</th><th class="px-2 py-1.5 text-right">البنود</th><th class="px-2 py-1.5 text-right">الإجمالي</th></tr></thead>
                        <tbody>
                            <tr v-for="(a, k) in preview.agents" :key="k" class="border-t border-gray-100 dark:border-gray-700/50 align-top">
                                <td class="px-2 py-1.5 font-bold">{{ a.agent }}</td>
                                <td class="px-2 py-1.5"><div v-for="(it, j) in a.items" :key="j" class="text-gray-600 dark:text-gray-300">{{ it.description }} · <span class="font-mono" dir="ltr">{{ it.qty }} × {{ money(it.unit) }}</span><span v-if="it.currency === 'SAR'" class="text-gray-400 font-mono"> ({{ money(it.original) }} ريال)</span></div></td>
                                <td class="px-2 py-1.5 font-mono" dir="ltr">{{ money(a.total) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </details>
            </div>
        </div>

        <div>
            <label for="oi-notes" :class="lbl">ملاحظات</label>
            <textarea id="oi-notes" v-model="st.notes" rows="2" :class="inp + ' resize-none'"></textarea>
        </div>

        <ul v-if="allErrors.length" class="p-3 ps-7 rounded-xl text-sm list-disc bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-300 space-y-0.5">
            <li v-for="(e, i) in allErrors" :key="i">{{ e }}</li>
        </ul>

        <div class="flex flex-wrap items-center gap-3">
            <button type="button" @click="submit" :disabled="busy || !st.offer_id" class="px-6 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md disabled:opacity-50">{{ editing ? '📤 حفظ وإرسال للاعتماد' : '🧾 حفظ الفاتورة' }}</button>
            <button type="button" @click="$emit('close')" class="px-6 py-2.5 rounded-xl text-sm text-gray-600 hover:bg-gray-100 dark:hover:bg-gray-800">إلغاء</button>
            <span v-if="offer" class="text-xs text-gray-500">التكاليف الناقصة لا تمنع الحفظ وحجز المقاعد، لكنها تمنع الاعتماد.</span>
        </div>
    </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import SearchableSelect from '@/Components/SearchableSelect.vue';

const props = defineProps({
    catalog: Array, roomTypes: Object, today: String, clients: Array, agents: Array, employees: Array, services: Array, rate: Number,
    currentEmployeeId: [Number, String], editing: Object, prefill: Object, presetOfferId: Number,
});
const emit = defineEmits(['close']);

const lbl = 'block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1';
const mini = 'block text-[11px] font-bold text-gray-500 dark:text-gray-400 mb-0.5';
const h4 = 'text-sm font-bold text-gray-800 dark:text-gray-100';
const inp = 'w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none';
const inpSm = 'w-full px-2.5 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none';
const money = (v) => Number(v || 0).toLocaleString('en', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
const r3 = (v) => Math.round((Number(v) || 0) * 1000) / 1000;
const curL = (c) => (c === 'SAR' ? 'ريال' : 'د.أ');
const nightsWord = (n) => (n >= 3 && n <= 10 ? 'ليالٍ' : 'ليلة');

const employeeOptions = computed(() => props.employees.map((e) => ({ value: e.id, label: e.name })));
const agentOptions = computed(() => props.agents.map((a) => ({ value: a.id, label: `${a.name} (${a.code})` })));
const agentName = (id) => props.agents.find((a) => a.id === id)?.name || 'وكيل';

// العروض المتاحة: الكتالوج، ومعه عرض الفاتورة المعدّلة أو عرض طلب التسعير إن لم يكن فيه
const offers = computed(() => {
    const list = [...(props.catalog || [])];
    for (const extra of [props.editing?.offer_form, props.prefill?.offer]) {
        if (!extra) continue;
        const i = list.findIndex((o) => o.id === extra.id);
        // بنية الفاتورة المعدّلة تستثني مقاعدها و«آخر تكلفة» منها، فتُقدَّم على الكتالوج
        if (i === -1) list.push(extra);
        else if (extra === props.editing?.offer_form) list.splice(i, 1, extra);
    }
    return list;
});
const locked = computed(() => !!props.editing);

const st = reactive({
    client_name: '', client_phone: '', sold_by: props.currentEmployeeId || '', trip_date: '', offer_id: null,
    lines: [], costs: [], discount: 0, notes: '', refresh: false, quote_request_id: null,
});
const note = ref('');
const preview = ref(null);
const serverErrors = ref([]);
const busy = ref(false);
let uid = 0;

const offer = computed(() => offers.value.find((o) => o.id === st.offer_id) || null);

/* ===== العميل ===== */
const normPhone = (p) => {
    let d = String(p || '').replace(/\D+/g, '');
    if (d.startsWith('00')) d = d.slice(2);
    if (d.startsWith('962')) d = '0' + d.slice(3);
    if (d.length === 9 && d[0] === '7') d = '0' + d;
    return d;
};
const phoneDigits = computed(() => normPhone(st.client_phone));
const matched = computed(() => (phoneDigits.value.length >= 9 ? props.clients.find((c) => normPhone(c.phone) === phoneDigits.value) || null : null));
const onName = () => {
    if (st.client_phone) return;
    const c = props.clients.find((x) => x.name === st.client_name.trim());
    if (c?.phone) st.client_phone = c.phone;
};

/* ===== التاريخ والعروض ===== */
const dates = computed(() => {
    const m = {};
    offers.value.filter((o) => o.is_ready && o.trip_date > props.today).forEach((o) => { m[o.trip_date] = (m[o.trip_date] || 0) + 1; });
    return Object.entries(m).sort((a, b) => a[0].localeCompare(b[0])).map(([date, count]) => ({ date, count }));
});
const onDateList = computed(() => {
    const d = st.trip_date;
    if (!d) return [];
    const ready = offers.value.filter((o) => o.is_ready && o.trip_date === d);
    const open = offers.value.filter((o) => !o.is_ready && (!o.valid_from || o.valid_from <= d) && (!o.valid_to || o.valid_to >= d));
    return [...ready, ...open];
});
const blockFor = (o) => {
    if (o.block && !(locked.value && o.id === st.offer_id)) return o.block;
    if (!o.is_ready && st.trip_date && ((o.valid_from && st.trip_date < o.valid_from) || (o.valid_to && st.trip_date > o.valid_to))) return 'خارج مدة العرض';
    return '';
};
const pickDate = (d) => { st.trip_date = d; onDate(); };
const onDate = () => {
    if (!onDateList.value.some((o) => o.id === st.offer_id)) { st.offer_id = null; st.lines = []; st.costs = []; preview.value = null; }
    const sellable = onDateList.value.filter((o) => o.is_ready && !blockFor(o));
    if (!st.offer_id && sellable.length === 1) selectOffer(sellable[0]);
};

/* ===== السطور ===== */
const roomsFor = (sel) => {
    const o = offer.value;
    if (!o || !o.has_stay) return [];
    let rooms = Object.keys(props.roomTypes);
    for (const g of o.groups.filter((x) => x.kind === 'stay')) {
        const opt = g.options.find((x) => x.id === sel[g.id]);
        rooms = rooms.filter((r) => opt && (opt.rooms || []).includes(r));
    }
    return rooms;
};
const defaultLine = (o) => {
    const sel = {};
    o.groups.forEach((g) => { const a = g.options.find((x) => x.is_active); if (a) sel[g.id] = a.id; });
    const l = { sel, room: null, pax: 1 };
    fixRoom(l, o);
    return l;
};
const fixRoom = (l, o = offer.value) => {
    if (!o?.has_stay) { l.room = null; return; }
    const rooms = roomsFor(l.sel);
    if (!rooms.includes(l.room)) l.room = ['triple', 'double', 'quad', 'single'].find((r) => rooms.includes(r)) || null;
};
const selectOffer = (o) => {
    if (st.offer_id === o.id) return;
    st.offer_id = o.id;
    st.lines = [defaultLine(o)];
    st.costs = [];
    syncTemplate();
    serverErrors.value = [];
};
const removeLine = (i) => {
    st.lines.splice(i, 1);
    // بنود السطر المحذوف تذهب، وبنود ما بعده تنتقل معه
    st.costs = st.costs.filter((r) => !r.fixed || r.line !== i).map((r) => (r.fixed && r.line > i ? Object.assign(r, { line: r.line - 1 }) : r));
};
const optText = (g, opt, l) => {
    if (g.kind === 'stay') {
        const p = opt.prices?.[l.room];
        return opt.label + (p ? ` (${p})` : ' (—)');
    }
    const full = opt.capacity && opt.used >= opt.capacity ? ' — ممتلئ' : '';
    return opt.label + (Number(opt.sell_flat) ? ` (+${Number(opt.sell_flat)})` : '') + full;
};

/* ===== بنود التكلفة ===== */
// البنود المقترحة من اختيار كل سطر: أسماء وكميات فقط، والخادم يعيد بناءها نفسها عند الحفظ
const templateFor = (o, l, i) => {
    const base = { line: i, qty: Number(l.pax) || 0, fixed: true };
    if (o.is_visa) return [{ ...base, src: 'visa', kind: 'visa', description: o.title, nights: null, service_id: o.visa_service_id, currency: 'JOD' }];
    const rows = [];
    for (const g of o.groups) {
        const opt = g.options.find((x) => x.id === l.sel[g.id]);
        if (!opt) continue;
        if (g.kind === 'stay') {
            if (!l.room) continue;
            for (const s of opt.stays || []) {
                rows.push({ ...base, src: `stay:${s.id}:${l.room}`, kind: 'hotel', description: `${s.name} · ${props.roomTypes[l.room]} · ${s.nights} ${nightsWord(s.nights)}`, nights: s.nights, service_id: s.service_id, currency: 'SAR' });
            }
        } else if (Number(opt.sell_flat) > 0) {
            rows.push({ ...base, src: `opt:${opt.id}`, kind: g.kind, description: opt.name, nights: null, service_id: opt.service_id, currency: 'JOD' });
        }
    }
    return rows;
};
// يعيد بناء المقترحة عند تغيّر الاختيار، ويحفظ ما أدخله البائع للبند نفسه
const syncTemplate = () => {
    const o = offer.value;
    if (!o) { st.costs = []; return; }
    const old = {};
    st.costs.filter((r) => r.fixed).forEach((r) => { old[r.line + '|' + r.src] = r; });
    const fresh = st.lines.flatMap((l, i) => templateFor(o, l, i)).map((t) => {
        const prev = old[t.line + '|' + t.src];
        return prev
            ? Object.assign(prev, { description: t.description, nights: t.nights, qty: t.qty, kind: t.kind })
            : { _k: ++uid, agent_id: null, unit: null, sell: null, ...t };
    });
    const manual = st.costs.filter((r) => !r.fixed);
    const same = fresh.length === st.costs.length - manual.length && fresh.every((r, i) => r === st.costs[i]);
    if (!same) st.costs = [...fresh, ...manual];
};
const rowTotal = (r) => {
    const per = r3((Number(r.unit) || 0) * (r.nights || 1));
    const perJod = r.currency === 'SAR' ? r3(per * (props.rate || 0.19)) : per;
    return r3(perJod * (Number(r.qty) || 0));
};
const rowDone = (r) => !!r.agent_id && (Number(r.unit) || 0) > 0 && (Number(r.qty) || 0) >= 1;
const missingCount = computed(() => (st.costs.length ? st.costs.filter((r) => !rowDone(r)).length : 1));
const suggest = (r) => offer.value?.last?.costs?.[r.src] || null;
const applySuggest = (r) => {
    const s = suggest(r);
    if (s) Object.assign(r, { agent_id: s.agent_id, unit: s.unit, currency: s.currency, service_id: s.service_id ?? r.service_id });
};
// الفندق نفسه بسعة أخرى: يُقترح وكيله وعملته فقط، لأن تكلفة الفرد تختلف بالسعة
const stayAgent = (r) => (r.kind === 'hotel' ? offer.value?.last?.stays?.[r.src.split(':').slice(0, 2).join(':')] || null : null);
const applyStayAgent = (r) => {
    const s = stayAgent(r);
    if (s) Object.assign(r, { agent_id: s.agent_id, currency: s.currency, service_id: s.service_id ?? r.service_id });
};
const totalPax = () => st.lines.reduce((a, l) => a + (Number(l.pax) || 0), 0);
const addManual = () => st.costs.push({ _k: ++uid, fixed: false, src: 'manual', line: null, kind: 'service', description: '', nights: null, qty: totalPax() || 1, agent_id: null, unit: null, currency: 'JOD', service_id: null, sell: null });
const copyManual = () => {
    const m = offer.value?.last?.manual;
    if (!m) return;
    // العدد الذي كان لكل المسافرين يتبع مسافري هذه الفاتورة
    m.rows.forEach((r) => st.costs.push({ _k: ++uid, fixed: false, src: 'manual', line: null, kind: 'service', nights: null, description: r.description, agent_id: r.agent_id, service_id: r.service_id, qty: r.qty === m.pax ? totalPax() : r.qty, unit: r.unit, currency: r.currency, sell: Number(r.sell) || null }));
};
watch(() => JSON.stringify([st.offer_id, st.lines]), syncTemplate);

/* ===== المعاينة من الخادم ===== */
let timer = null;
const payloadLines = () => st.lines.map((l) => ({ sel: { ...l.sel }, room: offer.value?.has_stay ? l.room : null, pax: l.pax }));
const payloadCosts = () => st.costs.map((r) => ({
    src: r.fixed ? r.src : 'manual', line: r.fixed ? r.line : null, description: r.fixed ? null : r.description,
    agent_id: r.agent_id || null, service_id: r.service_id || null, qty: r.fixed ? null : (Number(r.qty) || 0),
    unit: r.unit === '' || r.unit === null || r.unit === undefined ? null : Number(r.unit), currency: r.currency, sell: r.fixed ? null : (Number(r.sell) || 0),
}));
const runPreview = async () => {
    if (!st.offer_id || !st.lines.length) { preview.value = null; return; }
    try {
        const { data } = await axios.post('/invoices/offer-preview', {
            offer_id: st.offer_id, invoice_id: props.editing?.id || null, lines: payloadLines(),
            costs: payloadCosts(), discount: st.discount || 0, refresh: st.refresh,
        });
        preview.value = data;
    } catch (e) {
        preview.value = null;
    }
};
watch(() => JSON.stringify([st.offer_id, st.lines, st.costs, st.discount, st.refresh]), () => {
    clearTimeout(timer);
    timer = setTimeout(runPreview, 250);
});

const allErrors = computed(() => [...serverErrors.value, ...(preview.value?.errors || [])].filter((v, i, a) => v && a.indexOf(v) === i));

/* ===== الحفظ ===== */
const submit = () => {
    busy.value = true;
    serverErrors.value = [];
    const payload = {
        mode: 'offer',
        client_name: st.client_name, client_phone: st.client_phone, sold_by: st.sold_by || null,
        trip_date: st.trip_date, offer_id: st.offer_id, lines: payloadLines(), costs: payloadCosts(),
        discount: st.discount || 0, notes: st.notes || null, quote_request_id: st.quote_request_id, refresh_prices: st.refresh,
    };
    const opts = {
        preserveScroll: true,
        onSuccess: () => emit('close'),
        onError: (errs) => { serverErrors.value = Object.values(errs).flat(); },
        onFinish: () => { busy.value = false; },
    };
    props.editing ? router.put('/invoices/' + props.editing.id, payload, opts) : router.post('/invoices', payload, opts);
};

/* ===== التهيئة: تعديل، أو طلب تسعير، أو عرض محدّد ===== */
if (props.editing) {
    const e = props.editing;
    const snap = e.offer_lines || {};
    st.client_name = e.client?.name || '';
    st.client_phone = e.client_phone || e.client?.phone || '';
    st.sold_by = e.sold_by || '';
    st.trip_date = String(e.trip_date || '').slice(0, 10);
    st.offer_id = e.offer_id;
    st.lines = (snap.lines || []).map((l) => ({ sel: Object.fromEntries(Object.entries(l.sel || {}).map(([k, v]) => [k, Number(v)])), room: l.room, pax: l.pax }));
    st.costs = (snap.costs || []).map((r) => ({
        _k: ++uid, src: r.src, line: r.line, fixed: !!r.fixed, kind: r.kind, description: r.description, nights: r.nights, qty: r.qty,
        agent_id: r.agent_id, unit: r.unit, currency: r.currency, service_id: r.service_id, sell: Number(r.sell) || null,
    }));
    st.discount = snap.discount || 0;
    st.notes = e.notes || '';
    syncTemplate();
} else if (props.prefill) {
    const p = props.prefill;
    st.client_name = p.client_name || '';
    st.client_phone = p.client_phone || '';
    st.trip_date = p.trip_date || '';
    st.quote_request_id = p.quote_id;
    note.value = (p.number ? `من طلب ${p.number}. ` : '') + (p.note || '');
    const o = offers.value.find((x) => x.id === p.offer_id);
    if (o) {
        st.offer_id = o.id;
        st.lines = (p.lines || []).map((l) => {
            const base = defaultLine(o);
            if (l.sel) { base.sel = { ...base.sel, ...Object.fromEntries(Object.entries(l.sel).map(([k, v]) => [k, Number(v)])) }; base.room = l.room; fixRoom(base, o); }
            base.pax = l.pax || 1;
            return base;
        });
        syncTemplate();
    }
} else if (props.presetOfferId) {
    const o = offers.value.find((x) => x.id === props.presetOfferId);
    if (o) {
        st.trip_date = o.is_ready ? o.trip_date : (o.valid_from && o.valid_from > props.today ? o.valid_from : props.today);
        if (!blockFor(o)) selectOffer(o);
    }
}
runPreview();
</script>
