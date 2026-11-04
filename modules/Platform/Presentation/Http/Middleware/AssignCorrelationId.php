<?php

namespace Modules\Platform\Presentation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Platform\Contracts\Observability\CorrelationId;
use Symfony\Component\HttpFoundation\Response;

final class AssignCorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = CorrelationId::start($request->headers->get(CorrelationId::HEADER));

        $response = $next($request);
        $response->headers->set(CorrelationId::HEADER, $id);

        return $response;
    }
}
