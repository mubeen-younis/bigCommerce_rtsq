<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Requestmetadata extends Model
{
    use HasFactory;
    protected $table = 'requestmetadata';

    protected $fillable=[
        'request',
        'lineitems',
        'quotes',
        'response'
    ];

}
