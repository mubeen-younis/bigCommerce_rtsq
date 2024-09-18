<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Store;

class EnableLog extends Model
{
    //

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $table = "enable_logs";
    protected $fillable = ['store_id'];

    public static function enableAppLogs($request)
    {
        if(isset($request->store_hash)){
            $store = Store::where("hash", $request->store_hash)->select('id')->first();
            $AppLogSettings = self::firstOrNew(['store_id' => $store->id]);
            $AppLogSettings->log_status = $request->enable_app_logs ?? 0;
            $AppLogSettings->save();

            return response()->json(['error' => false, 'message' => $request->enable_app_logs == 1 ? 'Store logs enabled.' :  'Store logs disabled.' , 'data' => $AppLogSettings]);
        }

        return 'Something went wrong.';

    }

}
