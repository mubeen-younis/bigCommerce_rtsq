<?php

namespace App\Http\Controllers;

use App\Models\QuoteSetting;
use Illuminate\Http\Request;

class QuoteSettingsController extends Controller
{
    //
    public function getSettings(Request $request, $carrierId)
    {
        $carrierId = $carrierId ?? 1;
        $settings = QuoteSetting::where('installed_carrier_id', $carrierId)->first();
        return response()->json(['error' => false, 'data' => $settings, 'debug' => $request->all()], 200);
    }

    public function saveSettings(Request $request)
    {
        $quoteSettings = QuoteSetting::firstOrNew(['installed_carrier_id' => $request->carrierId]);
        $quoteSettings->installed_carrier_id = $request->carrierId;
        $quoteSettings->value = json_encode($request->all());
        $quoteSettings->save();
        return response()->json(['error' => false, 'message' => 'Quote settings have been saved successfully.', 'data' => $quoteSettings]);
    }

}
