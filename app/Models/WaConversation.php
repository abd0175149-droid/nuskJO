<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WaConversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'phone', 'wa_phone', 'display_name', 'client_id', 'user_id',
        'bot_enabled', 'bot_paused_until', 'last_inbound_at', 'last_outbound_at',
        'last_message_at', 'last_message_preview', 'unread_count',
        'needs_attention', 'assigned_to', 'status', 'bot_answered_in_id', 'attention_reason',
        // E11a: معرّف ميتا الخاص بنشاطنا (BSUID) واسم المستخدم والاسم كما أرسله العميل (غير موثوقين)
        'wa_user_id', 'wa_username', 'wa_profile_name',
        // E11: رقمنا المستقبِل، وأول إحالة (إعلان)، وحالة الربط بعميل (auto | manual | blocked)، والدمج
        'phone_number_id', 'referral', 'client_link', 'client_linked_by', 'client_linked_at', 'merged_into_id',
        // E14: إشعار حفظ الملفات واختيار العميل (granted | refused)
        'media_consent', 'media_consent_at', 'media_notice_at',
        // E17: الإغلاق والتأجيل و«غير مقروء» الداخلي (حالة عندنا وحدنا، بلا أي نداء لميتا)
        'closed_at', 'closed_by', 'snoozed_until', 'snoozed_by', 'marked_unread_at', 'marked_unread_by',
    ];

    // is_admin_chat في casts لا في fillable: يضبطه WaAdminNumber وحده (علَم لاصق)
    protected $casts = [
        'bot_enabled' => 'boolean',
        'needs_attention' => 'boolean',
        'is_admin_chat' => 'boolean',
        'bot_paused_until' => 'datetime',
        'last_inbound_at' => 'datetime',
        'last_outbound_at' => 'datetime',
        'last_message_at' => 'datetime',
        'referral' => 'array',
        'client_linked_at' => 'datetime',
        'erased_at' => 'datetime',
        'media_consent_at' => 'datetime',
        'media_notice_at' => 'datetime',
        'closed_at' => 'datetime',
        'snoozed_until' => 'datetime',
        'marked_unread_at' => 'datetime',
    ];

    /**
     * حقول تعرضها القائمة أو رأس المحادثة (E28): تغيّر أحدها بحفظ النموذج يُبلغ من يراها الآن (meta)، ومن فقدها بنقل
     * التوكيل. الرسائل والحالات من أحداث E21، والتوسيم من TopicRouter، والتحديث الجماعي من موضعه صراحةً.
     */
    public const REALTIME_FIELDS = [
        'bot_enabled', 'bot_paused_until', 'needs_attention', 'attention_reason', 'assigned_to', 'unread_count', 'status',
        'display_name', 'client_id', 'client_link', 'wa_username', 'wa_profile_name', 'phone', 'merged_into_id',
        'closed_at', 'snoozed_until', 'marked_unread_at', 'marked_unread_by',
    ];

    protected static function booted(): void
    {
        static::updated(function (self $conversation) {
            if (!$conversation->wasChanged(self::REALTIME_FIELDS)) {
                return;
            }
            $lost = $conversation->wasChanged('assigned_to') && $conversation->getOriginal('assigned_to') !== null
                ? [(int) $conversation->getOriginal('assigned_to')] : [];
            $conversation->touchRealtime('meta', $lost);
        });
    }

    /** البث اللحظي (E28): يُجمَّع في الطلب أو الوظيفة ويُبث بعد الرد أو الاعتماد. لا يرمي، وبلا أثر والعلم مطفأ */
    public function touchRealtime(string $kind, array $also = []): void
    {
        app(\App\Services\WhatsApp\Realtime\InboxBroadcaster::class)->touch((int) $this->id, $kind, $also);
    }

    /**
     * المحادثات التي يراها المستخدم (السياج في TopicRouter::scopeVisible).
     * scope محلي عن قصد، لا global scope: الويبهوك والطابور والبوت يعملون بلا مستخدم.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return \App\Services\WhatsApp\TopicRouter::scopeVisible($query, $user);
    }

    public function messages(): HasMany { return $this->hasMany(WaMessage::class, 'conversation_id'); }
    public function notes(): HasMany { return $this->hasMany(WaCustomerNote::class, 'conversation_id'); }
    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
    public function pins(): HasMany { return $this->hasMany(WaConversationPin::class, 'conversation_id'); }
    public function markedUnreadBy(): BelongsTo { return $this->belongsTo(User::class, 'marked_unread_by'); }

    /** الرقم معروف (عميل باسم مستخدم قد يراسلنا بلا رقم) */
    public function hasPhone(): bool
    {
        return filled($this->phone);
    }

    /**
     * العميل الذي تُعرض بياناته المالية: فقط إن عُرف الرقم. الربط بالمعرّف أو بالاسم وحده لا يكشف رصيداً.
     */
    public function financialClientId(): ?int
    {
        return $this->hasPhone() && $this->client_id ? (int) $this->client_id : null;
    }

    /** اسم للعرض والإشعارات: العميل، ثم الاسم، ثم اسم المستخدم، ثم الرقم، ثم رقم المحادثة */
    public function label(): string
    {
        return $this->client?->name
            ?: $this->display_name
            ?: $this->wa_profile_name
            ?: ($this->wa_username ? '@'.$this->wa_username : null)
            ?: ($this->hasPhone() ? '+'.$this->phone : null)
            ?: 'محادثة #'.$this->id;
    }

    /** للإشعارات والجرس (E15.10): مثل label() بلا رقم الهاتف */
    public function noticeLabel(): string
    {
        return $this->client?->name
            ?: $this->display_name
            ?: $this->wa_profile_name
            ?: ($this->wa_username ? '@'.$this->wa_username : null)
            ?: 'محادثة #'.$this->id;
    }

    /** نافذة ميتا: 24 ساعة من آخر رسالة واردة */
    public function isWindowOpen(): bool
    {
        return $this->last_inbound_at && $this->last_inbound_at->gt(now()->subDay());
    }

    /** الدقائق الباقية قبل إغلاق النافذة — null إن كانت مغلقة */
    public function windowMinutesLeft(): ?int
    {
        if (!$this->isWindowOpen()) {
            return null;
        }

        return (int) max(0, now()->diffInMinutes($this->last_inbound_at->copy()->addDay(), false));
    }

    /** هل البوت فعّال لهذه المحادثة الآن؟ */
    public function botActive(): bool
    {
        if (!$this->bot_enabled) return false;
        if ($this->bot_paused_until && $this->bot_paused_until->isFuture()) return false;
        return true;
    }

    public function pauseBot(int $minutes): void
    {
        $this->update(['bot_paused_until' => now()->addMinutes($minutes)]);
    }
}
