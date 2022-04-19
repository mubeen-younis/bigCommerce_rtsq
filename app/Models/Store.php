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
        'freightdesk_company_id'
    ];

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


}
