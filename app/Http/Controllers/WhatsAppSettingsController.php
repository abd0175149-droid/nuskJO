<?php

namespace App\Http\Controllers;

use App\Console\Commands\BackupSafeMode;
use App\Enums\AuditAction;
use App\Events\WhatsApp\ChannelHealthChanged;
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
use App\Services\WhatsApp\GraphVersions;
use App\Services\WhatsApp\IdentityMode;
use App\Services\WhatsApp\LlmCircuitBreaker;
use App\Services\WhatsApp\Llm\LlmFactory;
use App\Services\WhatsApp\MetaHealth;
use App\Services\WhatsApp\Settings\SettingsSchema;
use App\Services\WhatsApp\Settings\SettingsWriter;
use App\Services\WhatsApp\WhatsAppChannel;
use App\Services\WhatsApp\WhatsAppInbox;
use App\Support\FeatureFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * إعدادات بوت الواتساب (E10): حفظ جزئي برمز نسخة (409 عند التعارض)، وإيقاف طارئ بسبب في مسار مستقل،
 * وأسرار تُعيَّن وتُمسح ولا تعود للمتصفح أبداً (عدا رمز التحقق المولَّد لحظة توليده). كل كتابة عبر SettingsWriter.
 */
class WhatsAppSettingsController extends Controller
{
    private const SECRETS = ['wa_token' => 'رمز ميتا', 'wa_app_secret' => 'App Secret', 'api_key' => 'مفتاح المزوّد', 'fallback_api_key' => 'مفتاح المزوّد الاحتياطي'];

    public function show()
    {
        $this->authorizeSettings();

        return Inertia::render('WhatsApp/Settings', $this->payload() + [
            'title' => 'إعدادات بوت الواتساب',
            'providers' => LlmFactory::PROVIDERS,
            'webhookUrl' => url('/api/whatsapp/webhook'),
            'defaultPrompt' => BotEngine::DEFAULT_PROMPT,
            'toolList' => BotTools::labels(),
            // من سجل الأدوات (E15): شارة «يحوّل لموظف» وما لا يُطفأ
            'toolMeta' => BotTools::meta(),
            'graph' => GraphVersions::panel(),
            'health' => ChannelDoctor::checks(),
            'ops' => app(OpsHealth::class)->snapshot(),
            'meta' => MetaHealth::summary(WaBotSetting::current()),
            'mediaArchive' => rescue(fn () => \App\Services\WhatsApp\Media\MediaArchiver::summary(), null, false),
            // E20: استقبال الويبهوك (أعداد فقط)
            'webhookIntake' => rescue(fn () => \App\Services\WhatsApp\WebhookStats::summary(), null, false),
            // E26: آخر فحص أمني للصورة (التاريخ والأعداد فقط)
            'securityScan' => rescue(fn () => \App\Services\Ops\SecurityScan::summary(), null, false),
            // E18: آخر خطأ قناة من ميتا، وفئات اليوم، ونسبة إثبات سر التطبيق خلال 7 أيام
            'metaErrors' => [
                'last' => WaBotSetting::current()->wa_last_error,
                'today' => \App\Services\WhatsApp\MetaErrorReactor::categoryCounts(),
                'proof' => \App\Services\WhatsApp\Graph\GraphClient::proofSummary(),
            ],
        ]);
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
            'changes.wa_app_id.regex' => 'معرّف التطبيق أرقام فقط (من App settings › Basic)',
            'changes.wa_phone_number_id.regex' => 'Phone Number ID أرقام فقط',
            'changes.wa_waba_id.regex' => 'معرّف حساب الأعمال أرقام فقط (من WhatsApp Manager)',
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

        if (array_key_exists('wa_phone_number_id', $changes) && (string) $changes['wa_phone_number_id'] !== (string) $settings->wa_phone_number_id) {
            if ($changes['wa_phone_number_id'] !== null && WhatsAppChannel::probePhoneNumberId((string) $changes['wa_phone_number_id']) === false) {
                throw ValidationException::withMessages(['changes.wa_phone_number_id' => 'ميتا لا تعرف هذا المعرّف برمزنا الحالي — انسخه من WhatsApp › API Setup']);
            }
            // رقم جديد: ما تعلّمناه عن القديم لا يصلح لتمييز أحداث الحساب، فيُتعلّم من أول رسالة. المضبوط يدوياً يبقى
            $changes += ['wa_display_phone' => null];
            if ($settings->wa_waba_id_source !== 'manual') {
                $changes += ['wa_waba_id' => null, 'wa_waba_id_source' => null];
            }
        }
        // معرّف الحساب من الإعدادات يدوي (يفرض السياج ولا يكتب فوقه المتعلَّم)، ومسحه يعيده للتعلّم
        if (array_key_exists('wa_waba_id', $changes) && !array_key_exists('wa_waba_id_source', $changes)) {
            $changes['wa_waba_id_source'] = $changes['wa_waba_id'] === null ? null : 'manual';
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
            if (trim((string) ($data['confirm_text'] ?? '')) !== 'استئناف') {
                throw ValidationException::withMessages(['confirm_text' => 'اكتب «استئناف» للتأكيد']);
            }
            // بعد استعادة نسخة: القفل يبقى حتى يعتمد المدير المراجعة
            if ($s->wa_suspended && Setting::get(BackupSafeMode::REVIEW_KEY)) {
                throw ValidationException::withMessages(['state' => 'أكمل مراجعة ما بعد الاستعادة من صفحة النسخ الاحتياطي أولاً']);
            }
            // قفل من ميتا أو من رمز خطأ: تأكيد إضافي أن الحساب رُوجع
            if (in_array($s->wa_suspended_source, ['meta_event', 'graph_error'], true) && !$request->boolean('acknowledge')) {
                throw ValidationException::withMessages(['acknowledge' => 'أُوقف الإرسال بحدث من ميتا ('.$s->wa_suspended_reason.') — أكّد أنك راجعت حالة الحساب']);
            }
        }

        if ($on !== (bool) $s->wa_suspended) {
            $previous = $s->wa_suspended_source;
            SettingsWriter::apply([
                'wa_suspended' => $on, 'wa_suspended_at' => now(), 'wa_suspended_by' => $user->id,
                'wa_suspended_source' => $on ? 'manual' : null, 'wa_suspended_reason' => $data['reason'],
            ], null, AuditAction::WaSuspendManual, ['on' => $on, 'reason' => $data['reason']] + ($on ? [] : ['previous_source' => $previous]), label: 'قفل الإرسال الطارئ');
            // E21: تغيّر فعلي في صحة القناة بيد مستخدم (السبب نص المدير فلا يُحمل في الحدث)
            $suspended = $previous === 'manual' ? ChannelHealthChanged::SUSPENDED_MANUAL : ChannelHealthChanged::SUSPENDED_AUTO;
            \App\Services\WhatsApp\EventBus::emit($on
                ? new ChannelHealthChanged(ChannelHealthChanged::AVAILABLE, ChannelHealthChanged::SUSPENDED_MANUAL, 'manual', null)
                : new ChannelHealthChanged($suspended, ChannelHealthChanged::AVAILABLE, 'manual', $previous));

            WhatsAppInbox::notifyAdmins(
                $on ? '⛔ أُوقف إرسال الواتساب' : '✅ استُؤنف إرسال الواتساب',
                ($on ? 'أوقفه ' : 'استأنفه ').$user->name.': '.$data['reason'],
                '/whatsapp/settings',
            );
        }

        return response()->json(['killSwitch' => $this->killSwitchState(WaBotSetting::current())]);
    }

    /** تعيين سر (رمز ميتا، App Secret، مفتاح المزوّد) بسبب. الرد بلا القيمة */
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

    /** رمز تحقق عشوائي يُعاد مرة واحدة هنا؛ القاعدة تحفظ هاشه وحده */
    public function rotateVerifyToken(Request $request): JsonResponse
    {
        $this->authorizeSettings();
        $data = $request->validate(['reason' => 'required|in:routine,leak,setup,other']);
        if ((string) config('services.whatsapp.verify_token') !== '') {
            return response()->json(['message' => 'الرمز من البيئة (WA_WEBHOOK_VERIFY_TOKEN) — يُغيَّر في .env.production'], 409);
        }

        $token = bin2hex(random_bytes(32));
        $fresh = SettingsWriter::apply([
            'wa_verify_token' => null, 'wa_verify_token_hash' => hash('sha256', $token), 'wa_verify_token_rotated_at' => now(),
        ], null, AuditAction::WaVerifyRotate, ['reason' => $data['reason']], label: 'رمز التحقق');

        return response()->json([
            'token' => $token,
            'rotated_at' => $fresh->wa_verify_token_rotated_at?->toIso8601String(),
        ])->header('Cache-Control', 'no-store');
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

    /** «تمّ الحل» لآخر خطأ من ميتا (E18): يصفّر wa_last_error وحده، ولا يرفع أي قفل */
    public function clearLastError(): JsonResponse
    {
        $this->authorizeSettings();
        $s = WaBotSetting::current();
        $last = (array) ($s->wa_last_error ?? []);
        WaBotSetting::whereKey($s->getKey())->update(['wa_last_error' => null]);
        AuditLog::safe(AuditAction::WaLastErrorCleared, 'setting', null, 'wa_last_error',
            array_filter(['meta_code' => $last['meta_code'] ?? null, 'error_code' => $last['error_code'] ?? null], fn ($v) => $v !== null));

        return response()->json(['ok' => true, 'last_error' => null]);
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

    /**
     * PATCH api/whatsapp/settings/identity-mode: التعامل مع عميل بلا رقم ظاهر (E11.9). مثل أمر
     * whatsapp:identity-mode تماماً: Setting لا عمود في wa_bot_settings، والسجل بالقيمتين.
     */
    public function identityMode(Request $request): JsonResponse
    {
        $this->authorizeSettings();
        $data = $request->validate(['mode' => ['required', 'string', Rule::in(IdentityMode::MODES)]]);

        $before = IdentityMode::current();
        if ($before !== $data['mode']) {
            DB::transaction(function () use ($before, $data) {
                Setting::set('wa_identity_mode', $data['mode']);
                AuditLog::log(AuditAction::WaIdentityMode, 'setting', null, 'wa_identity_mode', ['from' => $before, 'to' => $data['mode']]);
            });
        }

        return response()->json(['identityMode' => IdentityMode::current()]);
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
                'has' => !empty(match ($key) {
                    'wa_token' => $s->token(), 'wa_app_secret' => $s->appSecret(), 'fallback_api_key' => $s->fallbackKey(), default => $s->llmKey()
                }),
                // مفتاح الاحتياطي بلا متغير بيئة خاص به (البيئة لمزوّده تُقرأ في fallbackKey)
                'source' => $sources[$key]['source'] ?? (filled($s->getAttributes()[$key] ?? null) ? 'db' : 'unset'),
                'env_var' => $sources[$key]['env_var'] ?? null,
                'decrypt_ok' => $sources[$key]['decrypt_ok'] ?? $s->decryptOk($key),
                'rotated_at' => $s->{"{$key}_rotated_at"}?->toIso8601String(),
            ])->all(),
            'verify' => [
                'has' => $s->verifyTokenHash() !== null,
                'source' => $sources['wa_verify_token']['source'],
                'rotated_at' => $s->wa_verify_token_rotated_at?->toIso8601String(),
                'last_handshake_at' => Cache::get('wa:verify:last_handshake_at'),
                // نص صريح قديم ما زال محفوظاً (يمسحه whatsapp:settings-finalize)
                'legacy_plain' => !empty($s->wa_verify_token),
            ],
            'signature' => [
                'last_ok_at' => Cache::get('wa:sig:last_ok_at'),
                'failures_last_hour' => \App\Services\WhatsApp\WebhookIntake::badSignatureCounts()['hour'],
            ],
            'killSwitch' => $this->killSwitchState($s),
            'breaker' => $this->breakerState($s),
            // E29: تأكيد الطبقة المدفوعة لمزوّد الذكاء (شرط ترجمة الرسائل للموظف)
            'llmTerms' => \App\Services\WhatsApp\MessageTranslator::termsState($s),
            'flags' => FeatureFlags::panel(),
            'identityMode' => IdentityMode::current(),
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
            'wa_phone_number_id' => $s->wa_phone_number_id,
            // حقل النموذج: المضبوط يدوياً وحده (E20). المتعلَّم من أول رسالة لرقمنا يُعرض «مكتشفاً» للمقارنة
            'wa_waba_id' => $s->wa_waba_id_source === 'manual' ? $s->wa_waba_id : null,
            'wa_waba_id_source' => $s->wa_waba_id_source,
            'wa_waba_detected' => \App\Services\Ops\OpsHealth::store()->get('wa:waba:detected') ?: ($s->wa_waba_id_source !== 'manual' ? $s->wa_waba_id : null),
            'wa_display_phone' => $s->wa_display_phone,
            'wa_app_id' => $s->wa_app_id,
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
            'env_app_id' => !empty(config('services.whatsapp.app_id')),
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
