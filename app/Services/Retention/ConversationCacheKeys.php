<?php

namespace App\Services\Retention;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * مفاتيح الكاش التي تحمل معرّف محادثة (E13): تُمحى مع المحادثة. ConversationCacheKeysTest يفشل إن ظهر في
 * app/ مفتاح «wa:…» بمعرّف محادثة غير مذكور هنا. {id} معرّف المحادثة، و% أي قيمة (لكاش القاعدة وحده).
 */
final class ConversationCacheKeys
{
    public const EXACT = [
        'wa:debounce:{id}', 'wa:running:{id}', 'wa:rerun:{id}', 'wa:admin:pending:{id}', 'wa:unserved:{id}',
        'wa:llm:isolated:{id}', 'wa:outage-notice:notify:{id}', 'wa:media:notice:{id}', 'wa:reprocess:{id}',
        // E35: تهدئة إيصال الفتح
        'wa:rr:open:{id}',
    ];

    public const PATTERNS = [
        'wa:rate:{id}:%', 'wa:rate:admin:{id}:%', 'wa:winrem:{id}:%', 'wa:recon:{id}:%', 'wa:outage-notice:{id}:%',
        'wa:llm:outage:%:{id}', 'wa:llm:admin-outage:%:{id}', 'wa:identity:conflict:{id}:%', 'wa:identity:conflict:%:{id}',
        'audit:view:%:{id}',
    ];

    /** @return int عدد المفاتيح المحذوفة من كاش القاعدة (المفاتيح الدقيقة تُنسى في أي مخزن) */
    public static function forget(int $conversationId): int
    {
        foreach (self::EXACT as $key) {
            Cache::forget(str_replace('{id}', (string) $conversationId, $key));
        }
        if (config('cache.default') !== 'database') {
            return 0;
        }
        $table = config('cache.stores.database.table', 'cache');
        $prefix = (string) config('cache.prefix');
        $deleted = 0;
        foreach (self::PATTERNS as $pattern) {
            $like = $prefix.str_replace('{id}', (string) $conversationId, $pattern);
            $deleted += DB::table($table)->where('key', 'like', $like)->delete();
        }

        return $deleted;
    }
}
