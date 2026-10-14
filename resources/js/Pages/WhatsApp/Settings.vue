<template>
    <AppLayout>
        <template #header>إعدادات بوت الواتساب</template>

        <div class="space-y-6 pb-4">
            <!-- ===== رسائل Flash ===== -->
            <div v-if="$page.props.flash?.success" class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700">✅ {{ $page.props.flash.success }}</div>
            <div v-if="$page.props.flash?.error" class="p-4 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700">❌ {{ $page.props.flash.error }}</div>
            <div v-if="Object.keys(form.errors).length" class="p-4 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700">
                ❌ تحقّق من الحقول التالية:
                <ul class="list-disc mt-1 pr-5 space-y-0.5">
                    <li v-for="(msg, key) in form.errors" :key="key">{{ msg }}</li>
                </ul>
            </div>

            <form @submit.prevent="save" class="space-y-6">

                <!-- ================= 1. حالة التشغيل ================= -->
                <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5 md:p-6">
                    <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-4 flex items-center gap-2">🚦 حالة التشغيل</h3>

                    <!-- البانر -->
                    <div
                        class="rounded-xl border p-4 mb-5"
                        :class="isLive
                            ? 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800'
                            : 'bg-amber-50 dark:bg-amber-900/20 border-amber-200 dark:border-amber-800'"
                    >
                        <p class="font-bold text-sm" :class="isLive ? 'text-green-700 dark:text-green-400' : 'text-amber-700 dark:text-amber-400'">
                            <template v-if="isLive">✅ البوت يعمل ويستقبل الرسائل</template>
                            <template v-else>⚠️ البوت غير جاهز للعمل</template>
                        </p>
                        <ul v-if="!isLive" class="mt-2 space-y-1 text-xs text-amber-800 dark:text-amber-300 list-disc pr-5">
                            <li v-for="(m, idx) in missing" :key="idx">{{ m }}</li>
                        </ul>
                        <p v-if="settings.wa_suspended" class="mt-2 text-xs font-bold text-red-600 dark:text-red-400">
                            🛑 الإرسال موقوف حاليًا بمفتاح الإيقاف الطارئ — لن تُرسل أي رسالة حتى إلغاؤه.
                        </p>
                    </div>

                    <!-- المفاتيح -->
                    <div class="space-y-3">
                        <label class="flex items-start gap-3 p-4 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 cursor-pointer">
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

                        <label class="flex items-start gap-3 p-4 rounded-xl border cursor-pointer"
                               :class="form.wa_suspended
                                   ? 'border-red-300 dark:border-red-800 bg-red-50 dark:bg-red-900/20'
                                   : 'border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40'">
                            <span class="relative inline-flex items-center shrink-0 mt-0.5">
                                <input v-model="form.wa_suspended" type="checkbox" class="sr-only peer"/>
                                <span class="block w-11 h-6 rounded-full bg-gray-300 dark:bg-gray-600 peer-checked:bg-red-600 transition-colors peer-focus-visible:ring-2 peer-focus-visible:ring-red-500"></span>
                                <span class="absolute top-0.5 right-0.5 w-5 h-5 rounded-full bg-white shadow transition-transform peer-checked:-translate-x-5"></span>
                            </span>
                            <span class="min-w-0">
                                <span class="block text-sm font-bold text-red-700 dark:text-red-400">🛑 إيقاف كل الإرسال (طارئ)</span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400 mt-0.5">يمنع أي رسالة صادرة فورًا — للاستخدام عند وجود خلل أو تجاوز في الفواتير.</span>
                            </span>
                        </label>
                        <p v-if="form.errors.wa_suspended" class="px-1 text-xs font-bold text-red-600 dark:text-red-400">
                            {{ form.errors.wa_suspended }} —
                            <Link href="/settings/backups" class="underline">النسخ الاحتياطي</Link>
                        </p>
                    </div>
                </div>

                <!-- ================= 2. ربط قناة ميتا ================= -->
                <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5 md:p-6">
                    <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-4 flex items-center gap-2">🔗 ربط قناة ميتا</h3>

                    <!-- Webhook URL -->
                    <div class="mb-5">
                        <label for="wa-webhook" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">رابط الـ Webhook (انسخه إلى لوحة Meta)</label>
                        <div class="flex flex-col sm:flex-row gap-2">
                            <input
                                id="wa-webhook"
                                :value="webhookUrl"
                                readonly
                                dir="ltr"
                                class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-sm font-mono text-gray-700 dark:text-gray-200 focus:outline-none"
                            />
                            <button type="button" @click="copyWebhook"
                                    class="shrink-0 px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm font-bold text-gray-700 dark:text-gray-200 hover:bg-gold-50 dark:hover:bg-gold-900/20">
                                {{ copied ? '✅ تم النسخ' : '📋 نسخ' }}
                            </button>
                        </div>
                        <p v-if="copyFailed" class="text-xs text-amber-600 dark:text-amber-400 mt-1">تعذّر النسخ تلقائيًا — حدّد الرابط وانسخه يدويًا.</p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="wa-pnid" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Phone Number ID</label>
                            <input id="wa-pnid" v-model="form.wa_phone_number_id" dir="ltr" placeholder="مثال: 123456789012345"
                                   class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm font-mono focus:ring-2 focus:ring-gold-500 focus:outline-none dark:text-white"/>
                            <p class="text-xs text-gray-500 mt-1">معرّف رقم الواتساب في حساب WhatsApp Business على Meta.</p>
                        </div>

                        <div>
                            <p class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">المكتشف من ميتا</p>
                            <dl class="rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/60 px-4 py-2.5 text-sm space-y-1">
                                <div class="flex items-center justify-between gap-3">
                                    <dt class="text-gray-500 dark:text-gray-400">معرّف حساب الأعمال</dt>
                                    <dd class="font-mono dark:text-white" dir="ltr">{{ settings.wa_waba_id || '—' }}</dd>
                                </div>
                                <div class="flex items-center justify-between gap-3">
                                    <dt class="text-gray-500 dark:text-gray-400">الرقم المعروض</dt>
                                    <dd class="font-mono dark:text-white" dir="ltr">{{ settings.wa_display_phone || '—' }}</dd>
                                </div>
                            </dl>
                            <p class="text-xs text-gray-500 mt-1">للقراءة فقط: يُعرفان من أول رسالة تصل لرقمنا، وبهما تُتجاهل تنبيهات ميتا لأرقام أخرى على التطبيق نفسه. قبل ذلك يوقف أي تنبيه خطير الإرسال احتياطاً.</p>
                        </div>

                        <div>
                            <label for="wa-verify" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Verify Token</label>
                            <input id="wa-verify" v-model="form.wa_verify_token" dir="ltr" placeholder="نص تختاره أنت ويُكتب نفسه في لوحة Meta"
                                   class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm font-mono focus:ring-2 focus:ring-gold-500 focus:outline-none dark:text-white"/>
                            <p class="text-xs text-gray-500 mt-1">يجب أن يطابق ما تكتبه في خانة Verify Token عند إعداد الـ Webhook.</p>
                        </div>

                        <!-- WA Token -->
                        <div>
                            <div class="flex items-center gap-2 mb-1 flex-wrap">
                                <label for="wa-token" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Access Token (WA_TOKEN)</label>
                                <span v-if="settings.has_token" class="px-2 py-0.5 rounded-full text-xs font-bold bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400">✅ محفوظ</span>
                            </div>
                            <input id="wa-token" v-model="form.wa_token" type="password" dir="ltr" autocomplete="new-password"
                                   :disabled="settings.env_token"
                                   :placeholder="settings.env_token ? 'مضبوط من بيئة الخادم' : secretPlaceholder"
                                   class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm font-mono focus:ring-2 focus:ring-gold-500 focus:outline-none dark:text-white disabled:opacity-40 disabled:cursor-not-allowed"/>
                            <p v-if="settings.env_token" class="text-xs text-blue-600 dark:text-blue-400 mt-1">🔒 مضبوط من بيئة الخادم (له الأولوية) — هذا الحقل اختياري ومعطّل.</p>
                            <p v-else class="text-xs text-gray-500 mt-1">توكن دائم من تطبيق Meta لإرسال الرسائل.</p>
                        </div>

                        <!-- App Secret -->
                        <div>
                            <div class="flex items-center gap-2 mb-1 flex-wrap">
                                <label for="wa-secret" class="block text-sm font-medium text-gray-700 dark:text-gray-300">App Secret</label>
                                <span v-if="settings.has_app_secret" class="px-2 py-0.5 rounded-full text-xs font-bold bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400">✅ محفوظ</span>
                            </div>
                            <input id="wa-secret" v-model="form.wa_app_secret" type="password" dir="ltr" autocomplete="new-password"
                                   :placeholder="secretPlaceholder"
                                   class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm font-mono focus:ring-2 focus:ring-gold-500 focus:outline-none dark:text-white"/>
                            <p class="text-xs text-gray-500 mt-1">يُستخدم للتحقق من توقيع الـ Webhook القادم من Meta.</p>
                        </div>
                    </div>

                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-4 p-3 rounded-xl bg-gray-50 dark:bg-gray-800/40 border border-gray-200 dark:border-gray-700">
                        ℹ️ ترك أي حقل سري فارغًا يُبقي القيمة المحفوظة كما هي — لا تُمحى أبدًا بالحفظ.
                    </p>

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
                </div>

                <!-- ================= 3. الذكاء ================= -->
                <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5 md:p-6">
                    <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-4 flex items-center gap-2">🧠 الذكاء</h3>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label for="wa-provider" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">المزوّد</label>
                            <select id="wa-provider" v-model="form.provider" @change="models = []; modelsMsg = ''"
                                    class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none dark:text-white">
                                <option v-for="(label, key) in providers" :key="key" :value="key">{{ label }}</option>
                            </select>
                            <p class="text-xs text-gray-500 mt-1">
                                {{ form.provider === 'google' ? 'Gemini: طبقة مجانية سخيّة — الأنسب للحجم العالي.' : 'Claude: جودة أعلى وتخزين مؤقّت للموجّه.' }}
                            </p>
                        </div>

                        <div>
                            <label for="wa-model" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">الموديل</label>
                            <select v-if="models.length" id="wa-model" v-model="form.model"
                                    class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none dark:text-white">
                                <option v-for="m in models" :key="m.id" :value="m.id">{{ m.id }}<span v-if="m.label && m.label !== m.id"> — {{ m.label }}</span></option>
                            </select>
                            <input v-else id="wa-model" v-model="form.model" dir="ltr"
                                   class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm font-mono focus:ring-2 focus:ring-gold-500 focus:outline-none dark:text-white"/>
                            <button type="button" @click="fetchModels" :disabled="loadingModels"
                                    class="mt-1.5 px-3 py-1.5 rounded-lg text-xs font-bold border border-gold-400 text-gold-700 dark:text-gold-400 hover:bg-gold-50 dark:hover:bg-gold-900/20 disabled:opacity-50">
                                {{ loadingModels ? '⏳ جاري الفحص…' : '🔄 فحص المفتاح وجلب النماذج المتاحة' }}
                            </button>
                            <p v-if="modelsMsg" class="text-xs mt-1" :class="modelsOk ? 'text-green-600' : 'text-red-600'">{{ modelsMsg }}</p>
                            <p v-else class="text-xs text-gray-500 mt-1">أسماء النماذج تتغيّر — اضغط الفحص لتختار من المتاح فعلياً لمفتاحك.</p>
                        </div>

                        <div>
                            <div class="flex items-center gap-2 mb-1 flex-wrap">
                                <label for="wa-apikey" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    مفتاح الـ API ({{ form.provider === 'google' ? 'Google AI Studio' : 'Anthropic' }})
                                </label>
                                <span v-if="settings.has_api_key" class="px-2 py-0.5 rounded-full text-xs font-bold bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400">✅ محفوظ</span>
                            </div>
                            <input id="wa-apikey" v-model="form.api_key" type="password" dir="ltr" autocomplete="new-password"
                                   :disabled="settings.env_api_key"
                                   :placeholder="settings.env_api_key ? 'مضبوط من بيئة الخادم' : secretPlaceholder"
                                   class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm font-mono focus:ring-2 focus:ring-gold-500 focus:outline-none dark:text-white disabled:opacity-40 disabled:cursor-not-allowed"/>
                            <p v-if="settings.env_api_key" class="text-xs text-blue-600 dark:text-blue-400 mt-1">🔒 مضبوط من بيئة الخادم (له الأولوية) — هذا الحقل اختياري ومعطّل.</p>
                            <p v-else class="text-xs text-gray-500 mt-1">ترك الحقل فارغًا يُبقي المفتاح المحفوظ كما هو.</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mt-4">
                        <div>
                            <label for="wa-ctx" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">عدد رسائل السياق</label>
                            <input id="wa-ctx" v-model.number="form.context_messages" type="number" min="4" max="60" dir="ltr"
                                   class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm font-mono focus:ring-2 focus:ring-gold-500 focus:outline-none dark:text-white"/>
                            <p class="text-xs text-gray-500 mt-1">كم رسالة سابقة يتذكّرها البوت (4–60) — الأكثر أدقّ وأغلى.</p>
                        </div>
                        <div>
                            <label for="wa-loops" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">أقصى عدد دورات الأدوات</label>
                            <input id="wa-loops" v-model.number="form.max_tool_loops" type="number" min="1" max="8" dir="ltr"
                                   class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm font-mono focus:ring-2 focus:ring-gold-500 focus:outline-none dark:text-white"/>
                            <p class="text-xs text-gray-500 mt-1">كم مرة يستدعي البوت الأدوات قبل الرد النهائي (1–8).</p>
                        </div>
                        <div>
                            <label for="wa-pause" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">مدة إيقاف البوت بعد تدخّل موظف (دقيقة)</label>
                            <input id="wa-pause" v-model.number="form.pause_minutes" type="number" min="1" max="1440" dir="ltr"
                                   class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm font-mono focus:ring-2 focus:ring-gold-500 focus:outline-none dark:text-white"/>
                            <p class="text-xs text-gray-500 mt-1">يصمت البوت في المحادثة بعد ردّ موظف (1–1440).</p>
                        </div>
                        <div>
                            <label for="wa-rate" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">حدّ الرسائل للعميل في الساعة</label>
                            <input id="wa-rate" v-model.number="form.rate_limit_per_hour" type="number" min="1" max="200" dir="ltr"
                                   class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm font-mono focus:ring-2 focus:ring-gold-500 focus:outline-none dark:text-white"/>
                            <p class="text-xs text-gray-500 mt-1">حماية من الاستهلاك الزائد لكل رقم (1–200).</p>
                        </div>
                    </div>
                </div>

                <!-- ================= 4. الشخصية وقاعدة المعرفة ================= -->
                <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5 md:p-6">
                    <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-4 flex items-center gap-2">🎭 الشخصية وقاعدة المعرفة</h3>

                    <div class="mb-6">
                        <div class="flex items-center justify-between gap-3 mb-1 flex-wrap">
                            <label for="wa-prompt" class="block text-sm font-medium text-gray-700 dark:text-gray-300">نص الشخصية (System Prompt)</label>
                            <button type="button" @click="restorePrompt"
                                    class="px-3 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-xs font-bold text-gray-700 dark:text-gray-200 hover:bg-gold-50 dark:hover:bg-gold-900/20">
                                ↺ استعادة النص الافتراضي
                            </button>
                        </div>
                        <textarea id="wa-prompt" v-model="form.system_prompt" rows="14"
                                  class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm leading-relaxed font-mono focus:ring-2 focus:ring-gold-500 focus:outline-none dark:text-white resize-y"></textarea>
                        <p class="text-xs text-gray-500 mt-1">
                            <span class="font-mono" dir="ltr">{{ promptChars }}</span> حرف — يحدّد نبرة البوت وحدوده وما يُمنع عليه قوله.
                        </p>
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
                               class="flex items-start gap-3 p-3 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 cursor-pointer hover:bg-gold-50 dark:hover:bg-gold-900/20">
                            <input type="checkbox" v-model="form.tools_config[key]"
                                   class="mt-0.5 w-4 h-4 rounded text-gold-500 focus:ring-gold-500 shrink-0"/>
                            <span class="min-w-0">
                                <span class="flex items-center gap-2 flex-wrap">
                                    <span class="text-sm font-medium text-gray-800 dark:text-gray-100">{{ label }}</span>
                                    <span v-if="handoffTools.includes(key)"
                                          class="px-2 py-0.5 rounded-full text-xs font-bold bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">يحوّل لموظف</span>
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
                                  placeholder="مثال: نعتذر، حدث خلل مؤقت — سيتواصل معك أحد موظفينا قريبًا."
                                  class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none dark:text-white resize-none"></textarea>
                        <p class="text-xs text-gray-500 mt-1">تُرسل للعميل إذا تعذّر توليد رد (انقطاع API أو خطأ غير متوقع).</p>
                    </div>

                    <label class="flex items-start gap-3 mt-4 p-4 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 cursor-pointer">
                        <input type="checkbox" v-model="form.fail_handoff" class="mt-0.5 w-4 h-4 rounded text-gold-500 focus:ring-gold-500 shrink-0"/>
                        <span class="min-w-0">
                            <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">عند الفشل: أوقف البوت ساعة وأشعر الإدارة</span>
                            <span class="block text-xs text-gray-500 mt-0.5">يمنع تكرار الأخطاء على كل العملاء ويُبقي الأمر تحت أعين الموظفين.</span>
                        </span>
                    </label>
                </div>

                <!-- ================= شريط الحفظ ================= -->
                <div class="sticky bottom-0 z-10 -mx-1 px-1 py-3">
                    <div class="rounded-2xl border border-gray-200 dark:border-gray-700 bg-white/95 dark:bg-gray-900/95 backdrop-blur shadow-md p-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <p class="text-xs text-gray-500 dark:text-gray-400">الحقول السرية الفارغة لا تُلغي القيم المحفوظة.</p>
                        <button type="submit" :disabled="form.processing"
                                class="w-full sm:w-auto px-8 py-3 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md disabled:opacity-50 disabled:cursor-not-allowed">
                            {{ form.processing ? '⏳ جارٍ الحفظ…' : '💾 حفظ الإعدادات' }}
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({
    title: String,
    settings: Object,
    webhookUrl: String,
    defaultPrompt: String,
    toolList: Object,
    providers: { type: Object, default: () => ({ google: 'Google Gemini', anthropic: 'Anthropic Claude' }) },
    graph: Object,
});

// أسوأ حالة بين إصدار الطلبات وإصدار الويبهوك
const graphAlert = computed(() => {
    const levels = [props.graph?.requests?.level, props.graph?.webhook?.level];
    if (levels.some((l) => l === 'expired' || l === 'unknown')) return 'red';
    return levels.includes('warn') ? 'amber' : null;
});

const handoffTools = ['request_quote', 'confirm_booking'];
const secretPlaceholder = 'اتركه فارغًا للإبقاء على القيمة المحفوظة';

// الأدوات: مفعّلة افتراضيًا إن لم يكن المفتاح محفوظًا
const savedTools = props.settings?.tools_config || {};
const toolsConfig = {};
Object.keys(props.toolList || {}).forEach((key) => {
    toolsConfig[key] = savedTools[key] === undefined ? true : !!savedTools[key];
});

const form = useForm({
    enabled: !!props.settings?.enabled,
    wa_suspended: !!props.settings?.wa_suspended,
    wa_phone_number_id: props.settings?.wa_phone_number_id || '',
    wa_verify_token: props.settings?.wa_verify_token || '',
    wa_token: '',
    wa_app_secret: '',
    api_key: '',
    provider: props.settings?.provider || 'google',
    model: props.settings?.model || 'gemini-2.5-flash',
    system_prompt: props.settings?.system_prompt || props.defaultPrompt || '',
    context_messages: Number(props.settings?.context_messages ?? 12),
    pause_minutes: Number(props.settings?.pause_minutes ?? 60),
    max_tool_loops: Number(props.settings?.max_tool_loops ?? 4),
    rate_limit_per_hour: Number(props.settings?.rate_limit_per_hour ?? 30),
    fail_message: props.settings?.fail_message || '',
    fail_handoff: !!props.settings?.fail_handoff,
    tools_config: toolsConfig,
});

// جلب النماذج المتاحة حيّاً من المزوّد — أسماء النماذج تتغيّر فلا نثبّتها
const models = ref([]);
const loadingModels = ref(false);
const modelsMsg = ref('');
const modelsOk = ref(false);

const fetchModels = async () => {
    loadingModels.value = true;
    modelsMsg.value = '';
    try {
        const { data } = await axios.post('/api/whatsapp/test-key', {
            provider: form.provider,
            api_key: form.api_key || undefined,
        }, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });

        models.value = data.models || [];
        modelsOk.value = true;
        modelsMsg.value = `✅ المفتاح صالح — ${data.count} نموذجاً متاحاً`;
        if (models.value.length && !models.value.some((m) => m.id === form.model)) {
            form.model = models.value[0].id;
        }
    } catch (e) {
        modelsOk.value = false;
        models.value = [];
        modelsMsg.value = '❌ ' + (e.response?.data?.error || 'تعذّر فحص المفتاح');
    } finally {
        loadingModels.value = false;
    }
};

const isLive = computed(() => !!(props.settings?.enabled && props.settings?.has_token && props.settings?.has_api_key));

const missing = computed(() => {
    const list = [];
    if (!props.settings?.enabled) list.push('البوت مُعطَّل — شغّل مفتاح «تشغيل البوت» ثم احفظ.');
    if (!props.settings?.has_token) list.push('لا يوجد Access Token للواتساب — أضِفه في قسم «ربط قناة ميتا».');
    if (!props.settings?.has_api_key) list.push('لا يوجد مفتاح API للذكاء — أضِفه في قسم «الذكاء».');
    if (!props.settings?.wa_phone_number_id) list.push('لم يُضبط Phone Number ID — لن يُعرف الرقم المُرسِل.');
    return list;
});

const promptChars = computed(() => (form.system_prompt || '').length);

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

const restorePrompt = () => {
    form.system_prompt = props.defaultPrompt || '';
};

const save = () => {
    form.put('/whatsapp/settings', { preserveScroll: true });
};
</script>
