<?php

namespace App\CustomClasses;

use App\Models\Store;

class BigCommerceFunctions
{
    public static $version = "v2";

    public static function getStoreSettings($storeHash): array
    {
        $endPoint = "https://api.bigcommerce.com/stores/" . $storeHash . "/" . self::$version . "/store";
        return self::getRequestArray($endPoint, [], 'GET', $storeHash);
    }

    public static function getRequestArray($endPoint, $request, $method, $storeHash): array
    {
        return ['endpoint' => $endPoint,
            'request' => $request,
            'method' => $method,
            'headers' => self::getHeaders($storeHash)
        ];
    }

    public static function getHeaders($storeHash)
    {
        $accessToken = Store::getAccessToken($storeHash);
        return ['Content-Type: Application/json',
            'X-Auth-Token: ' . $accessToken];
    }
}
