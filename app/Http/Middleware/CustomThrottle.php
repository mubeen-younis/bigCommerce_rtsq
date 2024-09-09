<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class CustomThrottle
{
    protected $limiter;

    public function __construct(RateLimiter $limiter)
    {
        $this->limiter = $limiter;
    }

    public function handle($request, Closure $next, $limit = 60, $minutes = 1, $guard = null)
    {
        $key = $this->resolveRequestSignature($request);

        if ($this->limiter->tooManyAttempts($key, $limit)) {
            return response()->json([
                'message' => 'You have exceeded the number of requests allowed. Please try again later.',
                'status' => '429'
            ], 429);
        }

        $this->limiter->hit($key, $minutes * 60);

        return $next($request);
    }

    protected function resolveRequestSignature($request)
    {
        return $request->ip();
    }
}
