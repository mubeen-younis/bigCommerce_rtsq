<?php

namespace App\Models;

use App\Helpers\Helper;
use Illuminate\Database\Eloquent\Model;
use Psy\Util\Str;

class ShippingGroup extends Model
{


    protected $guarded = [];
    protected $table = "shipping_groups";


    /**
     * @param $storeId
     * @return array
     */
    public static function getStoreShippingGroups($storeId): array
    {
        return optional(self::where('store_id', $storeId)->get())->toArray() ?? [];
    }


    public static function deleteShippingGroup($uuid)
    {
        self::where('uuid', $uuid)->delete();
    }

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
        return optional(self::where('id', $id)->first())->toArray() ?? [];
    }


    /**
     * @param $uuid
     * @return array
     */
    public static function getShippingGroupDetailByUuid($uuid)
    {
        return optional(self::where('uuid', $uuid)->first())->toArray() ?? [];
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


    /**
     * @param $shippingGroupData
     * @return mixed
     */
    public static function saveShippingGroup($shippingGroupData)
    {
        $shippingGroup = new self();
        $shippingGroup->nickname = $shippingGroupData['nickname'] ?? null;
        $shippingGroup->store_id = $shippingGroupData['store_id'];
        $shippingGroup->uuid = Helper::getUuid();
        $shippingGroup->checkout_description = $shippingGroupData['checkout_description'] ?? null;
        $shippingGroup->rate = $shippingGroupData['rate'] ?? 0;
        $shippingGroup->rate_x_quantity = $shippingGroupData['rate_x_quantity'] ?? false;
        $shippingGroup->save();
        return $shippingGroup->uuid;
    }

    /**
     * @param $id
     * @return array
     */
    public static function getShippingGroupDetailTest($id): array
    {
        // TODO: Need to get From DB
        return ['nickname' => 'group 1',
            'checkout_description' => 'group 1',
            'rate' => 0,
            'rate_x_quantity' => false,
        ];
    }

}
