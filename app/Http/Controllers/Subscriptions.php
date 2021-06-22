<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class Subscriptions extends Controller
{
    public function createSubscription(Request $request){
        return response()->json(['error' => false,
            'data' => [],
            'message' => 'Subscription',
        ], 200);
    }
}
