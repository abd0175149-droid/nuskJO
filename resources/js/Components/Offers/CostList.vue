<template>
    <section :class="compact ? 'space-y-2' : 'rounded-xl border p-4 space-y-3 border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900'">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h4 class="text-sm font-bold text-gray-800 dark:text-gray-100">{{ title }}<UseTags tags="cost"/></h4>
            <span v-if="hint" class="text-xs text-gray-400">{{ hint }}</span>
        </div>
        <div v-for="(c, i) in list" :key="c._k || i" class="grid grid-cols-2 md:grid-cols-8 gap-2 items-end p-2 rounded-lg bg-gray-50 dark:bg-gray-800/60">
            <div class="col-span-2">
                <label :for="'c-label-' + (c._k || i)" :class="mini">البند *</label>
                <input :id="'c-label-' + (c._k || i)" v-model="c.label" :class="inp" placeholder="مثال: تأشيرة عمرة"/>
            </div>
            <div>
                <label :for="'c-kind-' + (c._k || i)" :class="mini">النوع</label>
                <select :id="'c-kind-' + (c._k || i)" v-model="c.kind" :class="inp"><option v-for="(l, k) in meta.costKinds" :key="k" :value="k">{{ l }}</option></select>
            </div>
            <div class="col-span-2">
                <label :for="'c-agent-' + (c._k || i)" :class="mini">الوكيل *</label>
                <select :id="'c-agent-' + (c._k || i)" v-model="c.agent_id" :class="inp">
                    <option :value="null">— الوكيل —</option>
                    <option v-for="a in agents" :key="a.id" :value="a.id">{{ a.name }}</option>
                </select>
            </div>
            <div>
                <label :for="'c-basis-' + (c._k || i)" :class="mini">أساس الحساب</label>
                <select :id="'c-basis-' + (c._k || i)" v-model="c.basis" :class="inp"><option v-for="(l, k) in meta.costBases" :key="k" :value="k">{{ l }}</option></select>
            </div>
            <div>
                <label :for="'c-qty-' + (c._k || i)" :class="mini">العدد</label>
                <input :id="'c-qty-' + (c._k || i)" v-model="c.qty" type="number" step="any" min="0" dir="ltr" :class="inp + ' font-mono'"/>
            </div>
            <div>
                <label :for="'c-unit-' + (c._k || i)" :class="mini">تكلفة الوحدة</label>
                <input :id="'c-unit-' + (c._k || i)" v-model="c.unit_cost" type="number" step="0.001" min="0" dir="ltr" :class="inp + ' font-mono'"/>
            </div>
            <div>
                <label :for="'c-cur-' + (c._k || i)" :class="mini">العملة</label>
                <select :id="'c-cur-' + (c._k || i)" v-model="c.currency" :class="inp"><option value="SAR">ريال</option><option value="JOD">دينار</option></select>
            </div>
            <div class="col-span-2">
                <label :for="'c-svc-' + (c._k || i)" :class="mini">خدمة الإيراد</label>
                <select :id="'c-svc-' + (c._k || i)" v-model="c.service_id" :class="inp">
                    <option :value="null">تلقائي حسب النوع</option>
                    <option v-for="s in services" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
            </div>
            <div class="col-span-2 md:col-span-3 text-[11px] text-gray-500">
                {{ c.basis === 'per_group' ? 'مقطوع: يُوزَّع على مقاعد العرض، والباقي عند الإقفال.' : 'لكل مسافر في الفاتورة.' }}
                <span v-if="isSold(c)" class="text-amber-600"> له مبيعات: لا يُحذف ولا يتغير وكيله.</span>
            </div>
            <div class="text-left">
                <button type="button" @click="list.splice(i, 1)" :disabled="isSold(c)" class="px-2 py-1 rounded-lg text-xs text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 disabled:opacity-30">حذف</button>
            </div>
        </div>
        <p v-if="!list.length" class="text-xs text-gray-400">لا بنود.</p>
        <button type="button" @click="add" class="px-3 py-1.5 rounded-lg text-xs font-bold text-gray-700 dark:text-gray-200 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200">+ بند تكلفة</button>
    </section>
</template>

<script setup>
import UseTags from '@/Components/Offers/UseTags.vue';

const props = defineProps({
    title: String, hint: String, list: Array, agents: Array, services: Array, meta: Object,
    sold: { type: Array, default: () => [] }, defaultKind: { type: String, default: 'service' },
    defaultCurrency: { type: String, default: 'JOD' }, compact: Boolean,
});

const mini = 'block text-[11px] font-bold text-gray-500 dark:text-gray-400 mb-0.5';
const inp = 'w-full px-2.5 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none';

let uid = 500000;
const isSold = (c) => c.id && props.sold.includes(c.id);
const add = () => props.list.push({ _k: ++uid, id: null, kind: props.defaultKind, label: '', agent_id: null, service_id: null, basis: 'per_person', qty: 1, unit_cost: null, currency: props.defaultCurrency });
</script>
