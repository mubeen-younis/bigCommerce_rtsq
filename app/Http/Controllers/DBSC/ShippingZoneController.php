<?php

namespace App\Http\Controllers\DBSC;

use App\CurlRequest;
use App\CustomClasses\BigCommerceFunctions;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\DBSC\DbscShippingZone;

class ShippingZoneController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }


    /**
     * Gets Zones of store from BigCommerce
     * @return void
     */
    public function getZonesOfStore(Request $request)
    {
        $storeHash = $request->store_hash;
        $storeDetails = BigCommerceFunctions::getZonesOfStore($storeHash);
        $storeDetails = (new CurlRequest())->enSingleCurlRequest($storeDetails['endpoint'],
            $storeDetails['request'], $storeDetails['headers'], $storeDetails['method'], false);
        $response = json_decode($storeDetails['response'], true);
        return response()->json(['error' => false,
            'data' => $response,
            'message' => 'Zone Info',
        ], 200);
        
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $selected_region = DbscShippingZone::where('selected_region', '=', $request->selected_region)->exists();
        if ($selected_region) {
            return response()->json(['error' => true, 'message' => 'Shipping Zone already exits']);
        }
        $shipZone = new DbscShippingZone();
        $shipZone->zone_name = $request->zone_name;
        $shipZone->define_by_zone = $request->define_by_zone;
        $shipZone->selected_region = json_encode($request->selected_region);
        $shipZone->postcode = $request->postcode;
        $shipZone->profile_id = $request->profile_id;
        $shipZone->save();

        return response()->json(['error' => false, 'message' => 'Shipping Zone created Successfully', 'data' => $shipZone]);
    }

    /**
     * Display the specified resource.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function show()
    {
        $shipZone = DbscShippingZone::all();
        if ($shipZone === null) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Zone Found',
            ], 404);
        }
        return response()->json(['error' => false,
            'data' => $shipZone,
            'message' => 'Zone Info',
        ], 200);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function edit(Request $request)
    {
        if (empty($request->id)) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Zone Id',
            ], 404);
        }
        $shipZone = DbscShippingZone::where('id', $request->id)
            ->first();
        if ($shipZone === null) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Zone Found Against This Id',
            ], 404);
        }
        return response()->json(['error' => false,
            'data' => $shipZone,
            'message' => 'Zone Info',
        ], 200);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request)
    {
        if (empty($request->id)) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'Zone Id Not Exists',
            ], 404);
        }
        $shipZone = DbscShippingZone::where('id', $request->id)->exists();
        if ($shipZone) {
            $shipZone = DbscShippingZone::find($request->id);
            $shipZone->zone_name = $request->zone_name;
            $shipZone->define_by_zone = $request->define_by_zone;
            $shipZone->selected_region = $request->selected_region;
            $shipZone->postcode = $request->postcode;
            $shipZone->update();
            return response()->json(['error' => false,
                'data' => $shipZone,
                'message' => 'Zone Updated Successfully',
            ], 200);
        } else {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Zone exists against this Id',
            ], 404);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request)
    {
        if (empty($request->id)) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Zone Id',
            ], 404);
        }

        $shipZone = DbscShippingZone::find($request->id);
        if ($shipZone) {
            $shipZone->delete();
            return response()->json(['error' => false,
                'message' => "Zone deleted successfully",
                'data' => $request->id]);
        }
        return response()->json(['error' => true,
            'message' => "Zone not found"]);
    }
}
