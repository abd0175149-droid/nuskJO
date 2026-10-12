<template>
    <div class="space-y-5">
        <div v-if="dirty" class="p-3 rounded-xl border text-sm bg-blue-50 border-blue-200 text-blue-800 dark:bg-blue-900/20 dark:border-blue-800 dark:text-blue-200">في المسودة تعديلات لم تُحفظ. البوت والبطاقة يقرآن المحفوظ فقط، فلا تظهر هنا قبل الحفظ.</div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
            <section :class="box">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h4 :class="h4">شروط ظهور العرض للبوت</h4>
                    <span class="px-2.5 py-1 rounded-full text-xs font-bold" :class="bot.for_bot ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300' : 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'">{{ bot.for_bot ? 'يقرؤه البوت' : 'لا يقرؤه البوت' }}</span>
                </div>
                <ul class="space-y-1.5 text-sm">
                    <li v-for="c in bot.checks" :key="c.code" class="flex items-start gap-2">
                        <span class="shrink-0 w-5 h-5 rounded-full grid place-items-center text-xs font-bold" :class="c.ok ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300' : 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'">{{ c.ok ? '✓' : '✗' }}</span>
                        <span>{{ c.label }} <code class="text-[11px] text-gray-400" dir="ltr">{{ c.code }}</code></span>
                    </li>
                </ul>
                <p :class="hint">الشرطان الأخيران للعروض الجاهزة، وهما الشرط نفسه الذي يحجب العرض في فاتورة العرض، فلا يعرض البوت رحلة لا يمكن بيعها.</p>
            </section>

            <section :class="box">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h4 :class="h4">فحص التسرّب</h4>
                    <span class="px-2.5 py-1 rounded-full text-xs font-bold" :class="bot.leaks.length ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300'">{{ bot.leaks.length ? bot.leaks.length + ' حقل ممنوع' : 'نظيف' }}</span>
                </div>
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ bot.leaks.length ? bot.leaks.join('، ') : 'لا وكيل، ولا تكلفة، ولا سعر غرفة، ولا عدد مقاعد دقيق في ما يقرؤه البوت. القائمة البيضاء في toBotArray() هي الحاجز.' }}</p>
                <p :class="hint">البوت يرى التوفّر تقريبياً: «متوفر» أو «مقاعد محدودة».</p>
            </section>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 items-start">
            <section :class="box + ' min-w-0'">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h4 :class="h4">ما يقرؤه البوت حرفياً</h4>
                    <button type="button" @click="copy" class="px-2.5 py-1 rounded-lg text-xs font-bold text-gray-700 dark:text-gray-200 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200">{{ copied ? 'نُسخ ✓' : 'نسخ' }}</button>
                </div>
                <p :class="hint">هذا ما تُرجعه get_offers وget_offer_details لهذا العرض، من السجل نفسه الذي تحرّره وتبيع منه.</p>
                <pre ref="pre" dir="ltr" style="font-family: inherit; unicode-bidi: plaintext" class="text-[11px] leading-5 p-3 rounded-lg bg-gray-50 dark:bg-gray-950 border border-gray-200 dark:border-gray-800 overflow-auto max-h-[480px] text-left whitespace-pre text-gray-700 dark:text-gray-300">{{ json }}</pre>
            </section>

            <section :class="box">
                <h4 :class="h4">🖼️ بطاقة العرض</h4>
                <div v-if="bot.card_is_stale && bot.card_url && !bot.card_is_custom" class="p-3 rounded-xl border text-xs bg-amber-50 border-amber-200 text-amber-800 dark:bg-amber-900/20 dark:border-amber-800 dark:text-amber-200">⚠️ البطاقة أقدم من آخر تعديل. البوت يعيد توليدها تلقائياً قبل إرسالها، أو ولّدها الآن.</div>
                <div v-if="bot.card_is_custom" class="text-xs"><span class="px-2.5 py-1 rounded-full font-bold bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">بطاقة مرفوعة يدوياً</span> تُرسل بدل المولّدة.</div>
                <a v-if="bot.card_url" :href="bot.card_url" target="_blank" rel="noopener" class="block w-fit max-w-full rounded-xl border p-1 border-gray-200 dark:border-gray-700 hover:border-gold-500">
                    <img :src="bot.card_url" alt="بطاقة العرض" class="max-h-80 max-w-full rounded-lg"/>
                </a>
                <div v-else class="rounded-xl border-2 border-dashed p-6 text-center text-sm text-gray-500 border-gray-300 dark:border-gray-600">لم تُولَّد بطاقة بعد</div>
                <div v-if="can.update" class="flex flex-wrap gap-2">
                    <button type="button" @click="generate" :disabled="busy" class="px-4 py-2 rounded-xl text-sm font-bold text-gold-800 dark:text-gold-200 bg-gold-100 dark:bg-gold-900/30 hover:bg-gold-200 disabled:opacity-50">{{ busy ? '...جارٍ التوليد' : '🔄 توليد البطاقة' }}</button>
                    <a :href="'/offers/' + offerId + '/card/preview'" target="_blank" rel="noopener" class="px-4 py-2 rounded-xl text-sm font-bold text-gray-700 dark:text-gray-200 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200">👁️ معاينة القالب</a>
                    <button type="button" @click="cardInput?.click()" class="px-4 py-2 rounded-xl text-sm font-bold text-blue-700 dark:text-blue-300 bg-blue-50 dark:bg-blue-900/30 hover:bg-blue-100">⬆️ رفع بطاقة جاهزة</button>
                    <label class="sr-only" for="f-card-upload">رفع بطاقة جاهزة</label>
                    <input id="f-card-upload" ref="cardInput" type="file" accept="image/png,image/jpeg" class="hidden" @change="(e) => upload(e, 'card', '/card/upload')"/>
                    <button v-if="bot.card_is_custom" type="button" @click="removeCustom" class="px-4 py-2 rounded-xl text-sm font-bold text-red-600 bg-red-50 dark:bg-red-900/20 hover:bg-red-100">🗑️ إزالة المرفوعة</button>
                    <button type="button" @click="heroInput?.click()" class="px-4 py-2 rounded-xl text-sm font-bold text-gray-700 dark:text-gray-200 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200">🏞️ صورة الترويسة</button>
                    <label class="sr-only" for="f-hero-upload">رفع صورة الترويسة</label>
                    <input id="f-hero-upload" ref="heroInput" type="file" accept="image/png,image/jpeg,image/webp" class="hidden" @change="(e) => upload(e, 'hero', '/hero')"/>
                </div>
                <p :class="hint">تُرسم من الإسقاط نفسه الذي يقرؤه البوت، فلا تختلف أسعار الصورة عن أسعار النص. بيع المقاعد لا يجعلها قديمة.</p>
            </section>
        </div>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({ offerId: Number, bot: Object, can: Object, dirty: Boolean });

const box = 'rounded-xl border p-4 space-y-3 border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900';
const h4 = 'text-sm font-bold text-gray-800 dark:text-gray-100';
const hint = 'text-xs text-gray-400 dark:text-gray-500';

const json = computed(() => JSON.stringify(props.bot.payload, null, 2));
const busy = ref(false);
const copied = ref(false);
const cardInput = ref(null);
const heroInput = ref(null);
const pre = ref(null);

const opts = { preserveScroll: true };
const generate = () => {
    busy.value = true;
    router.post('/offers/' + props.offerId + '/card', {}, { ...opts, onFinish: () => { busy.value = false; } });
};
const upload = (e, key, url) => {
    const file = e?.target?.files?.[0];
    if (!file) return;
    const fd = new FormData();
    fd.append(key, file);
    router.post('/offers/' + props.offerId + url, fd, { ...opts, forceFormData: true });
    e.target.value = '';
};
const removeCustom = () => router.delete('/offers/' + props.offerId + '/card', opts);
const copy = async () => {
    try {
        await navigator.clipboard.writeText(json.value);
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 1500);
    } catch {
        const r = document.createRange();
        r.selectNodeContents(pre.value);
        const s = window.getSelection();
        s.removeAllRanges();
        s.addRange(r);
    }
};
</script>
