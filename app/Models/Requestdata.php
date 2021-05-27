<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Requestdata extends Model
{
    use HasFactory;
    protected $table = 'request';

    protected $fillable=[
        'store_id',
        'meta_id',
        'rate_id',
        'cart_id'
    ];
}
