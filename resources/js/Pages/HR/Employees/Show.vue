<template>
    <AppLayout>
        <template #header>ملف الموظف: {{ employee.user?.name }}</template>
        
        <div class="max-w-6xl mx-auto space-y-6">
            <!-- Header Card -->
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 flex items-start gap-6 relative overflow-hidden">
                <div class="absolute top-0 right-0 w-32 h-32 bg-gradient-to-br from-gold-500/20 to-transparent rounded-bl-full"></div>
                
                <div class="w-20 h-20 rounded-full bg-gold-100 text-gold-700 flex flex-shrink-0 items-center justify-center text-3xl font-bold border-4 border-white shadow-sm z-10">
                    {{ employee.user?.name?.charAt(0) }}
                </div>
                
                <div class="flex-1 z-10">
                    <div class="flex flex-wrap justify-between items-start gap-4">
                        <div>
                            <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ employee.user?.name }}</h2>
                            <p class="text-gray-500 mt-1">{{ employee.job_title || 'بدون مسمى وظيفي' }} &bull; {{ employee.department?.name || 'بدون قسم' }}</p>
                        </div>
                        <div class="flex gap-2">
                            <Link :href="`/employees/${employee.id}/edit`" class="px-4 py-2 rounded-xl border border-gray-200 text-sm font-bold text-gray-700 hover:bg-gray-50 bg-white shadow-sm">تعديل الملف</Link>
                            <span class="px-4 py-2 rounded-xl border text-sm font-bold shadow-sm" :class="employee.is_active ? 'bg-green-50 border-green-200 text-green-700' : 'bg-red-50 border-red-200 text-red-700'">
                                {{ employee.is_active ? 'نشط' : 'موقوف' }}
                            </span>
                        </div>
                    </div>
                    
                    <div class="mt-6 flex flex-wrap gap-x-8 gap-y-4 text-sm">
                        <div class="flex items-center gap-2"><span class="text-gray-400">الرقم الوظيفي:</span> <span class="font-mono font-bold">{{ employee.employee_number }}</span></div>
                        <div class="flex items-center gap-2"><span class="text-gray-400">رقم الهوية:</span> <span class="font-bold">{{ employee.national_id || '-' }}</span></div>
                        <div class="flex items-center gap-2"><span class="text-gray-400">تاريخ المباشرة:</span> <span class="font-bold">{{ employee.hire_date }}</span></div>
                        <div class="flex items-center gap-2"><span class="text-gray-400">نوع العقد:</span> <span class="font-bold">{{ employee.contract_type }}</span></div>
                        <div class="flex items-center gap-2"><span class="text-gray-400">الفرع:</span> <span class="font-bold px-2 py-0.5 rounded text-xs" :class="employee.country == 'SA'?'bg-green-100 text-green-800':'bg-blue-100 text-blue-800'">{{ employee.country }}</span></div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Right Column (Financial & Work) -->
                <div class="lg:col-span-1 space-y-6">
                    <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
                        <h3 class="font-bold text-gray-800 dark:text-gray-100 mb-4 border-b pb-2">البيانات المالية</h3>
                        <div class="space-y-3 text-sm">
                            <div class="flex justify-between"><span class="text-gray-500">العملة</span><span class="font-bold">{{ employee.currency }}</span></div>
                            <div class="flex justify-between"><span class="text-gray-500">الراتب الأساسي</span><span class="font-bold">{{ employee.basic_salary }}</span></div>
                            <div class="flex justify-between"><span class="text-gray-500">بدل السكن</span><span class="font-bold">{{ employee.housing_allowance }}</span></div>
                            <div class="flex justify-between"><span class="text-gray-500">بدل المواصلات</span><span class="font-bold">{{ employee.transport_allowance }}</span></div>
                            <div class="flex justify-between"><span class="text-gray-500">بدلات أخرى</span><span class="font-bold">{{ employee.other_allowance }}</span></div>
                            <div class="pt-2 border-t flex justify-between text-base"><span class="text-gray-800 font-bold">إجمالي الراتب</span><span class="font-bold text-gold-600">{{ totalSalary }}</span></div>
                        </div>
                        
                        <div class="mt-4 p-3 bg-gray-50 rounded-xl text-xs">
                            <p class="font-bold mb-1">العمل الإضافي:</p>
                            <p v-if="employee.overtime_calc_method === 'fixed'">مبلغ ثابت: {{ employee.overtime_hourly_rate }} / ساعة</p>
                            <p v-else>مضاعف: {{ employee.overtime_multiplier }}x من أجر الساعة</p>
                        </div>
                    </div>
                    
                    <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
                        <h3 class="font-bold text-gray-800 dark:text-gray-100 mb-4 border-b pb-2">بيانات البنك</h3>
                        <div class="space-y-3 text-sm">
                            <div class="flex justify-between"><span class="text-gray-500">اسم البنك</span><span class="font-bold">{{ employee.bank_name || '-' }}</span></div>
                            <div class="flex justify-between"><span class="text-gray-500">IBAN</span><span class="font-mono">{{ employee.bank_account || '-' }}</span></div>
                            <div class="flex justify-between"><span class="text-gray-500">SWIFT</span><span class="font-mono">{{ employee.bank_swift || '-' }}</span></div>
                        </div>
                    </div>
                </div>

                <!-- Left Column (Activities & Balances) -->
                <div class="lg:col-span-2 space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
                            <h3 class="font-bold text-gray-800 dark:text-gray-100 mb-3">الوردية</h3>
                            <div v-if="employee.shift" class="text-sm">
                                <p class="font-bold text-lg mb-1">{{ employee.shift.name }}</p>
                                <p class="text-gray-500" v-if="!employee.shift.is_flexible">{{ employee.shift.start_time }} - {{ employee.shift.end_time }}</p>
                                <p class="text-blue-500 font-bold" v-else>وردية مرنة ({{ employee.shift.min_hours }} ساعات)</p>
                                <p v-if="employee.custom_grace_minutes" class="mt-2 text-xs text-gold-600 bg-gold-50 p-1.5 rounded inline-block">فترة سماح مخصصة: {{ employee.custom_grace_minutes }} دقيقة</p>
                            </div>
                            <p v-else class="text-sm text-gray-400">غير محدد</p>
                        </div>
                        
                        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
                            <h3 class="font-bold text-gray-800 dark:text-gray-100 mb-3">أرصدة الإجازات</h3>
                            <div v-if="employee.leave_balances?.length" class="space-y-2">
                                <div v-for="b in employee.leave_balances" :key="b.id" class="flex items-center justify-between text-sm">
                                    <span>{{ b.leave_type?.name }}</span>
                                    <span class="font-bold" :class="b.remaining_days > 0 ? 'text-green-600' : 'text-red-500'">{{ b.remaining_days }} يوم</span>
                                </div>
                            </div>
                            <p v-else class="text-sm text-gray-400">لا يوجد أرصدة مسجلة</p>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
                        <h3 class="font-bold text-gray-800 dark:text-gray-100 mb-4 border-b pb-2">آخر حركات الحضور</h3>
                        <div v-if="employee.attendances?.length" class="overflow-x-auto text-sm">
                            <table class="w-full text-right">
                                <thead>
                                    <tr class="text-gray-500">
                                        <th class="py-2">التاريخ</th>
                                        <th class="py-2">الحضور</th>
                                        <th class="py-2">الانصراف</th>
                                        <th class="py-2">المدة</th>
                                        <th class="py-2">الحالة</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="a in employee.attendances" :key="a.id" class="border-t">
                                        <td class="py-2 font-mono">{{ a.date }}</td>
                                        <td class="py-2 text-green-600" dir="ltr">{{ formatTime(a.check_in) }}</td>
                                        <td class="py-2 text-red-600" dir="ltr">{{ formatTime(a.check_out) }}</td>
                                        <td class="py-2">{{ a.worked_hours }} س</td>
                                        <td class="py-2">{{ a.status }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-else class="text-sm text-center py-6 text-gray-400">لا يوجد سجلات حضور</p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/SmartLayout.vue';

const props = defineProps({
    employee: Object,
});

const totalSalary = computed(() => {
    const e = props.employee;
    return Number(e.basic_salary) + Number(e.housing_allowance) + Number(e.transport_allowance) + Number(e.other_allowance);
});

const formatTime = (timeString) => {
    if (!timeString) return '-';
    return timeString.split(' ')[1] || timeString; 
};
</script>
