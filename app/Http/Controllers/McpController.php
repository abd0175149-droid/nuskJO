<?php

namespace App\Http\Controllers;

use App\Mcp\Servers\OwnerReportsServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * نقطة خادم MCP للقراءة (E40.9، routes/ai.php، خارج مجموعة web: لا جلسة ولا CSRF). Streamable HTTP بأبسط أشكاله:
 * POST برسالة JSON-RPC واحدة ورد JSON واحد، والإشعار 202 بلا جسم، وبلا قناة SSE (GET وDELETE ‏405).
 */
class McpController extends Controller
{
    public function handle(Request $request, OwnerReportsServer $server): JsonResponse|Response
    {
        // حماية من DNS rebinding في المتصفح (مواصفة MCP): Origin إن وُجد يجب أن يكون مضيفنا
        $origin = $request->headers->get('Origin');
        if ($origin !== null && parse_url($origin, PHP_URL_HOST) !== parse_url((string) config('app.url'), PHP_URL_HOST)) {
            return response()->json(OwnerReportsServer::error(null, -32600, 'Origin not allowed'), 403);
        }
        $version = $request->headers->get('MCP-Protocol-Version');
        if ($version !== null && !in_array($version, OwnerReportsServer::PROTOCOL_VERSIONS, true)) {
            return response()->json(OwnerReportsServer::error(null, -32600, 'Unsupported protocol version'), 400);
        }

        $message = json_decode($request->getContent(), true, 32);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return response()->json(OwnerReportsServer::error(null, -32700, 'Parse error'), 400);
        }
        if (is_array($message) && array_is_list($message) && $message !== []) {
            return response()->json(OwnerReportsServer::error(null, -32600, 'Batching is not supported'), 400);
        }

        $reply = $server->handle($message, $request->user());

        return $reply === null
            ? response()->noContent(202)
            : response()->json($reply, 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** لا قناة SSE ولا جلسات: الطلبات كلها POST */
    public function unsupported(): Response
    {
        return response('', 405, ['Allow' => 'POST']);
    }
}
