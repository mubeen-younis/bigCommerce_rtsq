<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Orders;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    public function __construct()
    {

    }

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

    public function orderFromWebhook(Request $request)
    {
        try {
            $postData = file_get_contents("php://input");
            Log::info('Orderdata: ' . $postData);
            $postData = json_decode($postData, true);
            $storeHash = explode('/', $postData['producer']);
            $storeHash = $storeHash[1];
            $orderId = $postData['data']['id'];
            // Update,delete,create from  webhook
            $scope = $postData['scope'];
            $store = Store::where('hash', $storeHash)->first();
            //allow only create/update orders actions
            $onlyScopes = ['store/order/created', 'store/order/updated'];
            if (empty($store) || !in_array($scope, $onlyScopes)) {
                return null;
            }
            $toRequest['store_id'] = $store->id;
            $toRequest['store_name'] = $storeHash;
            $toRequest['order_id'] = $orderId;

            $this->getOrderByID($toRequest);
        } catch (\Exception $exception) {
            //  Have to LOg Here
        }
    }

    public function getOrderByID($toRequest)
    {
        Log::info('toRequest: ' . json_encode($toRequest));

        $order = Orders::where('order_id', $toRequest['order_id'])
            ->where('store_id', $toRequest['store_id'])
            ->first();
        if (empty($order)) {
            $order = new Orders();
        }
        $order->store_id = $toRequest['store_id'];
        $order->order_id = $toRequest['order_id'];
        $order->settings = json_encode(['setting' => 'settings here']);
        $order->save();
    }
}
