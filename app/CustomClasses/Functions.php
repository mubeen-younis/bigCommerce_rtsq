<?php

namespace App\CustomClasses;

use App\Models\BoxSize;
use Illuminate\Support\Facades\Log;

class Functions
{
    protected static $daysAfterExpiry = 4;

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

    public static function getCarrierNameOrCode($code, $getWsCode = 0): ?string
    {
        $carrierCodes = ['wweltl', 'rnlltl', 'xpoltl', 'fedexltl', 'gtzltl', 'cltl', 'upsltl', 'parcel_12wwe', 'parcel_12ups', 'parcel_12fd'];
        foreach ($carrierCodes as $carrierCode) {
            if (strpos($code, $carrierCode) !== false) {
                if ($getWsCode == 0) {
                    return self::getCarrierNameFromCode($carrierCode);
                } else {
                    return self::getCarrierCodeWs($carrierCode);
                }
            }
        }
        return null;
    }

    public static function getCarrierCodeWs($carrierCode): ?string
    {
        $carrierCodesWithName = ['wweltl' => 'wweLTL', 'rnlltl' => 'rnl', 'xpoltl' => 'xpoLogistics', 'upsltl' => 'upsLTL',
            'fedexltl' => 'fedexLTL', 'gtzltl' => 'globalTranz', 'cltl' => 'cerasis',
            'parcel_12wwe' => 'wweSmall', 'parcel_12ups' => 'upsSmall', 'parcel_12fd' => 'fedexSmall',];
        return $carrierCodesWithName[$carrierCode] ?? null;
    }

    public static function getCarrierNameFromCode($carrierCode): ?string
    {
        $carrierCodesWithName = ['wweltl' => 'Worldwide Express LTL', 'upsltl' => 'UPS LTL', 'rnlltl' => 'R&L Carriers', 'xpoltl' => 'XPO Logistics',
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

    public static function isSmallCarrier($code)
    {
        $carriers = ['parcel_12wwe', 'parcel_12ups', 'parcel_12fd'];
        foreach ($carriers as $carrier) {
            if (strpos($code, $carrier) !== false) {
                return true;
            }
        }
        return false;
    }


    public static function isExpiredSubscription($endDate): bool
    {
        try {
            if (blank($endDate)) {
                return false;
            }

            $endDate= date('m/d/Y', strtotime($endDate));
            $endDate = new \DateTime($endDate);
            $now = new \DateTime(now());
            // CHecks either the diff is positive or negative
            $invert = $endDate->diff($now)->invert ?? 0;
            $days = $endDate->diff($now)->days ?? 0;
            if ($invert == false && $days > self::$daysAfterExpiry) {
                return true;
            }
            return false;
        } catch (\Exception $exception) {
            Log::info('Exception on checking expiry ' . json_encode($exception->getMessage()));
            return false;
        }

    }


    public static function getDaysBwDates($startDate, $endDate)
    {
        try {
            if (blank($endDate) || blank($startDate)) {
                return 0;
            }
            $startDate = new \DateTime($startDate);
            $endDate = new \DateTime($endDate);
            $days = $endDate->diff($startDate)->days ?? 0;
            return $days;
        } catch (\Exception $exception) {
            Log::info('Exception on checking expiry ' . json_encode($exception->getMessage()));
            return 0;
        }

    }
}
