<?php

namespace App\Models\Subscription;

use App\Models\User;
use App\Models\Store;
use App\Models\Plans;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    /**
     * Eniture licenses functions
     * @return mixed
     */

    /**
     * Relation with store
     * @return BelongsTo
     */
    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /**
     * Relation with plan
     * @return BelongsTo
     */
    public function plan()
    {
        return $this->belongsTo(Plans::class, 'plan_id');

    }

    /**
     * Returns subscription details of BigCommerce
     * @param $id
     * @return mixed
     */
    public static function getSubscriptionDetails($id)
    {
        return self::where('id', $id)->with(['store' => function ($query) {
            $query->select('id', 'url', 'owner_email', 'store_domain');
        }, 'plan' => function ($query) {
            $query->select('id', 'name');
        }])->first();
    }
}
