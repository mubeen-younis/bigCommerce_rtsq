<?php

namespace App\CustomClasses;

use App\Models\Store;

class BigCommerceFunctions
{
    public static $version = "v2";
    public static $initalUrl = "https://api.bigcommerce.com/stores/";

    public static function getStoreSettings($storeHash): array
    {
        $endPoint = self::$initalUrl . $storeHash . "/" . self::$version . "/store";
        return self::getRequestArray($endPoint, [], 'GET', $storeHash);
    }

    public static function getWebhooksOfStore($storeHash)
    {
        $endPoint = self::$initalUrl . $storeHash . "/" . self::$version . "/hooks";
        return self::getRequestArray($endPoint, [], 'GET', $storeHash);

    }

    public static function getUpdateWebhookDetail($storeHash, $id)
    {
        $endPoint = self::$initalUrl . $storeHash . "/" . self::$version . "/hooks/" . $id;
        return self::getRequestArray($endPoint, json_encode(['is_active' => true]), 'PUT', $storeHash);

    }

    public static function getZonesOfStore($storeHash)
    {
        $endPoint = self::$initalUrl . $storeHash . "/" . self::$version . "/shipping/zones";
        return self::getRequestArray($endPoint, [], 'GET', $storeHash);

    }

    public static function getZone($storeHash, $id)
    {
        $endPoint = self::$initalUrl . $storeHash . "/" . self::$version . "/shipping/zones/" . $id;
        return self::getRequestArray($endPoint, [], 'GET', $storeHash, $id);

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
            'Accept: application/json',
            'X-Auth-Token: ' . $accessToken];
    }

}
