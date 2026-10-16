<?php

namespace App\Http\Middleware;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Services\Integrations\CloudflareAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cloudflare Access أمام /mcp/* (E40.9): رمز Cf-Access-Jwt-Assertion صالح لفريقنا وتطبيقنا، وإلا 404.
 * في الإنتاج بلا إعداد (MCP_CF_TEAM_DOMAIN وMCP_CF_AUD) يرد 404 دائماً: يفشل مغلقاً. خارج الإنتاج يُتخطى حتى يُضبط.
 */
class VerifyCloudflareAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!CloudflareAccess::configured()) {
            abort_if(app()->isProduction(), 404);

            return $next($request);
        }

        $jwt = (string) $request->header('Cf-Access-Jwt-Assertion', '');
        if ($jwt === '' || !app(CloudflareAccess::class)->verify($jwt)) {
            AuditLog::sampled(AuditAction::McpAuthFailed, 'cf:'.$request->ip(), 'mcp', null, 'cloudflare', ['reason' => $jwt === '' ? 'cf_missing' : 'cf_invalid']);
            abort(404);
        }

        return $next($request);
    }
}
