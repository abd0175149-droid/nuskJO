<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id', 'wamid', 'direction', 'source', 'msg_type',
        'body', 'payload', 'status', 'error_message', 'staff_id', 'meta_code', 'fbtrace_id', 'meta_at',
        // E11: رقمنا، والاقتباس، ورمز الخطأ الداخلي، ومفتاح الإرسال، ووصف الوسائط، والإحالة
        'phone_number_id', 'reply_to_wamid', 'error_code', 'send_key',
        'media_id', 'media_mime', 'media_sha256', 'media_filename', 'media_size', 'media_path', 'referral',
        // E14: حالة الأرشفة (pending|stored|too_large|failed|expired|rejected|purged|missing|skipped_quota|skipped_consent)
        'media_status', 'media_error', 'media_archived_at',
    ];

    // مسار الملف وبصمته لا يخرجان إن سُلسلت الرسالة (الصندوق يبني حقل media بنفسه)
    protected $hidden = ['media_path', 'media_sha256'];

    // meta_at: وقت ميتا للوارد (timestamp الويبهوك)، لا وقت وصوله إلينا
    protected $casts = ['payload' => 'array', 'meta_at' => 'datetime', 'referral' => 'array', 'media_archived_at' => 'datetime', 'media_size' => 'integer'];

    /** @var bool|null عمود body_norm موجود (بين نشر الكود والهجرة لا يكون) */
    private static ?bool $hasBodyNorm = null;

    protected static function booted(): void
    {
        // نص البحث المطبَّع (بلا تشكيل، والهمزات والتاء المربوطة موحّدة) لكل حفظ: الوارد والصادر وتحديث التفريغ الصوتي
        static::saving(function (self $m) {
            self::$hasBodyNorm ??= \Illuminate\Support\Facades\Schema::hasColumn('wa_messages', 'body_norm');
            if (self::$hasBodyNorm && ($m->isDirty('body') || !$m->exists)) {
                $m->body_norm = \App\Services\WhatsApp\TextNormalizer::forSearch($m->body);
            }
        });
    }

    public function conversation(): BelongsTo { return $this->belongsTo(WaConversation::class, 'conversation_id'); }

    /** الموظف الذي أرسل الرسالة — للرسائل من نوع staff */
    public function staff(): BelongsTo { return $this->belongsTo(User::class, 'staff_id'); }

    public function isInbound(): bool { return $this->direction === 'in'; }
}
