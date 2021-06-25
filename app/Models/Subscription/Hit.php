<?php

namespace App\Models\Subscription;

use Illuminate\Database\Eloquent\Model;

class Hit extends Model
{
    //
    protected $table = 'carriers_counts';
    protected $fillable = [
        'subscription_id',
        'db_hits',
        'api_hits',
        'sm_plan',
        'ltl_plan',
        'sm_hits',
        'ltl_hits',
        'plan_id',
        'store_id',
        'carrier_counts'
    ];
}
