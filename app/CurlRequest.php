<?php

namespace App;


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

    public function enSingleCurlRequest($endPoint, $request, $header, $method, $showHeaders = true)
    {
        set_time_limit(0);
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $endPoint);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        if ($method != 'GET') {
            // curl_setopt($soap_do, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $request);
        }
        curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
        //    curl_setopt($soap_do, CURLOPT_RETURNTRANSFER, 1);
        //    curl_setopt($soap_do, CURLOPT_VERBOSE, 1);
        //    curl_setopt($soap_do, CURLOPT_HEADER, 1);
//        curl_setopt($soap_do, CURLOPT_TIMEOUT, 5);


        if ($showHeaders == true) {
            curl_setopt($curl, CURLOPT_FAILONERROR, true);
        }
        $result = curl_exec($curl);

        if (curl_errno($curl)) {
            $error_msg = curl_error($curl);
            $this->curlResponse['status'] = false;
            $this->curlResponse['response'] = $error_msg;
            return $this->curlResponse;
        }
        curl_close($curl);
        $this->curlResponse['status'] = true;
        $this->curlResponse['response'] = $result;
        return $this->curlResponse;

    }

    /**
     * @param $requestArr
     * @param $method
     * @param null $desc
     * @return array
     */
    public function enMultiCurl($requestArr, $method, $desc = null)
    {
        $chs = [];
        // create array for responses
        $responses = [];
        // init curl multi handle
        $mh = curl_multi_init();
        // create running flag
        $running = null;

        // cycle through requests and set up
        foreach ($requestArr as $key => $request) {

            $requestData = $request['request'];
            $header = $request['header'];
            $hittingUrl = $request['endpoint'];
            // dd($requestData, $header, $hittingUrl);
            // init individual curl handle
            $chs[$key] = curl_init();
            // set url
            curl_setopt($chs[$key], CURLOPT_URL, $hittingUrl);
            // check for post data and handle if present
            curl_setopt($chs[$key], CURLOPT_POST, 1);
            if (isset($request['user_pwd'])) {
                curl_setopt($chs[$key], CURLOPT_USERPWD, $request['creds']);
            }
            curl_setopt($chs[$key], CURLOPT_POSTFIELDS, $requestData);
            curl_setopt($chs[$key], CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($chs[$key], CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($chs[$key], CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($chs[$key], CURLOPT_HTTPHEADER, $header);

            /*
             * execute Curl Reqeusst
             */
            curl_multi_add_handle($mh, $chs[$key]);
        }

        do {
            // execute curl requests
            curl_multi_exec($mh, $running);
        } while ($running > 0);

        // cycle through requests
        foreach ($chs as $key => $ch) {
            $smc3Key = explode('-', $key);

            $response = curl_multi_getcontent($ch);

            $info = curl_getinfo($ch);
            $responses[$key] = $response;
            // close individual handle
            curl_multi_remove_handle($mh, $ch);
        }
        // close multi handle
        curl_multi_close($mh);

        // dd($responses);
        return $responses;
    }

    /*
     * This function is used to update the SMC3 API Access Token
     * */


}
