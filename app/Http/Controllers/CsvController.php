<?php

namespace App\Http\Controllers;

use App\Models\Csv;
use Illuminate\Http\Request;

class CsvController extends Controller
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


        // dd($request->input('file'));

        // $file_name = "example_file.csv";

        // $path = $request->input('file')->move(public_path("/"),$file_name);

        // $file_url = url('/',$file_name);

        // return response()->json(['url'=>$file_url,'message'=>'file uploaded successfully'],200);

        $file = str_replace('data:application/vnd.ms;base64,', '', $request->input('file'));

        echo file_put_contents(storage_path()."/test.csv",base64_decode($file));

        // return response()->json(['message'=>'file uploaded successfully'],200);

    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Csv  $csv
     * @return \Illuminate\Http\Response
     */
    public function show(Csv $csv)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Csv  $csv
     * @return \Illuminate\Http\Response
     */
    public function edit(Csv $csv)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Csv  $csv
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Csv $csv)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Csv  $csv
     * @return \Illuminate\Http\Response
     */
    public function destroy(Csv $csv)
    {
        //
    }
}
