<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * تصنيف سير عمل داخلي على المحادثات (E29): «ينتظر جواز»، «دفع عربون»... يديره المدير ويختار منه الموظف بلا نص حر.
 * منفصل تماماً عن مواضيع التوجيه (wa_conversation_topics): لا يغيّر من يرى المحادثة ولا من وُكّلت إليه،
 * ولا يقرؤه البوت ولا يصل العميل.
 */
class WaLabel extends Model
{
    /** لوحة ثابتة تُرسم في الواجهة بأصناف Tailwind من خريطة، لا لون حر */
    public const COLORS = ['gray', 'red', 'amber', 'green', 'blue', 'purple', 'gold'];

    public const MAX_PER_CONVERSATION = 5;

    protected $fillable = ['name', 'color', 'is_active', 'sort_order', 'created_by'];

    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** الشريحة كما تصل الواجهة: لا شيء غير الاسم واللون والحالة */
    public function chip(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'color' => $this->color, 'active' => (bool) $this->is_active];
    }
}
