<template>
    <EmployeeLayout>
        <div class="max-w-5xl mx-auto">
            <!-- Welcome -->
            <div class="mb-10 text-center">
                <h1 class="text-2xl sm:text-3xl font-bold mb-2" :class="isDark ? 'text-gold-400' : 'text-gray-800'">
                    مرحباً، {{ user?.name }} 👋
                </h1>
                <p class="text-sm" :class="isDark ? 'text-gray-400' : 'text-gray-500'">
                    {{ user?.role?.name || 'موظف' }} — اختر الإجراء المطلوب
                </p>
            </div>

            <!-- Attendance Check-in Widget -->
            <div class="mb-10 max-w-lg mx-auto">
                <CheckInWidget />
            </div>

            <!-- Sections Container -->
            <div class="space-y-12">
                <div v-for="section in availableSections" :key="section.title">
                    
                    <!-- Section Title -->
                    <div class="flex items-center gap-3 mb-5">
                        <h2 class="text-xl font-bold" :class="isDark ? 'text-gray-200' : 'text-gray-800'">
                            {{ section.title }}
                        </h2>
                        <div class="flex-1 h-px" :class="isDark ? 'bg-gray-800' : 'bg-gray-200'"></div>
                    </div>

                    <!-- Shortcut Cards Grid -->
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4 sm:gap-6">
                        <template v-for="card in section.cards" :key="card.route">
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
                                <span class="font-bold text-sm sm:text-base text-center transition-colors"
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
                </div>
            </div>

            <!-- Empty state -->
            <div v-if="!hasAnyCards" class="text-center py-20">
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

const sections = [
    {
        title: '👤 خدماتي (الخدمات الذاتية)',
        cards: [
            { icon: '⏰', label: 'سجل حضوري', route: '/hr/my-attendance', module: 'self' },
            { icon: '🏖️', label: 'طلباتي', route: '/hr/my-requests', module: 'self' },
            { icon: '📃', label: 'قسيمة الراتب', route: '/hr/my-payslip', module: 'self' },
        ]
    },
    {
        title: '💼 قسم العمليات',
        cards: [
            { icon: '🏢', label: 'الوكلاء', route: '/agents', module: 'agents' },
            { icon: '👥', label: 'العملاء', route: '/clients', module: 'clients' },
            { icon: '📤', label: 'سندات الصرف', route: '/disbursements', module: 'disbursements' },
            { icon: '📄', label: 'سندات القبض', route: '/receipts', module: 'receipts' },
            { icon: '🧾', label: 'الفواتير', route: '/invoices', module: 'invoices' },
            { icon: '🔧', label: 'الخدمات', route: '/services', module: 'services' },
        ]
    },
    {
        title: '👨‍💼 إدارة الموارد البشرية',
        cards: [
            { icon: '👨‍💻', label: 'الموظفين', route: '/employees', module: 'employees' },
            { icon: '📅', label: 'الحضور والانصراف', route: '/attendance', module: 'attendance' },
            { icon: '✈️', label: 'إدارة الإجازات', route: '/leaves', module: 'leaves' },
            { icon: '💵', label: 'السلف', route: '/advances', module: 'advances' },
            { icon: '💰', label: 'مسيرات الرواتب', route: '/payrolls', module: 'payroll' },
        ]
    },
    {
        title: '📊 النظام والتقارير',
        cards: [
            { icon: '📈', label: 'الملخص اليومي', route: '/reports/daily-summary', module: 'reports' },
            { icon: '📑', label: 'تقارير HR', route: '/hr/reports', module: 'hr_reports' },
            { icon: '👥', label: 'المستخدمين', route: '/users', module: 'users' },
            { icon: '⚙️', label: 'الإعدادات', route: '/settings', module: 'settings' },
        ]
    }
];

const availableSections = computed(() => {
    return sections.map(section => {
        const filteredCards = section.cards.filter(c => c.module === 'self' || canAny(c.module));
        return {
            ...section,
            cards: filteredCards
        };
    }).filter(section => section.cards.length > 0);
});

const hasAnyCards = computed(() => availableSections.value.length > 0);
</script>
