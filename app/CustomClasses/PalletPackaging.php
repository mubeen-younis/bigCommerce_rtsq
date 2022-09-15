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
    public $itemArr;
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
        $pallet = $this->getPallet();
        $items = $resp['items'] ?? [];
        $itemsAlone = $resp['shipAloneItems'] ?? [];

        $hits = count($items);
        if ((count($items) && !empty($pallet)) || count($itemsAlone)) {
            $Bin3D = new Bin3D();
            $binResponse = $Bin3D->getBinResponse($this->storeId, $pallet, $items, $itemsAlone, $hits, $this->cartInfo, false, true);
            dd($binResponse);
        }

        return $this->palletPkgRequest;
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
            $isLtl = (isset($item['freightClass']) && $item['freightClass'] === 'ltl');
            $ownPallet = (isset($item['own_pallet']) && $item['own_pallet'] == 1);

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
            'shipAloneItems' => $itemsAlone,
        ];
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
