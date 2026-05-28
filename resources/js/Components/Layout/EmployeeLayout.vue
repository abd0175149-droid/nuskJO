<template>
    <div class="min-h-screen flex flex-col" :class="isDark ? 'bg-gray-950 text-gray-100' : 'bg-gray-50 text-gray-900'">

        <!-- Top Navbar -->
        <header class="h-14 sm:h-16 flex items-center justify-between px-4 sm:px-6 shadow-sm border-b sticky top-0 z-30"
                :class="isDark ? 'bg-gray-900 border-gold-900/20' : 'bg-white border-gray-200'">

            <!-- Right: Back button (if not on home) -->
            <div class="flex items-center gap-3 w-1/3">
                <button v-if="!isHome" @click="goHome"
                        class="flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-sm font-bold transition-all duration-200"
                        :class="isDark ? 'text-gold-400 hover:bg-gray-800' : 'text-gold-700 hover:bg-gold-50'">
                    <span class="text-lg">→</span>
                    <span>الرئيسية</span>
                </button>
                <!-- Page title when inside a sub-page -->
                <h2 v-if="!isHome" class="text-sm sm:text-base font-bold truncate"
                    :class="isDark ? 'text-gray-300' : 'text-gray-700'">
                    <slot name="header">{{ $page.props.title }}</slot>
                </h2>
            </div>

            <!-- Center: Logo -->
            <div class="w-1/3 flex justify-center">
                <div class="w-32 sm:w-40 cursor-pointer" @click="goHome">
                    <img src="/images/logo-company.png" alt="شركة صهيب الشاقدلي" class="w-full h-12 object-contain"/>
                </div>
            </div>

            <!-- Left: Actions -->
            <div class="w-1/3 flex items-center justify-end gap-1 sm:gap-2">
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
                    <!-- Settings Dropdown Menu -->
                    <div v-if="showSettings"
                         class="absolute top-full end-0 mt-2 w-52 rounded-xl shadow-2xl border z-50 overflow-hidden"
                         :class="isDark ? 'bg-gray-800 border-gray-700' : 'bg-white border-gray-200'">
                        <!-- User Info -->
                        <div class="px-4 py-3 border-b" :class="isDark ? 'border-gray-700' : 'border-gray-100'">
                            <p class="text-sm font-bold" :class="isDark ? 'text-gold-400' : 'text-gold-700'">{{ user?.name }}</p>
                            <p class="text-[11px] mt-0.5" :class="isDark ? 'text-gray-500' : 'text-gray-400'">{{ user?.email || 'موظف' }}</p>
                        </div>
                        <!-- Menu Items -->
                        <div class="py-1">
                            <button @click="showSettings = false; router.visit('/profile')"
                                    class="w-full text-right flex items-center gap-3 px-4 py-2.5 text-sm transition-colors"
                                    :class="isDark ? 'text-gray-300 hover:bg-gray-700' : 'text-gray-700 hover:bg-gray-50'">
                                <span>👤</span>
                                <span>إعدادات الحساب</span>
                            </button>
                        </div>
                        <!-- Logout -->
                        <div class="border-t py-1" :class="isDark ? 'border-gray-700' : 'border-gray-100'">
                            <Link href="/logout" method="post" as="button"
                               class="w-full text-right flex items-center gap-3 px-4 py-2.5 text-sm transition-colors"
                               :class="isDark ? 'text-red-400 hover:bg-red-900/20' : 'text-red-500 hover:bg-red-50'">
                                <span>🚪</span>
                                <span>تسجيل الخروج</span>
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Page Content -->
        <main class="flex-1 p-4 sm:p-6" :class="isDark ? 'bg-gray-950' : 'bg-gray-50'">
            <slot />
        </main>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { usePage, Link, router } from '@inertiajs/vue3';
import { requestFirebaseToken } from '../../firebase';
import axios from 'axios';

const page = usePage();
const user = computed(() => page.props.auth?.user);
const isDark = ref(document.documentElement.classList.contains('dark'));
const unreadCount = computed(() => page.props.unreadNotifications || 0);
const isHome = computed(() => page.url === '/' || page.url === '');

const goHome = () => router.visit('/');

// Notification state
const showNotifications = ref(false);
const notifications = ref([]);
const notificationsLoading = ref(false);
const showSettings = ref(false);
let echoChannel = null;

const toggleNotifications = async () => {
    showSettings.value = false; // أغلق الإعدادات لو مفتوحة
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

// Close dropdowns on outside click
const handleClickOutside = (e) => {
    if (showNotifications.value && !e.target.closest('.relative')) {
        showNotifications.value = false;
    }
    if (showSettings.value && !e.target.closest('.relative')) {
        showSettings.value = false;
    }
};

// Theme toggle
const toggleTheme = () => {
    isDark.value = !isDark.value;
    document.documentElement.classList.toggle('dark', isDark.value);
    localStorage.setItem('nusuk-theme', isDark.value ? 'dark' : 'light');
};

onMounted(() => {
    document.addEventListener('click', handleClickOutside);
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
});

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
    if (echoChannel && window.Echo && user.value?.id) {
        window.Echo.leave(`user.${user.value.id}`);
    }
});

const can = (permission) => {
    const perms = page.props.auth?.permissions || [];
    return page.props.auth?.isAdmin || perms.includes(permission);
};

defineExpose({ can, isDark });
</script>
