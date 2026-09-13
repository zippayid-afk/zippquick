<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The product ships WITHOUT a .env file (only .env.example). Until the operator
 * copies it into place the app can't run correctly, so short-circuit every request
 * with a clear "set up your .env" screen. Runs first (prepended) so it fires even
 * before installation. Once .env exists, this is a no-op.
 */
class EnsureEnvFileExists
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!file_exists(base_path('.env'))) {
            return response(view('env-missing'), 503);
        }

        return $next($request);
    }
}
