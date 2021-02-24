<?php

namespace App\Http\Controllers;

use App\Models\Addons;
use App\Models\InstalledAddon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    public function installAddon(Request $request)
    {
        $store_id = $request->store_id;

        if (empty($request->addon_id)) {
            return response()->json([
                'error' => true,
                'message' => 'Empty Addon ID',
            ], 200);
        }

        $installAddon = new InstalledAddon();
        $installAddon->store_id = $store_id;
        $installAddon->carrier_id = $request->addon_id;
        $installAddon->is_enabled = false;
        $installAddon->installed_at = now();
        $installAddon->plan_updated_at = now();
        $installAddon->save();

        return response()->json(['error' => false,
            'data' => $installAddon->id,
            'message' => 'Addon Installed Successfully',
        ], 200);

        return response()->json(['error' => true,
            'data' => [],
            'message' => "Addon couldn't be installed",
        ], 500);
    }

    public function getAddons(Request $request)
    {
        $store_id = $request->store_id;

        $addons = Addons::select('installed_addons.id', 'addons.name', 'installed_addons.is_enabled')
            ->join('installed_addons', 'installed_addons.addon_id', '=', 'addons.id')
            ->join('stores', 'stores.id', '=', 'installed_addons.store_id')
            ->where('stores.id', $store_id)->get();

        if ($addons->isEmpty()) {
            return response()->json(['error' => false,
                'data' => [],
                'message' => 'No Installed Addons Found',
            ], 200);
        }

        return response()->json(
            ['error' => false,
                'data' => $addons,
            ], 200);
    }

    public function getRecommendedAddons(Request $request)
    {
        $store_id = $request->store_id;

        $installedAddons = DB::table('installed_addons')->join('stores', 'stores.id', '=', 'installed_addons.store_id')->where('stores.id', $store_id)->pluck('addon_id');

        if ($installedAddons->isEmpty()) {
            $addons = Addons::get();

            return response()->json(['error' => false,
                'data' => $addons,
                'message' => 'Addons Found',
            ], 200);
        }

        $recommendedAddons = Addons::whereNotIn('id', $installedAddons)->get();

        return response()->json(
            ['error' => false,
                'addons' => $recommendedAddons,
            ], 200);

    }

    public function changeAddonStatus(Request $request)
    {
        if (empty($request->addon_id)) {
            return response()->json([
                'error' => true,
                'message' => 'Empty Addon Id',
            ]);
        }

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
