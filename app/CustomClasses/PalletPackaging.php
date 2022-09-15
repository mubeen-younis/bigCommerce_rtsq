<?php

namespace App\CustomClasses;

use App\Constants\Constant;
use App\CustomClasses\Bin3D\Bin3D;
use App\Helpers\Helpers;
use App\Models\BoxSize;
use Illuminate\Support\Facades\Log;

class PalletPackaging
{
    protected $endURL = 'https://us-east.api.3dbinpacking.com/packer/palletPack';
    public $itemsArr;
    public $storeData;
    public $storeId;
    public $cartInfo;

    public function __construct($itemArr = [], $storeData, $cartInfo)
    {
        $this->palletPkgRequest = [];
        $this->pallet = [];
        $this->itemsArr = $itemArr;
        $this->storeData = $storeData ?? [];
        $this->storeId = $storeData['store']['id'];
        $this->cartInfo = $cartInfo;
    }

    public function isAddonEnabled(): bool
    {
        return isset($this->storeData['enabled_addon_pallet']) && $this->storeData['enabled_addon_pallet'];
    }

    public function formatPalletPkgReqArr()
    {
        $this->palletPkgRequest['username'] = Constant::BIN_USER;
        $this->palletPkgRequest['api_key'] = Constant::BIN_API_KEY;
        $this->palletPkgRequest['params'] = $this->getParamsArr();
        $this->palletPkgRequest['pallet'] = $this->getPallet();
        $resp = $this->formatPalletItems();
        $this->palletPkgRequest['items'] = $resp['items'] ?? [];
        $items = $resp['items'] ?? [];
        $itemsAlone = $resp['itemsAlone'] ?? [];
        $pallet = $this->getPallet();

        $hits = count($items);
        if ((count($items) && !empty($pallet)) || count($itemsAlone)) {
            $Bin3D = new Bin3D();
            $binResponse = $Bin3D->getBinResponse($this->storeId, $pallet, $items, $itemsAlone, $hits, $this->cartInfo, false, true);
            // dd($this->palletPkgRequest, $binResponse);
            dd('3d bin response', $binResponse);
            $boxBins = $newOrigins = $newitemsArr = [];
            $origins = $this->origins = [];

            if (count($binResponse)) {
                foreach ($itemsAlone as $key => $itemAlone) {
                    foreach ($itemAlone as $alone) {
                        if (count($items) && isset($items[$key])) {
                            array_push($items[$key], $alone);
                        } else {
                            $items[$key][] = $alone;
                        }
                    }
                }
                // dd('items', $items);
                $binResponse = $this->addPackagingID($binResponse, $boxBins);
                // dd('bin resp with pkg id', $binResponse);
                $counting = 0;
                $counting = 0;

                foreach ($binResponse as $locationId => $bins) {
                    foreach ($bins->pallets_packed as $key => $binPacked) {
                        $bin = $binPacked;
                        // dd('bin', $bin);
                        $counting++;
                        // $origin = $bin->pallet_data->variant_id;
                        // TODO:need to change origin info according to the request origins
                        $origin = $bin->pallet_data->variant_id == 7 ? 2100 : 45467;
                        // dd($origin, $this->itemsArr);

                        $newkey = $origin . $key;
                        $newOrigins[$newkey] = $origins[$origin] ?? [];
                        $newitemsArr[$newkey] = $this->updatCommdityDetails($this->itemsArr[$origin], $bin, $boxBins, $this->itemsArr);
                    }
                }
            } else {
                $newOrigins = $this->origins;
                $newitemsArr = $this->itemsArr;
            }
        } else {
            $newOrigins = $this->origins;
            $newitemsArr = $this->itemsArr;
        }

        // dd('final response', $newOrigins, $newitemsArr);
        // return $this->palletPkgRequest;
        return $binResponse;
    }

    private function getParamsArr()
    {
        return array(
            'images_background_color' => '255,255,255',
            'images_bin_border_color' => '59,59,59',
            'images_bin_fill_color' => '230,230,230',
            'images_item_border_color' => '214,79,79',
            'images_item_fill_color' => '177,14,14',
            'images_item_back_border_color' => '215,103,103',
            'images_sbs_last_item_fill_color' => '99,93,93',
            'images_sbs_last_item_border_color' => '145,133,133',
            'images_width' => '100',
            'images_height' => '100',
            'images_source' => 'file',
            'images_sbs' => '1',
            'stats' => '1',
            'item_coordinates' => '1',
            'images_complete' => '1',
            'images_separated' => '1',
        );
    }

    private function getPallet()
    {
        $pallets = $this->getPalletsFromDB();
        // TODO:add pallet finding algorithm
        $pallet = [
            "w" => "40",
            "d" => "40",
            "h" => "90",
            "id" => "6",
            "max_wg" => "2000",
        ];

        return $pallet;
    }

    private function formatPalletItems(): array
    {
        $items = $itemsAlone = [];

        foreach ($this->itemsArr as $key => $item) {
            $isLtl = isset($item['freightClass']) && $item['freightClass'] === 'ltl';
            $ownPallet = isset($item['own_pallet']) && $item['own_pallet'] == 1;

            if ($isLtl) {
                $itemId = $item['id'];
                $items[$itemId]['id'] = $itemId;

                if ($ownPallet) {
                    $itemsAlone[$itemId]['wg'] = $item['lineItemWeight'] ?? 0;
                    $itemsAlone[$itemId]['h'] = Helpers::floatValue($item['lineItemHeight'] ?? 0);
                    $itemsAlone[$itemId]['d'] = Helpers::floatValue($item['lineItemLength'] ?? 0);
                    $itemsAlone[$itemId]['w'] = Helpers::floatValue($item['lineItemWidth'] ?? 0);
                    $itemsAlone[$itemId]['q'] = Helpers::floatValue($item['piecesOfLineItem'] ?? 0);
                    $itemsAlone[$itemId]['vr'] = $item['vertical_rotation'] ?? 0;
                    $itemsAlone[$itemId]['boxFee'] = $item['boxFee'] ?? 0;
                } else {
                    $items[$itemId]['wg'] = $item['lineItemWeight'] ?? 0;
                    $items[$itemId]['h'] = $item['lineItemHeight'] ?? 0;
                    $items[$itemId]['d'] = $item['lineItemLength'] ?? 0;
                    $items[$itemId]['w'] = $item['lineItemWidth'] ?? 0;
                    $items[$itemId]['q'] = $item['piecesOfLineItem'] ?? 0;
                    $items[$itemId]['vr'] = $item['vertical_rotation'] ?? 0;
                }
            }
        }

        return [
            'items' => $items,
            'itemsAlone' => $itemsAlone,
        ];
    }

    public function addPackagingID($binResponse, $boxBins)
    {
        foreach ($binResponse as $locationId => $bins) {
            foreach ($bins->pallets_packed as $key => $bin) {
                $items = $bin->items;
                $item = $items[0];
                $variant_id = $item->id;
                $binResponse[$locationId]->pallets_packed[$key]->pallet_data->variant_id = $variant_id;
                $boxId = $bin->pallet_data->id ?? 0;
                $binResponse[$locationId]->pallets_packed[$key]->pallet_data->name = isset($boxBins[$boxId]['name']) ? strtoupper(str_replace(' ', '_', trim(explode('__', $boxBins[$boxId]['name'])[0]))) : '';
            }
        }

        return $binResponse;
    }

    public function updatCommdityDetails($item, $bin, $boxBins, $itemsArr)
    {
        $boxWeight = 0;
        $price = $item['lineItemPrice'] ?? 0;
        $hazmat = 'N';

        if (isset($bin->pallet_data->id) && isset($boxBins[$bin->pallet_data->id])) {
            $boxWeight = $boxBins[$bin->pallet_data->id]['box_weight'];
            $price = 0;
            if (isset($bin->items)) {
                foreach ($bin->items as $itemData) {
                    if ($hazmat == 'N') {
                        $hazmat = $itemsArr[$itemData->id]['isHazmatLineItem'];
                    }
                    $price += $itemsArr[$itemData->id]['lineItemPrice'] ?? 0;
                }
            }
        }

        $item['lineItemLength'] = $bin->pallet_data->d ?? 0;
        $item['lineItemWidth'] = $bin->pallet_data->w ?? 0;
        $item['lineItemHeight'] = $bin->pallet_data->h ?? 0;
        $item['lineItemPrice'] = $price;
        $item['lineItemWeight'] = $bin->pallet_data->weight + $boxWeight;
        $item['isHazmatLineItem'] = $hazmat;

        $item['shipItemAlone'] = 1;
        if ((isset($item['own_pallet']) && $item['own_pallet'] == 0)) {
            $item['piecesOfLineItem'] = 1;
        }

        if (isset($bin->pallet_data->type) && $bin->pallet_data->type == 'item' && isset($bin->pallet_data->id)) {
            $item['variant_id'] = $bin->pallet_data->id ?? 0;
        }

        return $item;
    }

    public function sendCurlRequest($url, $postData)
    {
        $fieldString = http_build_query($postData);
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 1000);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fieldString);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Expect:'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            $output = curl_exec($ch);
            curl_close($ch);
            Log::info('$output ' . $output);
            return json_decode($output, true);
        } catch (\Throwable$e) {
            $result = [];
        }

        return $result;
    }

    public function getPalletsFromDB()
    {
        $boxes = BoxSize::getPallets($this->storeId);
        return $boxes;
    }
}
