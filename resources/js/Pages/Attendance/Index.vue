<script setup>
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';

// Custom debounce function
const debounce = (fn, delay) => {
    let timeoutId;
    return (...args) => {
        clearTimeout(timeoutId);
        timeoutId = setTimeout(() => fn(...args), delay);
    };
};

const props = defineProps({
    attendances: Object,
    employees: Array,
    filters: Object,
});

const searchForm = ref({
    employee_id: props.filters.employee_id || '',
    date: props.filters.date || '',
    status: props.filters.status || '',
});

const isEditModalOpen = ref(false);
const editingAttendance = ref(null);
const editForm = ref({
    check_in: '',
    check_out: '',
    status: '',
    notes: '',
});

const statusColors = {
    present: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
    absent: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
    late: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
    half_day: 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400',
    holiday: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
};

const statusLabels = {
    present: 'حاضر',
    absent: 'غائب',
    late: 'متأخر',
    half_day: 'نصف يوم',
    holiday: 'إجازة/عطلة',
};

watch(searchForm, debounce(function (value) {
    router.get(route('attendance.index'), value, { preserveState: true, replace: true });
}, 300), { deep: true });

const openEditModal = (attendance) => {
    editingAttendance.value = attendance;
    
    // Format check_in / check_out to H:i for input type="time"
    const formatTime = (datetimeStr) => {
        if (!datetimeStr) return '';
        const d = new Date(datetimeStr);
        return `${d.getHours().toString().padStart(2, '0')}:${d.getMinutes().toString().padStart(2, '0')}`;
    };

    editForm.value = {
        check_in: formatTime(attendance.check_in),
        check_out: formatTime(attendance.check_out),
        status: attendance.status,
        notes: attendance.notes || '',
    };
    isEditModalOpen.value = true;
};

const submitEdit = () => {
    router.post(route('attendance.manual-edit', editingAttendance.value.id), editForm.value, {
        preserveScroll: true,
        onSuccess: () => {
            isEditModalOpen.value = false;
        }
    });
};
</script>

<template>
    <AppLayout title="سجل الحضور والانصراف">
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">سجل الحضور والانصراف</h1>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">إدارة ومتابعة حركات دوام الموظفين</p>
                </div>
            </div>

            <!-- Filters -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <!-- Employee Filter -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">الموظف</label>
                        <select v-model="searchForm.employee_id" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-brand-500 focus:ring-brand-500">
                            <option value="">الكل</option>
                            <option v-for="emp in employees" :key="emp.id" :value="emp.id">
                                {{ emp.name }} ({{ emp.employee_number }})
                            </option>
                        </select>
                    </div>

                    <!-- Date Filter -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">التاريخ</label>
                        <input type="date" v-model="searchForm.date" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-brand-500 focus:ring-brand-500">
                    </div>

                    <!-- Status Filter -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">الحالة</label>
                        <select v-model="searchForm.status" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-brand-500 focus:ring-brand-500">
                            <option value="">الكل</option>
                            <option v-for="(label, key) in statusLabels" :key="key" :value="key">{{ label }}</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Table -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-right">
                        <thead class="bg-gray-50 dark:bg-gray-700/50 text-gray-600 dark:text-gray-300 font-medium border-b border-gray-200 dark:border-gray-700">
                            <tr>
                                <th class="px-6 py-4">الموظف</th>
                                <th class="px-6 py-4">التاريخ</th>
                                <th class="px-6 py-4">الحضور</th>
                                <th class="px-6 py-4">الانصراف</th>
                                <th class="px-6 py-4">ساعات العمل</th>
                                <th class="px-6 py-4">التأخير/الإضافي</th>
                                <th class="px-6 py-4">الحالة</th>
                                <th class="px-6 py-4 w-24">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            <tr v-for="att in attendances.data" :key="att.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                                <td class="px-6 py-4">
                                    <div class="font-medium text-gray-900 dark:text-white">{{ att.employee?.user?.name }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ att.employee?.employee_number }}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    {{ new Date(att.date).toLocaleDateString('ar-EG', { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' }) }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div v-if="att.check_in" class="flex flex-col">
                                        <span class="font-medium text-gray-900 dark:text-white">{{ new Date(att.check_in).toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' }) }}</span>
                                        <span class="text-[10px] text-gray-400">{{ att.check_in_method }}</span>
                                    </div>
                                    <span v-else class="text-gray-400">-</span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div v-if="att.check_out" class="flex flex-col">
                                        <span class="font-medium text-gray-900 dark:text-white">{{ new Date(att.check_out).toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' }) }}</span>
                                        <span class="text-[10px] text-gray-400">{{ att.check_out_method }}</span>
                                    </div>
                                    <span v-else class="text-gray-400">-</span>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="font-medium text-gray-900 dark:text-white">{{ att.worked_hours }} س</span>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex flex-col gap-1">
                                        <span v-if="att.late_minutes > 0" class="text-xs px-2 py-0.5 rounded bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-400 inline-block w-fit">
                                            تأخير: {{ att.late_minutes }} د
                                        </span>
                                        <span v-if="att.overtime_minutes > 0" class="text-xs px-2 py-0.5 rounded bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-400 inline-block w-fit">
                                            إضافي: {{ att.overtime_minutes }} د
                                        </span>
                                        <span v-if="att.late_minutes === 0 && att.overtime_minutes === 0" class="text-gray-400">-</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span :class="statusColors[att.status]" class="px-2.5 py-1 rounded-full text-xs font-medium border border-current">
                                        {{ statusLabels[att.status] || att.status }}
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    <button 
                                        v-if="$page.props.auth.permissions.includes('attendance.manual_edit')"
                                        @click="openEditModal(att)" 
                                        class="text-brand-600 hover:text-brand-900 dark:text-brand-400 dark:hover:text-brand-300"
                                        title="تعديل يدوي"
                                    >
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="attendances.data.length === 0">
                                <td colspan="8" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">
                                    لا توجد سجلات حضور مطابقة
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Pagination Placeholder -->
            <div v-if="attendances.links && attendances.links.length > 3" class="mt-4 flex justify-center">
                <!-- Pagination component can be added here if not globally provided -->
            </div>
        </div>

        <!-- Edit Modal -->
        <div v-if="isEditModalOpen" class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity dark:bg-gray-900/80" @click="isEditModalOpen = false"></div>
                <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
                <div class="inline-block align-bottom bg-white dark:bg-gray-800 rounded-xl text-right overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg w-full">
                    <form @submit.prevent="submitEdit">
                        <div class="bg-white dark:bg-gray-800 px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                            <div class="sm:flex sm:items-start">
                                <div class="mt-3 text-center sm:mt-0 sm:mr-4 sm:text-right w-full">
                                    <h3 class="text-lg leading-6 font-medium text-gray-900 dark:text-white" id="modal-title">تعديل سجل حضور</h3>
                                    <div class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                        {{ editingAttendance.employee.user.name }} - {{ editingAttendance.date }}
                                    </div>
                                    
                                    <div class="mt-6 space-y-4">
                                        <div class="grid grid-cols-2 gap-4">
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">وقت الحضور</label>
                                                <input type="time" v-model="editForm.check_in" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-brand-500 focus:ring-brand-500">
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">وقت الانصراف</label>
                                                <input type="time" v-model="editForm.check_out" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-brand-500 focus:ring-brand-500">
                                            </div>
                                        </div>

                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">الحالة</label>
                                            <select v-model="editForm.status" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-brand-500 focus:ring-brand-500">
                                                <option v-for="(label, key) in statusLabels" :key="key" :value="key">{{ label }}</option>
                                            </select>
                                        </div>

                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">ملاحظات التعديل</label>
                                            <textarea v-model="editForm.notes" rows="2" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-brand-500 focus:ring-brand-500"></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="bg-gray-50 dark:bg-gray-700/50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                            <button type="submit" class="w-full inline-flex justify-center rounded-lg border border-transparent shadow-sm px-4 py-2 bg-brand-600 text-base font-medium text-white hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-brand-500 sm:ml-3 sm:w-auto sm:text-sm">
                                حفظ التعديلات
                            </button>
                            <button type="button" @click="isEditModalOpen = false" class="mt-3 w-full inline-flex justify-center rounded-lg border border-gray-300 dark:border-gray-600 shadow-sm px-4 py-2 bg-white dark:bg-gray-800 text-base font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-brand-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                                إلغاء
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
