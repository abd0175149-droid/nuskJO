<template>
    <div class="space-y-5">
        <!-- بيانات العرض -->
        <section class="rounded-xl border p-4 space-y-4 border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h4 class="text-sm font-bold text-gray-800 dark:text-gray-100">📋 بيانات العرض</h4>
                <span class="text-xs text-gray-400">الوسوم بجانب كل حقل تبيّن من يقرؤه. لا يُدخل أي حقل مرتين.</span>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label for="f-title" :class="lbl">{{ isVisa ? 'مسمى التأشيرة *' : 'عنوان العرض *' }}<UseTags tags="bot card ready inv"/></label>
                    <input id="f-title" v-model="form.title" :class="inp" placeholder="مثال: عمرة المولد — برًا 10 أيام"/>
                </div>
                <div>
                    <label for="f-category" :class="lbl">التصنيف *<UseTags tags="bot card"/></label>
                    <select id="f-category" v-model="form.category" :class="inp" :disabled="hasSales" @change="onCategory">
                        <option v-for="(l, k) in meta.categories" :key="k" :value="k">{{ l }}</option>
                    </select>
                    <p v-if="hasSales" :class="hint">للعرض مبيعات، فلا يتحوّل من تأشيرة وإليها.</p>
                </div>
                <div v-if="usesRoute">
                    <label for="f-route" :class="lbl">المسار<UseTags tags="bot card"/></label>
                    <select id="f-route" v-model="form.route_mode" :class="inp">
                        <option :value="null">—</option>
                        <option v-for="(l, k) in meta.routeModes" :key="k" :value="k">{{ l }}</option>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label for="f-desc" :class="lbl">وصف العرض (يظهر للعميل)<UseTags tags="bot card"/></label>
                    <textarea id="f-desc" v-model="form.description_client" rows="3" :class="inp + ' resize-none'"></textarea>
                </div>
                <div class="md:col-span-2">
                    <label for="f-notes" :class="lbl">ملاحظات تظهر للعميل<UseTags tags="bot card"/></label>
                    <textarea id="f-notes" v-model="form.notes_public" rows="2" maxlength="1000" :class="inp + ' resize-none'"></textarea>
                </div>
                <template v-if="!isVisa">
                    <div>
                        <label for="f-nights" :class="lbl">عدد الليالي<UseTags tags="bot card"/></label>
                        <input id="f-nights" v-model="form.nights" type="number" min="0" dir="ltr" :class="inp + ' font-mono'"/>
                    </div>
                    <div>
                        <label for="f-airline" :class="lbl">شركة الطيران<UseTags tags="bot card"/></label>
                        <input id="f-airline" v-model="form.airline" :class="inp" placeholder="إن وُجد"/>
                    </div>
                </template>
                <div>
                    <label for="f-vfrom" :class="lbl">يُعرض للبوت من<UseTags tags="bot"/></label>
                    <input id="f-vfrom" v-model="form.valid_from" type="date" dir="ltr" :class="inp"/>
                </div>
                <div>
                    <label for="f-vto" :class="lbl">يُعرض للبوت حتى<UseTags tags="bot"/></label>
                    <input id="f-vto" v-model="form.valid_to" type="date" dir="ltr" :min="form.valid_from || undefined" :class="inp"/>
                    <p :class="hint">للعرض المفتوح: هي أيضاً الفترة التي يُباع فيها بفاتورة عرض.</p>
                </div>
                <div>
                    <label for="f-sort" :class="lbl">ترتيب العرض</label>
                    <input id="f-sort" v-model="form.sort_order" type="number" min="0" dir="ltr" :class="inp + ' font-mono'"/>
                </div>
                <div class="flex flex-col gap-2 justify-center">
                    <label for="f-active" class="flex items-center gap-2 cursor-pointer text-sm"><input id="f-active" v-model="form.is_active" type="checkbox" class="w-4 h-4 rounded text-gold-500"/> نشط</label>
                    <label for="f-bot" class="flex items-center gap-2 cursor-pointer text-sm"><input id="f-bot" v-model="form.is_bot_visible" type="checkbox" class="w-4 h-4 rounded text-gold-500"/> 🤖 ظاهر للبوت</label>
                </div>
            </div>
        </section>

        <!-- يشمل / لا يشمل -->
        <section class="rounded-xl border p-4 space-y-3 border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
            <h4 class="text-sm font-bold text-gray-800 dark:text-gray-100">✅ العرض يشمل / ❌ لا يشمل<UseTags tags="bot card"/></h4>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div v-for="key in ['includes', 'excludes']" :key="key">
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ key === 'includes' ? '✅ يشمل' : '❌ لا يشمل' }}</span>
                        <button type="button" @click="form[key] = [...(form[key] || []), '']" class="px-2 py-1 rounded-lg text-xs font-bold text-gold-800 bg-gold-100 hover:bg-gold-200 dark:bg-gold-900/30 dark:text-gold-200">+ إضافة</button>
                    </div>
                    <div class="space-y-2">
                        <div v-for="(it, i) in form[key]" :key="key + i" class="flex items-center gap-2">
                            <label class="sr-only" :for="'f-' + key + '-' + i">{{ key === 'includes' ? 'بند يشمله العرض' : 'بند لا يشمله العرض' }} {{ i + 1 }}</label>
                            <input :id="'f-' + key + '-' + i" v-model="form[key][i]" maxlength="120" :class="inp"/>
                            <button type="button" @click="form[key] = form[key].filter((_, j) => j !== i)" class="px-2.5 py-2 rounded-xl text-xs text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20" :aria-label="'حذف البند ' + (i + 1)">&times;</button>
                        </div>
                        <p v-if="!form[key]?.length" class="text-xs text-gray-400">لا توجد بنود.</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- التأشيرة -->
        <section v-if="isVisa" class="rounded-xl border p-4 space-y-3 border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
            <h4 class="text-sm font-bold text-gray-800 dark:text-gray-100">🛂 بيانات التأشيرة</h4>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label for="f-req" :class="lbl">الشروط والمتطلبات<UseTags tags="bot card"/></label>
                    <textarea id="f-req" v-model="form.requirements" rows="3" :class="inp + ' resize-none'"></textarea>
                </div>
                <div><label for="f-vval" :class="lbl">صلاحية التأشيرة<UseTags tags="bot card"/></label><input id="f-vval" v-model="form.visa_validity" maxlength="60" :class="inp"/></div>
                <div><label for="f-vent" :class="lbl">عدد مرات الدخول<UseTags tags="bot card"/></label><input id="f-vent" v-model="form.visa_entries" maxlength="40" :class="inp"/></div>
                <div><label for="f-vproc" :class="lbl">مدة الإصدار<UseTags tags="bot card"/></label><input id="f-vproc" v-model="form.visa_processing" maxlength="60" :class="inp"/></div>
            </div>
            <p :class="hint">السعر وتكلفته في تبويب «السعر والتكاليف».</p>
        </section>

        <!-- العرض الجاهز -->
        <section class="rounded-xl border p-4 space-y-4" :class="form.is_ready ? 'border-gold-400 bg-gold-50/60 dark:bg-gold-900/10 dark:border-gold-700' : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900'">
            <label for="f-ready" class="flex items-center gap-2 cursor-pointer text-sm font-bold text-gray-800 dark:text-gray-100">
                <input id="f-ready" v-model="form.is_ready" type="checkbox" class="w-4 h-4 rounded text-gold-500" @change="onReady"/>
                🎫 عرض جاهز: رحلة بتاريخ ومقاعد محدودة
            </label>
            <p :class="hint">يظهر في «العروض الجاهزة»، وفي فاتورة العرض حسب تاريخه، والمتاح يتحدّث مع كل فاتورة.</p>
            <div v-if="form.is_ready" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div><label for="f-trip" :class="lbl">تاريخ الرحلة *<UseTags tags="ready inv bot card"/></label><input id="f-trip" v-model="form.trip_date" type="date" dir="ltr" :class="inp"/></div>
                <div><label for="f-ret" :class="lbl">تاريخ العودة<UseTags tags="ready bot card"/></label><input id="f-ret" v-model="form.return_date" type="date" dir="ltr" :class="inp"/></div>
                <div>
                    <label for="f-mode" :class="lbl">وسيلة السفر *<UseTags tags="ready bot card"/></label>
                    <select id="f-mode" v-model="form.travel_mode" :class="inp">
                        <option v-for="(l, k) in meta.travelModes" :key="k" :value="k">{{ k === 'air' ? '✈️' : '🚌' }} {{ l }}</option>
                    </select>
                </div>
                <div class="md:col-span-2"><label for="f-dest" :class="lbl">الوجهة *<UseTags tags="ready bot card"/></label><input id="f-dest" v-model="form.destination" maxlength="120" :class="inp" placeholder="مثال: مكة والمدينة"/></div>
                <div><label for="f-seats" :class="lbl">عدد المقاعد الكلي *<UseTags tags="ready inv bot"/></label><input id="f-seats" v-model="form.total_seats" type="number" min="1" dir="ltr" :class="inp + ' font-mono'"/></div>
            </div>
            <p v-if="form.is_ready" :class="hint">البوت لا يرى عدد المقاعد: يرى «متوفر» أو «مقاعد محدودة»، ويختفي العرض عنه حين يكتمل أو تُقلع الرحلة. الفنادق وسعات الغرف في التبويب التالي.</p>
        </section>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import UseTags from '@/Components/Offers/UseTags.vue';

const props = defineProps({ form: Object, meta: Object, hasSales: Boolean });

const lbl = 'flex flex-wrap items-center gap-1 text-sm font-medium text-gray-700 dark:text-gray-300 mb-1';
const inp = 'w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none';
const hint = 'mt-1 text-xs text-gray-400 dark:text-gray-500';

const isVisa = computed(() => props.form.category === 'visa');
const usesRoute = computed(() => ['umrah', 'hajj'].includes(props.form.category));

const onCategory = () => {
    if (!['umrah', 'hajj'].includes(props.form.category)) props.form.route_mode = null;
    else if (!props.form.route_mode) props.form.route_mode = 'makkah_madinah';
};
const onReady = () => {
    if (props.form.is_ready && !props.form.travel_mode) props.form.travel_mode = 'land';
};
</script>
