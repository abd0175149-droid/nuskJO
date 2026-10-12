<template>
    <div class="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_320px] gap-5 items-start">
        <div class="space-y-5 min-w-0">
            <!-- ===== تأشيرة: السعر ===== -->
            <template v-if="isVisa">
                <section :class="box">
                    <h4 :class="h4">🛂 سعر التأشيرة</h4>
                    <div class="max-w-xs">
                        <label for="f-visa-price" :class="lbl">السعر للفرد (د.أ)<UseTags tags="bot card inv"/></label>
                        <input id="f-visa-price" v-model="form.price_per_person" type="number" step="0.001" min="0" dir="ltr" :class="inp + ' font-mono'"/>
                    </div>
                    <p :class="hint">هو السعر الذي يقوله البوت وتطبعه البطاقة وتبيع به فاتورة العرض. الوكيل والتكلفة يُدخلان في كل فاتورة.</p>
                </section>
            </template>

            <!-- ===== باقي التصنيفات ===== -->
            <template v-else>
                <section :class="box">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label for="f-seats2" :class="lbl">المقاعد<UseTags tags="ready inv"/></label>
                            <input id="f-seats2" v-model="form.total_seats" type="number" min="1" dir="ltr" :class="inp + ' font-mono'" :placeholder="form.is_ready ? '' : 'اختياري'"/>
                            <p :class="hint">تحجزها الفواتير المعلّقة والمعتمدة، والبوت يرى «متوفر» أو «مقاعد محدودة».</p>
                        </div>
                        <div>
                            <label for="f-base" :class="lbl">السعر الأساسي للفرد (د.أ)<UseTags tags="bot card inv"/></label>
                            <input id="f-base" v-model="form.base_sell_pp" type="number" step="0.001" min="0" dir="ltr" :class="inp + ' font-mono'"/>
                            <p :class="hint">يُضاف لسعر الفنادق المختارة، ويغطي ما يشمله العرض خارج الفنادق (التأشيرة، النقل…) وهامشه. صفر في نمط الباقات عادةً.</p>
                        </div>
                        <div>
                            <span :class="lbl">نمط الفنادق</span>
                            <div class="inline-flex rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden text-sm" role="group" aria-label="نمط الفنادق">
                                <button v-for="(l, k) in meta.pricingModes" :key="k" type="button" @click="setMode(k)" :disabled="hasSales" :aria-pressed="form.pricing_mode === k"
                                        class="px-3 py-2 font-bold disabled:cursor-not-allowed" :class="form.pricing_mode === k ? 'bg-gold-100 text-gold-800 dark:bg-gold-900/40 dark:text-gold-200' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300'">{{ l }}</button>
                            </div>
                            <p :class="hint">{{ form.pricing_mode === 'city' ? 'قائمة لفندق كل مدينة، والعميل يمزج بينها.' : 'كل خيار باقة تضمّ فنادقها بسعر واحد (كما كانت العروض).' }}</p>
                        </div>
                    </div>
                </section>


                <!-- المجموعات -->
                <section v-for="(g, gi) in form.groups" :key="g._k" class="rounded-xl border overflow-hidden border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
                    <header class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 bg-gray-50 dark:bg-gray-800/60 border-b border-gray-200 dark:border-gray-700">
                        <div class="flex flex-wrap items-center gap-2">
                            <label class="sr-only" :for="'g-label-' + g._k">اسم المجموعة</label>
                            <input :id="'g-label-' + g._k" v-model="g.label" :class="inpSm + ' w-40 font-bold'"/>
                            <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">قائمة منسدلة · {{ meta.groupKinds[g.kind] }}</span>
                            <template v-if="g.kind === 'stay' && form.pricing_mode === 'city'">
                                <label :for="'g-city-' + g._k" class="text-xs text-gray-500">المدينة</label>
                                <input :id="'g-city-' + g._k" v-model="g.city" :class="inpSm + ' w-24'"/>
                                <label :for="'g-nights-' + g._k" class="text-xs text-gray-500">الليالي</label>
                                <input :id="'g-nights-' + g._k" v-model="g.nights" type="number" min="0" dir="ltr" :class="inpSm + ' w-16 font-mono'"/>
                            </template>
                        </div>
                        <button type="button" @click="removeGroup(gi)" :disabled="groupSold(g)" :title="groupSold(g) ? 'فيها خيارات لها مبيعات' : ''" class="px-2 py-1 rounded-lg text-xs text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 disabled:opacity-30">حذف المجموعة</button>
                    </header>

                    <div class="divide-y divide-dashed divide-gray-200 dark:divide-gray-700">
                        <div v-for="(opt, oi) in g.options" :key="opt._k" class="p-4 space-y-3" :class="opt.is_active ? '' : 'opacity-60'">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <b class="text-sm text-gray-700 dark:text-gray-200">{{ g.kind === 'stay' ? 'الخيار' : 'خيار' }} {{ oi + 1 }}</b>
                                    <template v-if="g.kind !== 'stay' || form.pricing_mode === 'bundle'">
                                        <label class="sr-only" :for="'o-label-' + opt._k">اسم الخيار</label>
                                        <input :id="'o-label-' + opt._k" v-model="opt.label" :class="inpSm + ' w-44'" :placeholder="g.kind === 'stay' ? 'اسم الباقة: اقتصادية' : 'مثال: باص VIP'"/>
                                    </template>
                                    <span v-if="optSold(opt)" class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">له مبيعات</span>
                                    <label :for="'o-act-' + opt._k" class="flex items-center gap-1.5 text-xs font-bold cursor-pointer"><input :id="'o-act-' + opt._k" v-model="opt.is_active" type="checkbox" class="w-4 h-4 rounded text-gold-500"/> فعّال</label>
                                </div>
                                <button type="button" @click="removeOption(g, oi)" :disabled="optSold(opt)" :title="optSold(opt) ? 'له مبيعات: ألغِ «فعّال» بدل الحذف' : ''" class="px-2 py-1 rounded-lg text-xs text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 disabled:opacity-30">حذف الخيار</button>
                            </div>

                            <!-- خيار فندقي -->
                            <template v-if="g.kind === 'stay'">
                                <div v-for="(s, si) in opt.stays" :key="s._k" class="relative rounded-xl border border-dashed p-3 space-y-3 border-gray-300 dark:border-gray-600">
                                    <button v-if="opt.stays.length > 1" type="button" @click="removeStay(opt, si)" :disabled="stayGone(s)" class="absolute top-1 left-1 px-2 text-lg text-gray-400 hover:text-red-500 disabled:opacity-30" aria-label="حذف الفندق">&times;</button>
                                    <div>
                                        <p class="text-xs font-bold text-gray-500 mb-2">الفندق كما يراه العميل<UseTags tags="bot card"/></p>
                                        <div class="grid grid-cols-2 md:grid-cols-6 gap-2">
                                            <div v-if="form.pricing_mode === 'bundle'"><label :for="'s-city-' + s._k" :class="mini">المدينة</label><input :id="'s-city-' + s._k" v-model="s.city" :class="inpSm"/></div>
                                            <div class="col-span-2"><label :for="'s-name-' + s._k" :class="mini">اسم الفندق *</label><input :id="'s-name-' + s._k" v-model="s.name" :class="inpSm" placeholder="مثال: رحاب الصفوة"/></div>
                                            <div>
                                                <label :for="'s-rating-' + s._k" :class="mini">التصنيف</label>
                                                <select :id="'s-rating-' + s._k" v-model="s.rating" :class="inpSm"><option :value="null">—</option><option v-for="n in 7" :key="n" :value="n">{{ n }}★</option></select>
                                            </div>
                                            <div v-if="form.pricing_mode === 'bundle'"><label :for="'s-nights-' + s._k" :class="mini">الليالي</label><input :id="'s-nights-' + s._k" v-model="s.nights" type="number" min="0" dir="ltr" :class="inpSm + ' font-mono'"/></div>
                                            <div><label :for="'s-loc-' + s._k" :class="mini">الموقع</label><input :id="'s-loc-' + s._k" v-model="s.location" maxlength="80" :class="inpSm"/></div>
                                            <div><label :for="'s-meals-' + s._k" :class="mini">الوجبات</label><input :id="'s-meals-' + s._k" v-model="s.meals" maxlength="60" :class="inpSm"/></div>
                                            <div v-if="usesDistance"><label :for="'s-dist-' + s._k" :class="mini">المسافة عن الحرم</label><input :id="'s-dist-' + s._k" v-model="s.distance_haram" maxlength="40" :class="inpSm"/></div>
                                            <div class="flex items-end"><label :for="'s-plus-' + s._k" class="flex items-center gap-1.5 text-xs cursor-pointer pb-2"><input :id="'s-plus-' + s._k" v-model="s.rating_plus" type="checkbox" class="w-4 h-4 rounded text-gold-500"/> تصنيف زائد (+)</label></div>
                                        </div>
                                    </div>
                                </div>
                                <button v-if="form.pricing_mode === 'bundle' && opt.stays.length < 4" type="button" @click="opt.stays.push(blankStay(''))" class="px-3 py-1.5 rounded-lg text-xs font-bold text-gray-700 dark:text-gray-200 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200">+ فندق في مدينة أخرى ضمن الباقة</button>

                                <div class="rounded-lg p-2 border border-gray-200 dark:border-gray-700">
                                    <p class="text-xs font-bold text-gray-500 mb-2">سعر البيع للفرد (د.أ){{ Number(form.base_sell_pp) ? ' — يُضاف للأساسي' : '' }}<UseTags tags="bot card inv"/></p>
                                    <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                                        <div v-for="(rl, rk) in meta.roomTypes" :key="rk">
                                            <label :for="'o-price-' + opt._k + rk" :class="mini">{{ rl }}</label>
                                            <input :id="'o-price-' + opt._k + rk" v-model="opt.prices[rk]" type="number" step="0.001" min="0" dir="ltr" placeholder="—" :class="inpSm + ' font-mono'"/>
                                        </div>
                                    </div>
                                    <p class="text-[11px] text-gray-400 mt-1">اترك الخانة فارغة إن كانت السعة غير متاحة.</p>
                                    <div v-if="form.pricing_mode === 'bundle'" class="mt-2">
                                        <label :for="'o-inc-' + opt._k" :class="mini">ما يشمله سعر هذا الخيار<UseTags tags="bot card"/></label>
                                        <input :id="'o-inc-' + opt._k" v-model="opt.includes_note" :class="inpSm" placeholder="مثال: يشمل الإفطار يومياً"/>
                                    </div>
                                </div>
                            </template>

                            <!-- خيار غير فندقي (نقل…) -->
                            <template v-else>
                                <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                                    <div>
                                        <label :for="'o-flat-' + opt._k" :class="mini">فرق البيع للفرد (د.أ)<UseTags tags="bot card inv"/></label>
                                        <input :id="'o-flat-' + opt._k" v-model="opt.sell_flat" type="number" step="0.001" dir="ltr" :class="inpSm + ' font-mono'"/>
                                    </div>
                                    <div>
                                        <label :for="'o-cap-' + opt._k" :class="mini">سعة الخيار</label>
                                        <input :id="'o-cap-' + opt._k" v-model="opt.capacity" type="number" min="1" dir="ltr" placeholder="غير محدودة" :class="inpSm + ' font-mono'"/>
                                    </div>
                                </div>
                                <p :class="hint">الخيار الأول هو الافتراضي ويدخل في «يبدأ من». البقية يعرضها البوت إضافةً بفرق سعرها. الخيار بفرق سعر يقترح بند تكلفة في الفاتورة.</p>
                            </template>
                        </div>
                    </div>

                    <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700">
                        <button type="button" @click="addOption(g)" class="px-3 py-1.5 rounded-lg text-xs font-bold text-gold-800 dark:text-gold-200 bg-gold-100 dark:bg-gold-900/30 hover:bg-gold-200">+ خيار في «{{ g.label }}»</button>
                    </div>
                </section>

                <div class="flex flex-wrap items-center gap-2">
                    <label for="f-newkind" class="text-sm text-gray-500">مجموعة جديدة:</label>
                    <select id="f-newkind" v-model="newKind" :class="inpSm + ' w-auto'">
                        <option v-for="(l, k) in meta.groupKinds" :key="k" :value="k">{{ l }}</option>
                    </select>
                    <button type="button" @click="addGroup" class="px-3 py-1.5 rounded-lg text-xs font-bold text-gray-700 dark:text-gray-200 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200">+ إضافة مجموعة</button>
                </div>
            </template>
        </div>

        <!-- اللوحة الجانبية: اكتمال بيانات البيع (حسب آخر حفظ) -->
        <aside class="space-y-4 xl:sticky xl:top-20">
            <div v-if="isNew" :class="box">
                <p class="text-sm text-gray-500">احفظ العرض ليظهر هنا هل يُباع بفاتورة عرض.</p>
            </div>
            <div v-else :class="box">
                <b v-if="issues.length" class="text-sm text-red-600 dark:text-red-400">لا يُباع بفاتورة عرض بعد: {{ issues.length }} نقاط</b>
                <b v-else class="text-sm text-green-600 dark:text-green-400">بيانات البيع مكتملة: يُباع بفاتورة عرض.</b>
                <ul v-if="issues.length" class="list-disc ps-5 text-xs text-red-600 dark:text-red-400 space-y-0.5">
                    <li v-for="(x, i) in issues.slice(0, 10)" :key="i">{{ x }}</li>
                    <li v-if="issues.length > 10">و{{ issues.length - 10 }} أخرى…</li>
                </ul>
                <p class="text-[11px] text-gray-400">حسب آخر حفظ.</p>
            </div>
            <div :class="box">
                <b class="text-sm">أين التكاليف؟</b>
                <p class="text-xs text-gray-500 leading-relaxed">العرض يحمل البيع وحده. الوكيل والتكلفة يتغيران كل يوم، فيُدخلان في كل فاتورة عند البيع بقيمتهما الفعلية، والفاتورة لا تُعتمد قبل اكتمالهما. مصاريف الرحلة المشتركة (الباص المستأجر، المرشد) تُدخل مرة واحدة في «الإقفال».</p>
            </div>
        </aside>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import UseTags from '@/Components/Offers/UseTags.vue';

const props = defineProps({
    form: Object, meta: Object, sold: Object, hasSales: Boolean, issues: Array, isNew: Boolean,
});

const box = 'rounded-xl border p-4 space-y-3 border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900';
const h4 = 'text-sm font-bold text-gray-800 dark:text-gray-100';
const lbl = 'flex flex-wrap items-center gap-1 text-sm font-medium text-gray-700 dark:text-gray-300 mb-1';
const mini = 'flex flex-wrap items-center gap-1 text-[11px] font-bold text-gray-500 dark:text-gray-400 mb-0.5';
const inp = 'w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none';
const inpSm = 'w-full px-2.5 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none';
const hint = 'text-xs text-gray-400 dark:text-gray-500';

const isVisa = computed(() => props.form.category === 'visa');
const usesDistance = computed(() => ['umrah', 'hajj'].includes(props.form.category));
const newKind = ref('transport');

let uid = 100000;
const rooms = () => Object.fromEntries(Object.keys(props.meta.roomTypes).map((k) => [k, null]));
const blankStay = (city) => ({ _k: ++uid, id: null, city: city ?? null, name: '', rating: null, rating_plus: false, location: '', meals: '', distance_haram: '', nights: null });

const optSold = (opt) => opt.id && props.sold?.option?.includes(opt.id);
const stayGone = (s) => s.id && props.sold?.stay?.includes(s.id);
const groupSold = (g) => (g.options || []).some(optSold);

const addGroup = () => {
    const k = newKind.value;
    props.form.groups.push(k === 'stay'
        ? { _k: ++uid, id: null, kind: 'stay', label: props.form.pricing_mode === 'city' ? 'فندق مدينة' : 'الإقامة', city: '', nights: null, options: [] }
        : { _k: ++uid, id: null, kind: k, label: props.meta.groupKinds[k], city: null, nights: null, options: [] });
};
const removeGroup = (gi) => { props.form.groups.splice(gi, 1); };
const addOption = (g) => {
    if (g.kind === 'stay') {
        const stays = props.form.pricing_mode === 'bundle'
            ? (['umrah', 'hajj'].includes(props.form.category) && props.form.route_mode === 'makkah_madinah' ? [blankStay('مكة'), blankStay('المدينة')] : [blankStay('')])
            : [blankStay(g.city)];
        g.options.push({ _k: ++uid, id: null, label: '', includes_note: '', prices: rooms(), sell_flat: 0, capacity: null, is_active: true, stays });
    } else {
        g.options.push({ _k: ++uid, id: null, label: '', includes_note: null, prices: rooms(), sell_flat: 0, capacity: null, is_active: true, stays: [] });
    }
};
const removeOption = (g, oi) => { g.options.splice(oi, 1); };
const removeStay = (opt, si) => { opt.stays.splice(si, 1); };

// تبديل النمط: قالب جديد ما دام لم يُكتب اسم فندق، وإلا يتغير النمط فقط
const setMode = (mode) => {
    if (props.hasSales || props.form.pricing_mode === mode) return;
    const typed = props.form.groups.some((g) => g.options.some((o) => o.stays.some((s) => String(s.name || '').trim() !== '')));
    props.form.pricing_mode = mode;
    if (typed) return;
    const others = props.form.groups.filter((g) => g.kind !== 'stay');
    const stayGroups = mode === 'city'
        ? [{ _k: ++uid, id: null, kind: 'stay', label: 'فندق مكة', city: 'مكة', nights: 5, options: [] },
           { _k: ++uid, id: null, kind: 'stay', label: 'فندق المدينة', city: 'المدينة', nights: 4, options: [] }]
        : [{ _k: ++uid, id: null, kind: 'stay', label: 'الإقامة', city: null, nights: null, options: [] }];
    props.form.groups.splice(0, props.form.groups.length, ...stayGroups, ...others);
};

</script>
