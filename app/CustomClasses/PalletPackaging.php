<?php

namespace App\CustomClasses;

use App\CustomClasses\Bin3D\Bin3D;
use App\Helpers\Helpers;
use App\Models\BoxSize;
use Illuminate\Support\Facades\Log;

class PalletPackaging
{
    public $itemsArr;
    public $storeData;
    public $storeId;
    public $cartInfo;
    public $palletPkgRequest;
    public $ltlCarriers;
    private $origins;

    public function __construct($itemArr = [], $storeData, $cartInfo)
    {
        $this->palletPkgRequest = [];
        $this->pallet = [];
        $this->itemsArr = $itemArr;
        $this->storeData = $storeData ?? [];
        $this->storeId = $storeData['store']['id'];
        $this->cartInfo = $cartInfo;
        $this->palletPkgRequest = [];
        $this->ltlCarriers = $this->getLtlCarriers();
        $this->origins = [];
    }

    /**
     * If the addon is enabled, return true, otherwise return false.
     */
    public function isAddonEnabled(): bool
    {
        return isset($this->storeData['enabled_addon_pallet']) && $this->storeData['enabled_addon_pallet'];
    }

    /**
     * It returns an array of strings
     *
     * @return An array of strings.
     */
    public function getLtlCarriers()
    {
        $ltlCarriers = ['wweLTL', 'upsLTL', 'fedexLTL', 'globalTranz', 'cerasis', 'xpoLogistics', 'rnl', 'yrc', 'freightQuote', 'estes', 'dayross', 'odfl4me', 'saia', 'abf', 'southeastern', 'tql', 'echoLogistics', 'daylight', 'chr'];

        return $ltlCarriers;
    }

    /**
     * It checks if the carrier is in the list of LTL carriers
     *
     * @param carriers array of carriers
     */
    public function isLtlCarrierExists($carriers = [])
    {
        $isLtlCarr = false;
        foreach ($this->ltlCarriers as $carr) {
            if (isset($carriers[$carr])) {
                $isLtlCarr = true;
                $this->origins = $carriers[$carr]['originAddress'];
                break;
            }
        }

        return $isLtlCarr;
    }

    /**
     * It takes an array of carriers, a new origin address, and a previous origin address, and returns an array of carriers
     * with the new origin address
     *
     * @param carriers array of carriers and their addresses
     * @param newOrgAdress
     * @param prevOrgAdress
     */
    public function setLtlCarriersOrgAddresss($carriers = [], $newOrgAdress = [], $prevOrgAdress = [])
    {
        $carrsOrgAddresses = $carriers ?? [];

        if (!empty($this->ltlCarriers)) {
            foreach ($this->ltlCarriers as $carrName) {
                if (isset($carrsOrgAddresses[$carrName])) {
                    $carrsOrgAddresses[$carrName]['originAddress'] = $newOrgAdress ?? $prevOrgAdress;
                }
            }
        }

        return $carrsOrgAddresses;
    }

    /**
     * It takes an array of carriers, checks if any of them are LTL carriers, formats the pallet items, sets and gets the
     * pallet packaging response, formats the pallet bins, adds the packaging ID, gets the updated commodity details, and
     * finally gets the final response
     *
     * @param carriers array of carriers
     */
    public function formatPalletPkgReqArr($carriers = [])
    {
        // check if request contains any ltl carrier
        if (!$this->isLtlCarrierExists($carriers)) {
            Log::info('No Ltl carrier found in the request. Req Carriers: ', $carriers);
            return [];
        }

        // format packaging items for each shipments
        $this->formatPalletItems();
        // set pallet 3D Bin requests and compiles pallets response
        $palletResponse = $this->setAndGetPalletPkgResp();
        Log::info('Pallet packaging reponse: ', $palletResponse);
        $resp = [];

        // handling pallet packaging response
        if (count($palletResponse)) {
            // format pallet bins
            $palletBins = $this->formatPalletBins();
            Log::info('Formatted pallets: ', $palletBins);

            // adding varaint id and pallet name to packed items
            $palletResponse = $this->addPackagingID($palletResponse, $palletBins);
            Log::info('Pallet response after adding packaging id: ', $palletResponse);

            // updating commodity details of packed items for WS request
            $commodityResp = $this->getUpdatedCommodityDetails($palletResponse, $palletBins);
            Log::info('Updated commodity details: ', $commodityResp);

            // Final reponse
            $resp = $this->getFinalResponse($commodityResp, $palletResponse, $palletBins);
            Log::info('Final formatted respones:  ', $resp);

            return $resp;
        }

        return $resp;
    }

    /**
     * It takes an array of items, and returns an array of items that are packaged together.
     */
    private function setAndGetPalletPkgResp()
    {
        $shipments = $this->palletPkgRequest['shipments'] ?? [];
        $palletResponse = [];

        if (empty($shipments)) {
            return $palletResponse;
        }

        foreach ($shipments as $orgId => $ship) {
            $items = $ship['items'] ?? [];

            // selecting specific pallet for packaging
            $pltRes = $this->getPallet($items);
            $pallet = $pltRes['pallet'] ?? [];
            // formatting packaging items
            $resp = $this->formatPalletReqItems($orgId);
            $reqItems = $resp['items'] ?? [];
            // checking items marked as own pallet
            $itemsAlone = [];
            if (isset($pltRes['itemsAlone']) && !empty(
                $pltRes['itemsAlone'])) {
                $itemsAlone = $pltRes['itemsAlone'];
            } elseif ((isset($resp['itemsAlone']) && !empty($resp['itemsAlone']))) {
                $itemsAlone = $resp['itemsAlone'];
            }

            $this->palletPkgRequest['itemsAlone'] = $itemsAlone;
            $hits = count($reqItems);

            if ((count($reqItems) && !empty($pallet)) || count($itemsAlone)) {
                // setting up 3D Bin request for packaging
                try {
                    $Bin3D = new Bin3D();
                    $pltPckgResp = $Bin3D->getBinResponse($this->storeId, $pallet, $reqItems, $itemsAlone, $hits, $this->cartInfo, false, true);

                    if (!empty($pltPckgResp) && isset($pltPckgResp['palletResp']) && !empty($pltPckgResp['palletResp'])) {
                        $palletResponse[$orgId] = $pltPckgResp['palletResp'];
                    }
                } catch (\Throwable$th) {
                    Log::info('No repsonse from 3D Bin ' . $th->getMessage());
                }
            }
        }

        return $palletResponse;
    }

    /**
     * It takes an array of items and returns an array of items
     */
    private function formatPalletItems(): array
    {
        $items = $itemsAlone = [];
        $itemsArr = $this->itemsArr ?? [];
        $origins = $this->origins ?? [];

        foreach ($origins as $key => $origin) {
            $isLtl = isset($itemsArr[$key]['freightClass']) && $itemsArr[$key]['freightClass'] === 'ltl';
            $ownPallet = isset($itemsArr[$key]['own_pallet']) && $itemsArr[$key]['own_pallet'] == 1;

            if ($isLtl) {
                if ($ownPallet) {
                    $itemsAlone[$origin['locationId']][] = [
                        "variant_id" => $key,
                        "id" => $key,
                        "wg" => $itemsArr[$key]['lineItemWeight'] ?? 0,
                        "h" => Helpers::floatValue($itemsArr[$key]['lineItemHeight'] ?? 0),
                        "d" => Helpers::floatValue($itemsArr[$key]['lineItemLength'] ?? 0),
                        "w" => Helpers::floatValue($itemsArr[$key]['lineItemWidth'] ?? 0),
                        "q" => $itemsArr[$key]['piecesOfLineItem'] ?? 0,
                        "vr" => $itemsArr[$key]['vertical_rotation'] ?? 0,
                        "boxFee" => $itemsArr[$key]['boxFee'] ?? 0,
                    ];

                    $this->palletPkgRequest['shipments'][$key]['itemsAlone'][] = $itemsArr[$key];
                } else {
                    $items[$origin['locationId']][] = [
                        "variant_id" => $key,
                        "id" => $key,
                        "wg" => $itemsArr[$key]['lineItemWeight'] ?? 0,
                        "h" => $itemsArr[$key]['lineItemHeight'] ?? 0,
                        "d" => $itemsArr[$key]['lineItemLength'] ?? 0,
                        "w" => $itemsArr[$key]['lineItemWidth'] ?? 0,
                        "q" => $itemsArr[$key]['piecesOfLineItem'] ?? 0,
                        "vr" => $itemsArr[$key]['vertical_rotation'] ?? 0,
                    ];

                    $this->palletPkgRequest['shipments'][$key]['items'][] = $itemsArr[$key];
                }
            }
        }

        return [
            'items' => $items,
            'itemsAlone' => $itemsAlone,
        ];
    }

    /**
     * It takes an array of items, and returns an array of items
     *
     * @param orgItemskey This is the key of the item in the array.
     */
    private function formatPalletReqItems($orgItemskey = null)
    {
        $shipments = $this->palletPkgRequest['shipments'] ?? [];
        $pkgItems = $shipments[$orgItemskey]['items'] ?? [];
        $aloneItems = $shipments[$orgItemskey]['itemsAlone'] ?? [];
        $shipItems = array_merge($pkgItems, $aloneItems);

        $items = $itemsAlone = [];

        if (empty($shipItems)) {
            return [
                'items' => $items,
                'itemsAlone' => $itemsAlone,
            ];
        }

        if (count($shipItems)) {
            foreach ($shipItems as $item) {
                $isLtl = isset($item['freightClass']) && $item['freightClass'] === 'ltl';
                $ownPallet = isset($item['own_pallet']) && $item['own_pallet'] == 1;

                if ($isLtl) {
                    if ($ownPallet) {
                        $itemsAlone[$item['id']] = [
                            "variant_id" => $orgItemskey,
                            "id" => $orgItemskey,
                            "wg" => $item['lineItemWeight'] ?? 0,
                            "h" => Helpers::floatValue($item['lineItemHeight'] ?? 0),
                            "d" => Helpers::floatValue($item['lineItemLength'] ?? 0),
                            "w" => Helpers::floatValue($item['lineItemWidth'] ?? 0),
                            "q" => $item['piecesOfLineItem'] ?? 0,
                            "vr" => $item['vertical_rotation'] ?? 0, //vertical 0 or 1
                            "boxFee" => $item['boxFee'] ?? 0,
                        ];
                    } else {
                        $items[$item['id']] = [
                            "variant_id" => $orgItemskey,
                            "id" => $orgItemskey,
                            "wg" => $item['lineItemWeight'] ?? 0,
                            "h" => $item['lineItemHeight'] ?? 0,
                            "d" => $item['lineItemLength'] ?? 0,
                            "w" => $item['lineItemWidth'] ?? 0,
                            "q" => $item['piecesOfLineItem'] ?? 0,
                            "vr" => $item['vertical_rotation'] ?? 0,
                        ];
                    }
                }
            }
        }

        return [
            'items' => $items,
            'itemsAlone' => $itemsAlone,
        ];
    }

    /**
     * It finds the pallet that can fit the longest dimension of the cart items
     *
     * @param items array of items to be packed
     */
    private function getPallet($items = [])
    {
        $pallets = $this->getPalletsFromDB();
        $pallet = [];
        $foundPalletKey = -1;
        $longestDimension = $previousLongestDimension = 0;

        // First find the cart item with longest dimension size
        // vertical rotation = 0, then longest dimension from len and wid
        // vertical rotation = 1, then longest dimension from len and wid and height
        foreach ($items as $key => $lineItem) {
            if (!empty($lineItem['own_pallet'])) {
                continue;
            }

            if ($previousLongestDimension == 0) {
                $longestDimension = ($lineItem['lineItemWidth'] > $longestDimension) ? $lineItem['lineItemWidth'] : $longestDimension;
                $longestDimension = ($lineItem['lineItemLength'] > $longestDimension) ? $lineItem['lineItemLength'] : $longestDimension;

                if (!empty($lineItem['pallet_vertical_rotation']) && $lineItem['pallet_vertical_rotation'] == 1) {
                    $longestDimension = ($lineItem['lineItemHeight'] > $longestDimension) ? $lineItem['lineItemHeight'] : $longestDimension;
                }
            } else {
                $longestDimension = ($previousLongestDimension > $lineItem['lineItemWidth'] && $lineItem['lineItemWidth'] > $longestDimension) ? $lineItem['lineItemWidth'] : $longestDimension;
                $longestDimension = ($previousLongestDimension > $lineItem['lineItemLength'] && $lineItem['lineItemLength'] > $longestDimension) ? $lineItem['lineItemLength'] : $longestDimension;

                if (!empty($lineItem['pallet_vertical_rotation']) && $lineItem['pallet_vertical_rotation'] == 1) {
                    $longestDimension = ($previousLongestDimension > $lineItem['lineItemHeight'] && $lineItem['lineItemHeight'] > $longestDimension) ? $lineItem['lineItemHeight'] : $longestDimension;
                }
            }
        }

        // From all user defined pallets, finds the one that can accumodate longest dimension product
        // If no pallet selected, then all items will be marked as ship as own pallet
        $squareInches = 0;
        foreach ($pallets as $key => $pallet) {
            if ($longestDimension != 0 && ($longestDimension <= $pallet['width'] || $longestDimension <= $pallet['length']) && ($squareInches == 0 || $squareInches > ($pallet['width'] * $pallet['length']))) {
                $foundPalletKey = $key;
                $squareInches = $pallet['width'] * $pallet['length'];
            }
        }

        if ($foundPalletKey != -1) {
            $pallet = [
                "w" => Helpers::floatValue($pallets[$foundPalletKey]['width']),
                "d" => Helpers::floatValue($pallets[$foundPalletKey]['length']),
                "h" => Helpers::floatValue($pallets[$foundPalletKey]['height']),
                "id" => Helpers::floatValue($pallets[$foundPalletKey]['id']),
                "max_wg" => Helpers::floatValue($pallets[$foundPalletKey]['max_weight']),
            ];
        }

        return [
            'pallet' => $pallet,
            'itemsAlone' => $foundPalletKey == -1 ? $items : [],
        ];
    }

    /**
     * It takes an array of objects, and adds a new property to each object in the array
     *
     * @param palletResponse
     * @param palletBins
     */
    public function addPackagingID($palletResponse = [], $palletBins)
    {
        foreach ($palletResponse as $locationId => $pallet) {
            foreach ($pallet->pallets_packed as $key => $pallet) {
                if (!isset($pallet->pallet_data) || empty($pallet->pallet_data)) {
                    continue;
                }

                $items = $pallet->items;
                $item = $items[0];
                $variant_id = $item->id;
                $palletResponse[$locationId]->pallets_packed[$key]->pallet_data->variant_id = $variant_id;
                $boxId = $pallet->pallet_data->id ?? 0;
                $palletResponse[$locationId]->pallets_packed[$key]->pallet_data->name = isset($palletBins[$boxId]['name']) ? strtoupper(str_replace(' ', '_', trim(explode('__', $palletBins[$boxId]['name'])[0]))) : '';
            }
        }

        return $palletResponse;
    }

    /**
     * It takes an array of objects, and returns an array of objects
     *
     * @param palletResponse
     * @param palletBins
     */
    private function getUpdatedCommodityDetails($palletResponse = [], $palletBins = []): array
    {
        $newOrigins = $newitemsArr = [];
        $packedItemsOrgIds = [];
        $counting = 0;

        foreach ($palletResponse as $pallets) {
            foreach ($pallets->pallets_packed as $key => $palletPacked) {
                $pallet = $palletPacked;
                if (!isset($pallet->pallet_data) || empty($pallet->pallet_data)) {
                    continue;
                }

                $counting++;
                $origin = $pallet->pallet_data->variant_id;
                if (!in_array($origin, $packedItemsOrgIds)) {
                    array_push($packedItemsOrgIds, $origin);
                }

                $newkey = $origin . $key;
                $newOrigins[$newkey] = $this->origins[$origin];
                $newitemsArr[$newkey] = $this->updatCommdityDetails($this->itemsArr[$origin], $pallet, $palletBins, $this->itemsArr);
            }
        }

        return [
            'newOrgAddresses' => $newOrigins,
            'newItemsArr' => $newitemsArr,
            'orgIds' => $packedItemsOrgIds,
        ];
    }

    /**
     * It takes an array of items, a pallet, an array of pallet bins, and an array of items, and returns an array of items
     *
     * @param item This is the item that is being updated.
     * @param pallet This is the pallet object that is being updated.
     * @param palletBins This is an array of pallet ids and their box weights.
     * @param itemsArr This is an array of all the items in the order.
     *
     * @return the  array.
     */
    public function updatCommdityDetails($item = [], $pallet, $palletBins = [], $itemsArr = [])
    {
        $boxWeight = 0;
        $price = $item['lineItemPrice'] ?? 0;
        $hazmat = 'N';

        if (isset($pallet->pallet_data->id) && isset($palletBins[$pallet->pallet_data->id])) {
            $boxWeight = $palletBins[$pallet->pallet_data->id]['box_weight'];
            $price = 0;
            if (isset($pallet->items)) {
                foreach ($pallet->items as $itemData) {
                    if ($hazmat == 'N') {
                        $hazmat = $itemsArr[$itemData->id]['isHazmatLineItem'];
                    }
                    $price += $itemsArr[$itemData->id]['lineItemPrice'] ?? 0;
                }
            }
        }

        $item['lineItemLength'] = $pallet->pallet_data->d ?? 0;
        $item['lineItemWidth'] = $pallet->pallet_data->w ?? 0;
        $item['lineItemHeight'] = $pallet->pallet_data->h ?? 0;
        $item['lineItemPrice'] = $price;
        $item['lineItemWeight'] = $pallet->pallet_data->weight + $boxWeight;
        $item['isHazmatLineItem'] = $hazmat;

        $item['shipPalletAlone'] = 1;
        if ((isset($item['own_pallet']) && $item['own_pallet'] == 0)) {
            $item['piecesOfLineItem'] = 1;
        }

        if (isset($pallet->pallet_data->type) && $pallet->pallet_data->type == 'item' && isset($pallet->pallet_data->id)) {
            $item['variant_id'] = $pallet->pallet_data->id ?? 0;
        }

        return $item;
    }

    /**
     * It takes in 3 arrays, and returns an array with the 3 arrays as keys
     *
     * @param commodityResp
     * @param palletResponse
     * @param palletBins
     */
    public function getFinalResponse($commodityResp = [], $palletResponse = [], $palletBins = []): array
    {
        $newOrigins = $commodityResp['newOrgAddresses'] ?? [];
        $newitemsArr = $commodityResp['newItemsArr'] ?? [];
        $packedItemsOrgIds = $commodityResp['orgIds'] ?? [];

        $resp = [
            'items' => $newitemsArr,
            'originAddress' => $newOrigins,
            'palletResponse' => $palletResponse ?? [],
            'palletBins' => !empty($palletResponse) ? $palletBins : [],
            'packedItemsOrgIds' => $packedItemsOrgIds,
        ];

        return $resp;
    }

    /**
     * Get the pallets from the database, if they don't exist, return an empty array.
     *
     * @return array An array of pallets.
     */
    public function getPalletsFromDB(): array
    {
        $pallets = optional(BoxSize::getPallets($this->storeId))->toArray() ?? [];
        return $pallets;
    }

    /**
     * It takes an array of pallets from the database, and returns an array of pallets with the same data, but in a
     * different format.
     *
     * @return array An array of pallets.
     */
    private function formatPalletBins(): array
    {
        $pallets = $this->getPalletsFromDB();
        $palletBins = [];

        foreach ($pallets as $pallet) {
            $palletBins[$pallet['id']] = array(
                'nickname' => $pallet['nickname'],
                'name' => $pallet['box_name'],
                'w' => $pallet['width'],
                'h' => $pallet['height'],
                'd' => $pallet['length'],
                'id' => $pallet['id'],
                'max_wg' => $pallet['max_weight'],
                'box_weight' => $pallet['box_weight'],
            );
        }

        return $palletBins;
    }
}
