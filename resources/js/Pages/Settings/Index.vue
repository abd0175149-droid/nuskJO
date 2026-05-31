<template>
    <AppLayout>
        <template #header>الإعدادات</template>
        <div class="space-y-8">
            <div v-if="$page.props.flash?.success" class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700">✅ {{ $page.props.flash.success }}</div>

            <!-- سعر الصرف (ثابت) -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">💱 سعر الصرف</h3>
                <div class="flex items-center gap-6">
                    <div class="flex items-center gap-3 p-4 rounded-xl bg-gold-50 border border-gold-200">
                        <span class="text-3xl">💱</span>
                        <div>
                            <p class="text-xs text-gray-500 mb-1">SAR → JOD (ثابت)</p>
                            <p class="text-2xl font-bold font-mono text-gold-700" dir="ltr">0.190000</p>
                        </div>
                    </div>
                    <div class="text-sm text-gray-500">
                        <p>⚙️ سعر الصرف مثبت على <strong class="text-gold-700">0.19</strong></p>
                        <p class="text-xs mt-1 text-gray-400">يُستخدم في جميع العمليات (الفواتير، الحوالات، المحاسبة)</p>
                    </div>
                </div>
            </div>

            <!-- الإعدادات العامة -->
            <div v-for="(items, group) in settings" :key="group" class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                    {{ {company:'🏢 بيانات الشركة',printing:'🖨️ الطباعة',notifications:'🔔 الإشعارات',financial:'💰 المالية'}[group]||('⚙️ '+group) }}
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div v-for="item in items" :key="item.key">
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ item.description || item.key }}</label>
                        <template v-if="item.type==='boolean'">
                            <label class="flex items-center gap-2"><input v-model="settingsData[item.key]" type="checkbox" true-value="1" false-value="0" class="w-4 h-4 rounded text-gold-500"/><span class="text-sm text-gray-600">تفعيل</span></label>
                        </template>
                        <template v-else-if="item.type==='text'">
                            <textarea v-model="settingsData[item.key]" rows="2" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none resize-none"></textarea>
                        </template>
                        <template v-else>
                            <input v-model="settingsData[item.key]" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                        </template>
                    </div>
                </div>
            </div>

            <!-- قوالب الطباعة -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">🖨️ قوالب الطباعة</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- قالب مالي -->
                    <div class="p-4 rounded-xl border border-dashed border-gray-300 bg-gray-50">
                        <h4 class="font-bold text-sm text-gray-700 mb-2">📄 القالب المالي</h4>
                        <p class="text-xs text-gray-500 mb-3">يُستخدم لطباعة الفواتير والحوالات وسندات القبض</p>
                        <div v-if="templates?.financial" class="mb-3 p-2 bg-green-50 rounded-lg border border-green-200">
                            <span class="text-xs text-green-700">✅ قالب مرفوع</span>
                            <a :href="templates.financial" target="_blank" class="text-xs text-blue-600 mr-2 hover:underline">معاينة</a>
                        </div>
                        <form @submit.prevent="uploadTemplate('financial')" enctype="multipart/form-data">
                            <input ref="financialFile" type="file" accept=".pdf" class="block w-full text-xs text-gray-500 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-bold file:bg-gold-100 file:text-gold-700 hover:file:bg-gold-200"/>
                            <button type="submit" class="mt-2 px-4 py-2 rounded-lg text-xs font-bold text-white bg-gold-600 hover:bg-gold-700 disabled:opacity-50">📤 رفع القالب</button>
                        </form>
                    </div>
                    <!-- قالب محاسبي -->
                    <div class="p-4 rounded-xl border border-dashed border-gray-300 bg-gray-50">
                        <h4 class="font-bold text-sm text-gray-700 mb-2">📊 القالب المحاسبي</h4>
                        <p class="text-xs text-gray-500 mb-3">يُستخدم لطباعة كشف الحساب والقيود وشجرة الحسابات</p>
                        <div v-if="templates?.accounting" class="mb-3 p-2 bg-green-50 rounded-lg border border-green-200">
                            <span class="text-xs text-green-700">✅ قالب مرفوع</span>
                            <a :href="templates.accounting" target="_blank" class="text-xs text-blue-600 mr-2 hover:underline">معاينة</a>
                        </div>
                        <form @submit.prevent="uploadTemplate('accounting')" enctype="multipart/form-data">
                            <input ref="accountingFile" type="file" accept=".pdf" class="block w-full text-xs text-gray-500 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-bold file:bg-blue-100 file:text-blue-700 hover:file:bg-blue-200"/>
                            <button type="submit" class="mt-2 px-4 py-2 rounded-lg text-xs font-bold text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-50">📤 رفع القالب</button>
                        </form>
                    </div>
                </div>
                <div class="mt-4 pt-4 border-t border-gray-200">
                    <a href="/settings/print-layout" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl font-bold text-sm text-white bg-purple-600 hover:bg-purple-700 shadow-md">
                        🎨 محرر تخطيط الطباعة — تحديد مواقع العناصر بالسحب
                    </a>
                    <p class="text-xs text-gray-500 mt-1">حدد مكان كل عنصر على القالب بسحبه إلى الموقع المطلوب</p>
                </div>
            </div>

            <div class="flex justify-end">
                <button @click="saveSettings" :disabled="saving" class="px-8 py-3 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md disabled:opacity-50">💾 حفظ جميع الإعدادات</button>
            </div>
        </div>
    </AppLayout>
</template>
<script setup>
import { ref, reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
const props = defineProps({ settings: Object, templates: Object });

const settingsData = reactive({});
if (props.settings) {
    Object.values(props.settings).flat().forEach(s => { settingsData[s.key] = s.value || ''; });
}
const saving = ref(false);
const saveSettings = () => {
    saving.value = true;
    const payload = Object.entries(settingsData).map(([key, value]) => ({ key, value }));
    router.put('/settings', { settings: payload }, {
        preserveScroll: true,
        onFinish: () => { saving.value = false; },
    });
};

// قوالب الطباعة
const financialFile = ref(null);
const accountingFile = ref(null);

const uploadTemplate = (type) => {
    const fileInput = type === 'financial' ? financialFile.value : accountingFile.value;
    if (!fileInput?.files?.length) { alert('اختر ملف PDF أولاً'); return; }

    const formData = new FormData();
    formData.append('template', fileInput.files[0]);
    formData.append('type', type);

    router.post('/settings/upload-template', formData, {
        preserveScroll: true,
        forceFormData: true,
    });
};
</script>
