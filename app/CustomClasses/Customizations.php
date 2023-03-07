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
        /*Initially we have aadded customization for disabling eniture packageing for centennial store
        Now we have removed it*/
        $this->eniturePackagingDisabledStores = [];
    }

    /**
     * Disabling stores from eniture packaging
     * @param $request
     * @param $hash
     * @return array
     */
    public function eniturePackagingCustomization($request, $hash)
    {
        if (!empty($this->eniturePackagingDisabledStores) && in_array($hash, $this->eniturePackagingDisabledStores) && !empty($request['requestArr']['commdityDetails'])) {
            foreach ($request['requestArr']['commdityDetails'] as $key => $commodity) {
                $request['requestArr']['commdityDetails'][$key]['shipBinAlone'] = 1;
                $request['requestArr']['commdityDetails'][$key]['shipItemAlone'] = 1;
            }
        }
        return $request;
    }

}
