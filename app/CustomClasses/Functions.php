<?php

namespace App\CustomClasses;

use App\Models\BoxSize;

class Functions
{
    public static function hasInsureCarrier($code)
    {
        $insureCarriers = ['wweltl', 'parcel_12wwe', 'parcel_12ups', 'parcel_12fd'];
        foreach ($insureCarriers as $insureCarrier) {
            if (strpos($code, $insureCarrier) !== false) {
                return true;
            }
        }
        return false;
    }

    public static function getCarrierName($code): ?string
    {
        $carrierCodes = ['wweltl', 'rnlltl', 'xpoltl', 'fedexltl', 'gtzltl', 'cltl', 'parcel_12wwe', 'parcel_12ups', 'parcel_12fd'];
        foreach ($carrierCodes as $carrierCode) {
            if (strpos($code, $carrierCode) !== false) {
                return self::getCarrierNameFromCode($carrierCode);
            }
        }
        return null;
    }

    public static function getCarrierNameFromCode($carrierCode): ?string
    {
        $carrierCodesWithName = ['wweltl' => 'Worldwide Express LTL', 'rnlltl' => 'R&L Carriers', 'xpoltl' => 'XPO Logistics',
            'fedexltl' => 'FedEx LTL', 'gtzltl' => 'GlobalTranz LTL', 'cltl' => 'Cerasis Ltl',
            'parcel_12wwe' => 'Worldwide Express Small', 'parcel_12ups' => 'UPS Small', 'parcel_12fd' => 'FedEx Small'];
        return $carrierCodesWithName[$carrierCode] ?? null;


    }

    public static function getLiftResidentialStatus($rateId)
    {
        $response = ['resi' => 'n', 'liftG' => 'n', 'resiPickup' => 'n'];
        $response['resi'] = strpos($rateId, '+r') ? 'Y' : 'n';
        $response['liftG'] = strpos($rateId, '+lg') ? 'Y' : 'n';
        $response['resiPickup'] = strpos($rateId, '+pu') ? 'Y' : 'n';
        return $response;
    }

    public static function getBoxName($binId)
    {
        $nickname = BoxSize::getBoxNicknameAndFee($binId);
        return $nickname->nickname ?? null;
    }

    public static function isSmallQuote($quote)
    {
        $quote = explode('(', $quote)[0];
        $quote = trim($quote);
        $small = [
            'UPS Ground',
            'UPS 3 Day Select',
            'UPS 2nd Day Air',
            'UPS 2nd Day Air Saver',
            'UPS Next Day Air Saver',
            'UPS Next Day Air',
            'UPS Next Day Air Early',
            'Fedex Ground',
        ];
        return in_array($quote, $small);
    }
}
