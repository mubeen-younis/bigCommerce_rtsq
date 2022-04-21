<?php

namespace App\Models\Subscription;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    //
    protected $table = 'subscriptions';
    protected $fillable = [
        'store_id',
        'name',
        'email',
        'stripe_id',
        'stripe_status',
        'stripe_plan',
        'payment_method',
        'status',
        'charge_object',
        'ends_at',
        'transaction_id',
        'charge_id',
        'amount_charged',
        'plan_id',
        'subscription_id',
        'quantity',
        'paymentMethod_id',
    ];

    public function subscriptionStatus()
    {
        return $this->belongsTo(User::class, 'user_id');
    }


    public static function getEmail($storeId)
    {
        return optional(self::where('store_id', $storeId)->where('status', '!=', 2)->latest()->first())->email ?? '';
    }
}
