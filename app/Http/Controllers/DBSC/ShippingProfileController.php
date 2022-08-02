<?php

namespace App\Http\Controllers\DBSC;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\DBSC\DbscShippingProfile;

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
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $nickname = DbscShippingProfile::where('p_nickname', '=', $request->nickname)->exists();
        if ($nickname) {
            return response()->json(['error' => true, 'message' => 'Shipping Profile already exits']);
        }
        $shipProfile = new DbscShippingProfile();
        $shipProfile->p_nickname = $request->nickname;
        $shipProfile->shpping_class = json_encode($request->shpping_class);
        $shipProfile->save();
        
        return response()->json(['error' => false, 'message' => 'Shipping Profile created Successfully ']);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show()
    {
        //$shipProfile = DbscShippingProfile::all();
        $shipProfile = DbscShippingProfile::join('dbsc_shipping_origin', 'dbsc_profiles.id', '=', 'dbsc_shipping_origin.profiles_id')
            ->join('dbsc_shipping_zone','dbsc_shipping_origin.id', '=', 'dbsc_shipping_zone.dbsc_origin_id')
            ->join('dbsc_shipping_rates','dbsc_shipping_zone.id', '=', 'dbsc_shipping_rates.dbsc_zone_id')
            ->get();
        if ($shipProfile === null) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Profile Found',
            ], 404);
        }
        return response()->json(['error' => false,
            'data' => $shipProfile,
            'message' => 'Profile Info',
        ], 200);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
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
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
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
        $shipProfile = DbscShippingProfile::where('id', $request->id)->exists();
        if ($shipProfile) {
            $shipProfile = DbscShippingProfile::find($request->id);
            $shipProfile->p_nickname = $request->nickname;
            $shipProfile->shpping_class = $request->shpping_class;
            $shipProfile->update();
            return response()->json(['error' => false,
                'data' => [],
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
     * @param  int  $id
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

        $shipProfile = DbscShippingProfile::find($request->id);
        if($shipProfile){
            $shipProfile->delete();
            return response()->json(['error' => false,
            'message' => "Profile deleted successfully",
            'data' => $request->id]);
        }
        return response()->json(['error' => true,
            'message' => "Profile not found"]);
    }
}
