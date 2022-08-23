<?php

namespace App\CustomClasses\DBSC;

use App\Constants\Constant;
use App\Constants\Endpoints;
use App\CustomClasses\Functions;
use App\Models\DBSC\DistanceLookup;
use Illuminate\Support\Facades\Log;

class GetDistance
{
    protected $distanceMatrixUrl = "https://maps.googleapis.com/maps/api/distancematrix/json?";
    protected $googleDistanceApiKey = "AIzaSyAEpMbPnNPg2I2_X_65ulD9eHCH5KG7Exc";
    protected $googleGeocodingApiKey = "AIzaSyADPlm4GliK0B0HpHn6kKLJ2XAH7b3hd2w";


    /**
     * Gets Distance for Profile Rate
     * @param $type
     * @param $origin
     * @param $destination
     * @param $shop
     * @return array|bool|string
     */
    public function findDistance($type, $origin, $destination, $shop): bool|array|string
    {
        if ($type == 'Route') {
            // IF the nearest warehouse has already fetched so the route distance is also the nearest
            // So we can use that as well
            if (isset($origin['distance_m']) && !blank($origin['distance_m'])) {
                return $origin;
            }
            $originArr[0] = $origin;
            $distance = $this->findRouteDistances($originArr, $destination);

        } else {
            $distance = (new GetStraightDistance())->getStraightLineDistance($origin, $destination);
        }
        return $distance;
    }

    /**
     * Gets the Nearest Address from destination
     * @param $origins
     * @param $destination
     * @return mixed
     */
    public function getNearest($origins, $destination): mixed
    {
        if (count($origins) <= 1) {
            return $origins;
        }
        $nearest = $this->findRouteDistances($origins, $destination);
        if (isset($nearest['error'])) {
            return [];
        }
        return $nearest;
    }

    /**
     * Find the distance between origins and destination address.
     *
     * 1. First we check the record in the database agains origin and destination combination.
     * 2. If record found then we update the record by incrementing the lookup_count by 1. Then we return this
     *    record to use as distance. We loop through all the origin with destination to find the recode from the
     *    database if not found then we make an array of these combination and sends the request to Google API.
     * 3. If API returns the response then we save these distance record to database for future use.
     *
     * @param      $origins
     * @param      $destination
     * @param bool $no_res
     *
     * @return array|bool|false|string
     */
    public function findRouteDistances($origins, $destination): array|bool|string
    {
        // Contain the origins string as a url which will be passed to Google API to find the distance.
        $originUrl = '';

        // Combinations against we will get the distance from the API.
        $enabledCombinations = [];

        // Holds the distance got from database
        $distanceRows = [];

        $destination = (object)$destination;

        // also remove the the whitespaces in the zip code i.e. make 'J0Z 2S0' = 'J0Z2S0
        $destinationZip = str_replace(' ', '', trim($destination->zip));
        // Destination address
        $destinationUrl = urlencode(
            trim($destination->city) . ' ' .
            trim($destination->state) . ' ' .
            trim($destination->zip) . ' ' .
            trim($destination->country)
        );

        // if origin is array then execute following logic.
        if (is_array($origins)) {
            // loop through all the origins and get the distance from the database against origin and destination.
            foreach ($origins as $key => $origin) {
                // Get the distance row from database

                $distance = DistanceLookup::getDistanceData($origin['zip'], $destinationZip);

                if (!blank($distance)) {
                    $distanceRows[] = $distance;
                } else {
                    // if not found then make origin url string
                    $originUrl .= urlencode("{$origin['city']}  {$origin['state']} {$origin['zip']}|");
                    // Make combination
                    $enabledCombinations[] = ['origin_zip' => $origin['zip'], 'destination_zip' => $destinationZip];
                }
            }
            // If origin url is set, then we right trim the '|' sign from the string. There can be multiple origins in
            // url string.
            // i.e. 'Chicago+IL+60701+US|Chicago+IL+60701+US|' to 'Chicago+IL+60701+US|Chicago+IL+60701+US'
            if ($originUrl != '') {
                // remove '|' form right of the string
                $originUrl = rtrim($originUrl, '|');
            }

        }

        $apiResponse = '';
        /* API Call */
        if ($originUrl != '') {
            $distanceObj = (new self)->getDistanceFromGoogleApi($originUrl, $destinationUrl, $this->googleDistanceApiKey);
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
        if (isset($apiResponse) && !empty($enabledCombinations)) {
            // insert distance data to database (distance_lookup)
            $finalDistance = DistanceLookup::insertDistanceData($apiResponse, $enabledCombinations);
        }


        // If their we found data from the database then merge both the arrays.
        if (!empty($distanceRows)) {
            $finalDistance = array_merge($finalDistance, $distanceRows);
        }

        if (!blank($finalDistance)) {
            $distances = collect($finalDistance);
            $minDistanceOrigin = $distances->where('distance_m', $distances->min('distance_m'))->first();
            $nearestAddress = collect($origins)->where('zip', $minDistanceOrigin['origin_zip'])->first();
            $nearestAddress['distance_m'] = !empty($minDistanceOrigin['distance_m']) ? Functions::removeString($minDistanceOrigin['distance_m']) : null;
        } else {
            $nearestAddress = collect($origins)->first();
            $nearestAddress['distance_m'] = null;
        }
        //return the final array of distance
        return $nearestAddress;
    }


    /**
     * Gets Details of Distance From Google API
     * @param $origin
     * @param $destination
     * @param $apiKey
     * @return bool|string
     */
    public function getDistanceFromGoogleApi($origin, $destination, $apiKey)
    {
        $url = $this->distanceMatrixUrl;
        $url .= "origins=" . $origin . "&";
        $url .= "destinations=" . $destination . "&";
        $url .= "key=" . $apiKey;
//            error_log('$url:' . json_encode($url));
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
        // $output contains the output string
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

    /**
     * Check error in google API
     * @param $apiResponse
     * @return bool
     */
    public function googleAPIErrorExist($apiResponse)
    {
        return isset($apiResponse->error_message) || (isset($apiResponse->status) &&
                $apiResponse->status == 'INVALID_REQUEST') || $apiResponse->results[0] == '' ||
            (isset($apiResponse->rows[0]->elements[0]->status) && $apiResponse->rows[0]->elements[0]->status != 'OK');
    }


    public function getStraightLineDistance($origin, $destination)
    {
        dd(334);
    }


}
