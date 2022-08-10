<?php

namespace App\Models\DBSC;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BcZones extends Model
{
    use HasFactory;
    protected $table = 'zones';
    protected $fillable = [
        'name',
        'type',
        'bc_zone_id',
        'store_id ',
    ];
}
