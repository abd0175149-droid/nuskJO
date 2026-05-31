<template>
    <div class="min-h-screen flex relative">

        <!-- Mobile Overlay -->
        <Transition name="fade">
            <div v-if="sidebarOpen && isMobile"
                 class="fixed inset-0 z-40 bg-black/50 backdrop-blur-sm"
                 @click="sidebarOpen = false"/>
        </Transition>

        <!-- Sidebar -->
        <aside
            class="w-64 flex flex-col flex-shrink-0 transition-transform duration-300 ease-in-out z-50"
            :class="[
                isDark ? 'bg-gray-950 border-l border-gold-900/30' : 'bg-white border-l border-gray-200',
                isMobile ? 'fixed inset-y-0 right-0' : '',
                sidebarVisible ? 'translate-x-0' : (isMobile ? 'translate-x-full' : '-translate-x-full hidden')
            ]"
        >
            <!-- Logo -->
            <div class="flex items-center justify-between border-b py-4 px-4"
                 :class="isDark ? 'border-gold-900/30' : 'border-gray-200'">
                <div class="w-40 overflow-hidden mx-auto">
                    <img src="/images/logo-company.png?v=3" alt="شركة نسك للسياحة والسفر" class="w-full object-contain"/>
                </div>
                <button v-if="isMobile" @click="sidebarOpen = false"
                        class="p-1.5 rounded-lg text-gray-400 hover:text-red-500 hover:bg-gray-100 transition-colors">
                    ✕
                </button>
            </div>

            <!-- Navigation -->
            <nav ref="sidebarNav" class="flex-1 py-3 overflow-y-auto">
                <!-- Dashboard (standalone) -->
                <Link href="/"
                   @click="isMobile && (sidebarOpen = false)"
                   class="flex items-center gap-3 px-4 py-2.5 mx-2 rounded-lg text-sm transition-all duration-200"
                   :class="isActive('/')
                       ? (isDark ? 'bg-gold-600/20 text-gold-400 font-bold' : 'bg-gold-50 text-gold-800 font-bold border-r-4 border-gold-500')
                       : (isDark ? 'text-gray-400 hover:bg-gray-800 hover:text-gold-300' : 'text-gray-600 hover:bg-gray-50 hover:text-gold-700')">
                    <span class="text-lg">📊</span><span>لوحة القيادة</span>
                </Link>

                <!-- Menu Groups -->
                <template v-for="group in menuGroups" :key="group.label">
                    <button @click="toggleGroup(group.label)"
                        class="flex items-center justify-between w-full px-4 py-2 mt-3 mx-0 text-xs font-bold uppercase tracking-wide transition-colors"
                        :class="isDark ? 'text-gray-500 hover:text-gray-300' : 'text-gray-400 hover:text-gray-600'">
                        <span>{{ group.label }}</span>
                        <span class="text-[10px] transition-transform duration-200" :class="openGroups[group.label]?'rotate-180':''">▼</span>
                    </button>
                    <div v-show="openGroups[group.label]" class="space-y-0.5">
                        <template v-for="item in group.items" :key="item.route">
                            <Link v-if="!item.permission || can(item.permission)"
                               :href="item.route"
                               @click="isMobile && (sidebarOpen = false)"
                               class="flex items-center gap-3 px-4 py-2 mx-2 rounded-lg text-sm transition-all duration-200"
                               :class="isActive(item.route)
                                   ? (isDark ? 'bg-gold-600/20 text-gold-400 font-bold' : 'bg-gold-50 text-gold-800 font-bold border-r-4 border-gold-500')
                                   : (isDark ? 'text-gray-400 hover:bg-gray-800 hover:text-gold-300' : 'text-gray-600 hover:bg-gray-50 hover:text-gold-700')">
                                <span class="text-base">{{ item.icon }}</span>
                                <span>{{ item.label }}</span>
                            </Link>
                        </template>
                    </div>
                </template>
            </nav>

            <!-- User Info -->
            <div class="p-4 border-t" :class="isDark ? 'border-gold-900/30' : 'border-gray-200'">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-full flex items-center justify-center text-sm font-bold flex-shrink-0"
                         :class="isDark ? 'bg-gold-600 text-black' : 'bg-gold-100 text-gold-800'">
                        {{ user?.name?.charAt(0) }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium truncate" :class="isDark ? 'text-gray-200' : 'text-gray-800'">
                            {{ user?.name }}
                        </p>
                        <p class="text-xs truncate" :class="isDark ? 'text-gold-500' : 'text-gold-600'">
                            {{ user?.role?.name || 'مدير' }}
                        </p>
                    </div>
                </div>
            </div>
        </aside>

        <!-- Main Content -->
        <div class="flex-1 flex flex-col min-h-screen min-w-0">
            <!-- Top Navbar -->
            <header class="h-14 sm:h-16 flex items-center justify-between px-4 sm:px-6 shadow-sm border-b sticky top-0 z-30"
                    :class="isDark ? 'bg-gray-900 border-gold-900/20' : 'bg-white border-gray-200'">
                <div class="flex items-center gap-3">
                    <button @click="sidebarOpen = !sidebarOpen"
                            class="p-2 rounded-lg transition-colors"
                            :class="isDark ? 'text-gold-400 hover:bg-gray-800' : 'text-gold-600 hover:bg-gray-100'">
                        <span class="text-xl">☰</span>
                    </button>
                    <h2 class="text-base sm:text-lg font-bold truncate"
                        :class="isDark ? 'text-gold-400' : 'text-gray-800'">
                        <slot name="header">{{ $page.props.title || 'شركة نسك للسياحة والسفر' }}</slot>
                    </h2>
                </div>

                <div class="flex items-center gap-1 sm:gap-3">
                    <!-- Notification Bell -->
                    <div class="relative">
                        <button @click="toggleNotifications" class="relative p-2 rounded-lg transition-colors"
                                :class="isDark ? 'text-gray-400 hover:text-gold-400 hover:bg-gray-800' : 'text-gray-500 hover:text-gold-600 hover:bg-gray-100'">
                            <span class="text-lg sm:text-xl">🔔</span>
                            <span v-if="unreadCount > 0"
                                  class="absolute -top-0.5 -right-0.5 w-5 h-5 bg-red-500 text-white text-xs rounded-full flex items-center justify-center font-bold animate-pulse">
                                {{ unreadCount > 9 ? '9+' : unreadCount }}
                            </span>
                        </button>
                        <!-- Notifications Dropdown -->
                        <div v-if="showNotifications"
                             class="absolute top-full end-0 mt-2 w-80 max-h-96 overflow-y-auto rounded-xl shadow-2xl border z-50"
                             :class="isDark ? 'bg-gray-800 border-gray-700' : 'bg-white border-gray-200'">
                            <div class="flex items-center justify-between p-3 border-b" :class="isDark ? 'border-gray-700' : 'border-gray-100'">
                                <span class="font-bold text-sm">الإشعارات</span>
                                <button v-if="notifications.length" @click="markAllRead" class="text-xs text-blue-500 hover:underline">تعليم الكل كمقروء</button>
                            </div>
                            <div v-if="notificationsLoading" class="p-6 text-center text-gray-400 text-sm">جاري التحميل...</div>
                            <div v-else-if="!notifications.length" class="p-6 text-center text-gray-400 text-sm">لا يوجد إشعارات</div>
                            <div v-else>
                                <div v-for="n in notifications" :key="n.id"
                                     @click="openNotification(n)"
                                     class="p-3 cursor-pointer border-b transition-colors text-sm"
                                     :class="[
                                         isDark ? 'border-gray-700 hover:bg-gray-700' : 'border-gray-50 hover:bg-gray-50',
                                         !n.is_read ? (isDark ? 'bg-blue-900/20' : 'bg-blue-50/50') : ''
                                     ]">
                                    <div class="flex items-start gap-2">
                                        <span class="mt-0.5">{{ n.icon || 'ℹ️' }}</span>
                                        <div class="flex-1 min-w-0">
                                            <p class="font-bold text-xs" :class="!n.is_read ? 'text-blue-600' : ''">{{ n.title }}</p>
                                            <p class="text-xs mt-0.5 truncate" :class="isDark ? 'text-gray-400' : 'text-gray-500'">{{ n.body }}</p>
                                            <p class="text-[10px] mt-1" :class="isDark ? 'text-gray-500' : 'text-gray-400'">{{ n.time_ago }}</p>
                                        </div>
                                        <span v-if="!n.is_read" class="w-2 h-2 bg-blue-500 rounded-full mt-1 flex-shrink-0"></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Theme Toggle -->
                    <button @click="toggleTheme"
                            class="p-2 rounded-lg transition-colors"
                            :class="isDark ? 'text-gold-400 hover:bg-gray-800' : 'text-gray-500 hover:bg-gray-100'">
                        {{ isDark ? '☀️' : '🌙' }}
                    </button>

                    <!-- Settings Dropdown -->
                    <div class="relative">
                        <button @click="showSettings = !showSettings"
                                class="p-2 rounded-lg transition-colors"
                                :class="isDark ? 'text-gray-400 hover:text-gold-400 hover:bg-gray-800' : 'text-gray-500 hover:text-gold-600 hover:bg-gray-100'">
                            <span class="text-lg">⚙️</span>
                        </button>
                        <div v-if="showSettings"
                             class="absolute top-full end-0 mt-2 w-52 rounded-xl shadow-2xl border z-50 overflow-hidden"
                             :class="isDark ? 'bg-gray-800 border-gray-700' : 'bg-white border-gray-200'">
                            <div class="px-4 py-3 border-b" :class="isDark ? 'border-gray-700' : 'border-gray-100'">
                                <p class="text-sm font-bold" :class="isDark ? 'text-gold-400' : 'text-gold-700'">{{ user?.name }}</p>
                                <p class="text-[11px] mt-0.5" :class="isDark ? 'text-gray-500' : 'text-gray-400'">{{ user?.email || 'مدير' }}</p>
                            </div>
                            <div class="py-1">
                                <button @click="showSettings = false; router.visit('/profile')"
                                        class="w-full text-right flex items-center gap-3 px-4 py-2.5 text-sm transition-colors"
                                        :class="isDark ? 'text-gray-300 hover:bg-gray-700' : 'text-gray-700 hover:bg-gray-50'">
                                    <span>👤</span><span>إعدادات الحساب</span>
                                </button>
                            </div>
                            <div class="border-t py-1" :class="isDark ? 'border-gray-700' : 'border-gray-100'">
                                <Link href="/logout" method="post" as="button"
                                   class="w-full text-right flex items-center gap-3 px-4 py-2.5 text-sm transition-colors"
                                   :class="isDark ? 'text-red-400 hover:bg-red-900/20' : 'text-red-500 hover:bg-red-50'">
                                    <span>🚪</span><span>تسجيل الخروج</span>
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Page Content -->
            <main class="flex-1 p-3 sm:p-6 overflow-x-hidden" :class="isDark ? 'bg-gray-900' : 'bg-gray-50'">
                <slot />
            </main>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue';
import { usePage, Link, router } from '@inertiajs/vue3';
import { requestFirebaseToken } from '../../firebase';
import axios from 'axios';

const page = usePage();
const user = computed(() => page.props.auth?.user);
const isDark = ref(document.documentElement.classList.contains('dark'));
const isMobile = ref(window.innerWidth < 1024);
const sidebarOpen = ref(!isMobile.value);
const unreadCount = computed(() => page.props.unreadNotifications || 0);

// Notification state
const showNotifications = ref(false);
const notifications = ref([]);
const notificationsLoading = ref(false);
const showSettings = ref(false);
let echoChannel = null;

const toggleNotifications = async () => {
    showSettings.value = false;
    showNotifications.value = !showNotifications.value;
    if (showNotifications.value) {
        notificationsLoading.value = true;
        try {
            const { data } = await axios.get('/api/notifications', {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            });
            notifications.value = data.notifications || [];
        } catch (e) {
            console.warn('Failed to load notifications', e);
            notifications.value = [];
        }
        notificationsLoading.value = false;
    }
};

const openNotification = async (n) => {
    if (!n.is_read) {
        await axios.post(`/api/notifications/${n.id}/read`);
        n.is_read = true;
    }
    showNotifications.value = false;
    if (n.action_url) router.visit(n.action_url);
};

const markAllRead = async () => {
    try {
        await axios.post('/api/notifications/read-all', {}, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        });
    } catch(e) {}
    notifications.value.forEach(n => n.is_read = true);
    showNotifications.value = false;
    router.reload({ only: ['unreadNotifications'] });
};

// Close dropdown on outside click
const handleClickOutside = (e) => {
    if (showNotifications.value && !e.target.closest('.relative')) {
        showNotifications.value = false;
    }
    if (showSettings.value && !e.target.closest('.relative')) {
        showSettings.value = false;
    }
};

// حالة السايدبار المرئية
const sidebarVisible = computed(() => {
    if (isMobile.value) return sidebarOpen.value;
    return sidebarOpen.value;
});

// استجابة تغيير حجم الشاشة
const handleResize = () => {
    const wasMobile = isMobile.value;
    isMobile.value = window.innerWidth < 1024;
    // عند الانتقال من موبايل لديسكتوب: افتح السايدبار
    if (wasMobile && !isMobile.value) sidebarOpen.value = true;
    // عند الانتقال من ديسكتوب لموبايل: أغلق السايدبار
    if (!wasMobile && isMobile.value) sidebarOpen.value = false;
};
const sidebarNav = ref(null);

// Save sidebar scroll before Inertia navigates away
const removeBeforeListener = router.on('before', () => {
    if (sidebarNav.value) {
        sessionStorage.setItem('nusuk-sidebar-scroll', String(sidebarNav.value.scrollTop));
    }
});

onMounted(() => {
    window.addEventListener('resize', handleResize);
    document.addEventListener('click', handleClickOutside);
    
    // Request FCM Token for notifications
    if (user.value) {
        try { requestFirebaseToken(); } catch(e) {}
    }
    
    // WebSocket: إشعارات لحظية عبر Reverb
    if (window.Echo && user.value?.id) {
        echoChannel = window.Echo.private(`user.${user.value.id}`)
            .listen('.notification.new', (data) => {
                page.props.unreadNotifications = (page.props.unreadNotifications || 0) + 1;
                if (showNotifications.value) {
                    notifications.value.unshift(data.notification);
                }
            });
    }
    
    // Restore scroll position after DOM is ready
    nextTick(() => {
        const saved = sessionStorage.getItem('nusuk-sidebar-scroll');
        if (sidebarNav.value && saved) {
            sidebarNav.value.scrollTop = parseInt(saved, 10);
        }
    });
});

onUnmounted(() => {
    window.removeEventListener('resize', handleResize);
    document.removeEventListener('click', handleClickOutside);
    removeBeforeListener();
    if (echoChannel && window.Echo && user.value?.id) {
        window.Echo.leave(`user.${user.value.id}`);
    }
});

const can = (permission) => {
    const perms = page.props.auth?.permissions || [];
    return page.props.auth?.isAdmin || perms.includes(permission);
};

const currentPath = computed(() => page.url?.split('?')[0] || '/');

const isActive = (route) => {
    if (route === '/') return currentPath.value === '/';
    return currentPath.value.startsWith(route);
};

const toggleTheme = () => {
    isDark.value = !isDark.value;
    document.documentElement.classList.toggle('dark');
    localStorage.setItem('nusuk-theme', isDark.value ? 'dark' : 'light');
};

const menuGroups = [
    { label: '💼 العمليات', items: [
        { icon: '🧾', label: 'المطالبات', route: '/invoices', permission: 'invoices.view' },
        { icon: '📄', label: 'سندات القبض', route: '/receipts', permission: 'receipts.view' },
        { icon: '💱', label: 'الحوالات', route: '/transfers', permission: 'transfers.view' },
        { icon: '💰', label: 'المصاريف', route: '/expenses', permission: 'expenses.view' },
        { icon: '⚠️', label: 'المخالفات', route: '/violations', permission: 'violations.view' },
    ]},
    { label: '📂 عمليات أخرى', items: [
        { icon: '🏢', label: 'الوكلاء', route: '/agents', permission: 'agents.view' },
        { icon: '👥', label: 'العملاء', route: '/clients', permission: 'clients.view' },
        { icon: '📊', label: 'قائمة الدخل', route: '/accounting/profit-loss', permission: 'reports.view' },
    ]},
    { label: '👨‍💼 الموارد البشرية', items: [
        { icon: '👤', label: 'الموظفين', route: '/employees', permission: 'employees.view' },
        { icon: '⏰', label: 'الحضور والانصراف', route: '/attendance', permission: 'attendance.view' },
        { icon: '🏖️', label: 'الإجازات', route: '/leaves', permission: 'leaves.view' },
        { icon: '💵', label: 'السلف', route: '/advances', permission: 'advances.view' },
        { icon: '⚠️', label: 'الجزاءات', route: '/penalties', permission: 'penalties.view' },
        { icon: '💰', label: 'الرواتب', route: '/payrolls', permission: 'payroll.view' },
        { icon: '📊', label: 'تقارير HR', route: '/hr/reports', permission: 'hr_reports.view' },
    ]},
    { label: '🏛️ المحاسبة', items: [
        { icon: '🌳', label: 'شجرة الحسابات', route: '/accounting/chart-of-accounts', permission: 'reports.view' },
        { icon: '⚖️', label: 'ميزان المراجعة', route: '/accounting/trial-balance', permission: 'reports.view' },
        { icon: '📝', label: 'سجل القيود', route: '/accounting/journal-entries', permission: 'reports.view' },
        { icon: '📊', label: 'قائمة الدخل', route: '/accounting/profit-loss', permission: 'reports.view' },
        { icon: '🏦', label: 'الميزانية العمومية', route: '/accounting/balance-sheet', permission: 'reports.view' },
        { icon: '📅', label: 'الفترات والإقفال', route: '/accounting/periods', permission: 'reports.view' },
    ]},
    { label: '⚙️ الإعدادات', items: [
        { icon: '👥', label: 'المستخدمين', route: '/users', permission: 'settings.view' },
        { icon: '🛡️', label: 'الصلاحيات', route: '/roles', permission: 'settings.view' },
        { icon: '🔧', label: 'الخدمات', route: '/services', permission: 'settings.view' },
        { icon: '📋', label: 'أنواع المخالفات', route: '/violation-types', permission: 'settings.view' },
        { icon: '🏷️', label: 'تصنيفات المصاريف', route: '/expense-categories', permission: 'settings.view' },
        { icon: '🗓️', label: 'أنواع الإجازات', route: '/leave-types', permission: 'settings.view' },
        { icon: '📍', label: 'مواقع الحضور', route: '/attendance-locations', permission: 'settings.view' },
        { icon: '⚙️', label: 'إعدادات النظام', route: '/settings', permission: 'settings.view' },
    ]},
];

// تحديد القائمة المفتوحة بناءً على الصفحة النشطة
const getActiveGroup = () => {
    const path = page.url?.split('?')[0] || '/';
    for (const group of menuGroups) {
        for (const item of group.items) {
            if (path === item.route || path.startsWith(item.route + '/')) {
                return group.label;
            }
        }
    }
    return null;
};

const activeGroupLabel = getActiveGroup();
const openGroups = ref(
    menuGroups.reduce((acc, group) => {
        acc[group.label] = group.label === activeGroupLabel;
        return acc;
    }, {})
);
const toggleGroup = (label) => { 
    openGroups.value[label] = !openGroups.value[label]; 
};
</script>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.3s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
