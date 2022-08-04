<?php

namespace App\Models\DBSC;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DbscShippingProfile extends Model
{
    use HasFactory;
    protected $table = 'dbsc_profiles';
    protected $fillable = [
        'p_nickname',
        'shipping_classes',
    ];
}
