<?php

namespace App\Http\Controllers;

use App\Models\Store;
use Illuminate\Http\Request;

class FDOController extends Controller
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

    public function getFdoCompanyInfo(Request $request)
    {
        $store = Store::where('id', $request['store_id'])->first();

        return response()->json(['error' => false,
            'data' => $store,
            'message' => '',
        ], 200);
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
    public function update(Request $request)
    {
        $store = Store::where('id', $request['store_id'])->first();
        $messgae = 'FreightDesk Online ';

        if ($store) {
            if ($request['freightdesk_company_id'] && isset($request['freightdesk_company_id'])) {
                $store->freightdesk_company_id = $request['freightdesk_company_id'];
                $messgae .= 'connected successfully';
            } else {
                $store->freightdesk_company_id = null;
                $messgae .= 'disconnected successfully';
            }
            $store->save();

            return response()->json(['error' => false,
                'data' => [],
                'message' => $messgae,
            ], 200);
        } else {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'Store not found',
            ], 404);
        }
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
