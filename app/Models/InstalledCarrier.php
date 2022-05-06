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

    public static function getInstCarFromSlugANdStore($slug, $storeId, $promoCode = null)
    {
        $carrier = self::join('carriers', 'carriers.id', 'installed_carriers.carrier_id')
            ->select('slug', 'installed_carriers.id','installed_carriers.store_id', 'installed_carriers.is_enabled','carriers.slug')
            ->where('installed_carriers.store_id', $storeId)
            ->where('installed_carriers.is_enabled', 1)
            ->where('carriers.slug', $slug)
            ->first();
        if ($carrier === null) {
            return false;
        }
        if (!blank($promoCode)) {
            Connection::addPromoCodeInConnectionSettings($carrier->id, $promoCode);
        }
        return true;
    }

}
