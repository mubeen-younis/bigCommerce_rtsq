<?php

namespace App\Models\Subscription;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PackageSubscription extends Model
{
    use HasFactory;
    protected $table = 'package_subscriptions';
    protected $fillable = [
        'store_id',
        'package_id',
        'payment_method_id',
        'status',
        'subscription_time',
        'update_time',
        'expiry_time',
        'total_count',
        'sub_activated',
        'trial_available',
        'stripe_charge_id',
        'charge_cost',
    ];
}
