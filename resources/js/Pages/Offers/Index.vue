<template>
    <AppLayout>
        <template #header>العروض والباقات</template>

        <div class="space-y-6">
            <div v-if="$page.props.flash?.success" class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700 dark:bg-green-900/20 dark:border-green-800 dark:text-green-300">✅ {{ $page.props.flash.success }}</div>
            <div v-if="$page.props.flash?.error" class="p-4 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700 dark:bg-red-900/20 dark:border-red-800 dark:text-red-300">❌ {{ $page.props.flash.error }}</div>

            <!-- Filter bar -->
            <div class="flex flex-wrap items-center justify-between gap-4 filter-bar">
                <div class="flex items-center gap-3 flex-wrap">
                    <label class="sr-only" for="offers-search">بحث</label>
                    <input id="offers-search" v-model="search" type="text" placeholder="بحث بعنوان العرض..." class="w-72 max-w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:outline-none focus:ring-2 focus:ring-gold-500" @input="debounceSearch"/>
                    <label class="sr-only" for="offers-category">التصنيف</label>
                    <select id="offers-category" v-model="categoryFilter" @change="applyFilters" class="px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 text-sm focus:outline-none focus:ring-2 focus:ring-gold-500">
                        <option value="">🗂️ كل التصنيفات</option>
                        <option v-for="(cLabel, cKey) in categories" :key="cKey" :value="cKey">{{ cLabel }}</option>
                    </select>
                    <div class="inline-flex rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden text-sm" role="group" aria-label="نوع العرض">
                        <button v-for="k in kinds" :key="k.key" type="button" @click="setKind(k.key)" :aria-pressed="kindFilter === k.key"
                                class="px-3 py-2 font-bold" :class="kindFilter === k.key ? 'bg-gold-100 text-gold-800 dark:bg-gold-900/40 dark:text-gold-200' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700'">{{ k.label }}</button>
                    </div>
                </div>
                <div class="flex gap-2 w-full sm:w-auto">
                    <Link v-if="can?.create" href="/offers/create" class="flex-1 sm:flex-none text-center px-5 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md hover:shadow-gold-500/25">+ إضافة عرض</Link>
                    <Link v-if="can?.create" href="/offers/create?ready=1" class="flex-1 sm:flex-none text-center px-5 py-2.5 rounded-xl font-bold text-sm text-gold-800 dark:text-gold-200 bg-gold-100 dark:bg-gold-900/30 hover:bg-gold-200">🎫 عرض جاهز جديد</Link>
                </div>
            </div>

            <div class="p-4 rounded-xl border text-sm bg-blue-50 border-blue-200 text-blue-800 dark:bg-blue-900/20 dark:border-blue-800 dark:text-blue-200">
                كل العروض في مكان واحد: الجاهزة (رحلة بتاريخ ومقاعد) والمفتوحة (كالتأشيرة). من صفحة العرض تُحرَّر بياناته وفنادقه وتكاليفه، ومنها يقرأ بوت الواتساب وبطاقة العرض وفاتورة العرض — لا يُدخل العرض في مكان ثانٍ.
            </div>

            <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700">
                <div class="overflow-x-auto">
                <table class="w-full text-sm responsive-table">
                    <thead><tr class="bg-gray-50 dark:bg-gray-800/50 text-gray-600 dark:text-gray-400">
                        <th class="px-5 py-3 text-right font-bold">العرض</th>
                        <th class="px-5 py-3 text-right font-bold hide-mobile">الرحلة / المدة</th>
                        <th class="px-5 py-3 text-right font-bold">المقاعد</th>
                        <th class="px-5 py-3 text-right font-bold">يبدأ من</th>
                        <th class="px-5 py-3 text-right font-bold">البوت</th>
                        <th class="px-5 py-3 text-right font-bold hide-mobile">التكاليف</th>
                        <th class="px-5 py-3 text-right font-bold hide-mobile">البطاقة</th>
                        <th class="px-5 py-3 text-center font-bold">إجراءات</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="o in offers.data" :key="o.id" class="border-t border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-800/30">
                            <td data-label="العرض" class="px-5 py-3 text-right">
                                <Link :href="'/offers/' + o.id" class="font-medium text-gray-800 dark:text-gray-100 hover:text-gold-700">{{ o.title }}</Link>
                                <div class="flex flex-wrap gap-1 mt-1">
                                    <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ o.category_label }}</span>
                                    <span v-if="o.is_ready" class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-gold-100 text-gold-800 dark:bg-gold-900/40 dark:text-gold-200">🎫 جاهز</span>
                                    <span class="px-2 py-0.5 rounded-full text-[11px] font-bold" :class="tone(o.state.tone)">{{ o.state.label }}</span>
                                </div>
                            </td>
                            <td data-label="الرحلة" class="px-5 py-3 text-right text-xs hide-mobile whitespace-nowrap">
                                <template v-if="o.is_ready">
                                    <div class="font-mono tabular-nums" dir="ltr">{{ o.trip_date }}</div>
                                    <div class="text-gray-500">{{ o.travel_mode_label }} · {{ o.destination }}</div>
                                </template>
                                <span v-else-if="o.valid_from || o.valid_to" dir="ltr" class="font-mono tabular-nums text-gray-600 dark:text-gray-300">{{ o.valid_from || '…' }} → {{ o.valid_to || '…' }}</span>
                                <span v-else class="text-gray-400">مفتوح</span>
                            </td>
                            <td data-label="المقاعد" class="px-5 py-3 text-right text-xs">
                                <SeatBar v-if="o.total_seats" :total="o.total_seats" :approved="o.approved_seats" :booked="o.booked"/>
                                <span v-else class="text-gray-400">بلا حد</span>
                            </td>
                            <td data-label="يبدأ من" class="px-5 py-3 text-right whitespace-nowrap">
                                <template v-if="o.price_from"><span class="font-bold font-mono text-xs tabular-nums" dir="ltr">{{ money(o.price_from) }}</span><span class="text-xs text-gray-400"> د.أ</span></template>
                                <span v-else class="text-gray-400">—</span>
                            </td>
                            <td data-label="البوت" class="px-5 py-3 text-right">
                                <button type="button" @click="toggleBot(o)" :disabled="!can?.update" :title="o.is_bot_visible ? 'اضغط لإخفائه عن البوت' : 'اضغط لإظهاره للبوت'"
                                        class="px-2.5 py-1 rounded-full text-xs font-bold transition hover:opacity-80 disabled:cursor-default"
                                        :class="o.is_bot_visible ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'">{{ o.is_bot_visible ? '🤖 ظاهر' : 'مخفي' }}</button>
                                <div class="text-[11px] mt-1" :class="o.for_bot ? 'text-green-600 dark:text-green-400' : 'text-gray-400'">{{ o.for_bot ? 'يقرؤه البوت الآن' : 'لا يقرؤه: ' + o.bot_blocker }}</div>
                            </td>
                            <td data-label="التكاليف" class="px-5 py-3 text-right hide-mobile">
                                <span class="px-2.5 py-1 rounded-full text-xs font-bold" :class="o.issues_count ? 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300' : 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300'">{{ o.issues_count ? 'ناقصة (' + o.issues_count + ')' : 'مكتملة' }}</span>
                            </td>
                            <td data-label="البطاقة" class="px-5 py-3 text-right hide-mobile whitespace-nowrap">
                                <span class="px-2.5 py-1 rounded-full text-xs font-bold" :class="cardChip(o.card).cls">{{ cardChip(o.card).text }}</span>
                            </td>
                            <td data-label="" class="px-5 py-3 text-center whitespace-nowrap actions-cell">
                                <Link :href="'/offers/' + o.id" class="px-2 py-1 text-xs text-gold-700 dark:text-gold-400 hover:bg-gold-50 dark:hover:bg-gold-900/20 rounded-lg btn-mobile-sm">فتح ←</Link>
                                <button v-if="can?.delete && !o.invoices_count" @click="deleteTarget = o" class="px-2 py-1 text-xs text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg btn-mobile-sm">🗑️ حذف</button>
                            </td>
                        </tr>
                        <tr v-if="!offers.data?.length"><td colspan="8" class="px-5 py-12 text-center text-gray-400">لا توجد عروض</td></tr>
                    </tbody>
                </table>
                </div>
            </div>

            <div v-if="offers.last_page > 1" class="flex justify-center gap-1 mt-4">
                <template v-for="link in offers.links" :key="link.label">
                    <a v-if="link.url" :href="link.url" class="px-3 py-2 rounded-lg text-sm" :class="link.active ? 'bg-gold-500 text-black font-bold' : 'text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800'" v-html="link.label"/>
                    <span v-else class="px-3 py-2 text-sm text-gray-400" v-html="link.label"/>
                </template>
            </div>
        </div>

        <div v-if="deleteTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="deleteTarget = null">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-sm mx-4 p-6 text-center">
                <div class="text-5xl mb-4">⚠️</div>
                <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-2">تأكيد الحذف</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">حذف العرض <strong class="text-gray-800 dark:text-gray-100">{{ deleteTarget.title }}</strong>؟ لا يُحذف عرض له فواتير.</p>
                <div class="flex gap-3 justify-center">
                    <button @click="confirmDelete" class="px-6 py-2.5 rounded-xl font-bold text-sm text-white bg-red-500 hover:bg-red-600 shadow-md">🗑️ حذف</button>
                    <button @click="deleteTarget = null" class="px-6 py-2.5 rounded-xl text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800">إلغاء</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/SmartLayout.vue';
import SeatBar from '@/Components/Offers/SeatBar.vue';

const props = defineProps({ title: String, offers: Object, filters: Object, categories: Object, can: Object });

const kinds = [{ key: '', label: 'الكل' }, { key: 'ready', label: '🎫 الجاهزة' }, { key: 'open', label: 'تاريخ مفتوح' }];
const search = ref(props.filters?.search || '');
const categoryFilter = ref(props.filters?.category ?? '');
const kindFilter = ref(props.filters?.kind ?? '');
const deleteTarget = ref(null);
let t = null;

const money = (v) => Number(v || 0).toLocaleString('en', { minimumFractionDigits: 0, maximumFractionDigits: 3 });
const tone = (t) => ({
    green: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
    amber: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    red: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
    blue: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
}[t] || 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300');
const cardChip = (c) => ({
    fresh: { text: '✅ حديثة', cls: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300' },
    stale: { text: '⚠️ قديمة', cls: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300' },
}[c] || { text: '—', cls: 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300' });

const params = () => ({
    search: search.value || undefined,
    category: categoryFilter.value || undefined,
    kind: kindFilter.value || undefined,
});
const applyFilters = () => router.get('/offers', params(), { preserveState: true, replace: true });
const debounceSearch = () => { clearTimeout(t); t = setTimeout(applyFilters, 400); };
const setKind = (k) => { kindFilter.value = k; applyFilters(); };
const toggleBot = (o) => router.post('/offers/' + o.id + '/toggle-bot', {}, { preserveScroll: true });
const confirmDelete = () => router.delete('/offers/' + deleteTarget.value.id, { preserveScroll: true, onSuccess: () => { deleteTarget.value = null; } });
</script>
