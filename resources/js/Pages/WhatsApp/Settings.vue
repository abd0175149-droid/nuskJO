<template>
    <AppLayout>
        <template #header>إعدادات بوت الواتساب</template>

        <div class="space-y-6 pb-4">
            <div v-if="$page.props.flash?.success" class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700">✅ {{ $page.props.flash.success }}</div>
            <div v-if="$page.props.flash?.error" class="p-4 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700">❌ {{ $page.props.flash.error }}</div>
            <!-- صحة القناة (whatsapp:doctor): الرسالة والنصيحة فقط، بلا أي قيمة سرية -->
            <div v-if="health.length" role="status" class="p-4 rounded-xl border text-sm border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 text-amber-800 dark:text-amber-300">
                <p class="font-bold mb-1">🩺 صحة القناة</p>
                <ul class="space-y-1">
                    <li v-for="c in health" :key="c.code">{{ c.level === 'error' ? '❌' : '⚠️' }} {{ c.message }}</li>
                </ul>
            </div>

            <ConflictBanner :fields="conflict.fields" :others="conflict.others" @dismiss="conflict = { fields: [], others: [] }"/>
            <div v-if="Object.keys(errors).length" class="p-4 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700 dark:bg-red-900/20 dark:border-red-800 dark:text-red-300">
                ❌ لم يُحفظ شيء — تحقّق من:
                <ul class="list-disc mt-1 pr-5 space-y-0.5"><li v-for="(msg, key) in errors" :key="key">{{ msg }}</li></ul>
            </div>

            <form @submit.prevent="save()" class="space-y-6">

                <!-- ================= 1. حالة التشغيل ================= -->
                <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5 md:p-6">
                    <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-4 flex items-center gap-2">🚦 حالة التشغيل</h3>

                    <div class="rounded-xl border p-4 mb-4"
                         :class="isLive ? 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800' : 'bg-amber-50 dark:bg-amber-900/20 border-amber-200 dark:border-amber-800'">
                        <p class="font-bold text-sm" :class="isLive ? 'text-green-700 dark:text-green-400' : 'text-amber-700 dark:text-amber-400'">
                            {{ isLive ? '✅ البوت يعمل ويستقبل الرسائل' : '⚠️ البوت غير جاهز للعمل' }}
                        </p>
                        <ul v-if="!isLive" class="mt-2 space-y-1 text-xs text-amber-800 dark:text-amber-300 list-disc pr-5">
                            <li v-for="(m, idx) in missing" :key="idx">{{ m }}</li>
                        </ul>
                    </div>

                    <label class="flex items-start gap-3 p-4 mb-3 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 cursor-pointer"
                           :class="isDirty('enabled') ? 'ring-1 ring-gold-400' : ''">
                        <span class="relative inline-flex items-center shrink-0 mt-0.5">
                            <input v-model="form.enabled" type="checkbox" class="sr-only peer"/>
                            <span class="block w-11 h-6 rounded-full bg-gray-300 dark:bg-gray-600 peer-checked:bg-green-500 transition-colors peer-focus-visible:ring-2 peer-focus-visible:ring-gold-500"></span>
                            <span class="absolute top-0.5 right-0.5 w-5 h-5 rounded-full bg-white shadow transition-transform peer-checked:-translate-x-5"></span>
                        </span>
                        <span class="min-w-0">
                            <span class="block text-sm font-bold text-gray-800 dark:text-gray-100">تشغيل البوت</span>
                            <span class="block text-xs text-gray-500 mt-0.5">عند الإيقاف تُستقبل الرسائل وتُحفظ في صندوق الوارد لكن لا يردّ البوت.</span>
                        </span>
                    </label>

                    <KillSwitchPanel :state="killSwitch" :can="can" @changed="(v) => { killSwitch = v; refreshVersion(); }"/>
                </div>

                <!-- ================= صحة النظام والقناة ================= -->
                <MediaArchivePanel v-if="props.mediaArchive" :summary="props.mediaArchive"/>
                <WebhookIntakePanel v-if="props.webhookIntake" :summary="props.webhookIntake"/>

                <div id="ops" class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5 md:p-6 scroll-mt-20">
                    <div class="flex items-center justify-between gap-3 mb-4 flex-wrap">
                        <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100 flex items-center gap-2">🩺 صحة النظام والقناة</h3>
                        <button type="button" @click="checkMeta" :disabled="metaChecking || !meta.ready"
                                class="px-4 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm font-bold text-gray-700 dark:text-gray-200 hover:bg-gold-50 dark:hover:bg-gold-900/20 disabled:opacity-40 disabled:cursor-not-allowed">
                            {{ metaChecking ? '⏳ جارٍ الفحص…' : '🔄 افحص الآن' }}
                        </button>
                    </div>
                    <p v-if="metaMsg" role="status" class="text-xs mb-3" :class="metaMsgOk ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400'">{{ metaMsg }}</p>

                    <!-- النظام -->
                    <p class="text-sm font-bold text-gray-700 dark:text-gray-200 mb-2">النظام</p>
                    <ul class="grid grid-cols-1 sm:grid-cols-2 gap-2 mb-1 text-sm">
                        <li v-for="c in opsComponents" :key="c.code" class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 dark:border-gray-700 px-3 py-2">
                            <span class="dark:text-gray-200">{{ c.label }}</span>
                            <span class="text-xs">
                                <span class="font-bold" :class="c.ok ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400'">{{ c.ok ? '🟢 يعمل' : '🔴 متوقف' }}</span>
                                <span v-if="c.age !== undefined" class="text-gray-500 dark:text-gray-400"> · {{ ageText(c.age) }}</span>
                            </span>
                        </li>
                    </ul>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">
                        مهام فاشلة خلال 24 ساعة: <span class="font-bold">{{ ops.failed_24h ?? '؟' }}</span>
                        <template v-if="!ops.enabled"> · فحص /up مطفأ (OPS_HEALTH_CHECKS)</template>
                        <span class="block mt-1" data-test="last-inbound">{{ lastInboundText }}</span>
                    </p>
                    <!-- فهرس البحث في الرسائل (E30): آخر فحص يومي لـ whatsapp:fts-check -->
                    <p v-if="ops.search_index" data-test="search-index" class="text-xs text-gray-500 dark:text-gray-400 -mt-3 mb-4">
                        فهرس البحث: <span class="font-bold" :class="searchIndex.cls">{{ searchIndex.label }}</span>
                        · آخر فحص: <bdi dir="ltr">{{ when(ops.search_index.at) }}</bdi>
                        <template v-if="ops.search_index.repaired_at"> · أُصلح في <bdi dir="ltr">{{ when(ops.search_index.repaired_at) }}</bdi></template>
                        <template v-if="ops.search_index.secure_delete === false"> · المحذوف يُمحى من الفهرس بإعادة كتابته</template>
                    </p>
                    <SecurityScanLine :scan="props.securityScan"/>

                    <!-- رمز ميتا -->
                    <p class="text-sm font-bold text-gray-700 dark:text-gray-200 mb-2">رمز ميتا</p>
                    <div class="rounded-lg border border-gray-200 dark:border-gray-700 px-3 py-2 text-sm mb-4 space-y-1">
                        <p v-if="!meta.ready" class="text-gray-500 dark:text-gray-400">غير مهيّأ — يلزم Access Token وApp Secret وApp ID (في «ربط قناة ميتا»).</p>
                        <p v-else-if="!meta.credentials" class="text-gray-500 dark:text-gray-400">لم يُفحص بعد{{ meta.enabled ? '' : ' (الفحوص المجدولة مطفأة: WA_META_CHECKS)' }}.</p>
                        <template v-else>
                            <p class="font-bold" :class="stateClass(meta.credentials.state)">{{ stateLabel(meta.credentials.state) }}
                                <span v-if="meta.credentials.reasons?.length" class="font-normal"> — {{ meta.credentials.reasons.map(reasonLabel).join('، ') }}</span></p>
                            <p v-if="meta.credentials.state !== 'not_configured'" class="text-xs text-gray-600 dark:text-gray-400">
                                الانتهاء: <bdi dir="ltr">{{ meta.credentials.never_expires ? 'لا ينتهي' : (meta.credentials.expires_at || '—') }}</bdi>
                                <template v-if="meta.credentials.days_left != null"> (بعد {{ meta.credentials.days_left }} يوماً)</template>
                                <template v-if="meta.credentials.missing_scopes?.length"> · ناقص: <bdi dir="ltr" class="font-mono">{{ meta.credentials.missing_scopes.join(', ') }}</bdi></template>
                                <template v-if="meta.credentials.app_matches === false"> · الرمز من تطبيق آخر</template>
                            </p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">آخر فحص: <bdi dir="ltr">{{ when(meta.credentials.checked_at) }}</bdi></p>
                        </template>
                    </div>

                    <!-- صحة القناة -->
                    <p class="text-sm font-bold text-gray-700 dark:text-gray-200 mb-2">صحة القناة عند ميتا</p>
                    <div class="rounded-lg border border-gray-200 dark:border-gray-700 px-3 py-2 text-sm space-y-2">
                        <p v-if="!meta.channel" class="text-gray-500 dark:text-gray-400">لم تُفحص بعد.</p>
                        <template v-else>
                            <p class="font-bold" :class="stateClass(meta.channel.state)">{{ stateLabel(meta.channel.state) }}
                                <span v-if="meta.channel.can_send_message" class="font-mono font-normal" dir="ltr"> {{ meta.channel.can_send_message }}</span>
                                <span v-if="meta.channel.quality_rating" class="font-normal"> · الجودة: <bdi dir="ltr">{{ meta.channel.quality_rating }}</bdi></span></p>
                            <ul v-if="meta.channel.entities?.length" class="space-y-1.5">
                                <li v-for="e in meta.channel.entities" :key="e.entity_type" class="text-xs">
                                    <span class="font-mono" dir="ltr">{{ e.entity_type }}</span>:
                                    <span class="font-bold" :class="e.can_send_message === 'AVAILABLE' ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400'" dir="ltr">{{ e.can_send_message }}</span>
                                    <p v-for="(err, i) in e.errors" :key="i" class="text-gray-600 dark:text-gray-400 mt-0.5" dir="ltr">
                                        {{ err.error_description }}<template v-if="err.possible_solution"> — {{ err.possible_solution }}</template>
                                    </p>
                                    <p v-for="(info, i) in e.additional_info" :key="'i' + i" class="text-gray-600 dark:text-gray-400 mt-0.5" dir="ltr">{{ info }}</p>
                                </li>
                            </ul>
                            <p class="text-xs text-gray-500 dark:text-gray-400">آخر فحص: <bdi dir="ltr">{{ when(meta.channel.checked_at) }}</bdi></p>
                        </template>
                        <p v-if="meta.last_account_error" class="text-xs font-bold text-red-700 dark:text-red-400">
                            آخر خطأ اعتماد عند الإرسال: <bdi dir="ltr">[{{ meta.last_account_error.code }}]</bdi> — <bdi dir="ltr">{{ when(meta.last_account_error.at * 1000) }}</bdi>
                        </p>
                    </div>

                    <!-- صحة القناة كآلة حالات (E22): الحالة والسبب والانتهاء، واقتراح الرفع والرفع اليدوي، وسجل أحداث ميتا -->
                    <ChannelHealthCard v-if="channelHealth" :health="channelHealth" @changed="(v) => { channelHealth = v; }"/>

                    <!-- آخر خطأ من ميتا على مستوى القناة، وفئات اليوم، ونسبة إثبات سر التطبيق (E18) -->
                    <MetaLastErrorCard :last="metaErrors.last" :today="metaErrors.today" :proof="metaErrors.proof" @cleared="metaErrors.last = null"/>
                </div>

                <!-- ================= 2. ربط قناة ميتا ================= -->
                <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5 md:p-6">
                    <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-4 flex items-center gap-2">🔗 ربط قناة ميتا</h3>

                    <div class="mb-5">
                        <label for="wa-webhook" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">رابط الـ Webhook (انسخه إلى لوحة Meta)</label>
                        <div class="flex flex-col sm:flex-row gap-2">
                            <input id="wa-webhook" :value="webhookUrl" readonly dir="ltr"
                                   class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-sm font-mono text-gray-700 dark:text-gray-200 focus:outline-none"/>
                            <button type="button" @click="copyWebhook"
                                    class="shrink-0 px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm font-bold text-gray-700 dark:text-gray-200 hover:bg-gold-50 dark:hover:bg-gold-900/20">
                                {{ copied ? '✅ تم النسخ' : '📋 نسخ' }}
                            </button>
                        </div>
                        <p v-if="copyFailed" class="text-xs text-amber-600 dark:text-amber-400 mt-1">تعذّر النسخ تلقائيًا — حدّد الرابط وانسخه يدويًا.</p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <div class="flex items-center gap-2 mb-1 flex-wrap">
                                <label for="wa-pnid" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Phone Number ID</label>
                                <SourceBadge :source="sources.wa_phone_number_id"/>
                            </div>
                            <input id="wa-pnid" v-model="form.wa_phone_number_id" dir="ltr" inputmode="numeric" placeholder="مثال: 123456789012345"
                                   :disabled="sources.wa_phone_number_id?.source === 'env'"
                                   class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm font-mono focus:ring-2 focus:ring-gold-500 focus:outline-none dark:text-white disabled:opacity-40"
                                   :class="isDirty('wa_phone_number_id') ? 'ring-1 ring-gold-400' : ''"/>
                            <p v-if="errors.wa_phone_number_id" class="text-xs text-red-600 dark:text-red-400 mt-1">{{ errors.wa_phone_number_id }}</p>
                            <p v-else class="text-xs text-gray-500 mt-1">معرّف رقم الواتساب عند Meta. تغييره يحتاج تأكيداً ويُفحص عند ميتا قبل الحفظ: معرّف خاطئ يُسقط كل الوارد.</p>
                        </div>

                        <div>
                            <div class="flex items-center gap-2 mb-1 flex-wrap">
                                <label for="wa-waba" class="block text-sm font-medium text-gray-700 dark:text-gray-300">معرّف حساب الأعمال (WABA)</label>
                                <SourceBadge :source="sources.wa_waba_id"/>
                            </div>
                            <input id="wa-waba" v-model="form.wa_waba_id" dir="ltr" inputmode="numeric" :placeholder="settings.wa_waba_detected ? 'المكتشف: ' + settings.wa_waba_detected : 'من WhatsApp Manager'"
                                   :disabled="sources.wa_waba_id?.source === 'env'"
                                   class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm font-mono focus:ring-2 focus:ring-gold-500 focus:outline-none dark:text-white disabled:opacity-40"
                                   :class="isDirty('wa_waba_id') ? 'ring-1 ring-gold-400' : ''"/>
                            <p v-if="errors.wa_waba_id" class="text-xs text-red-600 dark:text-red-400 mt-1">{{ errors.wa_waba_id }}</p>
                            <p v-else-if="wabaMismatch" class="text-xs font-bold text-red-700 dark:text-red-400 mt-1" role="alert">
                                ⚠️ المضبوط لا يطابق المكتشف من ميتا (<bdi dir="ltr" class="font-mono">{{ settings.wa_waba_detected }}</bdi>) — صحّحه قبل تفعيل فرض السياج.
                            </p>
                            <p v-else class="text-xs text-gray-500 mt-1">به يرفض سياج الويبهوك إشعارات حساب آخر (عند تفعيل الفرض). اتركه فارغاً حتى تقارنه بالمكتشف أدناه.</p>
                            <dl class="mt-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/60 px-4 py-2.5 text-sm space-y-1">
                                <div class="flex items-center justify-between gap-3">
                                    <dt class="text-gray-500 dark:text-gray-400">المكتشف من ميتا</dt>
                                    <dd class="font-mono dark:text-white" dir="ltr">{{ settings.wa_waba_detected || '—' }}</dd>
                                </div>
                                <div class="flex items-center justify-between gap-3">
                                    <dt class="text-gray-500 dark:text-gray-400">الرقم المعروض</dt>
                                    <dd class="font-mono dark:text-white" dir="ltr">{{ settings.wa_display_phone || '—' }}</dd>
                                </div>
                            </dl>
                        </div>

                        <!-- رمز التحقق: يُولَّد ولا يُكتب يدوياً، ويُعرض مرة واحدة -->
                        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 p-4">
                            <div class="flex items-center gap-2 flex-wrap">
                                <p class="text-sm font-bold text-gray-800 dark:text-gray-100">Verify Token</p>
                                <SourceBadge :source="{ source: verify.source, env_var: 'WA_WEBHOOK_VERIFY_TOKEN' }"/>
                            </div>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                آخر توليد: <bdi dir="ltr">{{ when(verify.rotated_at) }}</bdi> · آخر مصافحة ناجحة: <bdi dir="ltr">{{ when(verify.last_handshake_at) }}</bdi>
                            </p>
                            <p v-if="verify.legacy_plain && verify.source !== 'env'" class="text-xs text-amber-700 dark:text-amber-400 mt-1">رمز قديم مكتوب يدوياً ما زال يعمل؛ يُستحسن توليد رمز عشوائي.</p>
                            <button v-if="verify.source !== 'env'" type="button" @click="rotateVerify" :disabled="rotating"
                                    class="mt-3 px-3 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-xs font-bold text-gray-700 dark:text-gray-200 hover:bg-gold-50 dark:hover:bg-gold-900/20 disabled:opacity-40">
                                {{ rotating ? '⏳' : '🎲 توليد رمز جديد' }}
                            </button>
                            <p v-else class="text-xs text-blue-700 dark:text-blue-300 mt-2">مضبوط من بيئة الخادم.</p>
                            <p v-if="verifyError" class="text-xs text-red-600 dark:text-red-400 mt-1">{{ verifyError }}</p>
                        </div>

                        <div>
                            <div class="flex items-center gap-2 mb-1 flex-wrap">
                                <label for="wa-app-id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">App ID</label>
                                <SourceBadge :source="sources.wa_app_id"/>
                            </div>
                            <input id="wa-app-id" v-model="form.wa_app_id" type="text" dir="ltr" inputmode="numeric" autocomplete="off"
                                   :disabled="sources.wa_app_id?.source === 'env'" placeholder="من App settings › Basic"
                                   class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm font-mono focus:ring-2 focus:ring-gold-500 focus:outline-none dark:text-white disabled:opacity-40"
                                   :class="isDirty('wa_app_id') ? 'ring-1 ring-gold-400' : ''"/>
                            <p v-if="errors.wa_app_id" class="text-xs text-red-600 dark:text-red-400 mt-1">{{ errors.wa_app_id }}</p>
                            <p v-else class="text-xs text-gray-500 mt-1">معرّف عام لا سري. به يُفحص رمز الوصول يومياً (صلاحيته وانتهاؤه).</p>
                        </div>

                        <SecretCard name="wa_token" :secret="secrets.wa_token" @changed="(v) => { secrets.wa_token = v; refreshVersion(); }">
                            <template #hint><span class="block">توكن مستخدم النظام الدائم لإرسال الرسائل. بعد تغييره: «🔄 افحص الآن» في صحة القناة.</span></template>
                        </SecretCard>
                        <SecretCard name="wa_app_secret" :secret="secrets.wa_app_secret" warn-clear="بلا App Secret يُرفض كل وارد من ميتا (لا يُتحقق من التوقيع)."
                                    @changed="(v) => { secrets.wa_app_secret = v; refreshVersion(); }">
                            <template #hint>
                                <span class="block">آخر توقيع صحيح: <bdi dir="ltr">{{ when(signature.last_ok_at) }}</bdi> · مرفوض هذه الساعة: <b>{{ signature.failures_last_hour }}</b></span>
                            </template>
                        </SecretCard>
                    </div>

                    <!-- الإصدارات والحماية (للقراءة فقط: تُضبط من بيئة الخادم) -->
                    <div v-if="graph" class="mt-4 rounded-xl border p-4 text-sm"
                         :class="graphAlert === 'red' ? 'border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20'
                               : graphAlert === 'amber' ? 'border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20'
                               : 'border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40'">
                        <p class="font-bold text-gray-800 dark:text-gray-100 mb-2">🧭 الإصدارات والحماية</p>
                        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1.5">
                            <div class="flex items-center justify-between gap-3">
                                <dt class="text-gray-500 dark:text-gray-400">إصدار الطلبات</dt>
                                <dd class="dark:text-white">
                                    <span class="font-mono" dir="ltr">{{ graph.requests.version || '—' }}</span>
                                    <span v-if="graph.requests.sunset" class="text-xs text-gray-500"> · ينتهي <bdi dir="ltr">{{ graph.requests.sunset }}</bdi> (بعد {{ graph.requests.days_left }} يوماً)</span>
                                </dd>
                            </div>
                            <div class="flex items-center justify-between gap-3">
                                <dt class="text-gray-500 dark:text-gray-400">إصدار الويبهوك المسجّل</dt>
                                <dd class="dark:text-white">
                                    <span class="font-mono" dir="ltr">{{ graph.webhook.version || 'غير مضبوط' }}</span>
                                    <span v-if="graph.webhook.sunset" class="text-xs text-gray-500"> · ينتهي <bdi dir="ltr">{{ graph.webhook.sunset }}</bdi></span>
                                </dd>
                            </div>
                            <div class="flex items-center justify-between gap-3">
                                <dt class="text-gray-500 dark:text-gray-400">appsecret_proof</dt>
                                <dd class="dark:text-white">{{ graph.appsecret_proof ? 'مُرسل' : 'غير مُرسل' }}</dd>
                            </div>
                            <div class="flex items-center justify-between gap-3">
                                <dt class="text-gray-500 dark:text-gray-400">فحص مضيف الوسائط</dt>
                                <dd class="dark:text-white">{{ graph.media_host_mode === 'enforce' ? 'رفض' : 'تسجيل' }}</dd>
                            </div>
                        </dl>
                        <p v-if="graphAlert === 'red'" class="text-xs font-bold text-red-700 dark:text-red-400 mt-2">⛔ إصدار منتهٍ أو غير معروف أو غير مضبوط — رقِّ الإصدار.</p>
                        <p v-else-if="graphAlert === 'amber'" class="text-xs font-bold text-amber-700 dark:text-amber-400 mt-2">⚠️ إصدار يقترب من نهاية دعمه — رقِّه قبل التاريخ.</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">يُغيَّر من بيئة الخادم (WA_GRAPH_VERSION وWA_WEBHOOK_VERSION)، لا من هنا.</p>
                    </div>

                    <!-- إشعار القراءة ومؤشر الكتابة (E35) -->
                    <ReadReceiptsField class="mt-4" v-model="form.send_read_receipts" :flag-on="readReceiptsFlagOn"
                                       :dirty="isDirty('send_read_receipts')" :error="errors.send_read_receipts"/>
                </div>

                <!-- ================= 3. الذكاء ================= -->
                <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5 md:p-6">
                    <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-4 flex items-center gap-2">🧠 الذكاء</h3>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label for="wa-provider" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">المزوّد</label>
                            <select id="wa-provider" v-model="form.provider" @change="models = []; modelsMsg = ''"
                                    class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none dark:text-white"
                                    :class="isDirty('provider') ? 'ring-1 ring-gold-400' : ''">
                                <option v-for="(label, key) in providers" :key="key" :value="key">{{ label }}</option>
                            </select>
                            <p class="text-xs text-gray-500 mt-1">تغيير المزوّد يحتاج تأكيداً ومفتاحه الخاص.</p>
                        </div>

                        <div>
                            <label for="wa-model" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">الموديل</label>
                            <select v-if="models.length" id="wa-model" v-model="form.model"
                                    class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none dark:text-white">
                                <option v-for="m in models" :key="m.id" :value="m.id">{{ m.id }}<span v-if="m.label && m.label !== m.id"> — {{ m.label }}</span></option>
                            </select>
                            <input v-else id="wa-model" v-model="form.model" dir="ltr"
                                   class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm font-mono focus:ring-2 focus:ring-gold-500 focus:outline-none dark:text-white"
                                   :class="isDirty('model') ? 'ring-1 ring-gold-400' : ''"/>
                            <button type="button" @click="fetchModels" :disabled="loadingModels"
                                    class="mt-1.5 px-3 py-1.5 rounded-lg text-xs font-bold border border-gold-400 text-gold-700 dark:text-gold-400 hover:bg-gold-50 dark:hover:bg-gold-900/20 disabled:opacity-50">
                                {{ loadingModels ? '⏳ جاري الفحص…' : '🔄 فحص المفتاح المحفوظ وجلب النماذج' }}
                            </button>
                            <p v-if="errors.model" class="text-xs text-red-600 dark:text-red-400 mt-1">{{ errors.model }}</p>
                            <p v-else-if="modelsMsg" class="text-xs mt-1" :class="modelsOk ? 'text-green-600' : 'text-red-600'">{{ modelsMsg }}</p>
                        </div>

                        <SecretCard name="api_key" :secret="secrets.api_key" @changed="(v) => { secrets.api_key = v; refreshVersion(); }">
                            <template #hint><span class="block">مفتاح {{ form.provider === 'google' ? 'Google AI Studio' : 'Anthropic' }}.</span></template>
                        </SecretCard>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-4 mt-4">
                        <SettingField v-for="f in fields" :key="f.key" :field="f" v-model="form[f.key]"
                                      :default-value="defaults[f.key]" :error="errors[f.key]" :dirty="isDirty(f.key)"/>
                    </div>

                    <!-- مزوّد احتياطي (E12): معطّل حتى يُضبط، ويُستعمل حين يكون قاطع الأساسي مفتوحاً -->
                    <details class="mt-5 rounded-xl border border-gray-200 dark:border-gray-700 p-4" :open="!!form.fallback_provider">
                        <summary class="cursor-pointer text-sm font-bold text-gray-800 dark:text-gray-100">🛟 مزوّد احتياطي (اختياري)</summary>
                        <p class="text-xs text-amber-700 dark:text-amber-400 mt-2">تفعيل مزوّد مختلف يرسل نص المحادثة لجهة ثانية، ويُذكر اسمه تلقائياً في صفحة الخصوصية.</p>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-3">
                            <div>
                                <label for="wa-fallback-provider" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">المزوّد</label>
                                <select id="wa-fallback-provider" v-model="form.fallback_provider"
                                        class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm dark:text-white"
                                        :class="isDirty('fallback_provider') ? 'ring-1 ring-gold-400' : ''">
                                    <option :value="null">— بلا احتياطي</option>
                                    <option v-for="(label, key) in providers" :key="key" :value="key">{{ label }}</option>
                                </select>
                                <p v-if="errors.fallback_provider" class="text-xs text-red-600 dark:text-red-400 mt-1">{{ errors.fallback_provider }}</p>
                            </div>
                            <div>
                                <label for="wa-fallback-model" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">النموذج</label>
                                <input id="wa-fallback-model" v-model="form.fallback_model" dir="ltr" :disabled="!form.fallback_provider"
                                       class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm font-mono dark:text-white disabled:opacity-50"
                                       :class="isDirty('fallback_model') ? 'ring-1 ring-gold-400' : ''"/>
                                <p v-if="errors.fallback_model" class="text-xs text-red-600 dark:text-red-400 mt-1">{{ errors.fallback_model }}</p>
                                <p v-else class="text-xs text-gray-500 mt-1">نموذج Gemini آخر بالمفتاح نفسه لا يضيف جهة جديدة، لكنه لا ينفع إن انتهت الحصة.</p>
                            </div>
                            <SecretCard name="fallback_api_key" :secret="secrets.fallback_api_key" @changed="(v) => { secrets.fallback_api_key = v; refreshVersion(); }">
                                <template #hint><span class="block">يُترك فارغاً إن كان مفتاح مزوّده في البيئة.</span></template>
                            </SecretCard>
                        </div>
                    </details>

                    <!-- تأكيد الطبقة المدفوعة (E29): شرط ترجمة الرسائل للموظف -->
                    <LlmTermsCard class="mt-5" :terms="llmTerms" @changed="refreshVersion"/>
                </div>

                <!-- ================= 4. الشخصية وقاعدة المعرفة ================= -->
                <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5 md:p-6">
                    <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-4 flex items-center gap-2">🎭 الشخصية وقاعدة المعرفة</h3>

                    <div class="mb-6">
                        <div class="flex items-center justify-between gap-3 mb-1 flex-wrap">
                            <label for="wa-prompt" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                نص الشخصية (System Prompt)
                                <span v-if="form.system_prompt === null" class="mr-2 px-2 py-0.5 rounded-full text-xs font-bold bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300">النص الافتراضي من النظام — يتحدّث تلقائياً</span>
                            </label>
                            <button v-if="form.system_prompt !== null" type="button" @click="form.system_prompt = null"
                                    class="px-3 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-xs font-bold text-gray-700 dark:text-gray-200 hover:bg-gold-50 dark:hover:bg-gold-900/20">
                                ↺ استعادة النص الافتراضي
                            </button>
                        </div>
                        <textarea id="wa-prompt" v-model="promptText" rows="14"
                                  class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm leading-relaxed font-mono focus:ring-2 focus:ring-gold-500 focus:outline-none dark:text-white resize-y"
                                  :class="isDirty('system_prompt') ? 'ring-1 ring-gold-400' : ''"></textarea>
                        <p v-if="errors.system_prompt" class="text-xs text-red-600 dark:text-red-400 mt-1">{{ errors.system_prompt }}</p>
                        <p v-else class="text-xs text-gray-500 mt-1"><span class="font-mono" dir="ltr">{{ promptText.length }}</span> حرف — يحدّد نبرة البوت وحدوده وما يُمنع عليه قوله.</p>
                    </div>

                    <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 p-4 flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm font-bold text-gray-800 dark:text-gray-100">📚 قاعدة المعرفة</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                صارت معلومات مستقلة تُضاف وتُعدّل وتُحذف من صفحتها، أو من واتساب عبر أرقام الإدارة.
                                <span v-if="settings.kb_entries !== undefined"><span class="font-mono" dir="ltr">{{ settings.kb_entries }}</span> معلومة فعّالة · <span class="font-mono" dir="ltr">{{ settings.kb_chars }}</span> حرف.</span>
                            </p>
                        </div>
                        <a href="/whatsapp/knowledge" class="shrink-0 px-4 py-2 rounded-xl text-sm font-bold text-gold-800 dark:text-gold-200 bg-gold-100 dark:bg-gold-900/30 hover:bg-gold-200 dark:hover:bg-gold-900/50">إدارة قاعدة المعرفة ←</a>
                    </div>
                </div>

                <!-- ================= 5. الأدوات ================= -->
                <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5 md:p-6">
                    <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-1 flex items-center gap-2">🧰 الأدوات</h3>
                    <p class="text-xs text-gray-500 mb-4">فعّل ما تسمح للبوت باستخدامه. إلغاء أداة يمنع البوت من استعمالها نهائيًا.</p>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <label v-for="(label, key) in toolList" :key="key"
                               class="flex items-start gap-3 p-3 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 hover:bg-gold-50 dark:hover:bg-gold-900/20"
                               :class="[toolDirty(key) ? 'ring-1 ring-gold-400' : '', lockedTools.includes(key) ? 'cursor-not-allowed' : 'cursor-pointer']"
                               :title="lockedTools.includes(key) ? 'لا تُطفأ — سياسة واتساب تشترط مسار تحويل بشري دائماً' : ''">
                            <input v-if="lockedTools.includes(key)" type="checkbox" checked disabled class="mt-0.5 w-4 h-4 rounded text-gold-500 shrink-0 opacity-60"
                                   :aria-label="label + ' (لا تُطفأ)'"/>
                            <input v-else type="checkbox" v-model="form.tools[key]" class="mt-0.5 w-4 h-4 rounded text-gold-500 focus:ring-gold-500 shrink-0"/>
                            <span class="min-w-0">
                                <span class="flex items-center gap-2 flex-wrap">
                                    <span class="text-sm font-medium text-gray-800 dark:text-gray-100">{{ label }}</span>
                                    <span v-if="handoffTools.includes(key)" class="px-2 py-0.5 rounded-full text-xs font-bold bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">يحوّل لموظف</span>
                                    <span v-if="lockedTools.includes(key)" class="px-2 py-0.5 rounded-full text-xs font-bold bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200">🔒 لا تُطفأ</span>
                                </span>
                                <span class="block text-xs font-mono text-gray-500 dark:text-gray-400 mt-0.5" dir="ltr">{{ key }}</span>
                            </span>
                        </label>
                    </div>
                </div>

                <!-- ================= 6. الفشل الآمن ================= -->
                <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5 md:p-6">
                    <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-4 flex items-center gap-2">🛟 الفشل الآمن</h3>
                    <div>
                        <label for="wa-fail" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">رسالة الاعتذار عند فشل البوت</label>
                        <textarea id="wa-fail" v-model="form.fail_message" rows="2"
                                  class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none dark:text-white resize-none"
                                  :class="isDirty('fail_message') ? 'ring-1 ring-gold-400' : ''"></textarea>
                        <p v-if="errors.fail_message" class="text-xs text-red-600 dark:text-red-400 mt-1">{{ errors.fail_message }}</p>
                        <p v-else class="text-xs text-gray-500 mt-1">تُرسل للعميل إذا تعذّر توليد رد (انقطاع API أو خطأ غير متوقع).</p>
                    </div>
                    <label class="flex items-start gap-3 mt-4 p-4 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 cursor-pointer"
                           :class="isDirty('fail_handoff') ? 'ring-1 ring-gold-400' : ''">
                        <input type="checkbox" v-model="form.fail_handoff" class="mt-0.5 w-4 h-4 rounded text-gold-500 focus:ring-gold-500 shrink-0"/>
                        <span class="min-w-0">
                            <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">عند الفشل: أوقف البوت ساعة وأشعر الإدارة</span>
                            <span class="block text-xs text-gray-500 mt-0.5">يمنع تكرار الأخطاء على كل العملاء ويُبقي الأمر تحت أعين الموظفين.</span>
                        </span>
                    </label>

                    <!-- قاطع مزوّد الذكاء (E12) -->
                    <div id="breaker" class="mt-6 scroll-mt-20">
                        <h4 class="text-base font-bold text-gray-800 dark:text-gray-100 mb-1">🔌 قاطع مزوّد الذكاء</h4>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">إن تعطّل المزوّد: يُحوَّل كل عميل لموظف برسالة ثابتة بدل رسالة الخلل، ويصل المدراء إشعار عند البدء وعند العودة فقط.</p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
                            <div>
                                <label for="wa-breaker-mode" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ LABELS.breaker_mode }}</label>
                                <select id="wa-breaker-mode" v-model="form.breaker_mode"
                                        class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm dark:text-white"
                                        :class="isDirty('breaker_mode') ? 'ring-1 ring-gold-400' : ''">
                                    <option value="off">إيقاف (السلوك القديم)</option>
                                    <option value="shadow">مراقبة فقط (يسجّل ولا يحوّل)</option>
                                    <option value="on">مفعّل</option>
                                </select>
                            </div>
                            <div v-for="k in ['breaker_threshold', 'breaker_window_minutes', 'breaker_open_minutes']" :key="k">
                                <label :for="`wa-${k}`" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ LABELS[k] }}</label>
                                <input :id="`wa-${k}`" v-model.number="form[k]" type="number" dir="ltr"
                                       class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm dark:text-white"
                                       :class="isDirty(k) ? 'ring-1 ring-gold-400' : ''"/>
                                <p v-if="errors[k]" class="text-xs text-red-600 dark:text-red-400 mt-1">{{ errors[k] }}</p>
                            </div>
                        </div>
                        <BreakerPanel :breaker="breaker" @changed="(v) => { breaker = v; }"/>
                    </div>
                </div>

                <IdentityModePanel :mode="identityMode"/>

                <FeatureFlagsPanel id="flags" class="scroll-mt-20" :flags="flags"/>

                <!-- ================= شريط الحفظ ================= -->
                <div class="sticky bottom-0 z-10 -mx-1 px-1 py-3">
                    <div class="rounded-2xl border border-gray-200 dark:border-gray-700 bg-white/95 dark:bg-gray-900/95 backdrop-blur shadow-md p-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <p class="text-xs" :class="dirtyCount ? 'font-bold text-gold-700 dark:text-gold-400' : 'text-gray-500 dark:text-gray-400'">
                            <template v-if="conflict.fields.length || conflict.others.length">⚠️ تغيّرت الإعدادات عند غيرك — راجع الشريط أعلى الصفحة ثم احفظ · </template>
                            {{ savedMsg || (dirtyCount ? `${dirtyCount} تغييرات غير محفوظة` : 'لا تغييرات') }}
                        </p>
                        <button type="submit" :disabled="saving || !dirtyCount"
                                class="w-full sm:w-auto px-8 py-3 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md disabled:opacity-50 disabled:cursor-not-allowed">
                            {{ saving ? '⏳ جارٍ الحفظ…' : '💾 حفظ التغييرات' }}
                        </button>
                    </div>
                </div>
            </form>

            <!-- ملف واتساب للأعمال (E31): خارج النموذج، بأزراره ونداءاته -->
            <BusinessProfileCard/>

            <!-- روابط ورموز QR عامة (E38): خلف العلم wa_qr_links -->
            <QrGeneralPanel v-if="qrLinksOn"/>
        </div>

        <OneTimeSecretModal :token="oneTimeToken" @close="oneTimeToken = null"/>
    </AppLayout>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import ConflictBanner from '@/Components/WhatsApp/ConflictBanner.vue';
import FeatureFlagsPanel from '@/Components/WhatsApp/FeatureFlagsPanel.vue';
import MediaArchivePanel from '@/Components/WhatsApp/MediaArchivePanel.vue';
import WebhookIntakePanel from '@/Components/WhatsApp/WebhookIntakePanel.vue';
import MetaLastErrorCard from '@/Components/WhatsApp/MetaLastErrorCard.vue';
import SecurityScanLine from '@/Components/WhatsApp/SecurityScanLine.vue';
import ChannelHealthCard from '@/Components/WhatsApp/ChannelHealthCard.vue';
import BreakerPanel from '@/Components/WhatsApp/BreakerPanel.vue';
import BusinessProfileCard from '@/Components/WhatsApp/BusinessProfileCard.vue';
import QrGeneralPanel from '@/Components/WhatsApp/QrGeneralPanel.vue';
import IdentityModePanel from '@/Components/WhatsApp/IdentityModePanel.vue';
import KillSwitchPanel from '@/Components/WhatsApp/KillSwitchPanel.vue';
import LlmTermsCard from '@/Components/WhatsApp/LlmTermsCard.vue';
import OneTimeSecretModal from '@/Components/WhatsApp/OneTimeSecretModal.vue';
import ReadReceiptsField from '@/Components/WhatsApp/ReadReceiptsField.vue';
import SecretCard from '@/Components/WhatsApp/SecretCard.vue';
import SettingField from '@/Components/WhatsApp/SettingField.vue';
import SourceBadge from '@/Components/WhatsApp/SourceBadge.vue';
import { when } from '@/utils/waSettings.js';

// الحفظ جزئي (E10): يُرسل ما تغيّر عن آخر قيم محمّلة فقط مع رمز النسخة؛ 409 يعني أن غيرك حفظ بعدك،
// فتُجلب القيم الجديدة وتبقى تعديلاتك فوقها. الأسرار والإيقاف لها أزرارها ولا تمر من هنا.
const props = defineProps({
    title: String,
    settings: Object,
    version: Number,
    fields: { type: Array, default: () => [] },
    defaults: { type: Object, default: () => ({}) },
    sources: { type: Object, default: () => ({}) },
    secrets: { type: Object, default: () => ({}) },
    verify: { type: Object, default: () => ({}) },
    signature: { type: Object, default: () => ({}) },
    killSwitch: { type: Object, default: () => ({}) },
    flags: { type: Array, default: () => [] },
    breaker: { type: Object, default: () => ({}) },
    identityMode: { type: String, default: 'store' },
    can: { type: Object, default: () => ({}) },
    webhookUrl: String,
    defaultPrompt: String,
    toolList: Object,
    providers: { type: Object, default: () => ({}) },
    graph: Object,
    health: { type: Array, default: () => [] },
    ops: { type: Object, default: () => ({ codes: [], queues: {} }) },
    meta: { type: Object, default: () => ({}) },
    mediaArchive: { type: Object, default: null },
    webhookIntake: { type: Object, default: null },
    securityScan: { type: Object, default: null },
    metaErrors: { type: Object, default: () => ({ last: null, today: {}, proof: null }) },
    channelHealth: { type: Object, default: null },
    toolMeta: { type: Array, default: () => [] },
    // E29: تأكيد الطبقة المدفوعة لمزوّد الذكاء
    llmTerms: { type: Object, default: () => ({}) },
});

// ===== الحالة المحلية (تتحدّث من ردود الخادم دون إعادة تحميل الصفحة) =====
const settings = ref({ ...props.settings });
const version = ref(props.version);
const sources = ref({ ...props.sources });
const secrets = ref(JSON.parse(JSON.stringify(props.secrets)));
const verify = ref({ ...props.verify });
const signature = ref({ ...props.signature });
const killSwitch = ref({ ...props.killSwitch });
const flags = ref([...props.flags]);
const qrLinksOn = computed(() => flags.value.some((f) => f.key === 'wa_qr_links' && f.on));
const breaker = ref({ ...props.breaker });
watch(() => props.breaker, (v) => { breaker.value = { ...v }; });
watch(() => props.sources, (v) => { sources.value = { ...v }; });

const LABELS = {
    enabled: 'تشغيل البوت', wa_phone_number_id: 'Phone Number ID', wa_waba_id: 'معرّف حساب الأعمال', wa_app_id: 'App ID', provider: 'المزوّد', model: 'الموديل',
    system_prompt: 'نص الشخصية', fail_message: 'رسالة الاعتذار', fail_handoff: 'التحويل عند الفشل',
    breaker_mode: 'وضع القاطع', breaker_threshold: 'إخفاقات متتالية تفتحه', breaker_window_minutes: 'نافذة العدّ (دقائق)',
    breaker_open_minutes: 'مدة وضع التحويل (دقائق)', fallback_provider: 'المزوّد الاحتياطي', fallback_model: 'نموذج الاحتياطي',
    send_read_receipts: 'إشعار القراءة ومؤشر الكتابة',
};
props.fields.forEach((f) => { LABELS[f.key] = f.label; });
const SCALARS = ['enabled', 'wa_phone_number_id', 'wa_waba_id', 'wa_app_id', 'provider', 'model', 'system_prompt', ...props.fields.map((f) => f.key), 'fail_message', 'fail_handoff',
    'breaker_mode', 'breaker_threshold', 'breaker_window_minutes', 'breaker_open_minutes', 'fallback_provider', 'fallback_model', 'send_read_receipts'];
const SENSITIVE = ['wa_phone_number_id', 'wa_waba_id', 'provider', 'fallback_provider', 'send_read_receipts'];
// من سجل الأدوات في الخادم (E15): ما يحوّل لموظف، وما لا يُطفأ
const handoffTools = (props.toolMeta || []).filter((t) => t.escalates).map((t) => t.key);
const lockedTools = (props.toolMeta || []).filter((t) => !t.disableable).map((t) => t.key);

// القيم القابلة للحفظ من صف الإعدادات؛ الأدوات: غياب المفتاح = مفعّلة
const editable = (s) => {
    const out = {};
    SCALARS.forEach((k) => { out[k] = s[k] ?? null; });
    out.enabled = !!s.enabled;
    out.fail_handoff = !!s.fail_handoff;
    out.tools = {};
    Object.keys(props.toolList || {}).forEach((k) => {
        const v = (s.tools_config || {})[k];
        out.tools[k] = v === undefined ? true : !!v;
    });
    return out;
};
const clone = (v) => JSON.parse(JSON.stringify(v));
const baseline = ref(editable(props.settings));
const form = reactive(clone(baseline.value));

const norm = (v) => (v === '' ? null : v);
const isDirty = (k) => norm(form[k]) !== norm(baseline.value[k]);
// المضبوط (يدوياً أو من البيئة) يخالف ما تعلّمناه من حمولة رقمنا
const wabaMismatch = computed(() => {
    const set = sources.value.wa_waba_id?.source === 'env' ? props.webhookIntake?.fence?.waba : settings.value.wa_waba_id;
    return !!set && !!settings.value.wa_waba_detected && String(set) !== String(settings.value.wa_waba_detected);
});
const toolDirty = (k) => form.tools[k] !== baseline.value.tools[k];
// E35: العلم فوق الإعداد؛ مطفأ = لا يصل ميتا شيء مهما كان الوضع
const readReceiptsFlagOn = computed(() => !!flags.value.find((f) => f.key === 'read_receipts')?.on);
const changes = computed(() => {
    const c = {};
    SCALARS.forEach((k) => { if (isDirty(k)) c[k] = norm(form[k]); });
    const tools = {};
    Object.keys(form.tools).forEach((k) => { if (toolDirty(k)) tools[k] = form.tools[k]; });
    if (Object.keys(tools).length) c.tools_config = tools;
    return c;
});
const dirtyCount = computed(() => SCALARS.filter(isDirty).length + Object.keys(form.tools).filter(toolDirty).length);

// الموجّه: null = النص الافتراضي الحي، فلا يتجمّد في القاعدة إن لم يُعدَّل
const promptText = computed({
    get: () => form.system_prompt ?? props.defaultPrompt ?? '',
    set: (v) => { form.system_prompt = v === props.defaultPrompt ? null : v; },
});

// ===== الحفظ =====
const saving = ref(false);
const errors = ref({});
const savedMsg = ref('');
const conflict = ref({ fields: [], others: [] });

const adopt = (data) => {
    version.value = data.version;
    settings.value = { ...data.settings };
    if (data.sources) sources.value = { ...data.sources };
    if (data.secrets) secrets.value = clone(data.secrets);
    if (data.verify) verify.value = { ...data.verify };
    if (data.signature) signature.value = { ...data.signature };
    if (data.killSwitch) killSwitch.value = { ...data.killSwitch };
    if (data.flags) flags.value = [...data.flags];
    if (data.breaker) breaker.value = { ...data.breaker };
};

// تعارض أو تغيير من نفس الصفحة رفع النسخة: نجلب الحالي، ونأخذ ما غيّره غيرنا، ونُبقي تعديلاتنا فوقه
const reconcile = async () => {
    const { data } = await axios.get('/api/whatsapp/settings');
    const fresh = editable(data.settings);
    const mine = changes.value;
    const fields = [];
    const others = [];
    SCALARS.forEach((k) => {
        if (norm(fresh[k]) === norm(baseline.value[k])) return;
        if (k in mine) fields.push(LABELS[k]);
        else { others.push(LABELS[k]); form[k] = fresh[k]; }
    });
    Object.keys(fresh.tools).forEach((k) => {
        if (fresh.tools[k] === baseline.value.tools[k]) return;
        if (mine.tools_config && k in mine.tools_config) fields.push(props.toolList[k]);
        else { others.push(props.toolList[k]); form.tools[k] = fresh.tools[k]; }
    });
    baseline.value = fresh;
    adopt(data);
    if (fields.length || others.length) conflict.value = { fields, others };
};
const refreshVersion = () => reconcile().catch(() => {});

const save = async (confirmed = false) => {
    const c = changes.value;
    if (!Object.keys(c).length) return;
    const sensitive = Object.keys(c).filter((k) => SENSITIVE.includes(k));
    if (sensitive.length && !confirmed) {
        if (!window.confirm(`تغيير ${sensitive.map((k) => LABELS[k]).join(' و')} يؤثر على كل الرسائل. متابعة؟`)) return;
        confirmed = true;
    }
    saving.value = true;
    errors.value = {};
    savedMsg.value = '';
    try {
        const { data } = await axios.patch('/whatsapp/settings', { version: version.value, changes: c, confirm: confirmed });
        adopt(data);
        baseline.value = editable(data.settings);
        Object.assign(form, clone(baseline.value));
        conflict.value = { fields: [], others: [] };
        savedMsg.value = '✅ حُفظت التغييرات';
        setTimeout(() => { savedMsg.value = ''; }, 3000);
        router.reload({ only: ['sources', 'health', 'meta', 'breaker'] });
    } catch (e) {
        const status = e.response?.status;
        if (status === 422) {
            const out = {};
            Object.entries(e.response.data.errors || {}).forEach(([key, msgs]) => {
                out[key.replace(/^changes\./, '').replace(/\..*$/, '')] = msgs[0];
            });
            errors.value = out;
        } else if (status === 409) {
            await reconcile();
            // الشريط أعلى الصفحة والمستخدم عند زر الحفظ أسفلها
            window.scrollTo({ top: 0, behavior: 'smooth' });
        } else {
            errors.value = { general: e.response?.data?.message || 'تعذّر الحفظ — حاول مرة أخرى' };
        }
    } finally {
        saving.value = false;
    }
};

// تنبيه قبل مغادرة الصفحة بتعديلات غير محفوظة
const beforeUnload = (e) => { if (dirtyCount.value) { e.preventDefault(); e.returnValue = ''; } };
let removeNavGuard = null;
onMounted(() => {
    window.addEventListener('beforeunload', beforeUnload);
    removeNavGuard = router.on('before', (event) => {
        if (dirtyCount.value && event.detail.visit.method === 'get' && !window.confirm('لديك تغييرات غير محفوظة. مغادرة الصفحة؟')) return false;
    });
});
onBeforeUnmount(() => { window.removeEventListener('beforeunload', beforeUnload); removeNavGuard?.(); });

// ===== رمز التحقق: يُولَّد ويُعرض مرة واحدة =====
const oneTimeToken = ref(null);
const rotating = ref(false);
const verifyError = ref('');
const rotateVerify = async () => {
    if (!window.confirm('الرمز الجديد يُبطل الحالي عند المصافحة التالية. ولّده ثم حدّثه فوراً في لوحة ميتا؟')) return;
    rotating.value = true;
    verifyError.value = '';
    try {
        const { data } = await axios.post('/api/whatsapp/settings/verify-token', { reason: verify.value.has ? 'routine' : 'setup' });
        oneTimeToken.value = data.token;
        await refreshVersion();
    } catch (e) {
        verifyError.value = e.response?.data?.message || 'تعذّر التوليد';
    } finally {
        rotating.value = false;
    }
};

// ===== الجاهزية =====
const isLive = computed(() => !!(settings.value.enabled && secrets.value.wa_token?.has && secrets.value.api_key?.has && !killSwitch.value.effective));
const missing = computed(() => {
    const list = [];
    if (!settings.value.enabled) list.push('البوت مُعطَّل — شغّل مفتاح «تشغيل البوت» ثم احفظ.');
    if (!secrets.value.wa_token?.has) list.push('لا يوجد رمز ميتا (WA_TOKEN) — أدخله في «ربط قناة ميتا».');
    if (!secrets.value.api_key?.has) list.push('لا يوجد مفتاح للمزوّد — أدخله في قسم «الذكاء».');
    if (!settings.value.wa_phone_number_id && sources.value.wa_phone_number_id?.source !== 'env') list.push('لم يُضبط Phone Number ID — لن يُعرف الرقم المُرسِل.');
    if (killSwitch.value.effective) list.push('كل الإرسال موقوف (انظر الإيقاف الطارئ أدناه).');
    return list;
});

// ===== صحة النظام والقناة =====
const meta = ref({ ...props.meta });
const metaErrors = reactive({ ...props.metaErrors });
watch(() => props.metaErrors, (v) => Object.assign(metaErrors, v));
const metaChecking = ref(false);
const metaMsg = ref('');
const metaMsgOk = ref(false);
watch(() => props.meta, (v) => { meta.value = { ...v }; });
const channelHealth = ref(props.channelHealth);
watch(() => props.channelHealth, (v) => { channelHealth.value = v; });

const opsComponents = computed(() => {
    const codes = props.ops?.codes || [];
    const queues = props.ops?.queues || {};
    return [
        { code: 'db', label: 'القاعدة', ok: !codes.includes('db') },
        { code: 'schedule_stale', label: 'المجدول', ok: !codes.includes('schedule_stale'), age: props.ops?.schedule_age },
        { code: 'queue_stale', label: 'العامل الخلفي', ok: !codes.includes('queue_stale'), age: queues.default },
        { code: 'reply_queue_stale', label: 'عامل ردود البوت', ok: !codes.includes('reply_queue_stale'), age: queues['wa-replies'] },
        { code: 'backlog', label: 'الطابور', ok: !codes.includes('backlog') },
        ...((props.ops?.warnings || []).includes('media_backlog') ? [{ code: 'media_backlog', label: 'تأخر أرشفة المرفقات', ok: false }] : []),
    ];
});

// فهرس البحث في الرسائل (E30)
const SEARCH_INDEX = {
    ok: ['سليم', 'text-green-700 dark:text-green-400'],
    repaired: ['أُصلح آلياً', 'text-amber-700 dark:text-amber-400'],
    failed: ['خلل — whatsapp:fts-check --repair', 'text-red-700 dark:text-red-400'],
    unsupported: ['غير مدعوم في SQLite، والبحث بطريقة أبطأ', 'text-amber-700 dark:text-amber-400'],
};
const searchIndex = computed(() => {
    const [label, cls] = SEARCH_INDEX[props.ops?.search_index?.state] || [props.ops?.search_index?.state || '—', ''];
    return { label, cls };
});
// صمت الوارد (E37): دقائق منذ آخر حدث وصل من ميتا (ويبهوك أو رسالة واردة)
const lastInboundText = computed(() => {
    const minutes = props.ops?.last_inbound_minutes;
    if (minutes === null || minutes === undefined) return 'لم يصل من ميتا أي حدث بعد';
    return minutes < 1 ? 'آخر حدث من ميتا قبل أقل من دقيقة' : `آخر حدث من ميتا قبل ${minutes} دقيقة`;
});

const ageText = (seconds) => {
    if (seconds === null) return 'لا نبضة';
    return seconds < 60 ? 'آخر نبضة قبل أقل من دقيقة' : `آخر نبضة قبل ${Math.floor(seconds / 60)} د`;
};
const STATES = { ok: '✅ سليم', warn: '⚠️ تحذير', critical: '⛔ حرج', unreachable: '📡 تعذّر الوصول إلى ميتا', not_configured: '— غير مهيّأ' };
const stateLabel = (state) => STATES[state] || state;
const stateClass = (state) => ({
    'text-green-700 dark:text-green-400': state === 'ok',
    'text-amber-700 dark:text-amber-400': state === 'warn' || state === 'unreachable',
    'text-red-700 dark:text-red-400': state === 'critical',
    'text-gray-500 dark:text-gray-400': state === 'not_configured',
});
const REASONS = {
    token_invalid: 'الرمز غير صالح', app_credentials_invalid: 'معرّف التطبيق أو سرّه خطأ', missing_scopes: 'صلاحيات ناقصة',
    app_mismatch: 'الرمز من تطبيق آخر', expiring: 'ينتهي قريباً', blocked: 'الإرسال موقوف', limited: 'الإرسال مقيّد', quality_red: 'جودة حمراء',
};
const reasonLabel = (reason) => REASONS[reason] || reason;

const checkMeta = async () => {
    metaChecking.value = true;
    metaMsg.value = '';
    try {
        const { data } = await axios.post('/api/whatsapp/meta-check', {}, { headers: { Accept: 'application/json' } });
        meta.value = data.meta;
        if (data.channelHealth) channelHealth.value = data.channelHealth;
        metaMsgOk.value = true;
        metaMsg.value = '✅ تم الفحص';
    } catch (e) {
        metaMsgOk.value = false;
        metaMsg.value = e.response?.status === 429 ? '❌ فحوص كثيرة — انتظر دقيقة' : '❌ تعذّر الفحص';
    } finally {
        metaChecking.value = false;
    }
};

// أسوأ حالة بين إصدار الطلبات وإصدار الويبهوك
const graphAlert = computed(() => {
    const levels = [props.graph?.requests?.level, props.graph?.webhook?.level];
    if (levels.some((l) => l === 'expired' || l === 'unknown')) return 'red';
    return levels.includes('warn') ? 'amber' : null;
});

// جلب النماذج المتاحة حيّاً بالمفتاح المحفوظ — أسماء النماذج تتغيّر فلا نثبّتها
const models = ref([]);
const loadingModels = ref(false);
const modelsMsg = ref('');
const modelsOk = ref(false);
const fetchModels = async () => {
    loadingModels.value = true;
    modelsMsg.value = '';
    try {
        const { data } = await axios.post('/api/whatsapp/test-key', { provider: form.provider }, { headers: { Accept: 'application/json' } });
        models.value = data.models || [];
        modelsOk.value = true;
        modelsMsg.value = `✅ المفتاح صالح — ${data.count} نموذجاً متاحاً`;
        if (models.value.length && !models.value.some((m) => m.id === form.model)) form.model = models.value[0].id;
    } catch (e) {
        modelsOk.value = false;
        models.value = [];
        modelsMsg.value = '❌ ' + (e.response?.data?.error || 'تعذّر فحص المفتاح');
    } finally {
        loadingModels.value = false;
    }
};

const copied = ref(false);
const copyFailed = ref(false);
const copyWebhook = async () => {
    copyFailed.value = false;
    try {
        await navigator.clipboard.writeText(props.webhookUrl || '');
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 2000);
    } catch (e) {
        copyFailed.value = true;
    }
};
</script>
