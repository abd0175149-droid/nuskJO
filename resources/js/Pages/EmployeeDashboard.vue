<template>
    <EmployeeLayout>
        <div class="max-w-4xl mx-auto">
            <!-- Welcome -->
            <div class="mb-8 text-center">
                <h1 class="text-2xl sm:text-3xl font-bold mb-2" :class="isDark ? 'text-gold-400' : 'text-gray-800'">
                    مرحباً، {{ user?.name }} 👋
                </h1>
                <p class="text-sm" :class="isDark ? 'text-gray-400' : 'text-gray-500'">
                    {{ user?.role?.name || 'موظف' }} — اختر الإجراء المطلوب
                </p>
            </div>

            <!-- Attendance Check-in Widget -->
            <div v-if="page.props.auth.permissions.includes('attendance.view')" class="mb-8 max-w-lg mx-auto">
                <CheckInWidget />
            </div>

            <!-- Shortcut Cards Grid -->
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4 sm:gap-6">
                <template v-for="card in availableCards" :key="card.route">
                    <Link :href="card.route"
                       class="group relative flex flex-col items-center justify-center gap-3 p-6 sm:p-8 rounded-2xl border-2 transition-all duration-300 cursor-pointer"
                       :class="[
                           isDark
                               ? 'bg-gray-900 border-gray-800 hover:border-gold-600 hover:bg-gray-800 hover:shadow-gold-900/30'
                               : 'bg-white border-gray-100 hover:border-gold-400 hover:bg-gold-50/30 hover:shadow-gold-200/50',
                           'hover:shadow-xl hover:-translate-y-1'
                       ]">
                        <!-- Icon -->
                        <div class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl flex items-center justify-center text-3xl sm:text-4xl transition-transform duration-300 group-hover:scale-110"
                             :class="isDark ? 'bg-gray-800 group-hover:bg-gold-900/30' : 'bg-gray-50 group-hover:bg-gold-100/50'">
                            {{ card.icon }}
                        </div>
                        <!-- Label -->
                        <span class="font-bold text-sm sm:text-base transition-colors"
                              :class="isDark ? 'text-gray-300 group-hover:text-gold-400' : 'text-gray-700 group-hover:text-gold-700'">
                            {{ card.label }}
                        </span>
                        <!-- Badge (optional count) -->
                        <span v-if="card.badge" 
                              class="absolute top-3 left-3 w-6 h-6 rounded-full bg-red-500 text-white text-xs flex items-center justify-center font-bold animate-pulse">
                            {{ card.badge }}
                        </span>
                    </Link>
                </template>
            </div>

            <!-- Empty state -->
            <div v-if="!availableCards.length" class="text-center py-20">
                <p class="text-6xl mb-4">🔒</p>
                <p class="text-lg font-bold" :class="isDark ? 'text-gray-400' : 'text-gray-500'">لا يوجد صلاحيات متاحة</p>
                <p class="text-sm mt-2" :class="isDark ? 'text-gray-500' : 'text-gray-400'">تواصل مع المسؤول لمنحك الصلاحيات المطلوبة</p>
            </div>
        </div>
    </EmployeeLayout>
</template>

<script setup>
import { computed } from 'vue';
import { usePage, Link } from '@inertiajs/vue3';
import EmployeeLayout from '@/Components/Layout/EmployeeLayout.vue';
import CheckInWidget from '@/Components/Attendance/CheckInWidget.vue';

const page = usePage();
const user = computed(() => page.props.auth?.user);
const isDark = computed(() => document.documentElement.classList.contains('dark'));

const can = (permission) => {
    const perms = page.props.auth?.permissions || [];
    return page.props.auth?.isAdmin || perms.includes(permission);
};

// التحقق إذا المستخدم عنده أي صلاحية في الموديول (view أو create أو update أو أي شي)
const canAny = (module) => {
    const perms = page.props.auth?.permissions || [];
    if (page.props.auth?.isAdmin) return true;
    return perms.some(p => p.startsWith(module + '.'));
};

const allCards = [
    { icon: '🏢', label: 'الوكلاء', route: '/agents', module: 'agents' },
    { icon: '👥', label: 'العملاء', route: '/clients', module: 'clients' },
    { icon: '💱', label: 'الحوالات', route: '/transfers', module: 'transfers' },
    { icon: '📄', label: 'سندات القبض', route: '/receipts', module: 'receipts' },
    { icon: '⚠️', label: 'المخالفات', route: '/violations', module: 'violations' },
    { icon: '🧾', label: 'الفواتير', route: '/invoices', module: 'invoices' },
    { icon: '💰', label: 'المصاريف', route: '/expenses', module: 'expenses' },
    { icon: '🔧', label: 'الخدمات', route: '/services', module: 'services' },
    { icon: '📋', label: 'أنواع المخالفات', route: '/violation-types', module: 'violation_types' },
    { icon: '📊', label: 'الملخص اليومي', route: '/reports/daily-summary', module: 'reports' },
    { icon: '⏰', label: 'سجل حضوري', route: '/hr/my-attendance', module: 'attendance' },
    { icon: '🏖️', label: 'طلباتي', route: '/hr/my-requests', module: 'leaves' },
    { icon: '📃', label: 'قسيمة الراتب', route: '/hr/my-requests', module: 'attendance' },
];

const availableCards = computed(() => allCards.filter(c => canAny(c.module)));
</script>
