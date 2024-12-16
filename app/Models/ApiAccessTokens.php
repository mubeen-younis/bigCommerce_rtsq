<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApiAccessTokens extends Model
{
    use HasFactory;
    protected $table = 'api_access_tokens';

    protected $fillable=[
        'store_id',
        'access_token',
    ];
}
