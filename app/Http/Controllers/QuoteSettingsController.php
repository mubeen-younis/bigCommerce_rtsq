<?php

namespace App\Http\Controllers;

use App\Models\QuoteSetting;
use Illuminate\Http\Request;

class QuoteSettingsController extends Controller
{
    //
    public function getSettings(Request $request)
    {
        $settings = QuoteSetting::where('installed_carrier_id', 1)->first();
        return response()->json(['error' => false, 'data' => $settings], 200);
    }

    public function saveSettings(Request $request)
    {
        $quoteSettings = QuoteSetting::firstOrNew(['installed_carrier_id' => 1]);
        $quoteSettings->installed_carrier_id = 1;
        $quoteSettings->value = json_encode($request->all());
        $quoteSettings->save();
        return response()->json(['error' => false, 'message' => 'Quote settings has been successfully saved.', 'data' => $quoteSettings]);
    }

}
