<script setup>
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    locations: Array
});

const isModalOpen = ref(false);
const isEditing = ref(false);

const form = ref({
    id: null,
    name: '',
    type: 'geo',
    ip_range: '',
    latitude: '',
    longitude: '',
    radius_meters: 200,
    is_active: true
});

const openModal = (location = null) => {
    if (location) {
        isEditing.value = true;
        form.value = { ...location, is_active: !!location.is_active };
    } else {
        isEditing.value = false;
        form.value = {
            id: null,
            name: '',
            type: 'geo',
            ip_range: '',
            latitude: '',
            longitude: '',
            radius_meters: 200,
            is_active: true
        };
    }
    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
};

const getCurrentLocation = () => {
    if (!navigator.geolocation) {
        alert('المتصفح لا يدعم تحديد الموقع');
        return;
    }
    
    alert('جاري تحديد الموقع...');
    navigator.geolocation.getCurrentPosition(
        (position) => {
            form.value.latitude = position.coords.latitude;
            form.value.longitude = position.coords.longitude;
            alert('تم تحديد الموقع بنجاح');
        },
        (error) => {
            alert('تعذر تحديد الموقع. يرجى التأكد من صلاحيات الموقع.');
        }
    );
};

const submit = () => {
    if (isEditing.value) {
        router.put(route('attendance-locations.update', form.value.id), form.value, {
            onSuccess: () => closeModal()
        });
    } else {
        router.post(route('attendance-locations.store'), form.value, {
            onSuccess: () => closeModal()
        });
    }
};

const deleteLocation = (id) => {
    if (confirm('هل أنت متأكد من حذف موقع الحضور هذا؟')) {
        router.delete(route('attendance-locations.destroy', id));
    }
};
</script>

<template>
    <AppLayout title="إعدادات مواقع الحضور">
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">مواقع الحضور</h1>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">إدارة النطاقات الجغرافية وعناوين IP المسموح بتسجيل الحضور منها</p>
                </div>
                <button @click="openModal()" class="btn-primary flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                    <span>إضافة موقع جديد</span>
                </button>
            </div>

            <!-- Table -->
            <div class="bg-white dark:bg-gray-800 shadow-sm rounded-xl overflow-hidden border border-gray-100 dark:border-gray-700">
                <table class="w-full text-sm text-right">
                    <thead class="bg-gray-50 dark:bg-gray-700/50 text-gray-600 dark:text-gray-300">
                        <tr>
                            <th class="px-6 py-4">اسم الموقع</th>
                            <th class="px-6 py-4">النوع</th>
                            <th class="px-6 py-4">التفاصيل</th>
                            <th class="px-6 py-4">الحالة</th>
                            <th class="px-6 py-4 text-center">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <tr v-for="location in locations" :key="location.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                            <td class="px-6 py-4 font-medium text-gray-900 dark:text-white">{{ location.name }}</td>
                            <td class="px-6 py-4">
                                <span v-if="location.type === 'geo'" class="px-2 py-1 bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400 rounded text-xs font-medium">موقع جغرافي (GPS)</span>
                                <span v-else class="px-2 py-1 bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400 rounded text-xs font-medium">عنوان IP</span>
                            </td>
                            <td class="px-6 py-4 text-gray-500 dark:text-gray-400" dir="ltr" style="text-align: right;">
                                <div v-if="location.type === 'geo'">
                                    {{ location.latitude }}, {{ location.longitude }}<br>
                                    <span class="text-xs text-gray-400">النطاق: {{ location.radius_meters }}m</span>
                                </div>
                                <div v-else>
                                    {{ location.ip_range }}
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <span v-if="location.is_active" class="px-2 py-1 bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400 rounded text-xs">نشط</span>
                                <span v-else class="px-2 py-1 bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400 rounded text-xs">معطل</span>
                            </td>
                            <td class="px-6 py-4 flex justify-center gap-3">
                                <button @click="openModal(location)" class="text-brand-600 hover:text-brand-900 dark:text-brand-400 dark:hover:text-brand-300">تعديل</button>
                                <button @click="deleteLocation(location.id)" class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300">حذف</button>
                            </td>
                        </tr>
                        <tr v-if="locations.length === 0">
                            <td colspan="5" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">لا توجد مواقع مضافة</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Modal -->
        <div v-if="isModalOpen" class="fixed inset-0 z-50 overflow-y-auto" role="dialog">
            <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity dark:bg-gray-900/80" @click="closeModal"></div>
                <span class="hidden sm:inline-block sm:align-middle sm:h-screen">&#8203;</span>
                <div class="inline-block align-bottom bg-white dark:bg-gray-800 rounded-xl text-right overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg w-full">
                    <form @submit.prevent="submit">
                        <div class="bg-white dark:bg-gray-800 px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                            <h3 class="text-lg leading-6 font-medium text-gray-900 dark:text-white mb-4">
                                {{ isEditing ? 'تعديل موقع' : 'إضافة موقع جديد' }}
                            </h3>
                            
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">اسم الموقع</label>
                                    <input type="text" v-model="form.name" required class="input-field" placeholder="مثال: الإدارة الرئيسية">
                                    <p v-if="$page.props.errors.name" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $page.props.errors.name }}</p>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">نوع التحقق</label>
                                    <select v-model="form.type" class="input-field">
                                        <option value="geo">موقع جغرافي (GPS)</option>
                                        <option value="ip">عنوان شبكة (IP)</option>
                                    </select>
                                </div>

                                <!-- GPS Fields -->
                                <template v-if="form.type === 'geo'">
                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">خط العرض (Latitude)</label>
                                            <input type="number" step="any" v-model="form.latitude" required class="input-field" dir="ltr">
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">خط الطول (Longitude)</label>
                                            <input type="number" step="any" v-model="form.longitude" required class="input-field" dir="ltr">
                                        </div>
                                    </div>
                                    <div>
                                        <button type="button" @click="getCurrentLocation" class="text-sm text-brand-600 hover:text-brand-800 dark:text-brand-400 dark:hover:text-brand-300 flex items-center gap-1">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                                            تحديد موقعي الحالي
                                        </button>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">نطاق السماح (بالمتر)</label>
                                        <input type="number" v-model="form.radius_meters" min="10" required class="input-field">
                                        <p class="text-xs text-gray-500 mt-1">المسافة المسموح بها حول هذه النقطة لتسجيل الحضور</p>
                                    </div>
                                </template>

                                <!-- IP Fields -->
                                <template v-if="form.type === 'ip'">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">عنوان IP أو النطاق</label>
                                        <input type="text" v-model="form.ip_range" required class="input-field" dir="ltr" placeholder="مثال: 192.168.1.5">
                                    </div>
                                </template>

                                <div>
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="checkbox" v-model="form.is_active" class="rounded border-gray-300 text-brand-600 shadow-sm focus:border-brand-300 focus:ring focus:ring-brand-200 focus:ring-opacity-50">
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">الموقع نشط</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="bg-gray-50 dark:bg-gray-700/50 px-4 py-3 sm:px-6 flex gap-3 justify-end">
                            <button type="button" @click="closeModal" class="btn-secondary">إلغاء</button>
                            <button type="submit" class="btn-primary">حفظ</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
