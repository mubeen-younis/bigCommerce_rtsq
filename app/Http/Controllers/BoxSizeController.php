<?php

namespace App\Http\Controllers;

use App\Models\BoxSize;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BoxSizeController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {

        return response()->json(BoxSize::get(),200);
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

            'nickname' => 'required',

            'length' => 'required',

            'width' => 'required',

            'height' => 'required',

            'max_weight' => 'required',

            'box_weight' => 'required',

            'is_available' => 'required',

            ];

            $validator = Validator::make($request->all(),$rules);

            if($validator->fails())

            {

             return response()->json($validator->errors(), 400);

            }

          $data = $request->all();

          $boxsize = BoxSize::create($data);

          $boxsize->save();

          return response()->json(['message' => "Form Submitted Successfully!"]);

    }

    /**
     * Display the specified resource.
     *
     * @param  \App\BoxSize  $boxSize
     * @return \Illuminate\Http\Response
     */
    public function show(BoxSize $boxSize)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\BoxSize  $boxSize
     * @return \Illuminate\Http\Response
     */
    public function edit(BoxSize $boxSize)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\BoxSize  $boxSize
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, BoxSize $boxSize)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\BoxSize  $boxSize
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $boxsize = BoxSize::find($id);

        $boxsize->delete();

        return response()->json(['message'=>"Record deleted Successfully"]);
    }
}
