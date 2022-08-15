<?php

namespace App\CustomClasses\DBSC;

use App\Models\DBSC\DistanceLookup;

class GetStraightDistance extends GetDistance
{


    public function getStraightLineDistance($origin, $destination)
    {
        $origin['city'] = trim($origin['city']);
        $origin['state'] = trim($origin['state']);
        $origin['zip'] = str_replace(' ', '', trim($origin['zip'])); // remove the white-spaces in the zip.
        $origin['country'] = trim($origin['country']);

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
            $this->log('$finalDistance', $finalDistance);
            $this->qaLogs('Distance after Haversine Formula', $finalDistance);

            $this->insertStraightLineDistanceDatabase($origin['zip'], $destinationZip, $finalDistance);
        }

        return array(
            'distance_meter' => $finalDistance,
        );

    }


    public function calculateGeoCodeDistance($origin, $destination)
    {
        // Now we will get the distance from Harvensive formula function
        // But for that first we will need to get the geocode for the origin and destination
        $locations = array($origin, $destination);
        $finalGeoCodeData = $this->getGeoCodeData($locations);
        dd(33);



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
            $geoCode = []; // TODO will get latitudes and logitudes from DB

            if (count($geoCode)) {
                if (!empty($geoCode['longitude']) && !empty($geoCode['latitude'])) {
                    $geoCodeRows[] = $geoCode;

                } else {
                    // if not found then make origin url string
                    $geoCodeUrl .= urlencode("{$location['city']}  {$location['state']} {$location['zip']}") . "|";
                    // Make combination
                    $enabledCombinations[] = [
                        'update_id' => $geoCode['id'],
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
            return ['error' => 'Google API Error'];
        }
        // Check only when origin url is set
        if (!empty($geoCodeUrl)) {
            if ($this->googleAPIErrorExist($apiResponse)) {
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
            dd(33);
        }
        // If there we found data from the database then merge both the arrays.
        if (!empty($geoCodeRows)) {
            $finalGeoCodeData = array_merge($finalGeoCodeData, $geoCodeRows);
        }


        return $finalGeoCodeData;
    }

    public function getGeoCodeDataApi($origin, $apiKey, $addressCount)
    {
        $url = "https://maps.googleapis.com/maps/api/geocode/json?";
        $url .= "address=" . $origin . "&";
        //$url .= "components=postal_code:".$destination."&";
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

    }


}
