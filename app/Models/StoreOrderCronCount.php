<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreOrderCronCount extends Model
{
    use HasFactory;

    protected $table = "store_order_cron_count";

    /**
     * Adds or updates cron data
     * @param $storeID
     * @param $order
     * @return void
     */
    public static function addOrUpdate($storeID, $orderID)
    {
        $order = self::where('store_id', $storeID)->first();
        if (blank($order)) {
            $order = new self();
        }
        $order->store_id = $storeID;
        $order->min_order = $orderID;
        $order->save();

    }
}
