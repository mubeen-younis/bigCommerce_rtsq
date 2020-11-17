<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccessTokens extends Model
{
    //

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'access_token',
    ];

    public $timestamps = false;
}
