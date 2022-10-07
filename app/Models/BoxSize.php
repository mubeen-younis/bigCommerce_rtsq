<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class BoxSize extends Model
{
    protected $guarded = [];


    public static function getBoxNicknameAndFee($boxId)
    {
        return optional(DB::table('box_sizes')->where('id', $boxId)
            ->select('nickname','box_fee')->first());
    }

    public static function getBoxDetail($boxId)
    {
        return optional(DB::table('box_sizes')->where('id', $boxId)->first());
    }

    public static function getUspsSmallAvailableBoxes($storeId)
    {
        return self::where(['store_id' => $storeId, 'is_available' => 1,])->where('box_type', 1)->orWhere('box_type', 3)->get();
    }

    public static function getUpsSmallAvailableBoxes($storeId)
    {
        return self::where(['store_id' => $storeId, 'is_available' => 1,])->where('box_type', 1)->orWhere('box_type', 5)->get();
    }
}
