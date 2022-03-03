<?php

namespace App\Http\Controllers;

use App\Helpers\Helpers;
use App\Models\Locations;
use Illuminate\Http\Request;

class FDOLocationsController extends Controller
{
    public function getLocations(Request $request)
    {
        $storeId = $request->store_id ?? null;
        $getDropships = $request->dropships ?? false;
        $getWarehouses = $request->warehouses ?? false;
        if ($getWarehouses) {
            $locations = Locations::where('type', '=', '1')->where('store_id', $storeId)->get();
            return Helpers::sendJsonResponseFdo(false, '', $locations);
        }
        if ($getDropships) {
            $locations = Locations::where('type', '=', '2')->where('store_id', $storeId)->get();
            return Helpers::sendJsonResponseFdo(false, '', $locations);
        }
        $locations = Locations::where('store_id', $storeId)->get();
        return Helpers::sendJsonResponseFdo(false, '', $locations);
    }
}
