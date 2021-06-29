<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array
     */
    protected $except = [
        'api/*',
        'webhooks',
        'order/webhooks',
        'subscribe-plan',
        'cancel-subscription',
        'add-carrier',
        'remove-carrier',
        'update-subscription',
        'change-payment-method',
        'bc-payment-failed',
        'bc-payment-succeeded'
    ];
}
