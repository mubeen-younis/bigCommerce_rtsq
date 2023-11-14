<?php

namespace App\CustomClasses\DBSC;

use App\Models\DBSC\DistanceLookup;
use App\Models\DBSC\AddressLookup;
use Illuminate\Support\Facades\Log;

class GetStraightDistance extends GetDistance
{


    public function getStraightLineDistance($origin, $destination)
    {
        foreach($origin as $key =>$orig){
            $origin['city'] = trim($orig['city']);
            $origin['state'] = trim($orig['state']);
            $origin['zip'] = str_replace(' ', '', trim($orig['zip'])); // remove the white-spaces in the zip.
            $origin['country'] = trim($orig['country']);
        }
        $destinationObj = (object)$destination;
        // also remove the whitespaces in the zip code i.e. make 'J0Z 2S0' = 'J0Z2S0
        $destinationZip = str_replace(' ', '', trim($destinationObj->zip));

        //First we will get the distance from the database from distance lookup table for straightline
        $distance = DistanceLookup::getDistanceData($origin['zip'], $destinationZip);
        if (!blank($distance)) {
            if (!blank($distance['distance_straight_line_m'])) {
                $finalDistance = $distance['distance_straight_line_m'];

            } else {
                $finalDistance = $this->calculateGeoCodeDistance($origin, $destination);

                $this->updateStraightLineDistanceDatabase($finalDistance, $distance);
            }
        } else {
            $finalDistance = $this->calculateGeoCodeDistance($origin, $destination);
            $this->insertStraightLineDistanceDatabase($origin['zip'], $destinationZip, $finalDistance);
        }

        return array(
            'distance_m' => $finalDistance,
        );

    }

    public function updateStraightLineDistanceDatabase($updateDistance, $distance)
    {
        $straightLineData = [
            'distance_straight_line_m' => $updateDistance,
            'lookup_count' => $distance['lookup_count'] + 1
        ];

        $result = DistanceLookup::where("id", $distance['id'])->update($straightLineData);
    }

    public function calculateGeoCodeDistance($origin, $destination)
    {
        // Now we will get the distance from Harvensive formula function
        // But for that first we will need to get the geocode for the origin and destination
        $locations = array($origin, $destination);
        $finalGeoCodeData = $this->getGeoCodeData($locations);

        foreach ($finalGeoCodeData as $key => $data) {
            if (isset($finalGeoCodeData['error'])) {
                Log::info('Google API Error ' . $finalGeoCodeData['error']);
                return ['error' => 'Google API Error'];
            };
            $longitude = $data['longitude'];
            $latitude = $data['latitude'];
            if ($origin['zip'] == $data['postal_code']) {
                $origin['lat'] = $latitude;
                $origin['lng'] = $longitude;
            }
            if ($destination['zip'] == $data['postal_code']) {
                $destination['lat'] = $latitude;
                $destination['lng'] = $longitude;
            }
        }

        return $this->getDistanceInMetersThroughHaversine($origin, $destination);
    }

    public function getDistanceInMetersThroughHaversine($senderLngLatArr, $receiverLngLatArr)
    {
        // convert from degrees to radians
        $latFrom = deg2rad($senderLngLatArr['lat']);
        $lonFrom = deg2rad($senderLngLatArr['lng']);
        $latTo = deg2rad($receiverLngLatArr['lat']);
        $lonTo = deg2rad($receiverLngLatArr['lng']);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $angle = 2 * asin(
                sqrt(
                    pow(sin($latDelta / 2), 2) +
                    cos($latFrom) *
                    cos($latTo) *
                    pow(sin($lonDelta / 2), 2)
                )
            );
        $earthRadius = 6371000; // radius of earth in miles

        $result = $angle * $earthRadius;

        return round(($result), 2);

    }

    public function getGeoCodeData($locations)
    {
        // Contain the origins string as a url which will be passed to Google API to find the distance.
        $geoCodeUrl = '';
        // Combinations against we will get the distance from the API.
        $enabledCombinations = [];
        // Holds the geolocation got from database
        $geoCodeRows = [];
        foreach ($locations as $location) {
            $geoCode = $this->getGeoCodeDataDatabase($location['zip']);

            if (!empty($geoCode)) {
                if (!empty($geoCode['longitude']) && !empty($geoCode['latitude'])) {
                    $geoCodeRows[] = $geoCode;

                } else {
                    // if not found then make origin url string
                    $geoCodeUrl .= urlencode("{$location['city']}  {$location['state']} {$location['zip']}") . "|";
                    // Make combination
                    $enabledCombinations[] = [
                        'lookup_count' => $geoCode['lookup_count'],
                        'zip' => $location['zip'],
                        'state' => $location['state'],
                        'country' => $location['country'],
                        'city' => $location['city'],
                    ];
                }
            } else {
                // if not found then make origin url string
                $geoCodeUrl .= urlencode("{$location['city']}  {$location['state']} {$location['zip']}") . "|";
                // Make combination
                $enabledCombinations[] = [
                    'zip' => $location['zip'],
                    'state' => $location['state'],
                    'country' => $location['country'],
                    'city' => $location['city'],
                ];
            }
        }

        if (!empty($geoCodeUrl)) {
            // If origin url is set, then we right trim the '|' sign from the string. There can be multiple origins in
            // url string.
            // i.e. 'Chicago+IL+60701+US|Chicago+IL+60701+US|' to 'Chicago+IL+60701+US|Chicago+IL+60701+US'
            $geoCodeUrl = rtrim($geoCodeUrl, '|');
            $addressCount = count($enabledCombinations);
            $geocodeObj = $this->getGeoCodeDataApi($geoCodeUrl, $this->googleGeocodingApiKey, $addressCount);
        }


        if ($geocodeObj != 'server_error') {
            $apiResponse = json_decode($geocodeObj);
        } else {
            Log::info('Google API error' . json_encode($geocodeObj));
            return ['error' => 'Google API Error'];
        }
        // Check only when origin url is set
        if (!empty($geoCodeUrl)) {
            if ($this->googleAPIErrorExist($apiResponse)) {
                Log::info('Google API error' . json_encode($geocodeObj));
                return ['error' => 'Google API Error'];
            };
        }

        // Declare an array for holding final array.
        $finalGeoCodeData = [];
        // Details contains the response from the Google API, So if that is set then we insert these records to database
        // for future use.
        // $enabledCombinations are the zips combs that are not exists in database.
        if ($apiResponse && !empty($enabledCombinations)) {
            $finalGeoCodeData = $this->verifyStoreGeoCodeApiResponse($enabledCombinations, $apiResponse);
        }
        // If there we found data from the database then merge both the arrays.
        if (!empty($geoCodeRows)) {
            $finalGeoCodeData = array_merge($finalGeoCodeData, $geoCodeRows);
        }

        return $finalGeoCodeData;
    }

    public function getGeoCodeDataDatabase($zip_code)
    {
        // Where part of the query. We get the record from the database against following parameters.
        $where = ['postal_code' => $zip_code];
        // Get the distance row from the database
        $geoCode = AddressLookup::where('postal_code', '=', $zip_code)->first();
        // If found
        if (!empty($geoCode)) {
            if (!empty($geoCode['longitude']) && !empty($geoCode['latitude'])) {
                // Increment the count for the lookup_count value and update.
                $updateParams = ['lookup_count' => $geoCode['lookup_count'] + 1];
                // update the last get id lookup count with an increment.
                $updateLookupCount = AddressLookup::where("id", $geoCode['id'])->update($updateParams);
            }
        }

        return $geoCode;
    }

    public function getGeoCodeDataApi($origin, $apiKey, $addressCount)
    {
        $url = "https://maps.googleapis.com/maps/api/geocode/json?";
        $url .= "address=" . $origin . "&";
        $url .= "key=" . $apiKey;

        $headers = array(
            "Content-type: text/xml;charset=\"utf-8\"",
            "Accept: text/xml",
            "Cache-Control: no-cache",
            "Pragma: no-cache",
        );
        $ch = curl_init();
        // set url
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, 180);
        // array of values
        //return the transfer as a string
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        // close curl resource to free up system resources
        $respInfo = curl_getinfo($ch);
        curl_close($ch);

        if ($respInfo['http_code'] == 200) {
            return $response;
        } else {
            return 'server_error';
        }
    }


    public function verifyStoreGeoCodeApiResponse($enabledCombinations, $apiResponse)
    {
        foreach($enabledCombinations as $key => $combination)
        {
            if ($this->dataExistsInGeoCodeApiResponse($apiResponse, $combination)) {
                $finalData[] = $this->insertOrUpdateGeoCodeData($apiResponse, $combination);
            }else {
                // send request to google api again
                $geoCodeUrl = urlencode("{$combination['city']}  {$combination['province']} {$combination['postal_code']}  ");

                $geocode_obj = $this->getGeoCodeDataApi($geoCodeUrl, $this->geoCodeApiKey, 1);

                $secondApiResponse = '';
                if ($geocode_obj != 'server_error') {
                    $secondApiResponse = json_decode($geocode_obj);
                } else {
                    $this->googleApiError = true;
                }
                if ($this->dataExistsInGeoCodeApiResponse($secondApiResponse, $combination)) {
                    $finalData[] = $this->insertOrUpdateGeoCodeData($secondApiResponse, $combination);
                }
            }
        }

        return $finalData;
    }

    public function dataExistsInGeoCodeApiResponse($response, $combination)
    {
        $results = $response->results;
        foreach ($results as $index => $result) {
            // So here we are checking whether postal code type exists and also whether our value matches against that type.
            if ($this->typeExistsInAddressComponents($result, 'postal_code', $combination['zip'])) {
                $this->index = (int)$index;
                return true;
            }
        }

        return false;
    }

    public function typeExistsInAddressComponents($result, $type, $combinationValue)
    {
        $address = $result->address_components;
        foreach ($address as $value) {

            if (
                ($value->long_name == $combinationValue ||
                    $value->short_name == $combinationValue)
                && in_array($type, $value->types)
            ) {
                return true;
            }

        }
        return false;
    }

    public function insertOrUpdateGeoCodeData($apiData, $combination)
    {
        /*
         * This function is used for inserting fresh geocode combinations in the database
         * */
        $locationData = [];

        $key = $this->index;
        $location_valid = isset($apiData->results[$key]->geometry->location) && !empty($apiData->results[$key]->geometry->location);
        $location = $apiData->results[$key]->geometry->location;

        if ($location_valid) {

            $combination_id_valid = isset($combination['update_id']) && !empty($combination['update_id']);

            $locationData = [
                'postal_code' => $combination['zip'],
                'province' => $combination['state'],
                'country' => $combination['country'],
                'city' => $combination['city'],
                'latitude' => $location->lat,
                'longitude' => $location->lng,
                'lookup_count' => isset($combination['lookup_count']) ? $combination['lookup_count']  : 0,
            ];
            if ($combination_id_valid) {
                $result = AddressLookup::where("id", $combination['update_id'])->update($locationData);
                
            } else {
                $result = AddressLookup::create($locationData);

            }
        }

        return $locationData;
    }

    public function insertStraightLineDistanceDatabase($origin_zip, $destination_zip, $final_distance)
    {
        $straightLineData = [
            'origin_zip' => $origin_zip,
            'destination_zip' => $destination_zip,
            'distance_straight_line_m' => $final_distance,
            'lookup_count' => 1
        ];

        $result = DistanceLookup::create($straightLineData);

    }

}