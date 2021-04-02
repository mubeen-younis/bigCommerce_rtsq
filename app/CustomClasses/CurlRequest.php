<?php

namespace App\CustomClasses;


/**
 * Class CurlRequest
 * @package App
 */
class CurlRequest
{
    /**
     * @var array
     */
    protected $curlResponse = array();

    /**
     * @param $endPoint
     * @param $request
     * @param $header
     * @param $method
     * @return array
     */

    public function enSingleCurlRequest($endPoint, $request, $header, $method, $desc = null, $showHeaders = true)
    {
        set_time_limit(0);
        $soap_do = curl_init();
        curl_setopt($soap_do, CURLOPT_URL, $endPoint);
        curl_setopt($soap_do, CURLOPT_RETURNTRANSFER, true);

        if ($method != 'GET') {
            // curl_setopt($soap_do, CURLOPT_POST, true);
            curl_setopt($soap_do, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($soap_do, CURLOPT_POSTFIELDS, $request);
        }
        curl_setopt($soap_do, CURLOPT_HTTPHEADER, $header);
        //    curl_setopt($soap_do, CURLOPT_RETURNTRANSFER, 1);
        //    curl_setopt($soap_do, CURLOPT_VERBOSE, 1);
        //    curl_setopt($soap_do, CURLOPT_HEADER, 1);
//        curl_setopt($soap_do, CURLOPT_TIMEOUT, 5);


        if ($showHeaders == true) {
            curl_setopt($soap_do, CURLOPT_FAILONERROR, true);
        }
        $result = curl_exec($soap_do);
        if (curl_errno($soap_do)) {
            $error_msg = curl_error($soap_do);
            $this->curlResponse['status'] = false;
            $this->curlResponse['response'] = $error_msg;
            return $this->curlResponse;
        }
        curl_close($soap_do);
        $this->curlResponse['status'] = true;
        $this->curlResponse['response'] = $result;
        return $this->curlResponse;

    }


}
