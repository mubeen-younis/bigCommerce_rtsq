<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CacheAddressLookup extends Model
{
    use HasFactory;
    protected $table = 'cache_address_lookup';

    protected $fillable = [
        'zip_code',
        'google_lookup',
    ];

    public static function insertAddressData($zipCode, $response)
    {
        $addressData = self::firstOrNew(['zip_code' => $zipCode]);

        $addressData->google_lookup = json_encode($response);        
        $addressData->save();
    }

    public static function getAddressLookupDatabase($zipCode)
    {
        return optional(self::where(['zip_code' => $zipCode])->first())->google_lookup;
    }
}
