<?php

namespace App\Http\Controllers\DBSC;

use App\Http\Controllers\Controller;
use App\Models\DBSC\DbscOtherSettings;
use Illuminate\Http\Request;

class OtherSettingsController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $data = DbscOtherSettings::first();

        return response()->json([
            'error' => false,
            'messgae' => '',
            'data' => $data,
        ]);
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
        if (!isset($request->id) || empty($request->id)) {
            $otherSettings = new DbscOtherSettings();
        } else {
            $otherSettings = DbscOtherSettings::find($request->id);
        }

        $otherSettings->multi_label = $request->multi_label;
        $otherSettings->multishipment_preference = $request->multishipment_preference;
        $otherSettings->save();

        return response()->json([
            'error' => false,
            'messgae' => 'Settings saved successfully.',
            'data' => $otherSettings,
        ]);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
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
