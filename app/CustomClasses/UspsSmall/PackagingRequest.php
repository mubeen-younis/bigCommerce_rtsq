<?php

namespace App\CustomClasses\UspsSmall;

use App\Constants\Constant;
use App\CustomClasses\CompileQuotes;
use App\Endpoints\Endpoints;
use App\Models\BoxSize;
use Illuminate\Support\Facades\Log;

class PackagingRequest
{
    private $binResArr, $finalBoxesForWs;

    public function __construct()
    {
        $this->CompileQuotes = new CompileQuotes();
        $this->storeBoxes = [];
        $this->uspsBoxes = [];
        $this->uspsActiveServices = [];
        $this->uspsPackagingEligible = false;
        $this->packagingRequest = [];
        $this->finalBoxesForWs = [];
        $this->binResArr = [];
        $this->locId = null;
    }

    public function getGroupedUSPSBoxes($storeId): array
    {
        $uspsBoxes = optional(BoxSize::getUspsSmallAvailableBoxes($storeId))->toArray() ?? [];
        if (blank($uspsBoxes)) {
            return [];
        }

        $this->storeBoxes = $uspsBoxes;
        return self::groupUspsBoxesByTypes($uspsBoxes);
    }

    private function groupUspsBoxesByTypes($uspsBoxes): array
    {
        $uspsGroupedBoxes = [];
        foreach ($uspsBoxes as $uspsBox) {
            $boxCode = !empty($uspsBox['box_name']) ? preg_replace('/\d+/', '', $uspsBox['box_name']) : "";
            $boxId = $uspsBox['id'] ?? null;

            if ($boxCode == "UPMB" || $boxCode == "UMEB" || $boxCode == "UFLAT") {
                $uspsGroupedBoxes[$boxCode][$boxId] = $this->formatBoxFields($uspsBox);
                $uspsGroupedBoxes[$boxCode][$boxId]['box_category'] = $boxCode;

                if ($boxCode == 'UPMB') {
                    $uspsGroupedBoxes[$boxCode][$boxId]['box_type'] = 'USPS Priority Mail Box';
                } else if ($boxCode == 'UMEB') {
                    $uspsGroupedBoxes[$boxCode][$boxId]['box_type'] = 'USPS Priority Mail Express Box';
                } else if ($boxCode == 'UFLAT') {
                    $uspsGroupedBoxes[$boxCode][$boxId]['box_type'] = 'USPS Priority Mail Large Flat Rate Box';
                }
            } else {
                $uspsGroupedBoxes['customBoxes'][$boxId] = $this->formatBoxFields($uspsBox);
            }
        }

        return $uspsGroupedBoxes;
    }

    private function formatBoxFields($box)
    {
        $formattedBox = [];

        $formattedBox['nickname'] = $box['nickname'] ?? '';
        $formattedBox['w'] = $box['width'] ?? '';
        $formattedBox['h'] = $box['height'] ?? '';
        $formattedBox['d'] = $box['length'] ?? '';
        $formattedBox['id'] = $box['id'];
        $formattedBox['max_wg'] = !empty($box['max_weight']) ? (!empty($box['box_weight']) ? $box['max_weight'] - $box['box_weight'] : $box['max_weight']) : '';
        $formattedBox['box_weight'] = $box['box_weight'] ?? '';
        $formattedBox['box_price'] = $box['box_fee'] ?? '';

        return $formattedBox;
    }

    public function getSbsPackedBoxes($sbsEnabled, $locId)
    {
        $binResponse = [];
        $boxLocId = $this->finalBoxesForWs[$locId] ?? null;
        if (blank($boxLocId)) {
            return [];
        }

        if ($sbsEnabled && $this->uspsPackagingEligible) {
            // Custome Boxes
            if (isset($boxLocId['customBoxes']) && !empty($boxLocId['customBoxes']['bins_packed']) && !isset($boxLocId['customBoxes']['bins_unpacked'])) {
                $binResponse['customBoxes'] = $boxLocId['customBoxes']['bins_packed'];
            }

            // USPS Priority MAIL
            if (isset($boxLocId['UPMB']) && !empty($boxLocId['UPMB']['bins_packed']) && !isset($boxLocId['UPMB']['bins_unpacked'])) {
                $binResponse['UPMB'] = $boxLocId['UPMB']['bins_packed'];
            }

            // Usps Priority Mail Express
            if (isset($boxLocId['UMEB']) && !empty($boxLocId['UMEB']['bins_packed']) && !isset($boxLocId['UMEB']['bins_unpacked'])) {
                $binResponse['UMEB'] = $boxLocId['UMEB']['bins_packed'];
            }

            //Usps Priority Mail Flat Rate*
            if (isset($boxLocId['UFLAT']) && !empty($boxLocId['UFLAT']['bins_packed']) && !isset($boxLocId['UFLAT']['bins_unpacked'])) {
                $binResponse['UFLAT'] = $boxLocId['UFLAT']['bins_packed'];
            }

            return $binResponse;
        }
    }

    public function setAndGetBinsResponse($storeId, $settings, $origins, $lineItems, $itemLocId)
    {
        $uspsBoxes = self::getGroupedUSPSBoxes($storeId) ?? [];
        if (blank($uspsBoxes)) {
            return null;
        }

        $this->uspsBoxes = $uspsBoxes;
        $this->setUspsActiveServices($settings);

        foreach ($lineItems as $locId => $itemsDetail) {
            if ((isset($itemsDetail['freight_enabled']) && $itemsDetail['freight_enabled'] == 'Y') || (isset($itemsDetail['freightClass']) && $itemsDetail['freightClass'] == 'ltl')) {
                continue;
            }

            $locId = $origins[$locId]['locationId'] ?? $locId;
            if ($this->uspsPackagingEligible) {
                $this->setUspsPackagingRequest($lineItems, $locId);
            }
        }

        $this->getAndSet3dBinResponse();
        $sbsPackedBoxes = $this->getSbsPackedBoxes(true, $itemLocId);

        $resp = [
            'packedBoxes' => $sbsPackedBoxes,
            'owdBoxes' => $this->finalBoxesForWs,
        ];
        return $resp;
    }

    private function setUspsActiveServices($settings)
    {
        if (!blank($settings)) {
            $allServices = $settings['quote_settings']['carrier_services'] ?? [];

            if (!blank($allServices)) {
                $this->uspsPackagingEligible = true;

                foreach ($allServices as $key => $service) {
                    if (isset($allServices[$key]) && $service) {
                        $this->setUspsServiceBoxType($key);
                    }
                }
            }
        }
    }

    private function setUspsServiceBoxType($type)
    {
        if ($type == 'usps_ground_advantage' || $type == 'usps_first_class_package_international_service') {
            $this->uspsActiveServices[] = 'customBoxes';
        }

        if ($type == 'usps_priority_mail_express' || $type == 'usps_priority_mail_international_express') {
            $this->uspsActiveServices[] = 'UMEB';
        }

        if ($type == 'usps_priority_mail' || $type == 'usps_priority_mail_international') {
            $this->uspsActiveServices[] = 'UPMB';
        }

        if ($type == 'usps_priority_mail_flat_rate' || $type == 'usps_priority_mail_international_flat_rate_box') {
            $this->uspsActiveServices[] = 'UFLAT';
        }
    }

    public function setUspsPackagingRequest($itemsDetail, $locId)
    {
        foreach ($this->uspsBoxes as $boxCode => $box) {
            if (in_array($boxCode, $this->uspsActiveServices)) {
                $requestParams = $this->get3dBinRequest($itemsDetail, $box);
                if (!blank($requestParams)) {
                    $this->packagingRequest[$locId . '-' . $boxCode] = $requestParams;
                }
            }
        }
    }

    public function get3dBinRequest($itemsDetail, $specifiedBoxes = [])
    {
        $request = [];
        $endPoint = Constant::BIN_URL;
        $request['username'] = Constant::BIN_USER;
        $request['api_key'] = Constant::BIN_API_KEY;
        $request['params'] = $this->getCommonParmeters();
        $request['bins'] = $this->getBins($specifiedBoxes);
        $items = $this->getUspsItems($itemsDetail);

        if (empty($items)) {
            return [];
        }

        $request['items'] = $items;
        return ['endpoint' => $endPoint, 'request' => 'query=' . json_encode($request), 'headers' => []];
    }

    public function getCommonParmeters()
    {
        return [
            'optimization_mode' => 'bins_utilization',
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
        ];
    }

    public function getBins($specifiedBoxes = [])
    {
        if (!blank($specifiedBoxes)) {
            return $this->getSpecificBoxes($specifiedBoxes);
        }

        $bins = [];
        foreach ($this->storeBoxes as $box) {
            $boxId = $box['id'];
            $bins[$boxId]['nickname'] = $box['name'] ?? '';
            $bins[$boxId]['w'] = $box['width'] ?? '';
            $bins[$boxId]['h'] = $box['height'] ?? '';
            $bins[$boxId]['d'] = $box['length'] ?? '';
            $bins[$boxId]['id'] = $boxId;
            $maxWeight = !empty($box['max_weight']) ? (!empty($box['box_weight']) ? $box['max_weight'] - $box['box_weight'] : $box['max_weight']) : '';
            $bins[$boxId]['max_wg'] = $maxWeight;
        }

        return $bins;
    }

    public function getSpecificBoxes($specifiedBins)
    {
        $bins = [];
        foreach ($specifiedBins as $box) {
            $boxId = $box['id'];
            $bins[$boxId]['nickname'] = $box['nickname'] ?? '';
            $bins[$boxId]['w'] = $box['w'] ?? '';
            $bins[$boxId]['h'] = $box['h'] ?? '';
            $bins[$boxId]['d'] = $box['d'] ?? '';
            $bins[$boxId]['id'] = $boxId;
            $maxWeight = !empty($box['max_wg']) ? (!empty($box['box_weight']) ? $box['max_wg'] - $box['box_weight'] : $box['max_wg']) : '';
        }

        return $bins;
    }

    public function getUspsItems($itemsDetail)
    {
        $items = [];
        foreach ($itemsDetail as $item) {
            /*
             * If item ship as == alone or multi-package, then we will not consider it for 3d bin calculation
             * Means on product settings customer have selected item
             * as ship own package or ship as multi pacakage
             * */
            $shipOwnOrMultiPackage = (isset($item['shipBinAlone']) && $item['shipBinAlone'] == 1) || (isset($item['shipMultiplePackage']) && $item['shipMultiplePackage'] == 1);
            if (!$shipOwnOrMultiPackage) {
                $productSettingsId = $item['id'];
                $items[$productSettingsId] = $this->getItemDetailForPackaging($item);
            }
        }

        return $items;
    }

    public function getItemDetailForPackaging($item)
    {
        $sbsItem['id'] = $item['id'] ?? '';
        $sbsItem['wg'] = $item['lineItemWeight'] ?? '';
        $sbsItem['h'] = $item['lineItemHeight'] ?? '';
        $sbsItem['d'] = $item['lineItemLength'] ?? '';
        $sbsItem['w'] = $item['lineItemWidth'] ?? '';
        $sbsItem['q'] = $item['originalPiecesOfLineItem'] ?? '';
        $sbsItem['vr'] = $item['allow_vertically'] ?? 0;

        return $sbsItem;
    }

    public function getAndSet3dBinResponse()
    {
        if (empty($this->packagingRequest)) {
            return null;
        }

        $curlResponse = $this->boxingMultiCurl($this->packagingRequest);
        $this->formatResponse($curlResponse);
    }

    public function boxingMultiCurl($boxingRequestArr)
    {
        $chs = [];
        // create array for responses
        $responses = [];
        // init curl multi handle
        $mh = curl_multi_init();
        // create running flag
        $running = null;
        // cycle through requests and set up
        foreach ($boxingRequestArr as $locIdWithType => $request) {
            $requestKey = $locIdWithType;
            $requestData = $request['request'];
            $header = $request['headers'];
            $hittingUrl = $request['endpoint'];
            // init individual curl handle
            $chs[$requestKey] = curl_init();
            // set url
            curl_setopt($chs[$requestKey], CURLOPT_URL, $hittingUrl);
            // check for post data and handle if present
            curl_setopt($chs[$requestKey], CURLOPT_POST, 1);
            curl_setopt($chs[$requestKey], CURLOPT_POSTFIELDS, $requestData);

            curl_setopt($chs[$requestKey], CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($chs[$requestKey], CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($chs[$requestKey], CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($chs[$requestKey], CURLOPT_HTTPHEADER, $header);
            curl_multi_add_handle($mh, $chs[$requestKey]);
        }

        do {
            // execute curl requests
            curl_multi_exec($mh, $running);
        } while ($running > 0);

        // cycle through requests
        foreach ($chs as $requestKey => $ch) {
            $response = curl_multi_getcontent($ch);

            $info = curl_getinfo($ch);
            $responses[$requestKey] = $response;
            // close individual handle
            curl_multi_remove_handle($mh, $ch);
        }
        // close multi handle
        curl_multi_close($mh);

        return $responses;
    }

    private function formatResponse($curlResponse)
    {
        foreach ($curlResponse as $locIdWithType => $response) {
            $locIdWithType = explode('-', $locIdWithType);
            $locId = $locIdWithType[0];
            $type = $locIdWithType[1];
            $this->locId = $locId;
            $response = json_decode($response, true);
            $this->setPackagingResponse($response['response'] ?? [], $locId, $type);
        }
    }

    private function setPackagingResponse($response, $locId, $type = null)
    {
        if (isset($response['bins_packed']) && !blank($response['bins_packed'])) {
            $this->setPackedItems3dBinResponse($response['bins_packed'], $locId, $type);
        }
        if (isset($response['not_packed_items']) && !blank($response['not_packed_items'])) {
            $this->setUnPackedItems3dBinResponse($response['not_packed_items'], $locId, $type);
        }
    }

    public function setPackedItems3dBinResponse($bins, $locId, $type = null)
    {
        $bins = $this->addBoxWeightInPackedBinsWeight($bins);
        $this->finalBoxesForWs[$locId][$type]['bins_packed'] = !empty($this->finalBoxesForWs[$locId][$type]['bins_packed']) ? array_merge($this->finalBoxesForWs[$locId][$type]['bins_packed'], $bins) : $bins;
    }

    public function addBoxWeightInPackedBinsWeight($bins)
    {
        $formattedBins = [];
        $collectionOfBoxes = collect($this->storeBoxes);
        foreach ($bins as $bin) {
            $binData = $collectionOfBoxes->where('id', $bin['bin_data']['id'])->first();
            $bin['bin_data']['comulative_weight'] = !empty($binData['box_weight']) ? $bin['bin_data']['weight'] + $binData['box_weight'] : $bin['bin_data']['weight'];
            $formattedBins[] = $bin;
        }

        return $formattedBins;
    }

    public function setUnPackedItems3dBinResponse($unPackedItems, $locId, $type = null)
    {
        foreach ($unPackedItems as $item) {
            for ($i = 0; $i < $item['q']; $i++) {
                $boxDetail['length'] = $item['d'] ?? '';
                $boxDetail['width'] = $item['w'] ?? '';
                $boxDetail['height'] = $item['h'] ?? '';
                $boxDetail['weight'] = $item['wg'] ?? '';
                $boxDetail['id'] = 'own_package';

                $itemDetail = $boxDetail;
                $itemDetail['id'] = $item['id'] ?? '';
                $itemDetail['q'] = 1;

                $detail = $this->arrayFormatOf3dBinResponse($boxDetail, $itemDetail);
                $this->binResArr[$locId][$type]['bins_unpacked'][] = $detail;
            }
        }
    }

    public function arrayFormatOf3dBinResponse($boxDetail = [], $itemDetail = [])
    {
        $arr = array(
            "bin_data" => array(
                "w" => $boxDetail['width'] ?? '',
                "h" => $boxDetail['height'] ?? '',
                "d" => $boxDetail['length'] ?? '',
                "id" => $boxDetail['id'] ?? '',
                "used_space" => 100,
                "weight" => $boxDetail['weight'] ?? '',
                // Will use that index for Ws Request
                "comulative_weight" => $boxDetail['weight'] ?? '',
                "gross_weight" => $boxDetail['weight'] ?? '',
                "used_weight" => $boxDetail['weight'] ?? '',
                "stack_height" => 2,
                "order_id" => "unknown",
            ),
            "image_complete" => Endpoints::getUnpackedBoxUrl(),
            "images_generation_time" => 0.00252,
            "packing_time" => 0.00327,
            "items" => array(
                array(
                    "id" => $itemDetail['id'] ?? '',
                    "w" => $itemDetail['width'] ?? '',
                    "h" => $itemDetail['height'] ?? '',
                    "d" => $itemDetail['length'] ?? '',
                    "wg" => $itemDetail['weight'] ?? '',
                    "q" => $itemDetail['q'] ?? 1,
                    "image_separated" => Endpoints::getUnpackedBoxUrl(),
                    "image_sbs" => Endpoints::getUnpackedBoxUrl(),
                    "coordinates" => array(
                        "x1" => 0,
                        "y1" => 0,
                        "z1" => 0,
                        "x2" => 2,
                        "y2" => 2,
                        "z2" => 2,
                    ),
                ),
            ),
        );

        return $arr;
    }
}
