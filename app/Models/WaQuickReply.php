<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * رد جاهز يدرجه الموظف في المنشئ (E17): متغيراته تُملأ في الخادم (QuickReplyRenderer)، ويُرسل بالمسار العادي
 * داخل نافذة الـ24 ساعة. نص يذكر بيانات دفع لا يُفعَّل قبل اعتماد من يملك whatsapp.settings.
 */
class WaQuickReply extends Model
{
    use SoftDeletes;

    public const CATEGORIES = [
        'general' => 'عام',
        'visa' => 'التأشيرات',
        'umrah' => 'العمرة',
        'documents' => 'الوثائق',
        'followup' => 'المتابعة',
        'hours' => 'الدوام',
    ];

    protected $fillable = [
        'title', 'body', 'category', 'is_active', 'needs_approval', 'approved_by', 'approved_at',
        'sort_order', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'needs_approval' => 'boolean',
        'approved_at' => 'datetime',
        'sort_order' => 'integer',
    ];

    /** ما يظهر للموظف في المنتقي: مفعّل وغير بانتظار اعتماد */
    public function scopeUsable($query)
    {
        return $query->where('is_active', true)->where('needs_approval', false);
    }
}
