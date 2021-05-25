<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return response()->json(
            [
                'data' => Order::all(),
                'error' => false,
            ]
        );
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
     * @param  \App\Models\Order  $order
     * @return \Illuminate\Http\Response
     */
    public function show(Order $order)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Order  $order
     * @return \Illuminate\Http\Response
     */
    public function edit(Order $order, Request $request)
    {
        if (empty($request->order_id)) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Order Id',
            ], 404);
        }

        $order = Order::where('id', $request->order_id)
            ->first();

        if ($order === null) {
            return response()->json(
                ['error' => true,
                    'data' => [],
                    'message' => 'No Order Found Against This Id',
                ], 404);
        }

        return response()->json(
            ['error' => false,
                'data' => $order,
                'message' => 'Product Info',
            ], 200);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Order  $order
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Order $order)
    {
        if (!$request->order_id || empty($request->order_id)) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Product Id',
            ], 404);
        }

        $order = Order::find($request->order_id);

        if ($order === null) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Product Found Against This Id',
            ], 404);
        }

        $order->settings = json_encode($request->only(['date', 'price']));
        $order->update();

        $this->updateSingleProductFromApi($request);

        return response()->json(['error' => false,
            'data' => Order::find($request->order_id),
            'message' => 'Order Updated Successfully',
        ], 200);

    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Order  $order
     * @return \Illuminate\Http\Response
     */
    public function destroy(Order $order)
    {
        //
    }
}
