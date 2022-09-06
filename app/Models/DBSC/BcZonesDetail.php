<?php

namespace App\Models\DBSC;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BcZonesDetail extends Model
{
    use HasFactory;
    protected $table = 'zones_details';
    protected $fillable = [
        'zone_id ',
    ];
}
