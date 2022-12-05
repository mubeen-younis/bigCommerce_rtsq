<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResidentialSetting extends Model
{
    use HasFactory;
    protected $table = 'residential_settings';

    protected $fillable=[
        'store_id',
        'settings',
    ];
}
