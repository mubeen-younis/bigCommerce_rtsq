<?php

namespace App\Http\Controllers\DBSC;

use App\Http\Controllers\Controller;
use App\Models\DBSC\ShippingClass;
use Illuminate\Http\Request;

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
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }
}
