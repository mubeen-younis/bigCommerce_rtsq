<?php

namespace App\CustomClasses;

class Customizations
{
    /*
      * Store for eniture packaging siabled
      * We mark there products as ship as own
      * */
    protected $eniturePackagingDisabledStores;

    public function __construct()
    {
        $this->eniturePackagingDisabledStores = ['uann2u', '2apcgz5zer'];
    }

    /**
     * Disabling stores from eniture packaging
     * @param $request
     * @param $hash
     * @return array
     */
    public function eniturePackagingCustomization($request, $hash)
    {
        if (in_array($hash, $this->eniturePackagingDisabledStores) && !empty($request['requestArr']['commdityDetails'])) {
            foreach ($request['requestArr']['commdityDetails'] as $key => $commodity) {
                $request['requestArr']['commdityDetails'][$key]['shipBinAlone'] = 1;
                $request['requestArr']['commdityDetails'][$key]['shipItemAlone'] = 1;
            }
        }
        return $request;
    }

}
