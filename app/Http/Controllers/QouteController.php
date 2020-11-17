<?php

namespace App\Http\Controllers;

use App\Models\Qoute;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class QouteController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return response()->json(Qoute::get(),200);
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

    //     $rules = [

    //         'carrier_name' => 'required',

    //         'qoute' => 'required',

    //         'estimate' => 'required',

    //         'cut_off_time' => 'required',

    //         'no_of_days' => 'required|numeric',

    //         'qoute_res_address' => 'required',

    //         'auto_detected_res_address' => 'required',

    //         'qoute_lift_gate_delivery' => 'required',

    //         'offer_lift_gate_delivery' => 'required',

    //         'always_lift_gate_delivery' => 'required',

    //         'hold_at_terminal' => 'required',

    //         'price' => 'required|numeric',

    //         'weight' => 'required|numeric',

    //         'max_weight' => 'required|numeric',

    //         'handling_fee' => 'required|numeric',

    //         'discount' => 'required|numeric',

    //         'standard_plan_required' => 'required',

    //         'qoute_details' => 'required',

    //         ];

    //   $validator = Validator::make($request->all(),$rules);

    //   if($validator->fails())

    //   {

    //    return response()->json($validator->errors(), 400);

    //   }

        $q = new Qoute;

        $q->value = json_encode($request->all());

        $q->save();

        return response()->json(['message' => "Form Submitted Successfully!"]);

    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Qoute  $qoute
     * @return \Illuminate\Http\Response
     */
    public function show(Qoute $qoute)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Qoute  $qoute
     * @return \Illuminate\Http\Response
     */
    public function edit(Qoute $qoute)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Qoute  $qoute
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Qoute $qoute)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Qoute  $qoute
     * @return \Illuminate\Http\Response
     */
    public function destroy(Qoute $qoute)
    {
        //
    }
}
