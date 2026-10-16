<?php

use App\Http\Controllers\McpController;
use App\Http\Middleware\AuthenticateMcpToken;
use App\Http\Middleware\EnsureMcpEnabled;
use App\Http\Middleware\VerifyCloudflareAccess;
use Illuminate\Support\Facades\Route;

/*
| خادم MCP للقراءة فقط (E40.9): خارج مجموعة web (لا جلسة ولا CSRF ولا Inertia)، ويُحمَّل من bootstrap/app.php.
| الترتيب: العلم (404) ثم Cloudflare Access (404) ثم رمز الوصول (401/403) ثم الحد (30 في الدقيقة لكل مستخدم).
| الموصلات البعيدة في claude.ai (OAuth) غير مدعومة في هذه المرحلة: docs/integrations/mcp.md.
*/

Route::post('mcp/owner', [McpController::class, 'handle'])
    ->middleware([EnsureMcpEnabled::class, VerifyCloudflareAccess::class, AuthenticateMcpToken::class.':mcp:read', 'throttle:mcp'])
    ->name('mcp.owner');
Route::match(['GET', 'DELETE'], 'mcp/owner', [McpController::class, 'unsupported'])
    ->middleware([EnsureMcpEnabled::class, VerifyCloudflareAccess::class]);
