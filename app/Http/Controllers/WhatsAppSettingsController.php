<?php

namespace App\Http\Controllers;

use App\Console\Commands\BackupSafeMode;
use App\Enums\AuditAction;
use App\Exceptions\StaleSettingsException;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\WaBotSetting;
use App\Models\WaKnowledgeEntry;
use App\Services\Ops\OpsHealth;
use App\Services\WhatsApp\BotEngine;
use App\Services\WhatsApp\BotTools;
use App\Services\WhatsApp\ChannelDoctor;
use App\Services\WhatsApp\LlmCircuitBreaker;
use App\Services\WhatsApp\OpenWa\OpenWaClient;
use App\Services\WhatsApp\Llm\LlmFactory;
use App\Services\WhatsApp\Settings\SettingsSchema;
use App\Services\WhatsApp\Settings\SettingsWriter;
use App\Services\WhatsApp\WhatsAppInbox;
use App\Support\FeatureFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * إعدادات بوت الواتساب (E10): حفظ جزئي برمز نسخة (409 عند التعارض)، وإيقاف طارئ بسبب في مسار مستقل،
 * ومفاتيح مزوّد الذكاء تُعيَّن وتُمسح ولا تعود للمتصفح أبداً. كل كتابة عبر SettingsWriter. أرقام واتساب نفسها وبوابتها
 * في صفحة «الأرقام» (2026-11-06)، وهنا بطاقة البوابة للقراءة وحدها.
 */
class WhatsAppSettingsController extends Controller
{
    private const SECRETS = ['api_key' => 'مفتاح المزوّد', 'fallback_api_key' => 'مفتاح المزوّد الاحتياطي'];

    public function show()
    {
        $this->authorizeSettings();

        return Inertia::render('WhatsApp/Settings', $this->payload() + [
            'title' => 'إعدادات بوت الواتساب',
            'providers' => LlmFactory::PROVIDERS,
            'defaultPrompt' => BotEngine::DEFAULT_PROMPT,
            'toolList' => BotTools::labels(),
            // من سجل الأدوات (E15): شارة «يحوّل لموظف» وما لا يُطفأ
            'toolMeta' => BotTools::meta(),
            'health' => ChannelDoctor::checks(),
            // E23: آخر فحص كامل (whatsapp:doctor --store أو الزر)، بلا أي قيمة سرية
            'doctor' => ChannelDoctor::last(),
            // E23: «إرسال تجريبي» (خلف علمه)، والمستلمون بالاسم وآخر أربعة أرقام
            'diagnostics' => [
                'test_send' => $testSend = \App\Services\WhatsApp\TestSend::enabled(),
                'recipients' => $testSend ? \App\Services\WhatsApp\TestSend::recipients() : [],
                'per_hour' => (int) config('whatsapp.monitor.test_send_per_hour', 10),
            ],
            'ops' => app(OpsHealth::class)->snapshot(),
            // بوابة OpenWA: الاتصال والإصدار (للقراءة)، والأرقام في صفحتها
            'gateway' => self::gateway(),
            'mediaArchive' => rescue(fn () => \App\Services\WhatsApp\Media\MediaArchiver::summary(), null, false),
            // E20: استقبال الويبهوك (أعداد فقط)
            'webhookIntake' => rescue(fn () => \App\Services\WhatsApp\WebhookStats::summary(), null, false),
            // E26: آخر فحص أمني للصورة (التاريخ والأعداد فقط)
            'securityScan' => rescue(fn () => \App\Services\Ops\SecurityScan::summary(), null, false),
            // E24: صادر اليوم بمصادره، والإصدار المنشور
            'outbound' => rescue(fn () => \App\Services\WhatsApp\OutboundCounter::summary(), null, false),
            'build' => config('app.build'),
        ]);
    }

    /**
     * حالة البوابة للقراءة: تصل أم لا، وإصدارها، وعدد الأرقام المربوطة. نداء واحد قصير بالمفتاح الرئيس عبر OpenWaClient.
     *
     * @return array{reachable: bool, version: ?string, channels: int, linked: int, url: string}
     */
    public static function gateway(): array
    {
        $res = rescue(fn () => OpenWaClient::admin()->request('GET', '/health'), null, false);
        $channels = \App\Models\WaChannel::query()->active()->get();

        return [
            'reachable' => (bool) ($res?->ok),
            'version' => $res?->ok ? (string) ($res->json('version') ?? $res->json('info.version') ?? '') ?: null : null,
            'error' => $res && !$res->ok ? $res->error?->code : null,
            'channels' => $channels->count(),
            'linked' => $channels->filter(fn ($c) => $c->isLinked())->count(),
            'url' => '/whatsapp/channels',
        ];
    }

    /** الحمولة نفسها JSON: تعيد الصفحة بناء نفسها بعد 409 */
    public function current(): JsonResponse
    {
        $this->authorizeSettings();

        return response()->json($this->payload())->header('Cache-Control', 'no-store');
    }

    /** PATCH whatsapp/settings: {version, changes: {key: value|null}, confirm?}. null = القيمة الافتراضية */
    public function update(Request $request): JsonResponse
    {
        $this->authorizeSettings();
        $changes = (array) $request->input('changes', []);
        $data = $request->validate(['version' => 'required|integer|min:1', 'confirm' => 'sometimes|boolean'] + SettingsSchema::patchRules($changes), [
            'changes.array' => 'حقل غير مسموح في الإعدادات: الأسرار والإيقاف لها أزرارها',
            'changes.model.regex' => 'اسم النموذج يقبل الحروف والأرقام و . _ - فقط',
        ]);

        $settings = WaBotSetting::current();
        $changes = [];
        foreach ($data['changes'] as $key => $value) {
            $changes[$key] = $value ?? SettingsSchema::default($key);
        }

        // الحقول الحساسة (رقم الإرسال والمزوّد) بتأكيد صريح
        $sensitive = array_filter(array_keys($changes), fn ($k) => SettingsSchema::isSensitive($k) && $changes[$k] != $settings->{$k});
        if ($sensitive && !$request->boolean('confirm')) {
            throw ValidationException::withMessages(['confirm' => 'تغيير '.implode(' و', array_map(fn ($k) => SettingsSchema::FIELDS[$k]['label'], $sensitive)).' يحتاج تأكيداً']);
        }

        // الاحتياطي يجب أن يختلف عن الأساسي (مزوّداً أو نموذجاً)، وإلا لا معنى له
        $fallbackProvider = $changes['fallback_provider'] ?? $settings->fallback_provider;
        if ($fallbackProvider !== null && (array_key_exists('fallback_provider', $changes) || array_key_exists('fallback_model', $changes))) {
            $fallbackModel = $changes['fallback_model'] ?? $settings->fallback_model;
            if (blank($fallbackModel)) {
                throw ValidationException::withMessages(['changes.fallback_model' => 'حدّد نموذج المزوّد الاحتياطي']);
            }
            if ($fallbackProvider === ($changes['provider'] ?? $settings->provider) && $fallbackModel === ($changes['model'] ?? $settings->model)) {
                throw ValidationException::withMessages(['changes.fallback_model' => 'الاحتياطي يطابق الأساسي — اختر نموذجاً أو مزوّداً آخر']);
            }
        }

        $before = ['provider' => $settings->provider, 'model' => $settings->model];
        try {
            $fresh = SettingsWriter::apply($changes, (int) $data['version'], AuditAction::WaSettingsUpdate);
        } catch (StaleSettingsException $e) {
            return response()->json(['error' => 'stale', 'message' => 'غيّر غيرك الإعدادات بعد فتحك الصفحة — راجع الحقول المتعارضة ثم احفظ', 'current_version' => $e->currentVersion], 409);
        }
        // مزوّد أو نموذج جديد: حادثة القديم لا تخص الجديد (E12)
        if ($before !== ['provider' => $fresh->provider, 'model' => $fresh->model]) {
            LlmCircuitBreaker::forceClose((string) $before['provider'], auth()->id(), 'settings_changed');
            LlmCircuitBreaker::forceClose((string) $fresh->provider, auth()->id(), 'settings_changed');
        }

        return response()->json(['version' => (int) $fresh->settings_version, 'settings' => $this->settings($fresh)]);
    }

    /** PUT القديم (تبويب فُتح قبل النشر): لا يكتب شيئاً. يُحذف في الإصدار التالي */
    public function legacyUpdate()
    {
        $this->authorizeSettings();

        return back()->with('error', 'تغيّرت صفحة الإعدادات — أعد تحميلها ثم احفظ');
    }

    /** الإيقاف الطارئ: on بسبب، وoff بسبب وكتابة «استئناف». WA_SUSPENDED في البيئة يتقدّم دائماً */
    public function killSwitch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'state' => 'required|in:on,off',
            'reason' => 'required|string|min:10|max:500',
            'confirm_text' => 'nullable|string|max:20',
            'acknowledge' => 'sometimes|boolean',
        ], ['reason.min' => 'اكتب السبب (10 أحرف على الأقل)']);
        $user = $request->user();
        $on = $data['state'] === 'on';

        abort_unless($on ? ($user->can('whatsapp.settings') || $user->can('whatsapp.kill_switch')) : $user->can('whatsapp.settings'), 403);
        if (config('services.whatsapp.suspended')) {
            return response()->json(['message' => 'الإيقاف مفروض من بيئة الخادم (WA_SUSPENDED) — لا يُغيَّر من هنا'], 409);
        }

        $s = WaBotSetting::current();
        if (!$on) {
            // الاستئناف تحقق معزّز (E27، بالعلم step_up)؛ الإيقاف نفسه حماية لا تُعاق
            \App\Services\Security\StepUp::ensure($request);
            if (trim((string) ($data['confirm_text'] ?? '')) !== 'استئناف') {
                throw ValidationException::withMessages(['confirm_text' => 'اكتب «استئناف» للتأكيد']);
            }
            // بعد استعادة نسخة: القفل يبقى حتى يعتمد المدير المراجعة
            if ($s->wa_suspended && Setting::get(BackupSafeMode::REVIEW_KEY)) {
                throw ValidationException::withMessages(['state' => 'أكمل مراجعة ما بعد الاستعادة من صفحة النسخ الاحتياطي أولاً']);
            }
        }

        if ($on !== (bool) $s->wa_suspended) {
            $previous = $s->wa_suspended_source;
            SettingsWriter::apply([
                'wa_suspended' => $on, 'wa_suspended_at' => now(), 'wa_suspended_by' => $user->id,
                'wa_suspended_source' => $on ? 'manual' : null, 'wa_suspended_reason' => $data['reason'],
            ], null, AuditAction::WaSuspendManual, ['on' => $on, 'reason' => $data['reason']] + ($on ? [] : ['previous_source' => $previous]), label: 'قفل الإرسال الطارئ');

            WhatsAppInbox::notifyAdmins(
                $on ? '⛔ أُوقف إرسال الواتساب' : '✅ استُؤنف إرسال الواتساب',
                ($on ? 'أوقفه ' : 'استأنفه ').$user->name.': '.$data['reason'],
                '/whatsapp/settings',
            );
        }

        return response()->json(['killSwitch' => $this->killSwitchState(WaBotSetting::current())]);
    }

    /** تعيين مفتاح المزوّد (الأساسي أو الاحتياطي) بسبب. الرد بلا القيمة */
    public function setSecret(Request $request, string $secret): JsonResponse
    {
        $this->authorizeSettings();
        abort_unless(isset(self::SECRETS[$secret]), 404);
        $data = $request->validate([
            'value' => 'required|string|min:20|max:4096',
            'reason' => 'required|in:routine,leak,setup,other',
            'note' => 'nullable|string|max:200',
        ]);
        if (($this->sourceOf($secret)) === 'env') {
            return response()->json(['message' => 'البيئة تتقدّم على المحفوظ — عدّل .env.production'], 409);
        }

        $fresh = SettingsWriter::apply([$secret => $data['value'], "{$secret}_rotated_at" => now()], null, AuditAction::WaSecretSet,
            ['field' => $secret, 'reason' => $data['reason'], 'note' => $data['note'] ?? null], label: self::SECRETS[$secret]);
        // مفتاح جديد: قد يكون سبب الحادثة نفسه (E12)
        if ($secret === 'api_key') {
            LlmCircuitBreaker::forceClose((string) $fresh->provider, auth()->id(), 'settings_changed');
        } elseif ($secret === 'fallback_api_key' && $fresh->fallback_provider) {
            LlmCircuitBreaker::forceClose((string) $fresh->fallback_provider, auth()->id(), 'settings_changed');
        }

        return response()->json([
            'has' => true,
            'rotated_at' => $fresh->{"{$secret}_rotated_at"}?->toIso8601String(),
            'fingerprint' => substr(hash('sha256', $data['value']), 0, 8),
        ])->header('Cache-Control', 'no-store');
    }

    public function clearSecret(Request $request, string $secret): JsonResponse
    {
        $this->authorizeSettings();
        abort_unless(isset(self::SECRETS[$secret]), 404);
        $data = $request->validate(['reason' => 'required|in:routine,leak,setup,other']);
        if ($this->sourceOf($secret) === 'env') {
            return response()->json(['message' => 'البيئة تتقدّم على المحفوظ — عدّل .env.production'], 409);
        }

        SettingsWriter::apply([$secret => null, "{$secret}_rotated_at" => now()], null, AuditAction::WaSecretClear,
            ['field' => $secret, 'reason' => $data['reason']], label: self::SECRETS[$secret]);

        return response()->json(['has' => false])->header('Cache-Control', 'no-store');
    }

    /** PATCH api/whatsapp/settings/flags/{flag}: {state: on|off|default, reason} */
    public function toggleFlag(Request $request, string $flag): JsonResponse
    {
        $this->authorizeSettings();
        abort_unless(isset(FeatureFlags::definitions()[$flag]), 404);
        $data = $request->validate(['state' => 'required|in:on,off,default', 'reason' => 'required|string|min:5|max:300']);
        if (!FeatureFlags::available($flag)) {
            throw ValidationException::withMessages(['state' => 'هذه الميزة لم تُشحن بعد، فلا تُفعَّل']);
        }
        // أعلام الحماية (E27): إطفاؤها يُضعف حماية كل الحسابات، فلا يبدّلها إلا المدير
        if ((FeatureFlags::definitions()[$flag]['admin_only'] ?? false) && !$request->user()->can('admin')) {
            return response()->json(['message' => 'هذا العلم يبدّله المدير وحده'], 403);
        }
        if (FeatureFlags::state($flag)['source'] === 'env') {
            return response()->json(['message' => 'العلم مفروض من البيئة — يُغيَّر في .env.production'], 409);
        }

        $flags = WaBotSetting::current()->feature_flags ?? [];
        if ($data['state'] === 'default') {
            unset($flags[$flag]);
        } else {
            $flags[$flag] = $data['state'] === 'on';
        }
        SettingsWriter::apply(['feature_flags' => $flags ?: null], null, AuditAction::WaFlagToggle,
            ['flag' => $flag, 'state' => $data['state'], 'reason' => $data['reason']], label: $flag);

        return response()->json(['flags' => FeatureFlags::panel()]);
    }

    /** POST api/whatsapp/settings/llm-breaker/open: وضع تحويل يدوي (تمرين، أو عطل يعرفه المدير قبل النظام) */
    public function breakerOpen(Request $request): JsonResponse
    {
        $this->authorizeSettings();
        $data = $request->validate(['minutes' => 'required|integer|min:5|max:240']);
        $provider = (string) WaBotSetting::current()->provider;

        LlmCircuitBreaker::forceOpen($provider, (int) $data['minutes'], auth()->id());
        AuditLog::log(AuditAction::WaBreakerManual, 'setting', null, 'llm_breaker', ['action' => 'open', 'provider' => $provider, 'minutes' => (int) $data['minutes']]);

        return response()->json(['breaker' => $this->breakerState(WaBotSetting::current())]);
    }

    /** POST api/whatsapp/settings/llm-breaker/close */
    public function breakerClose(): JsonResponse
    {
        $this->authorizeSettings();
        $s = WaBotSetting::current();
        foreach (array_filter([(string) $s->provider, $s->fallback_provider]) as $provider) {
            LlmCircuitBreaker::forceClose($provider, auth()->id(), 'manual');
        }
        AuditLog::log(AuditAction::WaBreakerManual, 'setting', null, 'llm_breaker', ['action' => 'close', 'provider' => (string) $s->provider]);

        return response()->json(['breaker' => $this->breakerState($s)]);
    }

    /**
     * POST api/whatsapp/settings/llm-terms {confirm}: المدير يؤكد أن مفتاح مزوّد الذكاء على الطبقة المدفوعة (شرط الترجمة،
     * E29)، أو يلغي تأكيده. التأكيد مربوط ببصمة المزوّد والمفتاح، ويُمسح آلياً عند تغيير أيهما. تدقيق صارم.
     */
    public function llmTerms(Request $request): JsonResponse
    {
        $this->authorizeSettings();
        $confirm = (bool) $request->validate(['confirm' => 'required|boolean'])['confirm'];
        $s = WaBotSetting::current();
        if ($confirm && blank($s->llmKey())) {
            throw ValidationException::withMessages(['confirm' => 'أضف مفتاح المزوّد أولاً، ثم أكّد']);
        }

        SettingsWriter::apply($confirm
            ? ['llm_terms_confirmed_at' => now(), 'llm_terms_confirmed_by' => auth()->id(), 'llm_terms_fp' => \App\Services\WhatsApp\MessageTranslator::termsFingerprint($s)]
            : ['llm_terms_confirmed_at' => null, 'llm_terms_confirmed_by' => null, 'llm_terms_fp' => null],
            null, AuditAction::WaLlmTermsConfirm, ['confirm' => $confirm, 'provider' => (string) $s->provider], label: 'تأكيد الطبقة المدفوعة لمزوّد الذكاء');

        return response()->json(['llmTerms' => \App\Services\WhatsApp\MessageTranslator::termsState(WaBotSetting::current())]);
    }

    /** حالة القاطع للأساسي والاحتياطي، وآخر 10 حوادث: أنواع وأوقات وأعداد، بلا بيانات عملاء */
    private function breakerState(WaBotSetting $s): array
    {
        return [
            'primary' => LlmCircuitBreaker::status((string) $s->provider),
            'fallback' => $s->fallback_provider ? LlmCircuitBreaker::status((string) $s->fallback_provider) + ['ready' => $s->fallbackReady()] : null,
            'incidents' => rescue(fn () => \App\Models\WaLlmIncident::latest('id')->limit(10)->get()->map(fn ($i) => [
                'id' => $i->id, 'provider' => $i->provider, 'model' => $i->model, 'kind' => $i->kind, 'http_status' => $i->http_status,
                'reason' => $i->reason, 'shadow' => $i->shadow, 'opened_at' => $i->opened_at?->toIso8601String(),
                'closed_at' => $i->closed_at?->toIso8601String(), 'close_reason' => $i->close_reason,
                'failed_probes' => $i->failed_probes, 'handoffs' => $i->handoffs,
            ])->all(), [], false),
        ];
    }

    // ==================== الحمولة ====================

    private function payload(): array
    {
        $s = WaBotSetting::current();
        $sources = $s->sources();

        return [
            'settings' => $this->settings($s),
            'version' => (int) ($s->settings_version ?? 1),
            'fields' => SettingsSchema::uiFields(),
            'defaults' => SettingsSchema::defaults(),
            'sources' => $sources,
            'secrets' => collect(self::SECRETS)->map(fn ($label, $key) => [
                'label' => $label,
                'has' => !empty($key === 'fallback_api_key' ? $s->fallbackKey() : $s->llmKey()),
                // مفتاح الاحتياطي بلا متغير بيئة خاص به (البيئة لمزوّده تُقرأ في fallbackKey)
                'source' => $sources[$key]['source'] ?? (filled($s->getAttributes()[$key] ?? null) ? 'db' : 'unset'),
                'env_var' => $sources[$key]['env_var'] ?? null,
                'decrypt_ok' => $sources[$key]['decrypt_ok'] ?? $s->decryptOk($key),
                'rotated_at' => $s->{"{$key}_rotated_at"}?->toIso8601String(),
            ])->all(),
            'signature' => [
                'last_ok_at' => Cache::get('wa:sig:last_ok_at'),
                'failures_last_hour' => \App\Services\WhatsApp\WebhookIntake::badSignatureCounts()['hour'],
            ],
            'killSwitch' => $this->killSwitchState($s),
            'breaker' => $this->breakerState($s),
            // E29: تأكيد الطبقة المدفوعة لمزوّد الذكاء (شرط ترجمة الرسائل للموظف)
            'llmTerms' => \App\Services\WhatsApp\MessageTranslator::termsState($s),
            'flags' => FeatureFlags::panel(),
            'can' => [
                'suspend' => auth()->user()->can('whatsapp.settings') || auth()->user()->can('whatsapp.kill_switch'),
                'resume' => auth()->user()->can('whatsapp.settings'),
            ],
        ];
    }

    /** القيم الآمنة وحدها: لا سر ولا هاش */
    private function settings(WaBotSetting $s): array
    {
        return [
            'enabled' => (bool) $s->enabled,
            // E35: المضبوط (الفعلي off ما دام العلم read_receipts مطفأ)
            'send_read_receipts' => \App\Services\WhatsApp\ReadReceipts::configured($s),
            'provider' => $s->provider ?: SettingsSchema::default('provider'),
            'model' => $s->model,
            // null = النص الافتراضي الحي (defaultPrompt)؛ لا يُحقن في الحقل فلا يتجمّد في القاعدة عند أول حفظ
            'system_prompt' => $s->system_prompt,
            'kb_entries' => rescue(fn () => WaKnowledgeEntry::where('is_active', true)->count(), 0, false),
            'kb_chars' => rescue(fn () => WaKnowledgeEntry::activeChars(), 0, false),
            'context_messages' => (int) $s->context_messages,
            'pause_minutes' => (int) $s->pause_minutes,
            'max_reply_age_minutes' => (int) ($s->max_reply_age_minutes ?? SettingsSchema::default('max_reply_age_minutes')),
            'max_tool_loops' => (int) $s->max_tool_loops,
            'rate_limit_per_hour' => (int) $s->rate_limit_per_hour,
            'fail_message' => $s->fail_message,
            'fail_handoff' => (bool) $s->fail_handoff,
            // قاطع المزوّد والاحتياطي (E12)
            'breaker_mode' => LlmCircuitBreaker::mode($s),
            'breaker_threshold' => (int) ($s->breaker_threshold ?? SettingsSchema::default('breaker_threshold')),
            'breaker_window_minutes' => (int) ($s->breaker_window_minutes ?? SettingsSchema::default('breaker_window_minutes')),
            'breaker_open_minutes' => (int) ($s->breaker_open_minutes ?? SettingsSchema::default('breaker_open_minutes')),
            'fallback_provider' => $s->fallback_provider,
            'fallback_model' => $s->fallback_model,
            'tools_config' => $s->tools_config ?? (object) [],
            // حد تنبيه الصادر (E24)، وإيقاف البوت بعد رسالة من هاتف الرقم
            'wa_daily_alert_threshold' => (int) ($s->wa_daily_alert_threshold ?? SettingsSchema::default('wa_daily_alert_threshold')),
            'echo_pause_minutes' => $s->echo_pause_minutes !== null ? (int) $s->echo_pause_minutes : null,
        ];
    }

    private function killSwitchState(WaBotSetting $s): array
    {
        return [
            'db' => (bool) $s->wa_suspended,
            'env' => (bool) config('services.whatsapp.suspended'),
            'effective' => $s->sendingSuspended(),
            'source' => $s->suspendSource(),
            'reason' => $s->wa_suspended_reason,
            'at' => $s->wa_suspended_at?->toIso8601String(),
            'by_name' => $s->wa_suspended_by ? User::whereKey($s->wa_suspended_by)->value('name') : null,
            'review_pending' => (bool) Setting::get(BackupSafeMode::REVIEW_KEY),
        ];
    }

    private function sourceOf(string $key): ?string
    {
        return WaBotSetting::current()->sources()[$key]['source'] ?? null;
    }

    private function authorizeSettings(): void
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);
    }
}
