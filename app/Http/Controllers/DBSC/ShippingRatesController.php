<?php

namespace App\Http\Controllers\DBSC;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\DBSC\DbscShippingRates;
class ShippingRatesController extends Controller
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
        $shipRates = new DbscShippingRates();
        $shipRates->display_as = $request->display_as;
        $shipRates->distance_display_preferences = $request->distance_display_preferences;
        $shipRates->description = $request->description;
        $shipRates->rate = $request->rate;
        $shipRates->distance_unit = $request->distance_unit;
        $shipRates->distance_measured_by = $request->distance_measured_by;
        $shipRates->minimum_distance = $request->minimum_distance;
        $shipRates->maximum_distance = $request->maximum_distance;
        $shipRates->minimum_weight = $request->minimum_weight;
        $shipRates->maximum_weight = $request->maximum_weight;
        $shipRates->and_or = $request->and_or;
        $shipRates->minimum_length = $request->minimum_length;
        $shipRates->maximum_length = $request->maximum_length;
        $shipRates->distance_adjustment = $request->distance_adjustment;
        $shipRates->rate_adjustment = $request->rate_adjustment;
        $shipRates->minimum_shipping_quote = $request->minimum_shipping_quote;
        $shipRates->maximum_shipping_quote = $request->maximum_shipping_quote;
        $shipRates->rating_method = $request->rating_method;
        $shipRates->dbsc_shipping_zone_id = $request->dbsc_zone_id;
        $shipRates->save();
        
        return response()->json(['error' => false, 'message' => 'Shipping Rates Added Successfully', 'data' => $shipRates]);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show()
    {
        $shipRates = DbscShippingRates::all();   
        if ($shipRates === null) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Rates Found',
            ], 404);
        }
        return response()->json(['error' => false,
            'data' => $shipRates,
            'message' => 'Rates Info',
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
                'message' => 'No Rates Id',
            ], 404);
        }
        $shipRates = DbscShippingRates::where('id', $request->id)
            ->first();
        if ($shipRates === null) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Rates Found Against This Id',
            ], 404);
        }
        return response()->json(['error' => false,
            'data' => $shipRates,
            'message' => 'Rates Info',
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
                'message' => 'Rates Id Not Exists',
            ], 404);
        }
        $shipRates = DbscShippingRates::where('id', $request->id)->exists();
        if ($shipRates) {
            $shipRates = DbscShippingRates::find($request->id);
            $shipRates->display_as = $request->display_as;
            $shipRates->distance_display_preferences = $request->distance_display_preferences;
            $shipRates->description = $request->description;
            $shipRates->rate = $request->rate;
            $shipRates->distance_unit = $request->distance_unit;
            $shipRates->distance_measured_by = $request->distance_measured_by;
            $shipRates->minimum_distance = $request->minimum_distance;
            $shipRates->maximum_distance = $request->maximum_distance;
            $shipRates->minimum_weight = $request->minimum_weight;
            $shipRates->maximum_weight = $request->maximum_weight;
            $shipRates->and_or = $request->and_or;
            $shipRates->minimum_length = $request->minimum_length;
            $shipRates->maximum_length = $request->maximum_length;
            $shipRates->distance_adjustment = $request->distance_adjustment;
            $shipRates->rate_adjustment = $request->rate_adjustment;
            $shipRates->minimum_shipping_quote = $request->minimum_shipping_quote;
            $shipRates->maximum_shipping_quote = $request->maximum_shipping_quote;
            $shipRates->rating_method = $request->rating_method;
            $shipRates->update();
            return response()->json(['error' => false,
                'data' => $shipRates,
                'message' => 'Rates Updated Successfully',
            ], 200);
        } else {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Rates exists against this Id',
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
                'message' => 'No Rates Id',
            ], 404);
        }

        $shipRate = DbscShippingRates::find($request->id);
        if($shipRate){
            $data['id'] = $shipRate->id;
            $data['zone_id'] = $shipRate->dbsc_shipping_zone_id;
            $shipRate->delete();
            
            return response()->json(['error' => false,
            'message' => "Rates deleted successfully",
            'data' => $data]);
        }
        return response()->json(['error' => true,
            'message' => "Rates not found"]);
    }
}
