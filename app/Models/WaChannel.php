<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * رقم واتساب على بوابة OpenWA: جلسة واحدة بمفتاح API محصور بها وسر توقيع webhook خاص بها (docs/whatsapp/openwa.md).
 * المفتاح والسر مشفّران ولا يخرجان في أي سلسلة (hidden). status من أحداث session.* (ChannelState)، والجاهزية الفعلية
 * من الفحص الحقيقي (whatsapp:channel-watch): «ready» وحدها لا تكفي، فالجلسة قد تبقى ready وهي عالقة.
 */
class WaChannel extends Model
{
    protected $fillable = [
        'uuid', 'name', 'phone', 'push_name', 'color', 'openwa_session_id', 'api_key', 'webhook_secret', 'webhook_id',
        'status', 'status_at', 'last_probe_ok_at', 'probe_failures', 'last_restart_at', 'last_inbound_at',
        'roles', 'bot_mode', 'is_default_for', 'warmup_started_at', 'paused_until', 'pause_reason', 'is_active', 'sort',
        'history_import_state', 'history_queue', 'history_imported_at',
    ];

    // قائمة المحادثات المنتظرة للاستيراد قد تكون آلاف المعرّفات: لا تخرج في أي سلسلة
    protected $hidden = ['api_key', 'webhook_secret', 'history_queue'];

    protected $casts = [
        'api_key' => 'encrypted',
        'webhook_secret' => 'encrypted',
        'roles' => 'array',
        'is_default_for' => 'array',
        'status_at' => 'datetime',
        'last_probe_ok_at' => 'datetime',
        'last_restart_at' => 'datetime',
        'last_inbound_at' => 'datetime',
        'warmup_started_at' => 'datetime',
        'paused_until' => 'datetime',
        'history_import_state' => 'array',
        'history_queue' => 'array',
        'history_imported_at' => 'datetime',
        'is_active' => 'boolean',
        'probe_failures' => 'integer',
    ];

    /** حالات البوابة كما هي (SessionStatus في OpenWA) */
    public const STATUSES = ['created', 'initializing', 'qr_ready', 'authenticating', 'ready', 'disconnected', 'action_required', 'failed'];

    /** متصلة برقم: مطابقة حرفية، فـ qr_ready تحتوي «ready» وهي انتظار ربط (درس WB web) */
    public const LINKED = ['ready'];

    public const ROLES = ['sales', 'support', 'notifications', 'admin'];

    public const BOT_MODES = ['full', 'limited', 'off'];

    /** ألوان الشارة في الصندوق بالترتيب لكل رقم جديد */
    public const PALETTE = ['#b8800f', '#2f7d5b', '#3b6ea8', '#a8473b', '#7a4fa0', '#4f7f8a'];

    protected static function booted(): void
    {
        static::creating(function (self $c) {
            $c->uuid ??= (string) Str::uuid();
        });
        static::saved(function (self $c) {
            self::$readyCache = null;
            // مفتاح جديد أو سر جديد يُقنَّع فوراً في السجلات
            if ($c->wasChanged(['api_key', 'webhook_secret']) || $c->wasRecentlyCreated) {
                \App\Support\SecretRedactor::forget();
            }
        });
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'wa_channel_users', 'channel_id', 'user_id')->withPivot('is_primary')->withTimestamps();
    }

    public function conversations(): HasMany { return $this->hasMany(WaConversation::class, 'channel_id'); }

    public function events(): HasMany { return $this->hasMany(WaChannelEvent::class, 'channel_id'); }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /** مربوط برقم ومتصل: الحالة ready حرفياً، والرقم معروف */
    public function isLinked(): bool
    {
        return in_array($this->status, self::LINKED, true) && filled($this->phone);
    }

    /**
     * جاهز للإرسال: مربوط، ونجح آخر فحص حقيقي خلال المدة (config whatsapp.openwa.ready_probe_minutes). قبل أول فحص بعد الربط
     * (status_at حديث) يُعدّ جاهزاً، فلا ينتظر رقم ربطه المدير للتو دقيقتين.
     */
    public function isReady(): bool
    {
        if (!$this->is_active || !$this->isLinked() || $this->openwa_session_id === null) {
            return false;
        }
        $window = now()->subMinutes(max(1, (int) config('whatsapp.openwa.ready_probe_minutes', 5)));
        if ($this->last_probe_ok_at?->gte($window)) {
            return true;
        }

        return $this->probe_failures === 0 && $this->status_at?->gte($window);
    }

    public function isPaused(): bool
    {
        return $this->paused_until !== null && $this->paused_until->isFuture();
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, (array) $this->roles, true);
    }

    /** الرقم الافتراضي لمجموعة رسائل نظام (invoice، trip، quote، lead، admin) */
    public function isDefaultFor(string $group): bool
    {
        return in_array($group, (array) $this->is_default_for, true);
    }

    /** jid رقمنا نفسه (للفحص الحقيقي contacts/check) */
    public function ownDigits(): string
    {
        return preg_replace('/\D/', '', (string) $this->phone) ?? '';
    }

    public function label(): string
    {
        return $this->name.($this->phone ? ' · +'.$this->phone : '');
    }

    /** عنوان الـ webhook كما تراه البوابة داخل شبكة compose */
    public function webhookUrl(): string
    {
        return config('whatsapp.openwa.webhook_base').'/api/openwa/webhook/'.$this->uuid;
    }

    public function logEvent(string $event, array $detail = []): void
    {
        rescue(fn () => WaChannelEvent::create(['channel_id' => $this->id, 'event' => mb_substr($event, 0, 32), 'detail' => $detail ?: null, 'created_at' => now()]), null, false);
    }

    private static ?bool $readyCache = null;

    /** في النظام رقم واحد على الأقل فعّال ومربوط (بديل channelReady القديم، يُحسب مرة لكل طلب) */
    public static function anyLinked(): bool
    {
        return self::$readyCache ??= rescue(fn () => self::query()->active()->where('status', 'ready')->whereNotNull('phone')->exists(), false, false);
    }

    public static function forgetCache(): void
    {
        self::$readyCache = null;
    }

    /**
     * الرقم الذي تُرسل منه رسالة نظام لمجموعة: الافتراضي لها إن كان جاهزاً، ثم أي رقم جاهز، بترتيب sort ثم id.
     * null: لا رقم جاهز.
     */
    public static function defaultFor(string $group): ?self
    {
        $all = self::query()->active()->orderBy('sort')->orderBy('id')->get();

        return $all->first(fn (self $c) => $c->isDefaultFor($group) && $c->isReady())
            ?? $all->first(fn (self $c) => $c->isReady());
    }

    public static function nextColor(): string
    {
        return self::PALETTE[self::query()->count() % count(self::PALETTE)];
    }
}
