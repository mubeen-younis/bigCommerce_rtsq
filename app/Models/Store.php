<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Store extends Model
{

    protected $table = 'stores';

    protected $fillable=[
        'token',
        'app_status',
        'is_trial_completed'
    ];
    public function installedCarriers()
    {
        return $this->hasMany(InstalledCarrier::class);
    }
}
