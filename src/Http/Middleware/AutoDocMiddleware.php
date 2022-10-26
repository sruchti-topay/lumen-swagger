<?php

namespace RonasIT\Support\AutoDoc\Http\Middleware;

use Closure;
use RonasIT\Support\AutoDoc\Services\SwaggerService;

/**
 * @property SwaggerService $service
 */
class AutoDocMiddleware
{
    public static $skipped = false;

    public function handle($request, Closure $next)
    {
        $response = $next($request);

        // (config('app.env') == 'testing') &&
        if ( !self::$skipped && !empty($request->route())) {
            app(SwaggerService::class)->addData($request, $response);
        }

        self::$skipped = false;

        return $response;
    }
}
