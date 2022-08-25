<?php

namespace App\Http\Controllers\DBSC;

use App\Http\Controllers\Controller;
use App\Models\DBSC\DbscOrigin;
use Illuminate\Http\Request;
use App\Models\DBSC\DbscShippingProfile;
use App\Models\DBSC\DbscShippingOrigin;
use App\Models\DBSC\DbscShippingRates;
use App\Models\DBSC\DbscShippingZone;

class ShippingProfileController extends Controller
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
     * Store a newly created resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $nickname = DbscShippingProfile::where('p_nickname', '=', $request->nickname)->exists();
        $req_classes = $request->shipping_classes;
        $classExists = $this->isClassExists($request, $req_classes);

        if(!$classExists){
            return response()->json(['error' => true, 'message' => 'Shipping Class already Used', 'data' => []]);
        }
       
        if ($nickname) {
            return response()->json(['error' => true, 'message' => 'Shipping Profile already exits']);
        }
        $shipProfile = new DbscShippingProfile();
        $shipProfile->p_nickname = $request->nickname;
        $shipProfile->shipping_classes = json_encode($request->shipping_classes);
        $shipProfile->store_id = $request->store_id;
        $shipProfile->save();

        return response()->json(['error' => false, 'message' => 'Shipping Profile created Successfully', 'data' => $shipProfile]);
    }

    public function isClassExists($request, $req_classes)
    {
        $profiles = DbscShippingProfile::whereKeyNot($request->id)->select('shipping_classes')->get();
        foreach($profiles as $profile){
            $profile_classes = json_decode($profile->shipping_classes);
            foreach($profile_classes as $profile_class){
                foreach($req_classes as $request_class){
                    if($profile_class === $request_class){
                        return false;   
                    }   
                }   
            }
        }
        return true;
    }

    /**
     * Display the specified resource.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function show(Request $request)
    {
        $formattedData = [];
        $storeId = $request['store_id'] ?? null;
        $storeProfiles = optional(DbscShippingProfile::where('store_id', $storeId)->get())->toArray() ?? [];
        $formattedData['store_profiles'] = $storeProfiles;


        foreach ($storeProfiles as $shipProfile) {

            $profileOrigins = optional(DbscOrigin::where('profile_id', $shipProfile['id'])->get())->toArray() ?? [];
            if (blank($profileOrigins)) {
                $formattedData['origins'][$shipProfile['id']] = [];
                continue;
            }
            $formattedData['origins'][$shipProfile['id']] = $profileOrigins;


            foreach ($profileOrigins as $profileOrigin) {

                $shippingOrigins = optional(DbscShippingOrigin::where('origin_id', $profileOrigin['id'])->get())->toArray() ?? [];
                $formattedData['origin'][$profileOrigin['id']] = $shippingOrigins;

                $shippingZones = optional(DbscShippingZone::where('dbsc_origin_id', $profileOrigin['id'])->get())->toArray() ?? [];
                $formattedData['zones'][$profileOrigin['id']] = $shippingZones;

                foreach ($shippingZones as $shippingZone) {
                    $shippingRates = optional(DbscShippingRates::where('dbsc_shipping_zone_id', $shippingZone['id'])->get())->toArray() ?? [];
                    $formattedData['rates'][$shippingZone['id']] = $shippingRates;

                }


            }

        }
        if ($formattedData === null) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Profile Found',
            ], 404);
        }
        return response()->json(['error' => false,
            'data' => $formattedData,
            'message' => 'Profiles Info',
        ], 200);
    }


    public function getProfileDetail(Request $request)
    {
        $profileId = $request['profile_id'] ?? null;
        foreach ($storeProfiles as $shipProfile) {
            $profileOrigins = optional(DbscOrigin::where('profile_id', $shipProfile['id'])->get())->toArray() ?? [];
            if (blank($profileOrigins)) {
                $formattedData[$shipProfile['id']] = null;
                continue;
            }

            dd(23, $profileOrigins);

        }

        if ($formattedData === null) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Profile Found',
            ], 404);
        }
        return response()->json(['error' => false,
            'data' => $formattedData,
            'message' => 'Profile Info',
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
                'message' => 'No Profile Id',
            ], 404);
        }
        $shipProfile = DbscShippingProfile::where('id', $request->id)
            ->first();
        if ($shipProfile === null) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Profile Found Against This Id',
            ], 404);
        }
        return response()->json(['error' => false,
            'data' => $shipProfile,
            'message' => 'Profile Info',
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
                'message' => 'Profile Id Not Exists',
            ], 404);
        }
     
        $req_classes = $request->shipping_classes;
        $classExists = $this->isClassExists($request, $req_classes);
        
        if(!$classExists){
            return response()->json(['error' => true, 'message' => 'Shipping Class already Used', 'data' => []]);
        }
        $shipProfile = DbscShippingProfile::find($request->id);
        if (!empty($shipProfile)) {
            $shipProfile->p_nickname = $request->nickname;
            $shipProfile->shipping_classes = json_encode($request->shipping_classes);
            if($shipProfile['is_general_profile'] == 1){
                $shipProfile->allow_all_classes = $request->allow_all_classes;
            }
            $shipProfile->update();
            return response()->json(['error' => false,
                'data' => $shipProfile,
                'message' => 'Profile Updated Successfully',
            ], 200);
        } else {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Profile exists against this Id',
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
                'message' => 'No Profile Id',
            ], 404);
        }
        $shipOrigin = DbscShippingOrigin::where('profile_id', '=', $request->id)->exists();
        $dbscOrigin = DbscOrigin::where('profile_id', '=', $request->id)->exists();
        $shipZone = DbscShippingZone::where('profile_id', '=', $request->id)->exists();

        if ($shipOrigin) {
            $shipOrigin = DbscShippingOrigin::where('profile_id', '=', $request->id)->delete();
        }

        if ($shipZone) {
            $ids = DbscShippingZone::where('profile_id', '=', $request->id)->select('id')->get();
            foreach ($ids as $id) {
                $shipRate = DbscShippingRates::where('dbsc_shipping_zone_id', '=', $id['id'])->exists();
                if ($shipRate) {
                    $shipRate = DbscShippingRates::where('dbsc_shipping_zone_id', '=', $id["id"])->delete();
                }
            }
            $shipZone = DbscShippingZone::where('profile_id', '=', $request->id)->delete();
        }

        if ($dbscOrigin) {
            $dbscOrigin = DbscOrigin::where('profile_id', '=', $request->id)->delete();
        }

        $shipProfile = DbscShippingProfile::find($request->id);
        if ($shipProfile) {
            $shipProfile->delete();
            return response()->json(['error' => false,
                'message' => "Profile deleted successfully",
                'data' => $request->id]);
        }
        return response()->json(['error' => true,
            'message' => "Profile not found"]);
    }
}
