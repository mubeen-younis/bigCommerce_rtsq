<?php

namespace App\Http\Controllers;

use App\CustomClasses\BigCommerceFunctions;
use App\Models\Store;
use Illuminate\Http\Request;
use App\CurlRequest;
use Illuminate\Support\Facades\Log;

class StoreController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public $curlRequest;

    public function __construct()
    {
        $this->curlRequest = new CurlRequest();
    }

    public function index(Request $request)
    {

        try {
            $storeHash = $request['store_hash'];
            $store = Store::where('hash', $storeHash)->first();
            if (empty($store)) {
                return [];
            }
            $storeDetails = BigCommerceFunctions::getStoreSettings($storeHash);
            $storeDetails = (new CurlRequest())->enSingleCurlRequest($storeDetails['endpoint'],
                $storeDetails['request'], $storeDetails['headers'], $storeDetails['method'], false);
            $response = json_decode($storeDetails['response'], true);
            
            if (empty($store['store_domain']) || $store['store_domain'] == null) {
                $store['store_domain'] = $response['domain'];
                $store->save();
            }

            return response()->json(['error' => false,
                'data' => $response,
            ], 200);

        } catch (\Exception $exception) {
            Log::info('Exception on getting Store Details ' . $exception->getMessage());
            return [];
        }

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
        //
    }

    /**
     * Display the specified resource.
     *
     * @param \App\Store $store
     * @return \Illuminate\Http\Response
     */
    public function show(Store $store)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param \App\Store $store
     * @return \Illuminate\Http\Response
     */
    public function edit(Store $store)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Store $store
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Store $store)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param \App\Store $store
     * @return \Illuminate\Http\Response
     */
    public function destroy(Store $store)
    {
        //
    }
}
