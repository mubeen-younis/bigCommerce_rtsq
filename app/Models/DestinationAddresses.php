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

    public static function saveDestination($address, $storeId, $status)
    {
        $destination = self::firstOrCreate(['store_id' => $storeId,'complete_Address' => $address]);
        $destination->complete_Address = $address;
        $destination->status = $status == 'Y' ? 1 : 0;
        $destination->save();
    }

    public static function isSameDestinatonAddress($address, $storeId)
    {
        return optional(self::where(['store_id' => $storeId,'complete_Address' => $address]))->first() ?? [];
    }
}
