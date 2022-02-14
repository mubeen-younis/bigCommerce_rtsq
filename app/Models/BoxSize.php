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
}
