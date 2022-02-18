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
}
