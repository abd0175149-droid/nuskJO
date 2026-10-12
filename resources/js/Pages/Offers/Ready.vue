<template>
    <AppLayout>
        <template #header>العروض الجاهزة</template>

        <div class="space-y-6">
            <div v-if="$page.props.flash?.success" class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700 dark:bg-green-900/20 dark:border-green-800 dark:text-green-300">✅ {{ $page.props.flash.success }}</div>
            <div v-if="$page.props.flash?.error" class="p-4 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700 dark:bg-red-900/20 dark:border-red-800 dark:text-red-300">❌ {{ $page.props.flash.error }}</div>

            <div class="flex flex-wrap items-center justify-between gap-4 filter-bar">
                <div class="inline-flex rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden text-sm" role="group" aria-label="تصفية العروض الجاهزة">
                    <button v-for="f in filters" :key="f.key" type="button" @click="setFilter(f.key)" :aria-pressed="filter === f.key"
                            class="px-4 py-2 font-bold" :class="filter === f.key ? 'bg-gold-100 text-gold-800 dark:bg-gold-900/40 dark:text-gold-200' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700'">{{ f.label }}</button>
                </div>
                <div class="flex gap-2 w-full sm:w-auto">
                    <Link v-if="can?.invoice" href="/invoices?offer_mode=1" class="flex-1 sm:flex-none text-center px-5 py-2.5 rounded-xl font-bold text-sm text-gold-800 dark:text-gold-200 bg-gold-100 dark:bg-gold-900/30 hover:bg-gold-200">🧾 فاتورة عرض</Link>
                    <Link v-if="can?.create" href="/offers/create?ready=1" class="flex-1 sm:flex-none text-center px-5 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md">+ عرض جاهز جديد</Link>
                </div>
            </div>

            <p class="text-sm text-gray-500 dark:text-gray-400">رحلات بتاريخ ومقاعد محدودة. هذه القائمة نافذة على العروض نفسها (العروض التي فُعّل فيها «عرض جاهز»)، وليست جدولاً ثانياً. المتاح يُحسب من الفواتير المعلّقة وتحت التعديل والمعتمدة، ويتحدّث مع كل فاتورة.</p>

            <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700">
                <div class="overflow-x-auto">
                <table class="w-full text-sm responsive-table">
                    <thead><tr class="bg-gray-50 dark:bg-gray-800/50 text-gray-600 dark:text-gray-400">
                        <th class="px-5 py-3 text-right font-bold">العرض</th>
                        <th class="px-5 py-3 text-right font-bold">تاريخ الرحلة</th>
                        <th class="px-5 py-3 text-right font-bold">الوجهة والسفر</th>
                        <th class="px-5 py-3 text-right font-bold">المقاعد</th>
                        <th class="px-5 py-3 text-right font-bold">الحالة</th>
                        <th class="px-5 py-3 text-right font-bold hide-mobile">البوت</th>
                        <th class="px-5 py-3 text-center font-bold hide-mobile">الفواتير</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="o in offers" :key="o.id" class="border-t border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-800/30 cursor-pointer" @click="open(o)">
                            <td data-label="العرض" class="px-5 py-3 text-right">
                                <Link :href="'/offers/' + o.id + '?tab=sales&from=ready'" class="font-medium text-gray-800 dark:text-gray-100 hover:text-gold-700" @click.stop>{{ o.title }}</Link>
                                <div class="text-[11px] text-gray-500 mt-0.5">{{ o.category_label }}</div>
                            </td>
                            <td data-label="تاريخ الرحلة" class="px-5 py-3 text-right whitespace-nowrap">
                                <div class="font-mono tabular-nums text-xs" dir="ltr">{{ o.trip_date }}</div>
                                <div v-if="o.return_date" class="text-[11px] text-gray-500">العودة <span class="font-mono" dir="ltr">{{ o.return_date }}</span></div>
                            </td>
                            <td data-label="الوجهة" class="px-5 py-3 text-right text-xs">
                                <div>{{ o.destination }}</div>
                                <div class="text-gray-500">{{ o.travel_mode === 'air' ? '✈️' : '🚌' }} {{ o.travel_mode_label }}</div>
                            </td>
                            <td data-label="المقاعد" class="px-5 py-3 text-right"><SeatBar :total="o.total_seats || 0" :approved="o.approved_seats" :booked="o.booked"/></td>
                            <td data-label="الحالة" class="px-5 py-3 text-right"><span class="px-2.5 py-1 rounded-full text-xs font-bold whitespace-nowrap" :class="tone(o.state.tone)">{{ o.state.label }}</span></td>
                            <td data-label="البوت" class="px-5 py-3 text-right text-xs hide-mobile" :class="o.for_bot ? 'text-green-600 dark:text-green-400' : 'text-gray-400'">{{ o.for_bot ? '🤖 يعرضه' : 'لا يعرضه' }}</td>
                            <td data-label="الفواتير" class="px-5 py-3 text-center font-mono tabular-nums hide-mobile">{{ o.invoices_count }}</td>
                        </tr>
                        <tr v-if="!offers.length"><td colspan="7" class="px-5 py-12 text-center text-gray-400">لا عروض جاهزة في هذه القائمة.</td></tr>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/SmartLayout.vue';
import SeatBar from '@/Components/Offers/SeatBar.vue';

const props = defineProps({ title: String, offers: Array, filter: String, can: Object });

const filters = [{ key: 'upcoming', label: 'القادمة' }, { key: 'past', label: 'السابقة والمُقفلة' }, { key: 'all', label: 'الكل' }];
const tone = (t) => ({
    green: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
    amber: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    red: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
    blue: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
}[t] || 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300');
const setFilter = (f) => router.get('/offers/ready', { filter: f }, { preserveState: true, replace: true });
const open = (o) => router.visit('/offers/' + o.id + '?tab=sales&from=ready');
</script>
