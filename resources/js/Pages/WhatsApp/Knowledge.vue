<template>
    <AppLayout>
        <template #header>قاعدة معرفة البوت</template>

        <div class="space-y-6 pb-4">
            <div v-if="$page.props.flash?.success" class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700 dark:bg-green-900/20 dark:border-green-800 dark:text-green-300">✅ {{ $page.props.flash.success }}</div>
            <div v-if="$page.props.flash?.error" class="p-4 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700 dark:bg-red-900/20 dark:border-red-800 dark:text-red-300">❌ {{ $page.props.flash.error }}</div>

            <!-- ===== الشرح والحجم ===== -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div class="lg:col-span-2 p-4 rounded-xl border text-sm leading-relaxed border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 text-gray-600 dark:text-gray-300">
                    كل معلومة فعّالة هنا يقرؤها بوت العملاء مع كل رسالة، بالترتيب الظاهر. اكتب سياسات وإجراءات ثابتة بجمل قصيرة واضحة:
                    ما تشمله الباقات، الأمتعة، التأشيرات، الإلغاء. <b>لا أسعار</b> (تأتي من العروض)، و<b>لا بيانات دفع</b>.
                    يمكن أيضاً إدارتها من واتساب عبر <a href="/whatsapp/admin-numbers" class="text-gold-700 dark:text-gold-400 font-bold underline">أرقام الإدارة</a>.
                </div>
                <div class="p-4 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm space-y-2">
                    <div class="flex items-baseline justify-between gap-2">
                        <span class="text-sm font-bold text-gray-700 dark:text-gray-200">الحجم الذي يُرسل مع كل رسالة</span>
                        <span class="text-xs font-mono tabular-nums text-gray-500" dir="ltr">{{ activeChars.toLocaleString('en') }} / {{ limits.total.toLocaleString('en') }}</span>
                    </div>
                    <div class="h-2.5 rounded-full bg-gray-100 dark:bg-gray-800 overflow-hidden">
                        <div class="h-full rounded-full transition-all" :class="usage > 0.9 ? 'bg-red-500' : usage > 0.7 ? 'bg-amber-500' : 'bg-gold-500'" :style="{ width: Math.min(100, usage * 100) + '%' }"></div>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400"><span class="font-mono" dir="ltr">{{ activeCount }}</span> معلومة فعّالة من <span class="font-mono" dir="ltr">{{ entries.length }}</span>. كل حرف هنا كلفة في كل ردّ.</p>
                </div>
            </div>

            <div class="flex justify-between items-center gap-3 flex-wrap">
                <h3 class="text-base font-bold text-gray-800 dark:text-gray-100">المعلومات</h3>
                <button type="button" @click="openForm()" class="px-4 py-2 rounded-xl text-sm font-bold text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md">+ معلومة جديدة</button>
            </div>

            <!-- ===== القائمة ===== -->
            <div v-if="entries.length" class="space-y-3">
                <div v-for="(e, i) in entries" :key="e.id"
                     class="rounded-xl border bg-white dark:bg-gray-900 shadow-sm p-4 space-y-2"
                     :class="e.is_active ? 'border-gray-200 dark:border-gray-700' : 'border-dashed border-gray-300 dark:border-gray-600 opacity-70'">
                    <div class="flex items-start justify-between gap-3 flex-wrap">
                        <div class="min-w-0 flex items-center gap-2 flex-wrap">
                            <span class="text-xs font-mono text-gray-400" dir="ltr">#{{ e.id }}</span>
                            <h4 class="font-bold text-gray-800 dark:text-gray-100">{{ e.title }}</h4>
                            <span v-if="!e.is_active" class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300">موقوفة — لا يقرؤها البوت</span>
                            <span v-if="e.source === 'whatsapp'" class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300">من واتساب</span>
                        </div>
                        <div class="flex items-center gap-1 shrink-0">
                            <button type="button" @click="move(i, -1)" :disabled="i === 0" :aria-label="'تحريك ' + e.title + ' للأعلى'" class="px-2 py-1 rounded-lg text-xs text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 disabled:opacity-30">▲</button>
                            <button type="button" @click="move(i, 1)" :disabled="i === entries.length - 1" :aria-label="'تحريك ' + e.title + ' للأسفل'" class="px-2 py-1 rounded-lg text-xs text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 disabled:opacity-30">▼</button>
                            <button type="button" @click="toggle(e)" class="px-2 py-1 rounded-lg text-xs font-bold hover:bg-gray-100 dark:hover:bg-gray-800" :class="e.is_active ? 'text-amber-700 dark:text-amber-400' : 'text-green-700 dark:text-green-400'">{{ e.is_active ? 'إيقاف' : 'تفعيل' }}</button>
                            <button type="button" @click="openForm(e)" class="px-2 py-1 rounded-lg text-xs font-bold text-gold-700 dark:text-gold-400 hover:bg-gold-50 dark:hover:bg-gold-900/20">✏️ تعديل</button>
                            <button type="button" @click="remove(e)" :aria-label="'حذف ' + e.title" title="حذف" class="px-2 py-1 rounded-lg text-xs font-bold text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20">🗑️</button>
                        </div>
                    </div>
                    <p class="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-line leading-relaxed" :class="expanded[e.id] ? '' : 'line-clamp-3'">{{ e.content }}</p>
                    <div class="flex items-center justify-between gap-2 flex-wrap text-xs text-gray-400">
                        <button v-if="e.content.length > 220" type="button" @click="expanded[e.id] = !expanded[e.id]" class="font-bold text-gold-700 dark:text-gold-400">{{ expanded[e.id] ? 'إخفاء' : 'عرض الكل' }}</button>
                        <span v-else></span>
                        <span><span class="font-mono" dir="ltr">{{ e.content.length }}</span> حرف<span v-if="e.updated_by"> · {{ e.updated_by }}</span><span v-if="e.updated_at"> · <span class="font-mono" dir="ltr">{{ e.updated_at }}</span></span></span>
                    </div>
                </div>
            </div>
            <div v-else class="rounded-xl border-2 border-dashed p-10 text-center space-y-3 border-gray-300 dark:border-gray-600">
                <p class="text-sm text-gray-500 dark:text-gray-400">لا معلومات بعد. البوت يردّ بالشخصية وبيانات الشركة والعروض فقط.</p>
                <button type="button" @click="openForm()" class="px-4 py-2 rounded-xl text-sm font-bold text-gold-800 bg-gold-100 dark:bg-gold-900/30 dark:text-gold-200">+ أضف أوّل معلومة</button>
            </div>
        </div>

        <!-- ===== نموذج الإضافة والتعديل ===== -->
        <!-- AppModal (E16.9): Escape والخلفية لا يضيّعان تعديلات غير محفوظة -->
        <AppModal :open="showForm" :title="editing ? 'تعديل معلومة' : 'معلومة جديدة'" size="lg" :dirty="formDirty" @close="showForm = false">
            <form @submit.prevent="save" class="space-y-5">
                <div>
                    <label for="kb-title" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">العنوان *</label>
                    <input id="kb-title" data-autofocus v-model="form.title" :maxlength="limits.title" required placeholder="مثال: الإلغاء والاسترداد"
                           class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                    <p v-if="form.errors.title" class="mt-1 text-xs text-red-500">{{ form.errors.title }}</p>
                </div>
                <div>
                    <label for="kb-content" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">النصّ *</label>
                    <textarea id="kb-content" v-model="form.content" rows="10" :maxlength="limits.content" required
                              placeholder="- جمل قصيرة واضحة، كل نقطة في سطر.&#10;- بلا أسعار وبلا بيانات دفع."
                              class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm leading-relaxed focus:ring-2 focus:ring-gold-500 focus:outline-none resize-y"></textarea>
                    <p class="mt-1 text-xs" :class="form.content.length > limits.content * 0.9 ? 'text-amber-600' : 'text-gray-400'"><span class="font-mono" dir="ltr">{{ form.content.length }} / {{ limits.content }}</span> حرف</p>
                    <p v-if="form.errors.content" class="mt-1 text-xs text-red-500">{{ form.errors.content }}</p>
                </div>
                <label for="kb-active" class="flex items-center gap-2 cursor-pointer">
                    <input id="kb-active" v-model="form.is_active" type="checkbox" class="w-4 h-4 rounded text-gold-500"/>
                    <span class="text-sm text-gray-700 dark:text-gray-300">فعّالة (يقرؤها البوت)</span>
                </label>
                <div class="flex gap-3 pt-2">
                    <button type="submit" :disabled="form.processing" class="px-6 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md disabled:opacity-50">حفظ</button>
                    <button type="button" @click="showForm = false" class="px-6 py-2.5 rounded-xl text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800">إلغاء</button>
                </div>
            </form>
        </AppModal>
    </AppLayout>
</template>

<script setup>
import { ref, reactive, computed } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import AppModal from '@/Components/AppModal.vue';
import { useConfirm } from '@/composables/useConfirm';

const props = defineProps({
    entries: { type: Array, default: () => [] },
    limits: { type: Object, default: () => ({ title: 150, content: 2000, total: 20000 }) },
});

const expanded = reactive({});
const showForm = ref(false);
const editing = ref(null);
const form = useForm({ title: '', content: '', is_active: true });
const { confirm } = useConfirm();
// لقطة الحقول عند الفتح: ما يختلف عنها تعديل غير محفوظ
const baseline = ref('');
const formDirty = computed(() => showForm.value && JSON.stringify(form.data()) !== baseline.value);

const activeCount = computed(() => props.entries.filter(e => e.is_active).length);
const activeChars = computed(() => props.entries.filter(e => e.is_active).reduce((s, e) => s + e.title.length + e.content.length, 0));
const usage = computed(() => activeChars.value / (props.limits.total || 1));

const openForm = (e = null) => {
    editing.value = e;
    form.clearErrors();
    form.title = e?.title || '';
    form.content = e?.content || '';
    form.is_active = e ? e.is_active : true;
    baseline.value = JSON.stringify(form.data());
    showForm.value = true;
};

const save = () => {
    const opts = { preserveScroll: true, onSuccess: () => { showForm.value = false; } };
    if (editing.value) form.put('/whatsapp/knowledge/' + editing.value.id, opts);
    else form.post('/whatsapp/knowledge', opts);
};

const toggle = (e) => {
    router.put('/whatsapp/knowledge/' + e.id, { title: e.title, content: e.content, is_active: !e.is_active }, { preserveScroll: true });
};

const remove = async (e) => {
    if (!(await confirm({ title: 'حذف معلومة', message: `حذف «${e.title}» نهائياً؟ تبقى نسخته في سجلّ التدقيق.`, confirmLabel: '🗑️ حذف', danger: true }))) return;
    router.delete('/whatsapp/knowledge/' + e.id, { preserveScroll: true });
};

const move = (i, dir) => {
    const ids = props.entries.map(e => e.id);
    const j = i + dir;
    [ids[i], ids[j]] = [ids[j], ids[i]];
    router.post('/whatsapp/knowledge/reorder', { ids }, { preserveScroll: true });
};
</script>
