<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    //
    protected $fillable=[
        'token'
    ];
    public function installedCarriers()
    {
        return $this->hasMany(InstalledCarrier::class);
    }
}
