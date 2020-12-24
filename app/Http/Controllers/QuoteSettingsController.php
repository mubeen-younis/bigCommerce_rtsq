<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class QuoteSettingsController extends Controller
{
    //
    public function getSettings(Request $request)
    {
        return response()->json([], 200);
    }
}
