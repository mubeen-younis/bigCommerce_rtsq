<?php

namespace App\Http\Controllers;

use DB;
use App\Models\Locations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LocationsController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()

    {

        return response()->json(Locations::get(), 200);

    }

    public function warehouse()

    {
        return response()->json(Locations::where('type', '=', '1')->get(), 200);
    }


    public function dropships()

    {


        return response()->json(Locations::where('type', '=', '2')->get(), 200);

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
        $rules = [
            'city' => 'required',
            'state' => 'required',
            'zip_code' => 'required',
            'country' => 'required',
        ];
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }
        $data = $request->all();
        //   $data['store_id'] = $request['store_id'];
        $location = Locations::create($data);
        $location->save();
        return response()->json(['message' => "Form Submitted Successfully!"]);
        // return response()->json($connection, 201);
    }

    /**
     * Display the specified resource.
     *
     * @param \App\Locations $locations
     * @return \Illuminate\Http\Response
     */
    public function show(Locations $locations)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param \App\Locations $locations
     * @return \Illuminate\Http\Response
     */
    public function edit(Locations $locations)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Locations $locations
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Locations $locations)
    {
        $locations->update($request->all());
        return response()->json($locations, 200);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param \App\Locations $locations
     * @return \Illuminate\Http\Response
     */
    public function delete_warehouse($id)
    {
        DB::table('locations')
            ->where('id', $id)
            ->where('type', '=', 1)
            ->delete();

        return response()->json(['message' => "Record deleted Successfully"]);
    }

    public function delete_dropships($id)
    {
        DB::table('locations')
            ->where('id', $id)
            ->where('type', '=', 2)
            ->delete();
        return response()->json(['message' => "Record deleted Successfully"]);
    }
}
