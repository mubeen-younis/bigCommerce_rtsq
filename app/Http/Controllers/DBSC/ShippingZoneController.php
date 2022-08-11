<?php

namespace App\Http\Controllers\DBSC;

use App\CurlRequest;
use App\CustomClasses\BigCommerceFunctions;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\DBSC\DbscShippingZone;
use App\Models\DBSC\DbscShippingRates;
use App\Models\DBSC\BcZones;
use App\Models\DBSC\BcZonesDetail;
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
        
        $this->storeBcZones($response, $request);

        return response()->json(['error' => false,
            'data' => $response,
            'message' => 'Zone Info',
        ], 200);
        
    }

    /**
     * Store Zones in db from BigCommerce
     * @return void
     */
    public function storeBcZones($response, $request)
    {
        foreach($response as $key => $bczone){
            
            $bc_id_exist  = BcZones::where('bc_zone_id', '=', $bczone['id'])->exists();
            
            if($bc_id_exist){
                continue;
            }
            else{
                $BcZone = new BcZones();
                $BcZone->bc_zone_id = $bczone['id'];
                $BcZone->name = $bczone['name'];
                $BcZone->type = $bczone['type'];
                $BcZone->store_id = $request->store_id;
                $BcZone->save();
                $zone_id = BcZones::where('bc_zone_id', '=', $bczone['id'])->select('id')->first();
                $BcZoneDetail = $bczone['locations'];

                if(!empty($BcZoneDetail)){
                    foreach($BcZoneDetail as $loc => $zoneDetail){
                        $BcZoneDetails = new BcZonesDetail();
                        $BcZoneDetails->country = $zoneDetail['country_iso2'] ?? '';
                        $BcZoneDetails->state_or_province = $zoneDetail['state_iso2'] ?? '';
                        $BcZoneDetails->zone_id = $zone_id['id'];
                        $BcZoneDetails->save(); 
                    }
                }
            }    
        }

    }

    /**
     * Store a newly created resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $regions = $this->AddUpdatezone($request);
    
        if(!$regions){
            return response()->json(['error' => true, 'message' => 'Shipping Zone already exits', 'data' => []]);
        }
        $shipZone = new DbscShippingZone();
        $shipZone->zone_name = $request->zone_name;
        $shipZone->selected_region = json_encode($request->selected_region);
        $shipZone->profile_id = $request->profile_id;
        $shipZone->dbsc_origin_id = $request->dbsc_origin_id;
        $shipZone->store_id = $request->store_id;
        $shipZone->save();

        return response()->json(['error' => false, 'message' => 'Shipping Zone created Successfully', 'data' => $shipZone]);
    }

    public function AddUpdatezone($request)
    {
        $storeHash = $request->store_hash;
        $regions = [];
        $ids = $request->selected_region;
        $shipZones = DbscShippingZone::where("profile_id", '=', $request->profile_id)->whereKeyNot($request->id)->select('selected_region')->get();
        $shipZones = json_decode($shipZones);

        if(!empty($shipZones)){
            foreach($shipZones as $key){
                $sav_regions = json_decode($key->selected_region);
                foreach($sav_regions as $region){
                    foreach($ids as $req_region){
                        if($region === $req_region){
                            return false;
                        }
                    }
                }
            }
        }

        foreach($ids as $req_region){

            $storeDetails = BigCommerceFunctions::getZone($storeHash, $req_region);
            $storeDetails = (new CurlRequest())->enSingleCurlRequest($storeDetails['endpoint'],
            $storeDetails['request'], $storeDetails['headers'], $storeDetails['method'], false);
            $selected = json_decode($storeDetails['response'], true);
            $selected_regions["id"] = $selected['id'] ?? null;
            $selected_regions["name"] = $selected['name'] ?? '';
            $selected_regions["type"] = $selected['type'] ?? '';
            $selected_regions["locations"] = $selected['locations'] ?? '';
            $regions[] = $selected_regions;   
        }
        
        return $regions;
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
        if (empty($request->id) || empty($request->profile_id)) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'Zone / Profile Id Not Exists',
            ], 404);
        }

        $regions = $this->AddUpdatezone($request);
    
        if(!$regions){
            return response()->json(['error' => true, 'message' => 'Shipping Zone already exits', 'data' => []]);
        }

        $shipZone = DbscShippingZone::where('id', $request->id)->exists();
        if ($shipZone) {
            $shipZone = DbscShippingZone::find($request->id);
            $shipZone->zone_name = $request->zone_name;
            $shipZone->selected_region = json_encode($request->selected_region);
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
            $data['id'] = $shipZone->id;
            $data['origin_id'] = $shipZone->dbsc_origin_id;
            $shipRate = DbscShippingRates::where('dbsc_shipping_zone_id', '=' ,$request->id)->exists();
            if($shipRate){
                $shipRate = DbscShippingRates::where('dbsc_shipping_zone_id', '=', $request->id)->delete();
            }
            $shipZone->delete();
            return response()->json(['error' => false,
                'message' => "Zone deleted successfully",
                'data' => $data]);
        }
        return response()->json(['error' => true,
            'message' => "Zone not found"]);
    }
}
