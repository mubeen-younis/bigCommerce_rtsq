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

    public function __construct($itemsArr = [], $storeData = [], $cartInfo = [])
    {
        $this->palletPkgRequest = [];
        $this->pallet = [];
        $this->itemsArr = $this->filterLtlCartItems($itemsArr);
        $this->storeData = $storeData ?? [];
        $this->storeId = $storeData['store']['id'] ?? null;
        $this->cartInfo = $cartInfo;
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
     * It takes an array of items, and returns an array of items that have a freightClass of ltl.
     *
     * @param items array of items in the cart
     *
     * @return An array of items that have a freightClass of ltl.
     */
    private function filterLtlCartItems($items = [])
    {
        $ltlItemsArr = $items ?? [];

        foreach ($ltlItemsArr as $key => $item) {
            $isLtl = (isset($item['freightClass']) && $item['freightClass'] === 'ltl');

            if (!$isLtl) {
                unset($ltlItemsArr[$key]);
            }
        }

        return $ltlItemsArr;
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
     * It checks if the carriers have more than one origin address
     *
     * @param carriers This is an array of carriers that you want to ship with.
     */
    private function isMultiShipment($carriers = [])
    {
        $locationIds = [];
        foreach ($carriers as $carrierName => $carrier) {
            foreach ($carrier['originAddress'] as $key => $origin) {
                if (!in_array($origin['locationId'], $locationIds)) {
                    $locationIds[] = (int) $origin['locationId'];
                }
            }
        }

        $isMulti = count($locationIds) > 1;
        return $isMulti;
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
     * It formats the packaging request and returns 3D Bin response
     *
     * @param carriers array of carriers
     */
    public function setAndGetPackagingResp($carriers = [])
    {
        if (!$this->isLtlCarrierExists($carriers)) {
            Log::info('No Ltl carrier found in the request. Req Carriers: ', $carriers);
            return [];
        }

        // format packaging and own packaging items
        $itemsResp = $this->formatPalletItems();
        $items = $itemsResp['items'] ?? [];
        $itemsAlone = $itemsResp['itemsAlone'] ?? [];

        // select specific pallet for packaging
        $palletResp = $this->getPallet();
        Log::info('Pallet Resp: ', $palletResp);

        $pallet = $palletResp['pallet'] ?? [];
        // if no pallet, then all cart items are packed as their own pallet
        if (empty($pallet) && count($palletResp['itemsAlone'])) {
            // set all cart items as their own pallet
            foreach ($this->itemsArr as $key => $value) {
                $this->itemsArr[$key]['own_pallet'] = 1;
            }

            // format cart items again
            $itemsResp = $this->formatPalletItems();
            $items = $itemsResp['items'] ?? [];
            $itemsAlone = $itemsResp['itemsAlone'] ?? [];
        }

        $palletResponse = $resp = [];

        if ((count($items) && count($pallet)) || count($itemsAlone)) {
            try {
                // addon hits consumption
                $hits = count($items);
                // check for multishipment request
                $isMultiShipment = $this->isMultiShipment($carriers);

                // setting up 3D Bin request for packaging
                $Bin3D = new Bin3D();
                $palletResponse = $Bin3D->getBinResponse($this->storeId, $pallet, $items, $itemsAlone, $hits, $this->cartInfo, $isMultiShipment, true);

                if (count($palletResponse)) {
                    foreach ($itemsAlone as $key => $itemAlone) {
                        foreach ($itemAlone as $alone) {
                            if (count($items) && isset($items[$key])) {
                                array_push($items[$key], $alone);
                            } else {
                                $items[$key][] = $alone;
                            }
                        }
                    }

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
                }
            } catch (\Throwable$th) {
                Log::info('No repsonse from 3D Bin ' . $th->getMessage());
                $resp = [];
            }
        }

        return $resp;
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
            if (!isset($itemsArr[$key])) {
                continue;
            }

            $isLtl = (isset($itemsArr[$key]['freightClass']) && $itemsArr[$key]['freightClass'] === 'ltl');
            // TODO:also need to handle increased weight threshold value in small products

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
    private function getPallet()
    {
        $pallets = $this->getPalletsFromDB();
        $foundPalletKey = -1;
        $longestDimension = $previousLongestDimension = 0;
        $items = $this->itemsArr;
        // as we will check against length, width and height
        $i = count($items) * 3;

        while ($foundPalletKey == -1 && $i > 0) {
            // First find the cart item with longest dimension size
            // vertical rotation = 0, then longest dimension from len and wid
            // vertical rotation = 1, then longest dimension from len and wid and height
            foreach ($items as $key => $lineItem) {
                if (isset($lineItem['own_pallet']) && $lineItem['own_pallet'] == 1) {
                    continue;
                }

                if ($previousLongestDimension == 0) {
                    $longestDimension = ($lineItem['lineItemWidth'] > $longestDimension) ? $lineItem['lineItemWidth'] : $longestDimension;
                    $longestDimension = ($lineItem['lineItemLength'] > $longestDimension) ? $lineItem['lineItemLength'] : $longestDimension;

                    if (isset($lineItem['pallet_vertical_rotation']) && $lineItem['pallet_vertical_rotation'] == 1) {
                        $longestDimension = ($lineItem['lineItemHeight'] > $longestDimension) ? $lineItem['lineItemHeight'] : $longestDimension;
                    }
                } else {
                    $longestDimension = ($previousLongestDimension > $lineItem['lineItemWidth'] && $lineItem['lineItemWidth'] > $longestDimension) ? $lineItem['lineItemWidth'] : $longestDimension;
                    $longestDimension = ($previousLongestDimension > $lineItem['lineItemLength'] && $lineItem['lineItemLength'] > $longestDimension) ? $lineItem['lineItemLength'] : $longestDimension;

                    if (isset($lineItem['pallet_vertical_rotation']) && $lineItem['pallet_vertical_rotation'] == 1) {
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

            $previousLongestDimension = $longestDimension;
            $longestDimension = 0;
            $i--;
        }

        $pallet = [];
        if ($foundPalletKey != -1) {
            $pallet = $this->getSelectedPallet($pallets[$foundPalletKey]);
        }

        return [
            'pallet' => $pallet,
            'itemsAlone' => $foundPalletKey == -1 ? $items : [],
        ];
    }

    private function getSelectedPallet($pallet = [])
    {
        return [
            "w" => $pallet['width'],
            "d" => $pallet['length'],
            "h" => $pallet['ext_height'],
            "id" => $pallet['id'],
            "max_wg" => $pallet['max_weight'],
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
                $item = $items[0] ?? null;
                if (empty($item) || $item == null) {
                    Log::info('Pallet items: ', $items);
                    continue;
                }

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
        foreach ($palletResponse as $pallets) {
            foreach ($pallets->pallets_packed as $key => $palletPacked) {
                $pallet = $palletPacked;
                if (!isset($pallet->pallet_data) || empty($pallet->pallet_data)) {
                    continue;
                }

                $origin = $pallet->pallet_data->variant_id;
                if(count($pallet->items) > 1){
                    foreach($pallet->items as $item){
                        array_push($packedItemsOrgIds, $item->id);
                    }
                }else{
                    if (!in_array($origin, $packedItemsOrgIds)) {
                        array_push($packedItemsOrgIds, $origin);
                    }
                }

                $newkey = str_shuffle($origin . $key . rand(10, 100));
                $newOrigins[$newkey] = $this->origins[$origin];
                $newitemsArr[$newkey] = $this->updateCommdityDetails($this->itemsArr[$origin], $pallet, $palletBins, $this->itemsArr);
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
    public function updateCommdityDetails($item = [], $pallet, $palletBins = [], $itemsArr = [])
    {
        $palletWeight = 0;
        $price = $item['lineItemPrice'] ?? 0;
        $hazmat = 'N';

        if (isset($pallet->pallet_data->id) && isset($palletBins[$pallet->pallet_data->id])) {
            $palletWeight = $palletBins[$pallet->pallet_data->id]['box_weight'];
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
        $item['lineItemWeight'] = $pallet->pallet_data->weight + $palletWeight;
        $item['isHazmatLineItem'] = $hazmat;

        $item['lineItemPalletFlag'] = 1;
        if ((isset($item['own_pallet']) && $item['own_pallet'] == 0)) {
            $item['piecesOfLineItem'] = 1;
        }
        $item['freightClass'] = 'ltl';

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

    public function addPalletResponseToQuotes($palletResponse = [], $quotes = [])
    {
        $palletFee = [];

        foreach ($quotes as $carrName => $quote) {
            $carriers = $this->ltlCarriers ?? [];
            if (in_array($carrName, $carriers)) {
                foreach ($palletResponse as $locId => $pallet) {
                    $quotes[$carrName][$locId]['palletPackagingData']['response'] = $pallet;
                    $palletFee[$locId] = $this->getCumulativePalletFee($pallet);
                }
            }
        }

        if (!empty($palletFee)) {
            $quotes = $this->addPalletFeeToQuotes($quotes, $palletFee);
        }

        return $quotes;
    }

    private function getCumulativePalletFee($pallets): float
    {
        $palletFee = 0;
        if (!empty($pallets->pallets_packed)) {
            foreach ($pallets->pallets_packed as $pack) {
                if (isset($pack->pallet_data->type) && $pack->pallet_data->type === 'item') {
                    $palletFee += $pack->pallet_data->boxFee ?? 0;
                } else {
                    $palletFee += optional($pack)->pallet_data->boxfee ?? 0;
                }
            }
        }

        return $palletFee;
    }

    private function addPalletFeeToQuotes($quotes = [], $palletFee = 0)
    {
        $carriers = $this->ltlCarriers ?? [];

        if (isset($quotes) && !empty($quotes)) {
            foreach ($quotes as $carName => $quot) {
                if (in_array($carName, $carriers)) {
                    foreach ($quot as $locId => $q) {
                        $updatedQuotes = $this->handlePalletFee($carName, $q, $quotes, $locId, $palletFee);

                        if (!empty($updatedQuotes)) {
                            $quotes = $updatedQuotes;
                        } else {
                            if (isset($q['q'])) {
                                foreach ($q['q'] as $key => $qs) {
                                    if (isset($qs['totalNetCharge']['Amount'])) {
                                        if (isset($palletFee[$locId])) {
                                            $quotes[$carName][$locId]['q'][$key]['totalNetCharge']['Amount'] = $qs['totalNetCharge']['Amount'] + $palletFee[$locId];
                                            $quotes[$carName][$locId]['q'][$key]['palletFees']['Amount'] = $palletFee[$locId];
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        return $quotes;
    }

    private function getCarrChargesIndex($carrName = '', $quote = [])
    {
        $charges = '';

        $carriers = ['globalTranz' => $quote['LtlAmount'], 'freightQuote' => $quote['totalNetCharge'], 'saia' => $quote['totalNetCharge'], 'estes' => $quote['ratpricing']['rattotalPrice'], 'odfl4me' => $quote['rateEstimate']['netFreightCharge'], 'echoLogistics' => $quote['TotalCharge'], 'daylight' => $quote['totalNetCharge'], 'chr' => $quote['totalNetCharge'], 'tql' => $quote['customerRate']];

        foreach ($carriers as $key => $value) {
            if ($key == $carrName) {
                $charges = $value;
            }
        }

        return $charges;
    }

    private function handlePalletFee($carName = '', $q, $quotes = [], $locId, $palletFee)
    {
        $quotesWithFee = $quotes ?? [];

        if (isset($palletFee[$locId]) && isset($q['q'])) {
            $quotesWithFee[$carName][$locId]['q']['palletFees']['Amount'] = $palletFee[$locId];

            if ($carName == 'upsLTL') {
                $quotesWithFee[$carName][$locId]['q']['totalNetCharge']['Amount'] = $q['q']['totalNetCharge']['Amount'] + $palletFee[$locId];
            } elseif ($carName == 'globalTranz') {
                foreach ($q['q'] as $key => $value) {
                    $quotesWithFee[$carName][$locId]['q'][$key]['LtlAmount'] = $value['LtlAmount'] + $palletFee[$locId];
                }
            } elseif ($carName == 'cerasis') {
                foreach ($q['q'] as $key => $value) {
                    $quotesWithFee[$carName][$locId]['q'][$key]['ShipmentRate'] = $value['ShipmentRate'] + $palletFee[$locId];
                }
            } elseif ($carName == 'yrc') {
                $index = $q['q']['pageRoot'] ?? null;
                $rateQuote = $index['bodyMain']['rateQuote'] ?? null;

                if (!empty($index) && !empty($rateQuote)) {
                    $quotesWithFee[$carName][$locId]['q']['pageRoot']['bodyMain']['rateQuote']['ratedCharges']['totalCharges'] = $rateQuote['ratedCharges']['totalCharges'] + $palletFee[$locId];
                } else {
                    $quotesWithFee[$carName][$locId]['q']['RatedCharges']['TotalCharges'] = $q['q']['RatedCharges']['TotalCharges'] + $palletFee[$locId];
                }
            } elseif ($carName == 'abf') {
                if (!$this->isAbfError($q)) {
                    $quotesWithFee[$carName][$locId]['q']['CHARGE'] = $q['q']['CHARGE'] + $palletFee[$locId];
                }
            } elseif ($carName == 'xpoLogistics') {
                if (isset($q['q']['serviceType']) && isset($q['q']['totalNetCharge'])) {
                    $quotesWithFee[$carName][$locId]['q']['totalNetCharge'] = $q['q']['totalNetCharge'] + $palletFee[$locId];
                }

                if (isset($q['q']['NetCharge'])) {
                    $quotesWithFee[$carName][$locId]['q']['NetCharge'][0] = $q['q']['NetCharge'][0] + $palletFee[$locId];
                }
            } elseif ($carName == 'rnl') {
                if (isset($q['q']['ServiceLevels']['ServiceLevel'])) {
                    foreach ($q['q']['ServiceLevels']['ServiceLevel'] as $key => $quote) {
                        $quotesWithFee[$carName][$locId]['q']['ServiceLevels']['ServiceLevel'][$key]['NetCharge'] = (float) str_replace('$', '', str_replace(',', '', $quote['NetCharge'])) + $palletFee[$locId];
                    }
                }
            } elseif ($carName == 'freightQuote' || $carName == 'chr') {
                foreach ($q['q'] as $key => $value) {
                    $quotesWithFee[$carName][$locId]['q'][$key]['totalNetCharge'] = $value['totalNetCharge'] + $palletFee[$locId];
                }
            } elseif ($carName == 'saia' || $carName == 'daylight') {
                $quotesWithFee[$carName][$locId]['q']['totalNetCharge'] = $q['q']['totalNetCharge'] + $palletFee[$locId];
            } elseif ($carName == 'estes') {
                foreach ($q['q'] as $key => $value) {
                    $quotesWithFee[$carName][$locId]['q'][$key]['totalNetCharge'] = $value['ratpricing']['rattotalPrice'] + $palletFee[$locId];
                }
            } elseif ($carName == 'odfl4me') {
                $quotesWithFee[$carName][$locId]['q']['rateEstimate']['netFreightCharge'] = $q['q']['rateEstimate']['netFreightCharge'] + $palletFee[$locId];
            } elseif ($carName == 'echoLogistics') {
                foreach ($q['q'] as $key => $value) {
                    $quotesWithFee[$carName][$locId]['q'][$key]['TotalCharge'] = $value['TotalCharge'] + $palletFee[$locId];
                }
            } elseif ($carName == 'dayross') {
                if (!$this->dayRossError($q)) {
                    $charges = $q['q']['TotalAmount'] ?? '';
                    $notFound = false;

                    if (!empty($charges)) {
                        $quotesWithFee[$carName][$locId]['q']['TotalAmount'] = $charges + $palletFee[$locId];
                        $notFound = true;
                    }

                    if (!$notFound) {
                        $charges = $value['q']['TotalCharges'] ?? '';
                        if (!empty($charges)) {
                            $quotesWithFee[$carName][$locId]['q']['TotalCharges'] = $charges + $palletFee[$locId];
                        }
                    }
                }
            } elseif ($carName == 'southeastern') {
                if (!$this->seflError($q)) {
                    $quotesWithFee[$carName][$locId]['q']['rateQuote'] = $q['q']['rateQuote'] + $palletFee[$locId];
                }
            } elseif ($carName == 'tql') {
                foreach ($q['q'] as $key => $value) {
                    $quotesWithFee[$carName][$locId]['q'][$key]['customerRate'] = $value['customerRate'] + $palletFee[$locId];
                }
            } else {
                $quotesWithFee = [];
            }
        }

        return $quotesWithFee;
    }

    private function isAbfError($q)
    {
        return isset($q['q']['NUMERRORS']) && $q['q']['NUMERRORS'] == 1;
    }

    private function dayRossError($q)
    {
        return isset($q['q']) && !isset($q['q']['soapBody']['soapFault']);
    }

    private function seflError($q)
    {
        return isset($q['q']) && isset($q['q']['error']) && ($q['q']['error'] == [] || $q['q']['error'] == 1);
    }

    public function formatOrderWidget($responseFromWS, $lineItem)
    {
        $palletItems = [];
        $orderWidget = $orderWidgetData = [];

        // quotes response from WS side
        foreach ($responseFromWS as $carrrierName => $WsResp) {
            foreach ($WsResp as $zip => $ws) {
                if (!(isset($ws->severity) && $ws->severity == 'ERROR')) {
                    $totalBoxes = 1;
                    $palletData = $ws->palletPackagingData->response->pallets_packed ?? [];
                    $itemCount = 0;

                    // loop pallet packaging packed items
                    foreach ($palletData as $key => $palletPacked) {
                        if (!isset($palletPacked->pallet_data) || empty($palletPacked->pallet_data)) {
                            continue;
                        }

                        $type = optional($palletPacked->pallet_data)->type ?? '';
                        $quantity = 1;

                        if ($type == 'item') {
                            $type = $palletPacked->pallet_data->type;
                            $product_id = $palletPacked->pallet_data->id;
                            $quantity = $palletPacked->pallet_data->quantity ?? 1;
                            $itemCount++;
                        }

                        $count = 0;

                        $orderWidgetData['type'] = $type;
                        $orderWidgetData['image_complete'] = $palletPacked->image_complete;
                        $orderWidgetData['quantity'] = $quantity;

                        // setting pallet dimensions
                        $orderWidgetData['d'] = $palletPacked->pallet_data->d . ' x ';
                        $orderWidgetData['w'] = $palletPacked->pallet_data->w . ' x ';
                        $orderWidgetData['h'] = $palletPacked->pallet_data->h;

                        // setting pallet name
                        $orderWidgetData['nickname'] = $this->getBoxName($palletPacked->pallet_data->id);

                        // formatting items packed in pallet
                        foreach ($palletPacked->items as $item) {
                            $productid = $item->id;
                            $palletItems[$zip][$productid] = 1;

                            $orderWidgetData['items'][$count]['product_name'] = $lineItem->items->$productid->lineItemName ?? '';
                            $orderWidgetData['items'][$count]['w'] = $item->w;
                            $orderWidgetData['items'][$count]['h'] = $item->h;
                            $orderWidgetData['items'][$count]['d'] = $item->d;

                            $orderWidgetData['items'][$count]['image_separated'] = $item->image_separated;
                            $orderWidgetData['items'][$count]['image_sbs'] = $item->image_sbs;

                            $orderWidget[$zip]['pallet'][$key] = $orderWidgetData;
                            ++$count;
                        }

                        unset($orderWidgetData);

                        if ($count) {
                            $orderWidget[$zip]['pallet'][$key]['number_of_items'] = $count;
                        }
                    }

                    $totalBoxes = isset($key) ? $key + 1 - $itemCount : 0;
                }
            }
        }

        return $orderWidget;
    }

    private function getBoxName($palletId)
    {
        $nickname = BoxSize::getBoxNicknameAndFee($palletId);
        return $nickname->nickname ?? null;
    }

    public function setNmfcNull($palletPkgResp, $itemsArr){
        
        if(isset($palletPkgResp['palletResponse']) && !empty($palletPkgResp['palletResponse'])){
            foreach($palletPkgResp['palletResponse'] as $pallets){
                if(isset($pallets->pallets_packed) && !empty($pallets->pallets_packed)){
                    foreach($pallets->pallets_packed as $palletPacked){
                        if(isset($palletPacked->items) && count($palletPacked->items) > 1){
                            foreach($palletPacked->items as $palletPkgItem){
                                foreach($itemsArr as $key => $packedItem){
                                    if($packedItem['variant_id'] == $palletPkgItem->id){
                                        if(isset($packedItem['lineItemNMFC'])){
                                            $itemsArr[$key]['lineItemNMFC'] = null;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        return $itemsArr;
    }
}
