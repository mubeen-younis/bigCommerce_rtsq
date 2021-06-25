<?php

namespace App\Models\Subscription;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    //
    protected $fillable = [
        'name',
        'slug',
        'stripe_plan',
        'cost',
        'description',
        'hits',
        'type',
        'plan_type',
        'stripe_plan_id',
    ];

    public function getRouteKeyName()
    {
        return 'slug';
    }
}
