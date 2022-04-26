<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstalledCarrier extends Model
{
    protected $guarded = [];
    public $timestamps = false;
    protected $fillable = [
        'store_id',
        'carrier_plan_id',
        'is_enabled'
    ];

    public static function getinstalledProvidersSlug($storeId)
    {
        return optional(self::join('carriers', 'carriers.id', 'installed_carriers.carrier_id')
                ->select('slug')
                ->where('installed_carriers.store_id', $storeId)
                ->where('installed_carriers.is_enabled', 1)
                ->get())->toArray() ?? [];
    }

    public static function getinstalledProviderSlug($installedProvId)
    {
        return optional(self::join('carriers', 'carriers.id', 'installed_carriers.carrier_id')
                ->select('slug')
                ->where('installed_carriers.id', $installedProvId)
                ->first())->toArray() ?? [];
    }

    public static function checkIsEnbCarFromSlugANdStore($slug, $storeId)
    {
        return self::join('carriers', 'carriers.id', 'installed_carriers.carrier_id')
            ->select('slug')
            ->where('installed_carriers.store_id', $storeId)
            ->where('installed_carriers.is_enabled', 1)
            ->where('carriers.slug', $slug)
            ->exists();
    }

}
