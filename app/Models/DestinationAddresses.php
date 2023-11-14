<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DestinationAddresses extends Model
{
    use HasFactory;
    protected $table = 'destination_addresses';
    protected $fillable = [
        'store_id',
    ];

    public static function saveDestination($address, $storeId, $status, $poBox)
    {
        $destination = self::firstOrCreate(['store_id' => $storeId,'complete_Address' => $address]);
        $destination->complete_Address = $address;
        $destination->status = $status == 'r' ? 1 : ($status == 'c' ? 2 : 0);
        $destination->is_pobox = $poBox;
        $destination->save();
    }

    public static function isSameDestinatonAddress($address, $storeId)
    {
        return optional(self::where(['store_id' => $storeId,'complete_Address' => $address]))->first() ?? [];
    }
}
