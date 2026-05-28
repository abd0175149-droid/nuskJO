<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import axios from 'axios';

const emit = defineEmits(['status-updated']);

const status = ref('loading'); // loading, not_checked_in, checked_in, checked_out, none
const checkInTime = ref(null);
const checkOutTime = ref(null);
const workedHours = ref(0);
const currentTime = ref('');
const currentDate = ref('');
let timeInterval = null;

const isProcessing = ref(false);
const errorMessage = ref('');
const successMessage = ref('');

const updateCurrentTime = () => {
    const now = new Date();
    currentTime.value = now.toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    currentDate.value = now.toLocaleDateString('ar-EG', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
};

const fetchStatus = async () => {
    try {
        const response = await axios.get('/api/attendance/status');
        const data = response.data;
        status.value = data.status;
        
        if (data.check_in_time) checkInTime.value = new Date(data.check_in_time);
        if (data.check_out_time) checkOutTime.value = new Date(data.check_out_time);
        if (data.worked_hours) workedHours.value = data.worked_hours;
        
        emit('status-updated', data);
    } catch (error) {
        console.error('Failed to fetch attendance status', error);
        errorMessage.value = 'تعذر جلب حالة الحضور الحالية.';
    }
};

onMounted(() => {
    updateCurrentTime();
    timeInterval = setInterval(updateCurrentTime, 1000);
    fetchStatus();
});

onUnmounted(() => {
    if (timeInterval) clearInterval(timeInterval);
});

const handleCheckIn = () => {
    processAttendance('check-in', 'تسجيل حضور');
};

const handleCheckOut = () => {
    if (confirm('هل أنت متأكد من تسجيل الانصراف الآن؟ لا يمكنك تسجيل الدخول مرة أخرى اليوم.')) {
        processAttendance('check-out', 'تسجيل انصراف');
    }
};

const processAttendance = (action, actionName) => {
    isProcessing.value = true;
    errorMessage.value = '';
    successMessage.value = '';

    if (!navigator.geolocation) {
        errorMessage.value = 'خدمة تحديد الموقع غير مدعومة في متصفحك.';
        isProcessing.value = false;
        return;
    }

    navigator.geolocation.getCurrentPosition(
        async (position) => {
            try {
                const response = await axios.post(`/attendance/${action}`, {
                    lat: position.coords.latitude,
                    lng: position.coords.longitude,
                    method: 'geo'
                });
                
                successMessage.value = response.data.message;
                await fetchStatus();
            } catch (error) {
                errorMessage.value = error.response?.data?.message || `حدث خطأ أثناء ${actionName}.`;
            } finally {
                isProcessing.value = false;
            }
        },
        (error) => {
            console.error(error);
            errorMessage.value = 'يرجى السماح بالوصول إلى الموقع الجغرافي لتسجيل الحضور.';
            isProcessing.value = false;
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
    );
};
</script>

<template>
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-6 flex flex-col items-center justify-center relative overflow-hidden">
        
        <!-- Background Decoration -->
        <div class="absolute -top-10 -right-10 w-32 h-32 bg-brand-500/10 rounded-full blur-2xl"></div>
        <div class="absolute -bottom-10 -left-10 w-32 h-32 bg-blue-500/10 rounded-full blur-2xl"></div>

        <div v-if="status === 'none'" class="text-center z-10">
            <div class="w-16 h-16 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-3">
                <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
            </div>
            <p class="text-gray-500 dark:text-gray-400">حسابك غير مرتبط بملف موظف. لا يمكنك تسجيل الحضور.</p>
        </div>

        <div v-else class="text-center z-10 w-full">
            <h2 class="text-lg font-medium text-gray-500 dark:text-gray-400 mb-1">{{ currentDate }}</h2>
            <div class="text-4xl font-bold text-gray-900 dark:text-white mb-8 tracking-wider font-mono">
                {{ currentTime }}
            </div>

            <!-- Messages -->
            <div v-if="errorMessage" class="mb-4 p-3 bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-400 rounded-lg text-sm">
                {{ errorMessage }}
            </div>
            <div v-if="successMessage" class="mb-4 p-3 bg-green-50 dark:bg-green-900/30 text-green-700 dark:text-green-400 rounded-lg text-sm">
                {{ successMessage }}
            </div>

            <!-- Action Buttons -->
            <div v-if="status === 'loading'" class="flex justify-center items-center h-32">
                <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-brand-500"></div>
            </div>

            <div v-else-if="status === 'not_checked_in'" class="flex justify-center">
                <button 
                    @click="handleCheckIn" 
                    :disabled="isProcessing"
                    class="relative group w-32 h-32 rounded-full flex flex-col items-center justify-center bg-gradient-to-br from-brand-400 to-brand-600 text-white shadow-lg shadow-brand-500/30 hover:shadow-brand-500/50 hover:scale-105 transition-all duration-300 disabled:opacity-70 disabled:scale-100"
                >
                    <svg class="w-10 h-10 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path></svg>
                    <span class="font-bold text-lg">حضور</span>
                    
                    <!-- Ripple effect -->
                    <span v-if="!isProcessing" class="absolute inset-0 rounded-full animate-ping opacity-20 bg-white group-hover:opacity-40"></span>
                </button>
            </div>

            <div v-else-if="status === 'checked_in'" class="flex flex-col items-center">
                <div class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                    تم تسجيل الحضور في: <span class="font-bold text-gray-900 dark:text-white">{{ checkInTime.toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' }) }}</span>
                </div>
                
                <button 
                    @click="handleCheckOut" 
                    :disabled="isProcessing"
                    class="w-32 h-32 rounded-full flex flex-col items-center justify-center bg-gradient-to-br from-orange-400 to-red-500 text-white shadow-lg shadow-red-500/30 hover:shadow-red-500/50 hover:scale-105 transition-all duration-300 disabled:opacity-70 disabled:scale-100"
                >
                    <svg class="w-10 h-10 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                    <span class="font-bold text-lg">انصراف</span>
                </button>
            </div>

            <div v-else-if="status === 'checked_out'" class="flex flex-col items-center p-4 bg-gray-50 dark:bg-gray-700/30 rounded-xl w-full">
                <div class="w-12 h-12 bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400 rounded-full flex items-center justify-center mb-3">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                </div>
                <h3 class="font-bold text-gray-900 dark:text-white mb-2">اكتمل دوام اليوم</h3>
                <div class="grid grid-cols-2 gap-4 w-full text-sm">
                    <div class="text-right">
                        <span class="text-gray-500 dark:text-gray-400 block">وقت الحضور:</span>
                        <span class="font-bold dark:text-white">{{ checkInTime.toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' }) }}</span>
                    </div>
                    <div class="text-right border-r border-gray-200 dark:border-gray-600 pr-4">
                        <span class="text-gray-500 dark:text-gray-400 block">وقت الانصراف:</span>
                        <span class="font-bold dark:text-white">{{ checkOutTime.toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' }) }}</span>
                    </div>
                </div>
                <div class="mt-3 pt-3 border-t border-gray-200 dark:border-gray-600 w-full text-center">
                    <span class="text-gray-500 dark:text-gray-400">إجمالي ساعات العمل:</span>
                    <span class="font-bold text-brand-600 dark:text-brand-400 mr-2">{{ workedHours }} س</span>
                </div>
            </div>
        </div>
    </div>
</template>
