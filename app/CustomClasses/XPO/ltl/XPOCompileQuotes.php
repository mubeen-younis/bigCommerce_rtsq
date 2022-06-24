<?php

namespace App\CustomClasses\XPO\ltl;

use App\Models\Locations;

class XPOCompileQuotes
{

    public static function originAssociatedAccNum($origins, $apiInfo)
    {
        $locationsDet = [];
        $connPostCode = $apiInfo['api']['physicalZipCode'];
        foreach ($origins as $key => $origin) {
            $senderZip = $origin['senderZip'];
            if ($senderZip == $connPostCode) {
                continue;
            }
            $locationId = $origin['locationId'];
            if (array_key_exists($locationId, $locationsDet)) {
                $locationInfo = $locationsDet[$locationId];
            } else {
                $locationInfo = $locationsDet[$locationId] = Locations::getlocationDetail($locationId);
            }
            $xpoAccountNumber = $locationInfo['xpo_account_number'] ?? null;
            if (blank($xpoAccountNumber)) {
                continue;
            }
            $origins[$key]['accountNumber'] = $xpoAccountNumber;
        }

        return $origins;
    }
}
