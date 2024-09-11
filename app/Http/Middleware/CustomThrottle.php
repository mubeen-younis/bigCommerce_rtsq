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

    public function handle($request, Closure $next, $limit = 60, $minutes = 1)
    {
        $key = $this->resolveRequestSignature($request);
        $maxAttempts = $limit;
        $decayMinutes = $minutes;

        // Check if the user has exceeded the max attempts
        if ($this->limiter->tooManyAttempts($key, $maxAttempts)) {
            $retryAfter = $this->limiter->availableIn($key);

            return response()->json([
                'message' => 'You have exceeded the number of requests allowed. Please try again later.',
                'status' => '429'
            ], 429)
            ->header('Retry-After', $retryAfter)
            ->header('X-RateLimit-Limit', $maxAttempts)
            ->header('X-RateLimit-Remaining', 0);
        }

        // Increment the hit count for the current key
        $this->limiter->hit($key, $decayMinutes * 60);

        // Get the remaining attempts
        $remainingAttempts = $this->limiter->retriesLeft($key, $maxAttempts);

        // Proceed with the request and add rate limit headers to the response
        $response = $next($request);

        return $response
        ->header('X-RateLimit-Limit', $maxAttempts)
        ->header('X-RateLimit-Remaining', $remainingAttempts);
    }

    protected function resolveRequestSignature($request)
    {
        return $request->ip();
    }
}