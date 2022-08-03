<?php

namespace App\Http\Controllers\DBSC;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\DBSC\DbscShippingOrigin;

class ShippingOriginController extends Controller
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
        $shipOrigin = new DbscShippingOrigin();
        $shipOrigin->ori_nickname = $request->nickname;
        $shipOrigin->street_address = $request->street_address;
        $shipOrigin->city = $request->city;
        $shipOrigin->state_or_province = $request->state_or_province;
        $shipOrigin->postal_code = $request->postal_code;
        $shipOrigin->country = $request->country;
        $shipOrigin->profile_id = $request->profile_id;
        $shipOrigin->availability_in_other_plugins = $request->availability_in_other_plugins;
        $shipOrigin->from_shipping_origin = $request->from_shipping_origin;
        $shipOrigin->save();
        
        return response()->json(['error' => false, 'message' => 'Shipping Origin created Successfully', 'data' => $shipOrigin]);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show()
    {
        $shipOrigin = DbscShippingOrigin::all();   
        if ($shipOrigin === null) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Origin Found',
            ], 404);
        }
        return response()->json(['error' => false,
            'data' => $shipOrigin,
            'message' => 'Origin Info',
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
                'message' => 'No Origin Id',
            ], 404);
        }
        $shipOrigin = DbscShippingOrigin::where('id', $request->id)
            ->first();
        if ($shipOrigin === null) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Origin Found Against This Id',
            ], 404);
        }
        return response()->json(['error' => false,
            'data' => $shipOrigin,
            'message' => 'Origin Info',
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
                'message' => 'No Origin Id',
            ], 404);
        }
        $shipOrigin = DbscShippingOrigin::where('id', $request->id)->exists();
        if ($shipOrigin) {
            $shipOrigin = DbscShippingOrigin::find($request->id);
            $shipOrigin->ori_nickname = $request->nickname;
            $shipOrigin->street_address = $request->street_address;
            $shipOrigin->city = $request->city;
            $shipOrigin->state_or_province = $request->state_or_province;
            $shipOrigin->postal_code = $request->postal_code;
            $shipOrigin->country = $request->country;
            $shipOrigin->availability_in_other_plugins = $request->availability_in_other_plugins;
            $shipOrigin->from_shipping_origin = $request->from_shipping_origin;
            $shipOrigin->update();
            return response()->json(['error' => false,
                'data' => $shipOrigin,
                'message' => 'Origin Updated Successfully',
            ], 200);
        } else {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Origin exists against this Id',
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
                'message' => 'No Origin Id',
            ], 404);
        }
        $shipOrigin = DbscShippingOrigin::find($request->id);
        
        if($shipOrigin){
            $shipOrigin->delete();
            return response()->json(['error' => false,
            'message' => "Origin deleted successfully",
            'data' => $request->id]);
        }
        return response()->json(['error' => true,
            'message' => "Origin not found"]);
    }
}
