<?php

namespace App\Http\Controllers;

use App\Models\DropShip;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DropShipController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return response()->json(DropShip::get(),200);

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

     $rules = [

        // 'value' => 'required',
        // 'type' => 'required',
        'nickname' => 'required',
        'city' => 'required',
        'state' => 'required',
        'zip_code' => 'required',
        'country' => 'required',
        // 'additional_params' => 'required',
      ];

      $validator = Validator::make($request->all(),$rules);

      if($validator->fails())

      {

       return response()->json($validator->errors(), 400);

      }

         $data = $request->all();

      //   $data['store_id'] = $request['store_id'];

        $dropship = DropShip::create($data);

        $dropship->save();

        return response()->json(['message' => "Form Submitted Successfully!"]);

        // return response()->json($connection, 201);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\DropShip  $dropShip
     * @return \Illuminate\Http\Response
     */
    public function show(DropShip $dropShip)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\DropShip  $dropShip
     * @return \Illuminate\Http\Response
     */
    public function edit(DropShip $dropShip)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\DropShip  $dropShip
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, DropShip $dropShip)
    {
        $dropShip->update($request->all());

        return response()->json($dropShip,200);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\DropShip  $dropShip
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {

     $dropship = DropShip::find($id);

     $dropship->delete();

    }
}
