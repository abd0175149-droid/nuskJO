<template>
    <AppLayout>
        <template #header>{{ $page.props.title }}</template>

        <div class="max-w-3xl">
            <div class="rounded-xl border shadow-sm p-6"
                 :class="isDark ? 'bg-gray-800 border-gold-900/20' : 'bg-white border-gray-200'">
                <form @submit.prevent="submit" class="space-y-5">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <!-- Name -->
                        <div>
                            <label class="block text-sm font-medium mb-1.5" :class="isDark ? 'text-gray-300' : 'text-gray-700'">اسم الوكيل *</label>
                            <input v-model="form.name" type="text" required
                                   class="w-full px-4 py-2.5 rounded-xl border text-sm focus:outline-none focus:ring-2 focus:ring-gold-500"
                                   :class="isDark ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-200 text-gray-800'" />
                            <p v-if="form.errors.name" class="mt-1 text-xs text-red-400">{{ form.errors.name }}</p>
                        </div>

                        <!-- Code -->
                        <div>
                            <label class="block text-sm font-medium mb-1.5" :class="isDark ? 'text-gray-300' : 'text-gray-700'">كود الوكيل *</label>
                            <input v-model="form.code" type="text" required dir="ltr"
                                   class="w-full px-4 py-2.5 rounded-xl border text-sm focus:outline-none focus:ring-2 focus:ring-gold-500"
                                   :class="isDark ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-200 text-gray-800'" />
                            <p v-if="form.errors.code" class="mt-1 text-xs text-red-400">{{ form.errors.code }}</p>
                        </div>

                        <!-- Phone -->
                        <div>
                            <label class="block text-sm font-medium mb-1.5" :class="isDark ? 'text-gray-300' : 'text-gray-700'">الهاتف</label>
                            <input v-model="form.phone" type="text" dir="ltr"
                                   class="w-full px-4 py-2.5 rounded-xl border text-sm focus:outline-none focus:ring-2 focus:ring-gold-500"
                                   :class="isDark ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-200 text-gray-800'" />
                        </div>

                        <!-- Email -->
                        <div>
                            <label class="block text-sm font-medium mb-1.5" :class="isDark ? 'text-gray-300' : 'text-gray-700'">البريد الإلكتروني</label>
                            <input v-model="form.email" type="email" dir="ltr"
                                   class="w-full px-4 py-2.5 rounded-xl border text-sm focus:outline-none focus:ring-2 focus:ring-gold-500"
                                   :class="isDark ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-200 text-gray-800'" />
                        </div>

                        <!-- Country -->
                        <div>
                            <label class="block text-sm font-medium mb-1.5" :class="isDark ? 'text-gray-300' : 'text-gray-700'">الدولة</label>
                            <input v-model="form.country" type="text"
                                   class="w-full px-4 py-2.5 rounded-xl border text-sm focus:outline-none focus:ring-2 focus:ring-gold-500"
                                   :class="isDark ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-200 text-gray-800'" />
                        </div>

                        <!-- City -->
                        <div>
                            <label class="block text-sm font-medium mb-1.5" :class="isDark ? 'text-gray-300' : 'text-gray-700'">المدينة</label>
                            <input v-model="form.city" type="text"
                                   class="w-full px-4 py-2.5 rounded-xl border text-sm focus:outline-none focus:ring-2 focus:ring-gold-500"
                                   :class="isDark ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-200 text-gray-800'" />
                        </div>

                        <!-- Contact Person -->
                        <div>
                            <label class="block text-sm font-medium mb-1.5" :class="isDark ? 'text-gray-300' : 'text-gray-700'">جهة الاتصال</label>
                            <input v-model="form.contact_person" type="text"
                                   class="w-full px-4 py-2.5 rounded-xl border text-sm focus:outline-none focus:ring-2 focus:ring-gold-500"
                                   :class="isDark ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-200 text-gray-800'" />
                        </div>

                        <!-- Active (edit only) -->
                        <div v-if="agent" class="flex items-end">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input v-model="form.is_active" type="checkbox" class="w-4 h-4 rounded text-gold-500 focus:ring-gold-500" />
                                <span class="text-sm" :class="isDark ? 'text-gray-300' : 'text-gray-700'">نشط</span>
                            </label>
                        </div>
                    </div>

                    <!-- Address -->
                    <div>
                        <label class="block text-sm font-medium mb-1.5" :class="isDark ? 'text-gray-300' : 'text-gray-700'">العنوان</label>
                        <textarea v-model="form.address" rows="2"
                                  class="w-full px-4 py-2.5 rounded-xl border text-sm focus:outline-none focus:ring-2 focus:ring-gold-500 resize-none"
                                  :class="isDark ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-200 text-gray-800'" ></textarea>
                    </div>

                    <!-- Notes -->
                    <div>
                        <label class="block text-sm font-medium mb-1.5" :class="isDark ? 'text-gray-300' : 'text-gray-700'">ملاحظات</label>
                        <textarea v-model="form.notes" rows="2"
                                  class="w-full px-4 py-2.5 rounded-xl border text-sm focus:outline-none focus:ring-2 focus:ring-gold-500 resize-none"
                                  :class="isDark ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-200 text-gray-800'" ></textarea>
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center gap-3 pt-2">
                        <button type="submit" :disabled="form.processing"
                                class="px-6 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 hover:from-gold-400 hover:to-gold-300 shadow-md transition-all disabled:opacity-50">
                            {{ form.processing ? '⏳ جاري الحفظ...' : (agent ? '💾 تحديث' : '✅ إضافة') }}
                        </button>
                        <a href="/agents" class="px-6 py-2.5 rounded-xl font-medium text-sm transition-colors"
                           :class="isDark ? 'text-gray-400 hover:bg-gray-700' : 'text-gray-600 hover:bg-gray-100'">
                            إلغاء
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/SmartLayout.vue';

const props = defineProps({ agent: Object });
const isDark = ref(document.documentElement.classList.contains('dark'));

const form = useForm({
    name: props.agent?.name || '',
    code: props.agent?.code || '',
    phone: props.agent?.phone || '',
    email: props.agent?.email || '',
    country: props.agent?.country || '',
    city: props.agent?.city || '',
    address: props.agent?.address || '',
    contact_person: props.agent?.contact_person || '',
    notes: props.agent?.notes || '',
    is_active: props.agent?.is_active ?? true,
});

const submit = () => {
    if (props.agent) {
        form.put(`/agents/${props.agent.id}`);
    } else {
        form.post('/agents');
    }
};
</script>
