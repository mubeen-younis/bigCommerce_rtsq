<?php

namespace App\CustomClasses\OdflLTL;

use App\Models\Locations;

class ODFLCompileQuotes
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
            $odflAccountNumber = $locationInfo['odfl_account_number'] ?? null;
            if (blank($odflAccountNumber)) {
                continue;
            }
            $origins[$key]['accountNumber'] = $odflAccountNumber;
        }

        return $origins;
    }
}
