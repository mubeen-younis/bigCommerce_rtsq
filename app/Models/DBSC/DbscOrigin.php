<?php

namespace App\Models\DBSC;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DbscOrigin extends Model
{
    use HasFactory;

    protected $table = 'dbsc_origins';
    protected $fillable = ['profile_id',];
}
