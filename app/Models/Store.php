<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Store extends Model
{

    protected $table = 'stores';

    protected $fillable = [
        'token',
        'app_status',
        'is_trial_completed',
        'freightdesk_company_id',
        'av_company_id'
    ];


    public function storeOrderCronCount()
    {
        return $this->hasOne(StoreOrderCronCount::class, 'store_id');
    }

    public function installedCarriers()
    {
        return $this->hasMany(InstalledCarrier::class);
    }

    public static function getStoreUrlFromStoreId($storeId)
    {
        return optional(self::where('id', $storeId)->first())->url ?? null;
    }

    public static function getStoreDetailsFromStoreId($storeId): array
    {
        return optional(self::where('id', $storeId)->first())->toArray() ?? [];
    }

    public static function getAccessToken($storeHash)
    {
        return optional(self::where('hash', $storeHash)->first())->access_token ?? null;

    }

    public static function updateWeightUnit($storeHash, $unit)
    {
        self::where('hash', $storeHash)->update(['weight_unit' => $unit]);
    }

    public static function getStoreWeightUnit($storeId)
    {
        return optional(self::where('id', $storeId)->first())->weight_unit ?? 'lbs';

    }

    /**
     * Returns active stores
     * @return mixed
     */
    public static function getActiveStores()
    {
        return optional(self::where('app_status', 1)->select('id', 'url', 'hash', 'access_token')->with('storeOrderCronCount')->get())->toArray();
    }


}
