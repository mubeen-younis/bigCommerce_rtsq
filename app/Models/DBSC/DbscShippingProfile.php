<?php

namespace App\Models\DBSC;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\DBSC\ShippingClass;

class DbscShippingProfile extends Model
{
    use HasFactory;

    protected $table = 'dbsc_profiles';
    protected $fillable = [
        'p_nickname',
        'shipping_classes',
        'store_id',
        'is_general_profile',
        'allow_all_classes',
    ];


    /**
     * @param $storeId
     * @return array
     */
    protected static function getGeneralProfileSettings($storeId): array
    {
        return optional(self::where(['store_id' => $storeId, 'is_general_profile' => 1])->first())->toArray() ?? [];
    }

    /**
     * @return void
     */
    protected static function getProfileIdOfShippingClass($shippingClass, $storeId)
    {
        $profiles = optional(self::where(['store_id' => $storeId, 'is_general_profile' => 0])->select('id', 'shipping_classes')->get())->toArray() ?? [];
        $shippingClass = ShippingClass::where(['id' => $shippingClass])->select('class_name')->first() ?? [];
        if (blank($profiles)) {
            return null;
        }
        foreach ($profiles as $profile) {
            $shippingClasses = json_decode($profile['shipping_classes'], true);
            if (blank($shippingClasses)) {
                continue;
            }
            if (in_array($shippingClass['class_name'], $shippingClasses)) {
                return $profile['id'];
            }
        }
        return null;

    }


    protected static function getProfileRates($profileId, $zoneId, $storeId)
    {
        return optional(DbscShippingRates::join('dbsc_shipping_zone', 'dbsc_shipping_rates.dbsc_shipping_zone_id', 'dbsc_shipping_zone.id')
            ->join('dbsc_profiles', 'dbsc_profiles.id', 'dbsc_shipping_zone.profile_id')
            ->join('dbsc_origins', 'dbsc_origins.id', 'dbsc_shipping_zone.dbsc_origin_id')
            ->select('*')
            ->where([
                'dbsc_profiles.id' => $profileId,
                'dbsc_profiles.store_id' => $storeId,
                'dbsc_shipping_rates.dbsc_shipping_zone_id' => $zoneId,
            ])->get())->toArray() ?? [];
    }

    public static function makeOrderWidget($data, $order)
    {
        $dbscResp = $data['dbsc_resp'] ? json_decode($data['dbsc_resp']) : [];
        
        $isMultiShipment = $dbscResp->isMultiShipment ?? false;
        $shipments = $dbscResp->shipments ?? [];
        if (!$isMultiShipment) {
            $shipments = collect($shipments)->filter(function($ship) use($order) {
                return $order['rate_id'] === $ship->rate_details->rate_id;
            })->toArray() ?? [];
        }

        $widget = [];
        if (count($shipments) > 0) {
            foreach ($shipments as $key => $ship) {
                $data = [];

                $data['locationtype'] = '';
                $origin = $ship->origin;
                $data['address'] = $origin->street_address . ', ' . $origin->city . ', ' . $origin->state . ' ' . $origin->zip;
                $data['shipping_method'] = $ship->rate_details->title ?? '';
                $rate = $order['shipping_rate'] ?? 0.00;
                $data['shipping_rate'] = '$' . number_format((float)$rate, 2,) ?? 0.00;

                $items = [];
                foreach ($ship->items as $item) {
                    $itemQuantity = $item->originalPiecesOfLineItem . ' X ' . $item->lineItemName;
                    array_push($items, $itemQuantity);
                }

                $data['items'] = $items;
                $data['accessories'] = [];

                $widget[] = $data;
            }
        }

        return $widget;
    }
}
