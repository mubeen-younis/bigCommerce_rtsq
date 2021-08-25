<?php

namespace App\Models\Subscription;

use Illuminate\Database\Eloquent\Model;

class SubscriptionStatus extends Model
{
    //
    protected $table = 'subscription_status';

    protected $fillable = [
        'user_id',
        'sm_plan_id',
        'ltl_plan_id',
        'payment_id',
        'status'
    ];
}
