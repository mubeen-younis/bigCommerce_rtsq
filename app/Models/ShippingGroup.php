<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingGroup extends Model
{

    protected $guarded = [];

    protected $table = "shipping_groups";

    /**
     * @param $shippingGroupItems
     * @return array
     */
    public static function setShippingGroup($shippingGroupItems): array
    {
        if (blank($shippingGroupItems)) {
            return [];
        }
        $groupItemsByShippingGroup = [];
        foreach ($shippingGroupItems as $item) {
            $groupItemsByShippingGroup[$item['shipping_group']][] = $item;
        }
        $rate = 0;
        $response = [];
        foreach ($groupItemsByShippingGroup as $shippingGroupId => $group) {
            $groupDetail = self::getShippingGroupDetail($shippingGroupId);
            $response[0]['title'] = $groupDetail['checkout_description'];
            if ($groupDetail['rate_x_quantity']) {
                $rate += self::getSumAftermultipItemGroupwithQty($group, $groupDetail['rate']);
            } else {
                $rate += $groupDetail['rate'];
            }
        }
        if (count($groupItemsByShippingGroup) > 1) {
            $response[0]['title'] = "Shipping";
        }
        $response[0]['rate'] = $rate;
        $response[0]['code'] = "ShippingGroup";
        return $response;
    }


    /**
     * @param $id
     * @return array
     */
    public static function getShippingGroupDetail($id): array
    {
        // TODO: Need to get From DB
        return ['nickname' => 'group 1',
            'checkout_description' => 'group 1',
            'rate' => 0,
            'rate_x_quantity' => false,
        ];
    }


    /**
     * @param $group
     * @param $rate
     * @return float|int
     */
    public static function getSumAftermultipItemGroupwithQty($group, $rate)
    {
        $noOfQuantityInGroup = collect($group)->sum('piecesOfLineItem');
        return $noOfQuantityInGroup * $rate;
    }

}
