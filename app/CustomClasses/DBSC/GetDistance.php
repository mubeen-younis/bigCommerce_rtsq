<?php

namespace App\CustomClasses\DBSC;

use App\Models\DBSC\DistanceLookup;

class GetDistance
{
    private $googleDistanceApiKey = "AIzaSyAEpMbPnNPg2I2_X_65ulD9eHCH5KG7Exc";
    private $googleGeocodingApiKey = "AIzaSyADPlm4GliK0B0HpHn6kKLJ2XAH7b3hd2w";

    public function findDistance($type, $origin, $destination, $shop)
    {
        $distance = null;
        if ($type == 'Route') {
            $distance = $this->findRouteDistance($origin, $destination, $shop);
        } else {
            $distance = $this->getStraightLineDistance($origin, $destination, $shop);
        }
        return $distance;
    }


    /**
     * @param     $origin
     * @param     $destination
     * @param     $shop
     *
     * @return array
     */
    public function findRouteDistance($origin, $destination, $shop)
    {
        // Contain the origins string as a url which will be passed to Google API to find the distance.
        $originUrl = '';
        // Combinations against we will get the distance from the API.
        $enabledCombinations = [];
        // Holds the distance got from database
        $distanceRows = [];
        $origin['city'] = trim($origin['city']);
        $origin['state'] = trim($origin['state']);
        $origin['zip'] = str_replace(' ', '', trim($origin['zip'])); // remove the white-spaces in the zip.
        $origin['country'] = trim($origin['country']);

        $destinationObject = (object)$destination;

        // also remove the the whitespaces in the zip code i.e. make 'J0Z 2S0' = 'J0Z2S0
        $destinationZip = str_replace(' ', '', trim($destinationObject->zip));
        // Destination address
        $destinationUrl = urlencode(
            trim($destinationObject->city) . ' ' .
            trim($destinationObject->state) . ' ' .
            trim($destinationObject->zip) . ' ' .
            trim($destinationObject->country)
        );


        // Get the distance from the database against origin and destination.
        // Get the distance row from database

        $distance = DistanceLookup::getDistanceData($origin['zip'], $destinationZip);

        //if found
        if (!blank($distance)) {
            $distanceRows[] = $distance;
        } else {
            // if not found then make origin url string
            $originUrl .= urlencode("{$origin['city']}  {$origin['state']} {$origin['zip']}") . "|";
            // Make combination
            $enabledCombinations[] = ['origin_zip' => $origin['zip'], 'destination_zip' => $destinationZip];
        }
        $distanceObj = '';
        if (!empty($originUrl)) {
            // If origin url is set, then we right trim the '|' sign from the string.
            // There can be multiple origins in url string.
            // i.e. 'Chicago+IL+60701+US|Chicago+IL+60701+US|' to 'Chicago+IL+60701+US|Chicago+IL+60701+US'
            $originUrl = rtrim($originUrl, '|');
            // check for available lookups
            $distanceObj = $this->getDistanceMatrixDataApi($originUrl, $destinationUrl, $this->googleDistanceApiKey);
            dd(23, $distanceObj);
            if ($distanceObj == 'error' || empty($distanceObj)) {
                return ['error' => 'The lookups are not available.'];
            }
        }

        $apiResponse = [];

        // Check only when origin url is set
        if (!empty($originUrl)) {
            if ($distanceObj == 'server_error') {
                return ['error' => 'Server error'];
            } else {
                // to change the array to object recursively
                $apiResponse = json_decode($distanceObj);
            }
            if ($this->googleAPIErrorExist($apiResponse)) {
                return ['error' => 'Google API error.'];
            }
        }

        // Declare an array for holding final array.
        $finalDistance = [];
        // Details contains the response from the Google API, So if that is set then we insert these records to database
        // for future use.
        // $enabledCombinations are the zips combs that are not exists in database.
        if ($apiResponse && !empty($enabledCombinations)) {
            // insert distance data to database (distance_lookup)
            $finalDistance = DistanceLookup::insertDistanceData($apiResponse, $enabledCombinations);
        }
        // If we found data from the database then merge both the arrays.
        if (!empty($distanceRows)) {
            $finalDistance = array_merge($finalDistance, $distanceRows);
        }

        foreach ($finalDistance as $key => $distance) {
            $miles = $distance['distance_mi'];
            $metres = $distance['distance_m'];

            $distanceArray['distance_km'] = $miles;
            $distanceArray['distance_miles'] = $miles;
            $distanceArray['distance_m'] = $metres;

        }
        return array(
            'origin' => $origin,
            'distance_meter' => $distanceArray['distance_m'],
            'distance_km' => $distanceArray['distance_km']
        );

    }



}
