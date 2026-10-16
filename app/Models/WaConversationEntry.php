<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * مصدر بدء المحادثة (E38): رمز QR (qr) أو رابط برسالة معبأة (link) أو إعلان انقر للواتساب (ad).
 * قياس داخلي تقريبي: ميتا لا توفر تحليلات للرموز، والعميل قد يحذف المرجع. لا ctwa_clid ولا نص الإعلان.
 */
class WaConversationEntry extends Model
{
    public const UPDATED_AT = null;

    public const CHANNELS = ['qr' => '📷 رمز QR', 'link' => '🔗 رابط', 'ad' => '📣 إعلان'];

    protected $fillable = [
        'conversation_id', 'message_id', 'offer_id', 'qr_link_id', 'channel',
        'referral_source_type', 'referral_source_id', 'referral_headline', 'created_at',
    ];

    protected $casts = ['created_at' => 'datetime', 'offer_id' => 'integer', 'qr_link_id' => 'integer'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WaConversation::class, 'conversation_id');
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function qrLink(): BelongsTo
    {
        return $this->belongsTo(WaQrLink::class, 'qr_link_id');
    }
}
