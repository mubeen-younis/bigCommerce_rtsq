<?php

namespace App\Models;

use App\Constants\Constant;
use App\Helpers\Helper;
use App\Helpers\Helpers;
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
        $response[0]['code'] = "shippingGroup";
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
        // return optional(self::where('uuid', $uuid)->first())->toArray() ?? [];
        return self::where('uuid', $uuid)->first();
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
    public static function saveOrUpdateShippingGroup($shippingGroupData)
    {
        if (isset($shippingGroupData['uuid'])) {
            $shippingGroup = self::getShippingGroupDetailByUuid($shippingGroupData['uuid']);
            if (blank($shippingGroup)) {
                return [
                    'error' => true,
                    'message' => 'Shipping group not found.',
                    'data' => []
                ];
            }
            $message = 'updated successfully.';
            $save = 0;
        } else {
            $shippingGroup = new self();
            $shippingGroup->uuid = Helpers::getUuid();
            $message = 'added successfully.';
            $save = 1;
        }
        $shippingGroup->nickname = $shippingGroupData['nickname'] ?? null;
        $shippingGroup->store_id = $shippingGroupData['store_id'];
        $shippingGroup->checkout_description = $shippingGroupData['checkout_description'] ?? null;
        $shippingGroup->rate = $shippingGroupData['rate'] ?? 0;
        $shippingGroup->rate_x_quantity = $shippingGroupData['rate_x_quantity'] ?? false;
        $shippingGroup->save();

        return [
            'error' => false,
            'message' => $message,
            'data' => [
                'shippingGroup' => $shippingGroup,
                'save' => $save
            ]
        ];
    }


    public static function shippingGroupOrderWidget($data, $order)
    {
        $lineItem = json_decode($data['lineitems'])->lineItemData;
        $origins = $lineItem->origin;
        $items = $lineItem->items;
        $count = 0;
        $insertedIds = $insertedNames = [];
        //print_r($items); exit;
        $code = '';
        foreach ($origins as $key => $origin) {
            $item = $items->$key;
            $city = $origin->senderCity ? $origin->senderCity . ',' : '';
            $state = $origin->senderState ?? '';
            $zip = $origin->locationId != '' ? $origin->locationId : $origin->senderZip;
            $senderZip = $origin->senderZip ?? '';
            $orderWidget[$zip]['sbs'] = [];
            $orderWidget[$zip]['locationtype'] = $item->dropship_enabled == 'N' ? 'Warehouse' : 'Dropship';
            $orderWidget[$zip]['address'] = $city . ' ' . $state . ' ' . $senderZip;
            $orderWidget[$zip]['totalBoxes'] = $totalBoxes ?? 0;
            $sRate = $order['shipping_rate'];
            //print_r($multiShipmentresponse); exit;

            $shipping_name = explode('(', $order['shipping_name']);
            $sName = $shipping_name[0] ?? '';
            $sName = str_replace(Constant::RESI_LABEL, '', $sName);
            $sName = str_replace(Constant::LIFT_LABEL, '', $sName);
            $sName = str_replace(Constant::RESI_LIFT_LABEL, '', $sName);
            $sMethod = isset($shipping_name[1]) ? '(' . $shipping_name[1] : '';

            $orderWidget[$zip]['shipping_method'] = $sName . $sMethod;
            $orderWidget[$zip]['shipping_rate'] = '$' . number_format((float)$sRate, 2,);
            if ($item->shipMultiplePackage) {
                if ((!in_array($item->lineItemName, $insertedNames))) {
                    $insertedNames[] = $item->lineItemName;
                    $orderWidget[$zip]['items'][] = $item->originalPiecesOfLineItem . ' X ' . $item->lineItemName;
                }
            } else {
                if ((!in_array($item->id, $insertedIds))) {
                    $insertedIds[] = $item->id;
                    $orderWidget[$zip]['items'][] = $item->originalPiecesOfLineItem . ' X ' . $item->lineItemName;
                }
            }
            $orderWidget[$zip]['accessories'] = [];
            $count++;
        }
        return $orderWidget;
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
