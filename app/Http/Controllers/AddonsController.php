<?php

namespace App\Http\Controllers;

use App\Models\Addons;
use Illuminate\Http\Request;

class AddonsController extends Controller
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
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Addons  $addons
     * @return \Illuminate\Http\Response
     */
    public function show(Addons $addons)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Addons  $addons
     * @return \Illuminate\Http\Response
     */
    public function edit(Addons $addons)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Addons  $addons
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Addons $addons)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Addons  $addons
     * @return \Illuminate\Http\Response
     */
    public function destroy(Addons $addons)
    {
        //
    }

    public function getAddons(Request $request){
        $store = $request->store;
        $addons = Addons::select('addons.id', 'addons.name', 'installed_addons.is_enabled' )
            ->join('installed_addons', 'installed_addons.addon_id','=','addons.id')
            ->join('stores', 'stores.id', '=', 'installed_addons.store_id')
            ->where('stores.hash', $store )->get();
        return response()->json(
            ['error' => false,
                'data' => $addons
            ], 200);
    }
}
