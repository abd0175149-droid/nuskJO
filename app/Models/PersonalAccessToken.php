<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * رمز وصول شخصي (E40، خادم MCP): مخطط Sanctum نفسه (الرمز بصمة sha256، وقدرات، وانتهاء)، بلا الحزمة.
 * النص الصريح «id|njmcp_…» يُعرض مرة واحدة عند الإنشاء ولا يُخزَّن. القدرة الوحيدة اليوم mcp:read.
 */
class PersonalAccessToken extends Model
{
    public const PREFIX = 'njmcp_';

    protected $fillable = ['name', 'token', 'abilities', 'expires_at'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'abilities' => 'json',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function tokenable(): MorphTo
    {
        return $this->morphTo('tokenable');
    }

    /** الرمز من ترويسة Bearer: المعرّف ثم مقارنة البصمة بزمن ثابت. null لأي شكل غير صالح */
    public static function findToken(string $plain): ?self
    {
        if (!preg_match('/^(\d{1,18})\|('.preg_quote(self::PREFIX, '/').'[A-Za-z0-9]{40,80})$/', $plain, $m)) {
            return null;
        }
        $token = static::query()->find((int) $m[1]);

        return $token && hash_equals((string) $token->token, hash('sha256', $m[2])) ? $token : null;
    }

    public function can(string $ability): bool
    {
        $abilities = (array) $this->abilities;

        return in_array('*', $abilities, true) || in_array($ability, $abilities, true);
    }

    public function expired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
