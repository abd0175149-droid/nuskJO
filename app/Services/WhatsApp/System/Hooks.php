<?php

namespace App\Services\WhatsApp\System;

use App\Models\WaConversation;
use App\Models\WaOutbox;
use Illuminate\Support\Facades\Schema;

/** نقاط ربط صغيرة بين الصندوق وطابور رسائل النظام */
final class Hooks
{
    private static ?bool $ready = null;

    /** وارد من العميل: تُلغى متابعاته المعلقة لأنواع cancel_on_reply، على هذه المحادثة أو هاتفه على أي رقم */
    public static function customerReplied(WaConversation $conv): void
    {
        if (!(self::$ready ??= Schema::hasTable('wa_outbox'))) {
            return;
        }
        $keys = array_keys(array_filter(Catalog::TYPES, fn ($t, $k) => (bool) (Catalog::effective($k)['cancel_on_reply'] ?? false), ARRAY_FILTER_USE_BOTH));
        if ($keys === []) {
            return;
        }
        WaOutbox::query()->whereIn('status', WaOutbox::OPEN)->whereIn('type_key', $keys)->where('is_test', false)
            ->where(fn ($q) => $q->where('conversation_id', $conv->id)->when($conv->phone, fn ($w) => $w->orWhere('phone', \App\Services\WhatsApp\PhoneNormalizer::canonical((string) $conv->phone))))
            ->update(['status' => WaOutbox::CANCELLED, 'defer_reason' => 'رد العميل قبل موعدها', 'updated_at' => now()]);
    }
}
