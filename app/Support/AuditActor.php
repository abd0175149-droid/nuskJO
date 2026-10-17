<?php

namespace App\Support;

use Illuminate\Support\Facades\Context;

/**
 * من الفاعل في صف التدقيق: موظف (الافتراضي في طلب ويب)، أو البوت، أو رقم إدارة، أو ميتا، أو النظام.
 * الوسم في السياق المخفي (لا يُكتب في السجلات)، وscope يعيد ما قبله فلا يتسرب إلى كتابات لاحقة.
 */
final class AuditActor
{
    public const TYPES = ['user', 'bot', 'admin_number', 'meta', 'system'];

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public static function as(string $type, ?string $ref, callable $fn, ?int $userId = null): mixed
    {
        return Context::scope($fn, [], ['audit_actor' => ['type' => $type, 'ref' => $ref, 'user_id' => $userId]]);
    }

    /** @return array{type: string, ref: ?string, user_id: ?int} */
    public static function current(): array
    {
        $actor = Context::getHidden('audit_actor');
        if (is_array($actor)) {
            return $actor;
        }
        if (auth()->check()) {
            return ['type' => 'user', 'ref' => null, 'user_id' => (int) auth()->id()];
        }

        // طلب ويب بلا دخول (محاولة دخول، ويبهوك) أو طرفية وطابور
        return ['type' => request()->route() ? 'user' : 'system', 'ref' => null, 'user_id' => null];
    }
}
