<?php

namespace App\Http\Controllers;
use App\Models\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;


class ConnectionController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
         return response()->json(Connection::get(),200);
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

      'billing_account_no' => 'required',

      'meter_number' => 'required',

      'password' => 'required',

      'auth_key' => 'required',

      'shipper_account_no' => 'required',

      'billing_address' => 'required',

      'city' => 'required',

      'state' => 'required',

      'zip_code' => 'required',

      'country' => 'required',

      'physical_address' => 'required',

      ];

      $validator = Validator::make($request->all(),$rules);

      if($validator->fails())

      {

       return response()->json($validator->errors(), 400);

      }

      $con = new Connection;

      $con->value = json_encode($request->all());

      $con->save();

      return response()->json(['message' => "Form Submitted Successfully!"]);

      }

    /**
     * Display the specified resource.
     *
     * @param  \App\Connection  $connection
     * @return \Illuminate\Http\Response
     */
    public function show(Connection $connection)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Connection  $connection
     * @return \Illuminate\Http\Response
     */
    public function edit(Connection $connection)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Connection  $connection
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Connection $connection)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Connection  $connection
     * @return \Illuminate\Http\Response
     */
    public function destroy(Connection $connection)
    {
        //
    }
}
