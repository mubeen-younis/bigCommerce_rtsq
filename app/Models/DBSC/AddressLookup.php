<?php

namespace App\Models\DBSC;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AddressLookup extends Model
{
    use HasFactory;
    protected $table = 'address_lookup';
    protected $fillable = [
        'postal_code',
        'province',
        'country',
        'city',
        'longitude',
        'latitude',
        'lookup_count'
    ];
}