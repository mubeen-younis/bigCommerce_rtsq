<?php

namespace App\Http\Controllers\DBSC;

use App\CustomClasses\Shipping;
use App\Http\Controllers\Controller;
use App\Models\DBSC\ShippingClass;
use Illuminate\Http\Request;
use App\Models\ProductSetting;

class ShippingClassController extends Controller
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
        $slug = ShippingClass::where('slug', '=', $request->slug)->exists();
        if ($slug) {
            return response()->json(['error' => true, 'message' => 'Shipping class already exits']);
        }
        $shipClass = new ShippingClass();
        $shipClass->class_name = $request->class_name;
        $shipClass->slug = $request->slug;
        $shipClass->description = $request->description;
        $shipClass->save();
        
        return response()->json(['error' => false, 'message' => 'Shipping class added Successfully', 'data' => $shipClass]);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show()
    {
        $shippingClass = ShippingClass::all();

        return response()->json(['error' => false, 'data' => $shippingClass]);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
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
        if (!isset($request->id) || empty($request->id)) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'Class Id not found',
            ], 404);
        }

        $shipClass = ShippingClass::find($request->id);
        if (!$shipClass) {
            return response()->json(['error' => true, 'message' => 'Shipping class not found', 'data' => '']);
        }

        $shipClass->class_name = $request->class_name;
        $shipClass->slug = $request->slug;
        $shipClass->description = $request->description;
        $shipClass->save();

        return response()->json(['error' => false, 'message' => 'Shipping class updated successfully', 'data' => $shipClass]);
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
                'message' => 'No class Id',
            ], 404);
        }

        $shipClass = ShippingClass::find($request->id);
        $products = ProductSetting::where('shipping_class', '=', $shipClass->id)->get();
        foreach($products as $key => $product){
            $product['shipping_class'] = null;
            $product['shipping_class_enabled'] = null;
            $product->update();
        }

        if (!$shipClass) {
            return response()->json(['error' => true,
            'message' => "Class not found"]);
        }

        $shipClass->delete();
        return response()->json(
            ['error' => false,
            'message' => "Shipping Class deleted successfully",
            'data' => $request->id]
        );
    }
}
