<?php

namespace App\Http\Middleware;

use App\Support\FeatureFlags;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** خادم MCP (E40.9): 404 والعلم mcp_server مطفأ، قبل أي مصادقة، فلا يكشف المسار وجوده */
class EnsureMcpEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(FeatureFlags::on('mcp_server'), 404);

        return $next($request);
    }
}
