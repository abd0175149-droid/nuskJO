<template>
    <AppLayout>
        <template #header>{{ $page.props.title }}</template>
        <div class="max-w-3xl">
            <div class="rounded-xl border shadow-sm p-6 bg-white border-gray-200">
                <form @submit.prevent="submit" class="space-y-5">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1.5">اسم العميل *</label>
                            <input v-model="form.name" type="text" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-gold-500" />
                            <p v-if="form.errors.name" class="mt-1 text-xs text-red-400">{{ form.errors.name }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1.5">كود العميل *</label>
                            <input v-model="form.code" type="text" required dir="ltr" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-gold-500" />
                            <p v-if="form.errors.code" class="mt-1 text-xs text-red-400">{{ form.errors.code }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1.5">الوكيل</label>
                            <SearchableSelect v-model="form.agent_id" :options="agentOptions" placeholder="بدون وكيل" search-placeholder="ابحث عن وكيل..." />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1.5">الهاتف</label>
                            <input v-model="form.phone" type="text" dir="ltr" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-gold-500" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1.5">البريد</label>
                            <input v-model="form.email" type="email" dir="ltr" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-gold-500" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1.5">الجنسية</label>
                            <input v-model="form.nationality" type="text" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-gold-500" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1.5">رقم الهوية</label>
                            <input v-model="form.id_number" type="text" dir="ltr" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-gold-500" />
                        </div>
                        <div v-if="client" class="flex items-end">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input v-model="form.is_active" type="checkbox" class="w-4 h-4 rounded text-gold-500" />
                                <span class="text-sm text-gray-700">نشط</span>
                            </label>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">العنوان</label>
                        <textarea v-model="form.address" rows="2" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-gold-500 resize-none" ></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">ملاحظات</label>
                        <textarea v-model="form.notes" rows="2" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-gold-500 resize-none" ></textarea>
                    </div>
                    <div class="flex items-center gap-3 pt-2">
                        <button type="submit" :disabled="form.processing" class="px-6 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md disabled:opacity-50">
                            {{ form.processing ? '⏳ جاري الحفظ...' : (client ? '💾 تحديث' : '✅ إضافة') }}
                        </button>
                        <a href="/clients" class="px-6 py-2.5 rounded-xl text-sm text-gray-600 hover:bg-gray-100">إلغاء</a>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/SmartLayout.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
const props = defineProps({ client: Object, agents: Array });
const agentOptions = computed(() => props.agents.map(a => ({ value: a.id, label: `${a.name} (${a.code})` })));
const form = useForm({
    name: props.client?.name||'', code: props.client?.code||'',
    phone: props.client?.phone||'', email: props.client?.email||'',
    agent_id: props.client?.agent_id||'', nationality: props.client?.nationality||'',
    id_number: props.client?.id_number||'', address: props.client?.address||'',
    notes: props.client?.notes||'', is_active: props.client?.is_active??true,
});
const submit=()=>{props.client?form.put('/clients/'+props.client.id):form.post('/clients')};
</script>
