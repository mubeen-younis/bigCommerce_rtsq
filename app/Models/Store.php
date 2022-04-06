<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Store extends Model
{

    protected $table = 'stores';

    protected $fillable = [
        'token',
        'app_status'
    ];

    public function installedCarriers()
    {
        return $this->hasMany(InstalledCarrier::class);
    }

    public static function getStoreUrlFromStoreId($storeId)
    {
        return optional(self::where('id', $storeId)->first())->url ?? null;
    }
}
