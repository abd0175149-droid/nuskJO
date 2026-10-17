<?php

namespace App\Http\Middleware;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * مصادقة خادم MCP برمز وصول شخصي (E40.9، بديل auth:sanctum وabilities بلا الحزمة): Bearer «id|njmcp_…» غير منتهٍ،
 * لمستخدم نشط يملك mcp.use، وبالقدرة المطلوبة (mcp:read). بلا رمز أو برمز باطل 401، وبلا القدرة أو الصلاحية 403.
 * كل رفض يُدقَّق بالعيّنات بلا الرمز. آخر استعمال يُكتب مرة في الدقيقة على الأكثر (SQLite واحد).
 */
class AuthenticateMcpToken
{
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $plain = (string) $request->bearerToken();
        $token = $plain === '' ? null : PersonalAccessToken::findToken($plain);
        $user = $token?->tokenable;

        if (!$token || !$user instanceof User) {
            return $this->deny($request, 401, $plain === '' ? 'missing' : 'invalid');
        }
        if ($token->expired()) {
            return $this->deny($request, 401, 'expired', $user);
        }
        if (!$user->is_active) {
            return $this->deny($request, 401, 'inactive', $user);
        }
        foreach ($abilities as $ability) {
            if (!$token->can($ability)) {
                return $this->deny($request, 403, 'ability', $user);
            }
        }
        if (!$user->can('mcp.use')) {
            return $this->deny($request, 403, 'permission', $user);
        }

        if (!$token->last_used_at || $token->last_used_at->lt(now()->subMinute())) {
            PersonalAccessToken::query()->whereKey($token->id)->update(['last_used_at' => now()]);
        }
        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }

    private function deny(Request $request, int $status, string $reason, ?User $user = null): JsonResponse
    {
        AuditLog::sampled(AuditAction::McpAuthFailed, $reason.':'.($user?->id ?? $request->ip()), 'mcp', $user?->id, 'token', ['reason' => $reason]);

        return response()->json(['error' => $status === 401 ? 'unauthorized' : 'forbidden'], $status, $status === 401 ? ['WWW-Authenticate' => 'Bearer'] : []);
    }
}
