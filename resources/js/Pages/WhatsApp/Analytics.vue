<template>
    <AppLayout>
        <template #header>استهلاك البوت وجودته</template>

        <Head :title="title || 'استهلاك البوت وجودته'" />

        <div class="space-y-6 pb-6">

            <!-- ===== شريط التبويبات + التحديث ===== -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2" role="tablist" aria-label="أقسام التحليلات">
                    <button
                        v-for="tb in tabs" :key="tb.key"
                        type="button" role="tab"
                        :aria-selected="tab === tb.key"
                        class="px-4 py-2.5 rounded-xl border text-sm font-bold transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-gold-500"
                        :class="tab === tb.key
                            ? 'border-gold-400 bg-gold-50 dark:bg-gold-900/20 text-gold-700 dark:text-gold-400'
                            : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-600 dark:text-gray-300 hover:border-gold-300'"
                        @click="tab = tb.key"
                    >{{ tb.label }}</button>
                </div>

                <div class="flex items-center gap-3">
                    <span v-if="refreshError" class="text-xs text-red-600 dark:text-red-400">تعذّر التحديث — حاول مرّة أخرى</span>
                    <span v-else-if="refreshedAt" class="text-xs text-gray-400" dir="ltr">{{ refreshedAt }}</span>
                    <button
                        type="button"
                        class="px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm font-bold text-gray-700 dark:text-gray-200 hover:border-gold-400 disabled:opacity-40 disabled:cursor-not-allowed focus:outline-none focus-visible:ring-2 focus-visible:ring-gold-500"
                        :disabled="loading"
                        aria-label="تحديث بيانات الاستهلاك والجودة"
                        @click="refresh"
                    >{{ loading ? '⏳ جارٍ التحديث…' : '🔄 تحديث' }}</button>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- ==================  التبويب ١ — الاستهلاك  ================= -->
            <!-- ============================================================ -->
            <div v-show="tab === 'usage'" class="space-y-5" role="tabpanel" aria-label="الاستهلاك والتكلفة">

                <!-- ①  التكلفة + منحنى 30 يوماً -->
                <section class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5">
                    <div class="flex flex-wrap items-start justify-between gap-4 mb-4">
                        <div>
                            <p class="text-xs font-bold tracking-wide text-gray-500 dark:text-gray-400 mb-1">التكلفة + منحنى 30 يوماً</p>
                            <p class="text-2xl font-bold tnum" :style="{ color: C_COST }" dir="ltr">{{ money(rangeCost) }}</p>
                            <p class="text-xs text-gray-400 mt-1">آخر {{ range }} يوماً · اليوم {{ money(t.today_cost) }} · الإجمالي {{ money(t.cost_total) }}</p>
                        </div>
                        <div class="flex items-center gap-2" role="group" aria-label="مدى الأيام المعروضة">
                            <button
                                v-for="r in ranges" :key="r"
                                type="button"
                                :aria-pressed="range === r"
                                class="px-3 py-1.5 rounded-lg border text-xs font-bold transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-gold-500"
                                :class="range === r
                                    ? 'border-gold-400 bg-gold-50 dark:bg-gold-900/20 text-gold-700 dark:text-gold-400'
                                    : 'border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400 hover:border-gold-300'"
                                @click="range = r"
                            >{{ r }} يوماً</button>
                        </div>
                    </div>

                    <div ref="costEl" class="chart-scroll">
                        <svg v-if="series.length" :width="costW" :height="CH.h" :viewBox="`0 0 ${costW} ${CH.h}`" role="img"
                             :aria-label="`منحنى التكلفة اليومية لآخر ${series.length} يوماً`" class="block">
                            <!-- الشبكة -->
                            <line v-for="(gy, gi) in [0, 0.5, 1]" :key="'g'+gi"
                                  :x1="CH.padL" :x2="costW - CH.padR" :y1="gridY(gy)" :y2="gridY(gy)"
                                  class="stroke-gray-200 dark:stroke-gray-700" stroke-width="1" />
                            <!-- محور Y -->
                            <text :x="CH.padL - 8" :y="CH.padT + 4" text-anchor="end" class="text-[10px] fill-gray-400 tnum" dir="ltr">{{ money(costMax) }}</text>
                            <text :x="CH.padL - 8" :y="CH.h - CH.padB + 4" text-anchor="end" class="text-[10px] fill-gray-400 tnum" dir="ltr">$0</text>

                            <!-- المنحنى كاملاً — مُعتَّم -->
                            <g opacity="0.35">
                                <path :d="costArea(costPts)" :fill="C_COST" fill-opacity="0.18" />
                                <path :d="costLine(costPts)" fill="none" :stroke="C_COST" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
                            </g>
                            <!-- المدى المحدّد — بكامل الوضوح -->
                            <g opacity="1">
                                <path :d="costArea(selPts)" :fill="C_COST" fill-opacity="0.28" />
                                <path :d="costLine(selPts)" fill="none" :stroke="C_COST" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" />
                                <circle v-for="p in selPts" :key="'c'+p.i" :cx="p.x" :cy="p.y" r="2.5" :fill="C_COST" />
                            </g>

                            <!-- محور X -->
                            <text v-for="p in costXLabels" :key="'x'+p.i" :x="p.x" :y="CH.h - 7" text-anchor="middle"
                                  class="text-[10px] fill-gray-400 tnum" dir="ltr">{{ p.label }}</text>
                        </svg>
                        <p v-else class="py-10 text-center text-sm text-gray-400">لا توجد بيانات أيام بعد — سيظهر المنحنى بعد أوّل ردّ.</p>
                    </div>
                    <p v-if="series.length && costMax === 0" class="text-xs text-gray-400 mt-2">لا كلفة مسجّلة في هذه الفترة — المنحنى عند الصفر.</p>
                </section>

                <!-- ②  أعمدة التوكنز — 14 يوماً -->
                <section class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5">
                    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                        <p class="text-xs font-bold tracking-wide text-gray-500 dark:text-gray-400">أعمدة التوكنز — 14 يوماً</p>
                        <div class="flex items-center gap-4 text-xs text-gray-500 dark:text-gray-400">
                            <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded" :style="{ background: C_IN }"></span> إدخال</span>
                            <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded" :style="{ background: C_OUT }"></span> إخراج</span>
                        </div>
                    </div>

                    <div ref="tokEl" class="chart-scroll">
                        <svg v-if="last14.length" :width="tokW" :height="TB.h" :viewBox="`0 0 ${tokW} ${TB.h}`" role="img"
                             aria-label="توكنز الإدخال والإخراج لآخر 14 يوماً" class="block">
                            <line v-for="(gy, gi) in [0, 0.5, 1]" :key="'tg'+gi"
                                  :x1="TB.padL" :x2="tokW - TB.padR" :y1="tokGridY(gy)" :y2="tokGridY(gy)"
                                  class="stroke-gray-200 dark:stroke-gray-700" stroke-width="1" />
                            <text :x="TB.padL - 6" :y="TB.padT + 4" text-anchor="end" class="text-[10px] fill-gray-400 tnum" dir="ltr">{{ num(tokMaxReal) }}</text>
                            <text :x="TB.padL - 6" :y="TB.h - TB.padB + 4" text-anchor="end" class="text-[10px] fill-gray-400 tnum" dir="ltr">0</text>

                            <g v-for="b in tokBars" :key="'b'+b.i">
                                <rect :x="b.gx" :y="TB.padT" :width="b.gw" :height="TB.h - TB.padT - TB.padB"
                                      :fill="pinned === b.i ? C_GOLD : 'transparent'"
                                      :fill-opacity="pinned === b.i ? 0.14 : 0" />
                                <rect :x="b.inX" :y="b.inY" :width="b.bw" :height="b.inH" :fill="C_IN" rx="1.5" />
                                <rect :x="b.outX" :y="b.outY" :width="b.bw" :height="b.outH" :fill="C_OUT" rx="1.5" />
                                <text v-if="b.i % 2 === 0" :x="b.gx + b.gw / 2" :y="TB.h - 8" text-anchor="middle"
                                      class="text-[9px] fill-gray-400 tnum" dir="ltr">{{ b.label }}</text>
                                <!-- منطقة نقر/تركيز لكل يوم -->
                                <rect
                                    :x="b.gx" :y="TB.padT" :width="b.gw" :height="TB.h - TB.padT - TB.padB"
                                    fill="transparent" class="cursor-pointer outline-none"
                                    role="button" tabindex="0"
                                    :aria-pressed="pinned === b.i"
                                    :aria-label="`تفاصيل يوم ${b.label}`"
                                    @click="togglePin(b.i)"
                                    @keydown.enter.prevent="togglePin(b.i)"
                                    @keydown.space.prevent="togglePin(b.i)"
                                />
                            </g>
                        </svg>
                        <p v-else class="py-10 text-center text-sm text-gray-400">لا توكنز مسجّلة بعد.</p>
                    </div>

                    <!-- تفصيل اليوم المثبّت -->
                    <div v-if="pinnedDay" class="mt-4 rounded-xl border border-gold-300 dark:border-gold-700/60 bg-gold-50 dark:bg-gold-900/10 p-4">
                        <div class="flex items-center justify-between gap-3 mb-3">
                            <p class="text-sm font-bold text-gold-700 dark:text-gold-400 tnum" dir="ltr">📌 {{ pinnedDay.day || pinnedDay.label }}</p>
                            <button type="button" class="text-xs text-gray-500 hover:text-red-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-gold-500 rounded"
                                    @click="pinned = null">إلغاء التثبيت ✕</button>
                        </div>
                        <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 text-xs">
                            <div><p class="text-gray-500 dark:text-gray-400">إدخال</p><p class="font-bold tnum" :style="{ color: C_IN }" dir="ltr">{{ num(pinnedDay.in) }}</p></div>
                            <div><p class="text-gray-500 dark:text-gray-400">إخراج</p><p class="font-bold tnum" :style="{ color: C_OUT }" dir="ltr">{{ num(pinnedDay.out) }}</p></div>
                            <div><p class="text-gray-500 dark:text-gray-400">كاش</p><p class="font-bold tnum text-gray-700 dark:text-gray-200" dir="ltr">{{ num(pinnedDay.cache) }}</p></div>
                            <div><p class="text-gray-500 dark:text-gray-400">ردود</p><p class="font-bold tnum text-gray-700 dark:text-gray-200" dir="ltr">{{ num(pinnedDay.replies) }}</p></div>
                            <div><p class="text-gray-500 dark:text-gray-400">التكلفة</p><p class="font-bold tnum" :style="{ color: C_COST }" dir="ltr">{{ money(pinnedDay.cost) }}</p></div>
                        </div>
                    </div>
                    <p v-else-if="last14.length" class="text-xs text-gray-400 mt-3">اضغط أيّ يوم لتثبيت تفصيله هنا.</p>
                </section>

                <!-- ③ دونات · ④ المتوسّط اليومي · ⑤ متوسّط كلفة الردّ -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-5">

                    <!-- ③ دونات إدخال/إخراج -->
                    <section class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5">
                        <p class="text-xs font-bold tracking-wide text-gray-500 dark:text-gray-400 mb-3">دونات إدخال/إخراج</p>
                        <div class="chart-scroll flex justify-center">
                            <svg width="150" height="150" viewBox="0 0 150 150" role="img"
                                 :aria-label="`توزيع التوكنز: إدخال ${num(t.tokens_in)} مقابل إخراج ${num(t.tokens_out)}`" class="block">
                                <circle cx="75" cy="75" :r="DONUT_R" fill="none" stroke-width="16"
                                        class="stroke-gray-200 dark:stroke-gray-700" />
                                <template v-if="tokensTotal > 0">
                                    <circle cx="75" cy="75" :r="DONUT_R" fill="none" :stroke="C_OUT" stroke-width="16"
                                            :stroke-dasharray="DONUT_C" stroke-dashoffset="0" transform="rotate(-90 75 75)" />
                                    <circle cx="75" cy="75" :r="DONUT_R" fill="none" :stroke="C_IN" stroke-width="16"
                                            :stroke-dasharray="`${(inputShare * DONUT_C).toFixed(2)} ${DONUT_C}`"
                                            stroke-dashoffset="0" transform="rotate(-90 75 75)" stroke-linecap="butt" />
                                </template>
                                <text x="75" y="72" text-anchor="middle" class="text-xl font-bold fill-gray-800 dark:fill-gray-100 tnum" dir="ltr">
                                    {{ tokensTotal > 0 ? Math.round(inputShare * 100) + '%' : '—' }}
                                </text>
                                <text x="75" y="90" text-anchor="middle" class="text-[10px] fill-gray-400">إدخال</text>
                            </svg>
                        </div>
                        <div class="flex items-center justify-center gap-4 mt-2 text-xs text-gray-500 dark:text-gray-400">
                            <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full" :style="{ background: C_IN }"></span> <span class="tnum" dir="ltr">{{ num(t.tokens_in) }}</span></span>
                            <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full" :style="{ background: C_OUT }"></span> <span class="tnum" dir="ltr">{{ num(t.tokens_out) }}</span></span>
                        </div>
                        <p class="text-xs text-gray-400 mt-3">الإدخال هو مَن يحكم الفاتورة عادةً — سياق المحادثة يُعاد إرساله كلّ مرّة.</p>
                        <p v-if="tokensTotal === 0" class="text-xs text-gray-400 mt-1">لا توكنز بعد.</p>
                    </section>

                    <!-- ④ المتوسّط اليوميّ -->
                    <section class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5 flex flex-col">
                        <p class="text-xs font-bold tracking-wide text-gray-500 dark:text-gray-400 mb-2">المتوسّط اليوميّ</p>
                        <p class="text-2xl font-bold tnum" :style="{ color: C_COST }" dir="ltr">{{ money(t.daily_avg) }}</p>
                        <p class="text-xs text-gray-400 mt-2">محسوب على <span class="tnum" dir="ltr">{{ num(t.active_days) }}</span> يوماً نشطاً لا على 30.</p>
                        <div class="mt-auto pt-4 border-t border-gray-100 dark:border-gray-700/50 grid grid-cols-2 gap-2 text-xs">
                            <div><p class="text-gray-500 dark:text-gray-400">كلفة 30 يوماً</p><p class="font-bold tnum text-gray-700 dark:text-gray-200" dir="ltr">{{ money(t.cost_30) }}</p></div>
                            <div><p class="text-gray-500 dark:text-gray-400">ردود 30 يوماً</p><p class="font-bold tnum text-gray-700 dark:text-gray-200" dir="ltr">{{ num(t.replies_30) }}</p></div>
                        </div>
                    </section>

                    <!-- ⑤ متوسّط كلفة الردّ -->
                    <section class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5 flex flex-col">
                        <p class="text-xs font-bold tracking-wide text-gray-500 dark:text-gray-400 mb-2">متوسّط كلفة الردّ</p>
                        <p class="text-2xl font-bold tnum" :style="{ color: C_GOLD }" dir="ltr">{{ money(t.avg_reply) }}</p>
                        <p class="text-xs text-gray-400 mt-2">على <span class="tnum" dir="ltr">{{ num(t.replies) }}</span> ردّاً إجمالاً.</p>
                        <div class="mt-auto pt-4 border-t border-gray-100 dark:border-gray-700/50 grid grid-cols-3 gap-2 text-xs">
                            <div><p class="text-gray-500 dark:text-gray-400">إدخال</p><p class="font-bold tnum" :style="{ color: C_IN }" dir="ltr">{{ num(t.tokens_in) }}</p></div>
                            <div><p class="text-gray-500 dark:text-gray-400">إخراج</p><p class="font-bold tnum" :style="{ color: C_OUT }" dir="ltr">{{ num(t.tokens_out) }}</p></div>
                            <div><p class="text-gray-500 dark:text-gray-400">كاش</p><p class="font-bold tnum text-gray-700 dark:text-gray-200" dir="ltr">{{ num(t.tokens_cache) }}</p></div>
                        </div>
                    </section>
                </div>

                <!-- الوسيط مقابل المتوسّط + المختبر -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                    <section class="md:col-span-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5">
                        <p class="text-xs font-bold tracking-wide text-gray-500 dark:text-gray-400 mb-3">كلفة المحادثة الواحدة</p>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="rounded-xl border border-gold-300 dark:border-gold-700/60 bg-gold-50 dark:bg-gold-900/10 p-4">
                                <p class="text-xs text-gray-600 dark:text-gray-300 mb-1">الدردشة الروتينية (وسيط)</p>
                                <p class="text-xl font-bold tnum" :style="{ color: C_GOLD }" dir="ltr">{{ money(t.median_conv) }}</p>
                            </div>
                            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 p-4">
                                <p class="text-xs text-gray-600 dark:text-gray-300 mb-1">المتوسّط</p>
                                <p class="text-xl font-bold tnum text-gray-700 dark:text-gray-200" dir="ltr">{{ money(t.mean_conv) }}</p>
                            </div>
                        </div>
                        <p class="text-xs text-gray-400 mt-3">الوسيط يقاوم المحادثات الشاذّة — اعتمده في التسعير.</p>
                    </section>
                    <section class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5">
                        <p class="text-xs font-bold tracking-wide text-gray-500 dark:text-gray-400 mb-2">كلفة المختبر (Playground)</p>
                        <p class="text-sm font-bold tnum text-gray-600 dark:text-gray-300" dir="ltr">{{ money(t.playground_cost) }}</p>
                        <p class="text-xs text-gray-400 mt-2">منفصلة عن كلفة العملاء — تجاربك أنت.</p>
                    </section>
                </div>

                <!-- ⑥ أغلى 5 محادثات -->
                <section class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5">
                    <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
                        <p class="text-xs font-bold tracking-wide text-gray-500 dark:text-gray-400">أغلى 5 محادثات</p>
                        <span class="text-xs px-2 py-0.5 rounded-lg bg-gold-50 dark:bg-gold-900/20 text-gold-700 dark:text-gold-400 font-bold">أسرع أداة تشخيص</span>
                    </div>
                    <div ref="topEl" class="space-y-2">
                        <Link
                            v-for="c in topConversations" :key="c.id"
                            :href="`/whatsapp/inbox?conv=${c.id}`"
                            class="block rounded-xl border border-gray-200 dark:border-gray-700 hover:border-gold-400 bg-white dark:bg-gray-900 px-3 py-2.5 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-gold-500"
                        >
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-sm text-gray-800 dark:text-gray-100 truncate">{{ c.name || 'محادثة #' + c.id }}</span>
                                <span class="text-sm font-bold tnum shrink-0" :style="{ color: C_COST }" dir="ltr">{{ money(c.cost) }}</span>
                            </div>
                            <div class="mt-2 h-1.5 rounded-full bg-gray-100 dark:bg-gray-800 overflow-hidden">
                                <div class="h-full rounded-full" :style="{ width: barPx(c.cost, topMax, topBarW) + 'px', background: C_COST }"></div>
                            </div>
                        </Link>
                        <p v-if="!topConversations.length" class="py-8 text-center text-sm text-gray-400">لا محادثات مُسعَّرة بعد.</p>
                    </div>
                </section>

                <!-- ⑦ شريط الأسعار -->
                <section class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5">
                    <div class="flex flex-wrap items-center justify-between gap-2 mb-1">
                        <p class="text-xs font-bold tracking-wide text-gray-500 dark:text-gray-400">شريط الأسعار</p>
                        <span v-if="usage.model" class="text-xs text-gray-400 font-mono" dir="ltr">{{ usage.model }}</span>
                    </div>
                    <p class="text-xs text-gray-400 mb-4">كل صفّ يُسعَّر بنموذجه هو — تبديل النموذج لا يُعيد تسعير التاريخ.</p>

                    <form @submit.prevent="savePrices" class="space-y-4">
                        <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="bg-gray-50 dark:bg-gray-800/50 text-gray-600 dark:text-gray-400">
                                        <th class="px-3 py-2.5 text-right font-bold">النموذج</th>
                                        <th class="px-3 py-2.5 text-center font-bold">إدخال ($/1M)</th>
                                        <th class="px-3 py-2.5 text-center font-bold">إخراج ($/1M)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(row, idx) in priceRows" :key="row.model" class="border-t border-gray-100 dark:border-gray-700/50">
                                        <td class="px-3 py-2 text-right font-mono text-xs text-gray-700 dark:text-gray-200 whitespace-nowrap" dir="ltr">{{ row.model }}</td>
                                        <td class="px-3 py-2 text-center">
                                            <label class="sr-only" :for="`pin-${idx}`">سعر الإدخال للنموذج {{ row.model }}</label>
                                            <input :id="`pin-${idx}`" v-model="row.in" type="number" step="0.01" min="0" dir="ltr"
                                                   class="w-24 px-2 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 dark:text-white text-xs tnum text-center focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                                        </td>
                                        <td class="px-3 py-2 text-center">
                                            <label class="sr-only" :for="`pout-${idx}`">سعر الإخراج للنموذج {{ row.model }}</label>
                                            <input :id="`pout-${idx}`" v-model="row.out" type="number" step="0.01" min="0" dir="ltr"
                                                   class="w-24 px-2 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 dark:text-white text-xs tnum text-center focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                                        </td>
                                    </tr>
                                    <tr v-if="!priceRows.length">
                                        <td colspan="3" class="px-3 py-8 text-center text-sm text-gray-400">لا أسعار مضبوطة بعد.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="flex flex-wrap items-end gap-4">
                            <div>
                                <label for="cache-discount" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">خصم الكاش</label>
                                <input id="cache-discount" v-model="cacheDiscount" type="number" min="0" max="1" step="0.05" dir="ltr"
                                       class="w-28 px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 dark:text-white text-sm tnum focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                                <p class="text-xs text-gray-400 mt-1">توكنز الكاش تُفوتَر بنسبة من سعر الإدخال.</p>
                            </div>
                            <button type="submit" :disabled="priceForm.processing"
                                    class="px-5 py-2.5 rounded-xl bg-gold-500 hover:bg-gold-600 text-white text-sm font-bold disabled:opacity-40 disabled:cursor-not-allowed focus:outline-none focus-visible:ring-2 focus-visible:ring-gold-500">
                                {{ priceForm.processing ? '⏳ جارٍ الحفظ…' : '💾 حفظ الأسعار' }}
                            </button>
                            <span v-if="priceForm.recentlySuccessful" class="text-xs text-green-600 dark:text-green-400 font-bold">✅ حُفظت</span>
                        </div>
                    </form>
                </section>
            </div>

            <!-- ============================================================ -->
            <!-- ====================  التبويب ٢ — الجودة  ================== -->
            <!-- ============================================================ -->
            <div v-show="tab === 'quality'" class="space-y-5" role="tabpanel" aria-label="الجودة">

                <!-- ① قمع الحجز -->
                <section class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5">
                    <p class="text-xs font-bold tracking-wide text-gray-500 dark:text-gray-400 mb-4">قمع الحجز</p>
                    <div ref="funnelEl" class="space-y-3">
                        <div v-for="(f, i) in funnel" :key="f.key">
                            <div class="flex items-center justify-between gap-2 mb-1 text-xs">
                                <span class="text-gray-700 dark:text-gray-200 font-medium">{{ f.label }}</span>
                                <span class="text-gray-400 tnum" dir="ltr">
                                    {{ num(f.value) }}
                                    <template v-if="i > 0"> · {{ pct(f.value, funnel[i - 1].value) }} ↓ · {{ pct(f.value, funnel[0].value) }} من القمّة</template>
                                    <template v-else> · 100%</template>
                                </span>
                            </div>
                            <div class="h-7 rounded-lg bg-gray-100 dark:bg-gray-800 overflow-hidden">
                                <div class="h-full rounded-lg transition-all"
                                     :style="{ width: barPx(f.value, funnelMax, funnelW) + 'px', background: funnelColor(i) }"></div>
                            </div>
                        </div>
                        <p v-if="!funnel.length" class="py-8 text-center text-sm text-gray-400">لا بيانات قمع بعد.</p>
                    </div>
                </section>

                <!-- ② مؤشّرات الردود -->
                <section class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5">
                    <p class="text-xs font-bold tracking-wide text-gray-500 dark:text-gray-400 mb-4">مؤشّرات الردود</p>
                    <div v-if="metrics.length" class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
                        <div v-for="m in metrics" :key="m.key"
                             class="rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 p-3">
                            <p class="text-xs text-gray-500 dark:text-gray-400 leading-snug mb-1">{{ m.label }}</p>
                            <p class="text-lg font-bold tnum" :class="metricClass(m)" dir="ltr">
                                {{ num(m.value) }}<span v-if="m.unit" class="text-xs font-normal mr-0.5">{{ m.unit }}</span>
                            </p>
                        </div>
                    </div>
                    <p v-else class="py-8 text-center text-sm text-gray-400">لا مؤشّرات بعد.</p>
                </section>

                <!-- ③ ما يحتاج نظرك -->
                <section class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5">
                    <p class="text-xs font-bold tracking-wide text-gray-500 dark:text-gray-400 mb-4">ما يحتاج نظرك</p>
                    <div v-if="attention.length" class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                        <div class="min-w-[340px]">
                            <div class="grid grid-cols-[76px_1fr_92px] gap-2 px-3 py-2.5 bg-gray-50 dark:bg-gray-800/50 text-xs font-bold text-gray-600 dark:text-gray-400">
                                <span>النوع</span><span>السؤال</span><span class="text-center">الوقت</span>
                            </div>
                            <Link
                                v-for="(a, i) in attention" :key="i"
                                :href="`/whatsapp/inbox?conv=${a.conversation_id}`"
                                class="grid grid-cols-[76px_1fr_92px] gap-2 items-center px-3 py-2.5 border-t border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-gold-500"
                            >
                                <span class="px-2 py-0.5 rounded-lg text-xs font-bold text-center"
                                      :class="isFault(a.type)
                                          ? 'bg-red-500/15 text-red-600 dark:text-red-400'
                                          : 'bg-amber-500/15 text-amber-600 dark:text-amber-400'">{{ typeLabel(a.type) }}</span>
                                <span class="text-sm text-gray-800 dark:text-gray-100 truncate">{{ a.question || '—' }}</span>
                                <span class="text-xs text-gray-400 tnum text-center" dir="ltr">{{ a.at || '—' }}</span>
                            </Link>
                        </div>
                    </div>
                    <p v-else class="py-8 text-center text-sm text-gray-400">لا شيء يحتاج نظرك الآن ✅</p>
                </section>

                <!-- ④ أكثر الأدوات استعمالاً -->
                <section class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5">
                    <p class="text-xs font-bold tracking-wide text-gray-500 dark:text-gray-400 mb-4">أكثر الأدوات استعمالاً</p>
                    <div ref="toolsEl" class="space-y-2.5">
                        <div v-for="tl in tools" :key="tl.name">
                            <div class="flex items-center justify-between gap-2 mb-1">
                                <span class="text-xs font-mono text-gray-700 dark:text-gray-200 truncate" dir="ltr">{{ tl.name }}</span>
                                <span class="text-xs font-bold tnum text-gray-500 dark:text-gray-400 shrink-0" dir="ltr">{{ num(tl.count) }}</span>
                            </div>
                            <div class="h-2.5 rounded-full bg-gray-100 dark:bg-gray-800 overflow-hidden">
                                <div class="h-full rounded-full" :style="{ width: barPx(tl.count, toolsMax, toolsW) + 'px', background: C_IN }"></div>
                            </div>
                        </div>
                        <p v-if="!tools.length" class="py-8 text-center text-sm text-gray-400">لم تُستعمل أيّ أداة بعد.</p>
                    </div>
                </section>

                <!-- ⑤ الأثر التجاري أسبوعياً -->
                <section class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5">
                    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                        <p class="text-xs font-bold tracking-wide text-gray-500 dark:text-gray-400">الأثر التجاري أسبوعياً</p>
                        <div class="flex items-center gap-4 text-xs text-gray-500 dark:text-gray-400">
                            <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded" :style="{ background: C_GOLD }"></span> طلبات تسعير</span>
                            <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded" :style="{ background: C_WON }"></span> تمّ الحجز</span>
                        </div>
                    </div>
                    <div ref="weekEl" class="chart-scroll">
                        <svg v-if="weekly.length" :width="weekW" :height="WB.h" :viewBox="`0 0 ${weekW} ${WB.h}`" role="img"
                             aria-label="طلبات التسعير والحجوزات لآخر 8 أسابيع" class="block">
                            <line v-for="(gy, gi) in [0, 0.5, 1]" :key="'wg'+gi"
                                  :x1="WB.padL" :x2="weekW - WB.padR" :y1="weekGridY(gy)" :y2="weekGridY(gy)"
                                  class="stroke-gray-200 dark:stroke-gray-700" stroke-width="1" />
                            <text :x="WB.padL - 6" :y="WB.padT + 4" text-anchor="end" class="text-[10px] fill-gray-400 tnum" dir="ltr">{{ num(weekMaxReal) }}</text>
                            <text :x="WB.padL - 6" :y="WB.h - WB.padB + 4" text-anchor="end" class="text-[10px] fill-gray-400 tnum" dir="ltr">0</text>
                            <g v-for="b in weekBars" :key="'w'+b.i">
                                <rect :x="b.aX" :y="b.aY" :width="b.bw" :height="b.aH" :fill="C_GOLD" rx="1.5" />
                                <rect :x="b.bX" :y="b.bY" :width="b.bw" :height="b.bH" :fill="C_WON" rx="1.5" />
                                <text :x="b.gx + b.gw / 2" :y="WB.h - 8" text-anchor="middle" class="text-[9px] fill-gray-400" dir="ltr">{{ b.label }}</text>
                            </g>
                        </svg>
                        <p v-else class="py-10 text-center text-sm text-gray-400">لا بيانات أسبوعية بعد.</p>
                    </div>
                    <p v-if="weekly.length && weekMaxReal === 0" class="text-xs text-gray-400 mt-2">لا طلبات ولا حجوزات في الأسابيع الثمانية الماضية.</p>
                </section>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({ title: String, usage: Object, quality: Object });

/* ===================== الألوان ===================== */
const C_IN = '#3987e5';
const C_OUT = '#d95926';
const C_COST = '#199e70';
const C_GOLD = '#c5a059';
const C_WON = '#199e70';

/* ===================== الحالة ===================== */
const tabs = [
    { key: 'usage', label: 'الاستهلاك والتكلفة' },
    { key: 'quality', label: 'الجودة' },
];
const tab = ref('usage');
const ranges = [7, 14, 30];
const range = ref(30);
const pinned = ref(null);

const usage = ref(props.usage && typeof props.usage === 'object' ? props.usage : {});
const quality = ref(props.quality && typeof props.quality === 'object' ? props.quality : {});

const loading = ref(false);
const refreshError = ref(false);
const refreshedAt = ref('');

async function refresh() {
    loading.value = true;
    refreshError.value = false;
    try {
        const { data } = await axios.get('/api/whatsapp/analytics');
        if (data && typeof data === 'object') {
            if (data.usage && typeof data.usage === 'object') usage.value = data.usage;
            if (data.quality && typeof data.quality === 'object') quality.value = data.quality;
            seedPrices();
            pinned.value = null;
            refreshedAt.value = new Date().toLocaleTimeString('en-GB');
        }
    } catch (e) {
        refreshError.value = true;
    } finally {
        loading.value = false;
    }
}

/* ===================== أدوات التنسيق ===================== */
const n = (v) => {
    const x = Number(v);
    return Number.isFinite(x) ? x : 0;
};
const money = (v) => '$' + n(v).toFixed(4);
const num = (v) => n(v).toLocaleString('en-US');
const pct = (a, b) => (n(b) > 0 ? ((n(a) / n(b)) * 100).toFixed(1) + '%' : '—');

/* ===================== قياس العرض الحقيقي ===================== */
function useWidth(fallback = 640, min = 280) {
    const el = ref(null);
    const w = ref(fallback);
    let ro = null;
    const read = () => {
        const cw = el.value ? el.value.clientWidth : 0;
        if (cw > 0) w.value = Math.max(min, Math.round(cw));
    };
    onMounted(() => {
        read();
        if (typeof ResizeObserver === 'undefined' || !el.value) return;
        ro = new ResizeObserver((entries) => {
            for (const entry of entries) {
                const cw = entry.contentRect ? entry.contentRect.width : 0;
                if (cw > 0) w.value = Math.max(min, Math.round(cw));
            }
        });
        ro.observe(el.value);
    });
    onBeforeUnmount(() => {
        if (ro) ro.disconnect();
        ro = null;
    });
    return { el, w };
}

const { el: costEl, w: costW } = useWidth(640, 300);
const { el: tokEl, w: tokW } = useWidth(640, 300);
const { el: weekEl, w: weekW } = useWidth(640, 300);
const { el: topEl, w: topW } = useWidth(560, 120);
const { el: funnelEl, w: funnelW } = useWidth(560, 120);
const { el: toolsEl, w: toolsW } = useWidth(560, 120);

/* عرض شريط أفقي بالبكسل — لا نِسَب مئوية */
const barPx = (value, max, width) => {
    const w = Math.max(0, n(width));
    const m = n(max);
    if (m <= 0 || w <= 0) return 0;
    const px = (n(value) / m) * w;
    return Math.max(n(value) > 0 ? 3 : 0, Math.min(w, Math.round(px)));
};

/* ===================== بيانات الاستهلاك ===================== */
const t = computed(() => (usage.value && typeof usage.value.tiles === 'object' && usage.value.tiles) || {});
const series = computed(() => (Array.isArray(usage.value?.series) ? usage.value.series : []));
const topConversations = computed(() => (Array.isArray(usage.value?.top_conversations) ? usage.value.top_conversations : []).slice(0, 5));
const topMax = computed(() => Math.max(0, ...topConversations.value.map((c) => n(c.cost))));
/* العرض الداخلي للشريط داخل بطاقة المحادثة (بعد الحاشية والحدود) */
const topBarW = computed(() => Math.max(40, topW.value - 26));

const tokensTotal = computed(() => n(t.value.tokens_in) + n(t.value.tokens_out));
const inputShare = computed(() => (tokensTotal.value > 0 ? n(t.value.tokens_in) / tokensTotal.value : 0));
const DONUT_R = 58;
const DONUT_C = +(2 * Math.PI * DONUT_R).toFixed(2);

/* --- ① منحنى التكلفة --- */
const CH = { h: 210, padT: 16, padB: 28, padL: 62, padR: 14 };
const costMax = computed(() => Math.max(0, ...series.value.map((d) => n(d.cost))));
const costScale = computed(() => (costMax.value > 0 ? costMax.value : 1));
const innerH = CH.h - CH.padT - CH.padB;
const gridY = (f) => CH.padT + innerH * (1 - f);
const tokGridY = (f) => TB.padT + (TB.h - TB.padT - TB.padB) * (1 - f);
const weekGridY = (f) => WB.padT + (WB.h - WB.padT - WB.padB) * (1 - f);

const costPts = computed(() => {
    const list = series.value;
    const count = list.length;
    const usable = Math.max(1, costW.value - CH.padL - CH.padR);
    return list.map((d, i) => ({
        i,
        label: String(d.label ?? d.day ?? ''),
        x: +(CH.padL + (count > 1 ? (i * usable) / (count - 1) : usable / 2)).toFixed(2),
        y: +(CH.padT + innerH - (n(d.cost) / costScale.value) * innerH).toFixed(2),
    }));
});
const selStart = computed(() => Math.max(0, series.value.length - range.value));
const selPts = computed(() => costPts.value.slice(selStart.value));
const rangeCost = computed(() => series.value.slice(selStart.value).reduce((s, d) => s + n(d.cost), 0));
const costXLabels = computed(() => costPts.value.filter((p) => p.i % 5 === 0));

const costLine = (pts) => (pts.length ? pts.map((p, k) => (k === 0 ? 'M' : 'L') + p.x + ' ' + p.y).join(' ') : '');
const costArea = (pts) => {
    if (!pts.length) return '';
    const base = CH.h - CH.padB;
    const first = pts[0];
    const last = pts[pts.length - 1];
    return costLine(pts) + ` L ${last.x} ${base} L ${first.x} ${base} Z`;
};

/* --- ② أعمدة التوكنز 14 يوماً --- */
const TB = { h: 190, padT: 14, padB: 28, padL: 56, padR: 12 };
const last14 = computed(() => series.value.slice(-14));
const tokMaxReal = computed(() => Math.max(0, ...last14.value.map((d) => Math.max(n(d.in), n(d.out)))));
const tokScale = computed(() => (tokMaxReal.value > 0 ? tokMaxReal.value : 1));
const tokBars = computed(() => {
    const list = last14.value;
    if (!list.length) return [];
    const plot = Math.max(1, tokW.value - TB.padL - TB.padR);
    const gw = plot / list.length;
    const bw = Math.max(3, Math.min(16, gw * 0.34));
    const h = TB.h - TB.padT - TB.padB;
    const base = TB.h - TB.padB;
    return list.map((d, k) => {
        const gx = TB.padL + k * gw;
        const inH = Math.round((n(d.in) / tokScale.value) * h);
        const outH = Math.round((n(d.out) / tokScale.value) * h);
        const mid = gx + gw / 2;
        return {
            i: k,
            label: String(d.label ?? d.day ?? ''),
            gx: +gx.toFixed(2), gw: +gw.toFixed(2), bw: +bw.toFixed(2),
            inX: +(mid - bw - 1).toFixed(2), inY: base - inH, inH,
            outX: +(mid + 1).toFixed(2), outY: base - outH, outH,
        };
    });
});
const pinnedDay = computed(() => (pinned.value === null ? null : last14.value[pinned.value] || null));
const togglePin = (i) => { pinned.value = pinned.value === i ? null : i; };

/* --- ⑦ الأسعار --- */
const priceRows = ref([]);
const cacheDiscount = ref(0);
function seedPrices() {
    const p = usage.value && typeof usage.value.prices === 'object' && usage.value.prices ? usage.value.prices : {};
    priceRows.value = Object.keys(p).map((k) => ({ model: k, in: n(p[k]?.in), out: n(p[k]?.out) }));
    cacheDiscount.value = n(usage.value?.cache_discount);
}
seedPrices();

const priceForm = useForm({ model_prices: {}, cache_discount: 0 });
function savePrices() {
    const map = {};
    priceRows.value.forEach((r) => { map[r.model] = { in: n(r.in), out: n(r.out) }; });
    priceForm.model_prices = map;
    priceForm.cache_discount = Math.min(1, Math.max(0, n(cacheDiscount.value)));
    priceForm.put('/whatsapp/prices', { preserveScroll: true, preserveState: true });
}

/* ===================== بيانات الجودة ===================== */
const funnel = computed(() => (Array.isArray(quality.value?.funnel) ? quality.value.funnel : []));
const funnelMax = computed(() => (funnel.value.length ? Math.max(0, n(funnel.value[0].value)) : 0));
const funnelColor = (i) => [C_IN, C_GOLD, C_OUT, C_COST][i % 4];

const metrics = computed(() => (Array.isArray(quality.value?.metrics) ? quality.value.metrics : []));
function metricClass(m) {
    const good = String(m?.good || 'neutral');
    const v = n(m?.value);
    if (good === 'low') return v === 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400';
    if (good === 'high') return v > 0 ? 'text-green-600 dark:text-green-400' : 'text-gray-500 dark:text-gray-400';
    return 'text-gray-700 dark:text-gray-200';
}

const attention = computed(() => (Array.isArray(quality.value?.attention) ? quality.value.attention : []));
const isFault = (type) => ['عطل', 'error', 'fail', 'failure', 'fault', 'broken'].includes(String(type || '').toLowerCase());
const typeLabel = (type) => (isFault(type) ? 'عطل' : 'تحويل');

const tools = computed(() => (Array.isArray(quality.value?.tools) ? quality.value.tools : []));
const toolsMax = computed(() => Math.max(0, ...tools.value.map((x) => n(x.count))));

const WB = { h: 190, padT: 14, padB: 28, padL: 52, padR: 12 };
const weekly = computed(() => (Array.isArray(quality.value?.weekly) ? quality.value.weekly : []));
const weekMaxReal = computed(() => Math.max(0, ...weekly.value.map((d) => Math.max(n(d.requests), n(d.won)))));
const weekScale = computed(() => (weekMaxReal.value > 0 ? weekMaxReal.value : 1));
const weekBars = computed(() => {
    const list = weekly.value;
    if (!list.length) return [];
    const plot = Math.max(1, weekW.value - WB.padL - WB.padR);
    const gw = plot / list.length;
    const bw = Math.max(4, Math.min(20, gw * 0.32));
    const h = WB.h - WB.padT - WB.padB;
    const base = WB.h - WB.padB;
    return list.map((d, k) => {
        const gx = WB.padL + k * gw;
        const aH = Math.round((n(d.requests) / weekScale.value) * h);
        const bH = Math.round((n(d.won) / weekScale.value) * h);
        const mid = gx + gw / 2;
        return {
            i: k,
            label: String(d.label ?? ''),
            gx: +gx.toFixed(2), gw: +gw.toFixed(2), bw: +bw.toFixed(2),
            aX: +(mid - bw - 1).toFixed(2), aY: base - aH, aH,
            bX: +(mid + 1).toFixed(2), bY: base - bH, bH,
        };
    });
});
</script>

<style scoped>
.tnum {
    font-variant-numeric: tabular-nums;
}
.chart-scroll {
    overflow-x: auto;
    overflow-y: hidden;
    max-width: 100%;
}
</style>
