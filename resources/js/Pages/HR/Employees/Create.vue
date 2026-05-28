<template>
    <AppLayout>
        <template #header>إضافة موظف جديد</template>
        
        <div class="max-w-5xl mx-auto space-y-6">
            <div class="flex items-center gap-4">
                <Link href="/employees" class="text-gray-500 hover:text-gold-600 transition-colors">
                    <span>← عودة للقائمة</span>
                </Link>
            </div>

            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                <form @submit.prevent="submit" class="space-y-8">
                    <!-- Basic Info -->
                    <div>
                        <h4 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-4 pb-2 border-b">البيانات الأساسية</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">المستخدم المربوط *</label>
                                <select v-model="form.user_id" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none">
                                    <option :value="null">-- اختر المستخدم --</option>
                                    <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }} ({{ u.email }})</option>
                                </select>
                                <p v-if="form.errors.user_id" class="text-red-500 text-xs mt-1">{{ form.errors.user_id }}</p>
                                <p class="text-xs text-gray-500 mt-1">تظهر فقط الحسابات التي ليس لها ملف موظف حالياً.</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">الفرع التابع له *</label>
                                <select v-model="form.country" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none">
                                    <option value="JO">الأردن</option>
                                    <option value="SA">السعودية</option>
                                </select>
                                <p v-if="form.errors.country" class="text-red-500 text-xs mt-1">{{ form.errors.country }}</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">تاريخ المباشرة *</label>
                                <input v-model="form.hire_date" type="date" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                                <p v-if="form.errors.hire_date" class="text-red-500 text-xs mt-1">{{ form.errors.hire_date }}</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">نوع العقد *</label>
                                <select v-model="form.contract_type" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none">
                                    <option value="full_time">دوام كامل (Full Time)</option>
                                    <option value="part_time">دوام جزئي (Part Time)</option>
                                    <option value="contract">عقد محدد المدة (Contract)</option>
                                </select>
                                <p v-if="form.errors.contract_type" class="text-red-500 text-xs mt-1">{{ form.errors.contract_type }}</p>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">القسم</label>
                                <select v-model="form.department_id" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none">
                                    <option :value="null">-- بدون قسم --</option>
                                    <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
                                </select>
                                <p v-if="form.errors.department_id" class="text-red-500 text-xs mt-1">{{ form.errors.department_id }}</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">المسمى الوظيفي</label>
                                <input v-model="form.job_title" type="text" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                                <p v-if="form.errors.job_title" class="text-red-500 text-xs mt-1">{{ form.errors.job_title }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Shift & Attendance -->
                    <div>
                        <h4 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-4 pb-2 border-b">الدوام والورديات</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">الوردية الافتراضية</label>
                                <select v-model="form.shift_id" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none">
                                    <option :value="null">-- بدون وردية محددة --</option>
                                    <option v-for="s in shifts" :key="s.id" :value="s.id">{{ s.name }}</option>
                                </select>
                                <p v-if="form.errors.shift_id" class="text-red-500 text-xs mt-1">{{ form.errors.shift_id }}</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">فترة سماح التأخير المخصصة للموظف (دقائق)</label>
                                <input v-model.number="form.custom_grace_minutes" type="number" min="0" placeholder="اتركه فارغاً لاستخدام إعداد الوردية" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                                <p v-if="form.errors.custom_grace_minutes" class="text-red-500 text-xs mt-1">{{ form.errors.custom_grace_minutes }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Financial & Salary -->
                    <div>
                        <h4 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-4 pb-2 border-b">البيانات المالية والرواتب</h4>
                        
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">عملة الراتب *</label>
                            <select v-model="form.currency" required class="w-full md:w-1/3 px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none">
                                <option value="JOD">دينار أردني (JOD)</option>
                                <option value="SAR">ريال سعودي (SAR)</option>
                            </select>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">الراتب الأساسي *</label>
                                <input v-model.number="form.basic_salary" type="number" step="0.001" min="0" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                                <p v-if="form.errors.basic_salary" class="text-red-500 text-xs mt-1">{{ form.errors.basic_salary }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">بدل السكن *</label>
                                <input v-model.number="form.housing_allowance" type="number" step="0.001" min="0" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">بدل المواصلات *</label>
                                <input v-model.number="form.transport_allowance" type="number" step="0.001" min="0" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">بدلات أخرى *</label>
                                <input v-model.number="form.other_allowance" type="number" step="0.001" min="0" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                            </div>
                        </div>

                        <div class="p-4 border rounded-xl bg-gray-50 dark:bg-gray-800">
                            <h5 class="text-sm font-bold text-gray-800 dark:text-gray-200 mb-3">حساب العمل الإضافي (Overtime)</h5>
                            <div class="flex gap-6 mb-4">
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="radio" v-model="form.overtime_calc_method" value="multiplier" class="text-gold-500 focus:ring-gold-500"/>
                                    <span class="text-sm">مضاعف لساعة العمل المعتادة</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="radio" v-model="form.overtime_calc_method" value="fixed" class="text-gold-500 focus:ring-gold-500"/>
                                    <span class="text-sm">مبلغ ثابت لكل ساعة إضافية</span>
                                </label>
                            </div>

                            <div v-if="form.overtime_calc_method === 'multiplier'" class="w-full md:w-1/3">
                                <label class="block text-sm font-medium text-gray-700 mb-1">المضاعف (مثال: 1.5x) *</label>
                                <input v-model.number="form.overtime_multiplier" type="number" step="0.1" min="1" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                            </div>
                            <div v-if="form.overtime_calc_method === 'fixed'" class="w-full md:w-1/3">
                                <label class="block text-sm font-medium text-gray-700 mb-1">المبلغ الثابت للساعة *</label>
                                <input v-model.number="form.overtime_hourly_rate" type="number" step="0.001" min="0" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                            </div>
                        </div>
                    </div>

                    <!-- Personal & Bank Info -->
                    <div>
                        <h4 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-4 pb-2 border-b">البيانات الشخصية والبنكية</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">رقم الهوية / الإقامة</label>
                                <input v-model="form.national_id" type="text" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">رقم الجواز</label>
                                <input v-model="form.passport_number" type="text" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                            </div>
                            <div class="col-span-1 md:col-span-2 lg:col-span-1"></div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">اسم البنك</label>
                                <input v-model="form.bank_name" type="text" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">رقم الحساب (IBAN)</label>
                                <input v-model="form.bank_account" type="text" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">SWIFT Code</label>
                                <input v-model="form.bank_swift" type="text" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">ملاحظات</label>
                        <textarea v-model="form.notes" rows="3" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none resize-none"></textarea>
                    </div>

                    <div>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input v-model="form.is_active" type="checkbox" class="w-5 h-5 rounded text-gold-500"/>
                            <span class="font-bold text-gray-800">حساب موظف نشط</span>
                        </label>
                    </div>

                    <div class="pt-6 border-t flex gap-4">
                        <button type="submit" :disabled="form.processing" class="px-8 py-3 rounded-xl font-bold text-white bg-black hover:bg-gray-800 shadow-md disabled:opacity-50">
                            {{ form.processing ? 'جاري الحفظ...' : 'حفظ بيانات الموظف' }}
                        </button>
                        <Link href="/employees" class="px-8 py-3 rounded-xl font-bold text-gray-700 bg-gray-100 hover:bg-gray-200">
                            إلغاء
                        </Link>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { useForm, Link } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/SmartLayout.vue';

const props = defineProps({
    users: Array,
    departments: Array,
    shifts: Array,
});

const form = useForm({
    user_id: null,
    department_id: null,
    shift_id: null,
    job_title: '',
    hire_date: new Date().toISOString().split('T')[0],
    contract_type: 'full_time',
    country: 'JO',
    currency: 'JOD',
    
    basic_salary: 0,
    housing_allowance: 0,
    transport_allowance: 0,
    other_allowance: 0,
    
    overtime_calc_method: 'multiplier',
    overtime_hourly_rate: 0,
    overtime_multiplier: 1.5,
    
    custom_grace_minutes: null,
    
    bank_name: '',
    bank_account: '',
    bank_swift: '',
    national_id: '',
    passport_number: '',
    notes: '',
    is_active: true,
});

const submit = () => {
    form.post('/employees');
};
</script>
