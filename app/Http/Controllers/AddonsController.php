<?php

namespace App\Http\Controllers;

use App\Models\Addons;
use App\Models\InstalledAddon;
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
        $response = [
            'error' => false,
            'addons' => Addons::get(),
        ];

        return response()->json($response, 200);

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

    public function getAddons(Request $request)
    {
        if (!empty($request->header('authorization'))) {
            $token = explode(' ', $request->header('authorization'))[1];

            $addons = Addons::select('installed_addons.id', 'addons.name', 'installed_addons.is_enabled')
                ->join('installed_addons', 'installed_addons.addon_id', '=', 'addons.id')
                ->join('stores', 'stores.id', '=', 'installed_addons.store_id')
                ->where('stores.token', $token)->get();

            return response()->json(
                ['error' => false,
                    'data' => $addons,
                ], 200);
        }
    }

    public function getRecommendedAddons(Request $request)
    {
        if (!empty($request->header('authorization'))) {
            $token = explode(' ', $request->header('authorization'))[1];

            $addons = Addons::select('addons.id', 'addons.name', 'installed_addons.is_enabled')
                ->join('installed_addons', 'installed_addons.addon_id', '!=', 'addons.id')
                ->join('stores', 'stores.id', '=', 'installed_addons.store_id')
                ->where('stores.token', $token)->get();

            return response()->json(
                ['error' => false,
                    'addons' => $addons,
                ], 200);

        }
    }

    public function changeAddonStatus(Request $request)
    {
        if (empty($request->addon_id)) {
            return response()->json([
                'error' => true,
                'message' => 'Empty Addon Id',
            ]);
        }

        if (!empty($request->header('authorization'))) {
            $addon = InstalledAddon::find($request->addon_id);

            if ($addon) {
                InstalledAddon::where('id', $request->addon_id)->update(['is_enabled' => !$addon->is_enabled]);

                return response()->json(['data' => InstalledAddon::find($request->addon_id), 'message' => 'Addon Status updated', 'error' => false], 200);
            } else {
                return response()->json([
                    'message' => 'Invalid Addon ID',
                    'error' => true,
                ], 404);
            }
        }
    }
}
