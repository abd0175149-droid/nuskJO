<?php

namespace App\Models\Concerns;

use App\Models\PersonalAccessToken;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

/**
 * رموز الوصول الشخصية للمستخدم (E40): الشكل الذي يعطيه Laravel\Sanctum\HasApiTokens، بلا الحزمة.
 * createToken يعيد النص الصريح مرة واحدة: «id|njmcp_» ثم 40 حرفاً عشوائياً وبصمة crc32b للتعرّف على الرمز المسرّب.
 */
trait HasApiTokens
{
    public function tokens(): MorphMany
    {
        return $this->morphMany(PersonalAccessToken::class, 'tokenable');
    }

    /** @return array{accessToken: PersonalAccessToken, plainTextToken: string} */
    public function createToken(string $name, array $abilities, ?DateTimeInterface $expiresAt): array
    {
        $entropy = Str::random(40);
        $secret = PersonalAccessToken::PREFIX.$entropy.hash('crc32b', $entropy);
        $token = $this->tokens()->create([
            'name' => $name,
            'token' => hash('sha256', $secret),
            'abilities' => array_values($abilities),
            'expires_at' => $expiresAt,
        ]);

        return ['accessToken' => $token, 'plainTextToken' => $token->getKey().'|'.$secret];
    }
}
