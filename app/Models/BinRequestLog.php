<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BinRequestLog extends Model
{
    use HasFactory;
    protected $table = 'bin_request_log';
    protected $fillable = [
        'store_id',
        'cart_id',
        'request',
        'request_hash',
        'api_response',
        'request_time',
        'response_time',
        'hits'
    ];
}
