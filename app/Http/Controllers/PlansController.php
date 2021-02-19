<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Plans;

class PlansController extends Controller
{
    public function getPlansInfo(Request $request){
        $plans = Plans::all();
        return ['error' => false,
            'data' => $plans,
            'status' => 200
        ];
    }
}
