<?php

namespace App\Models\DBSC;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DbscOtherSettings extends Model
{
    use HasFactory;

    protected $table = 'dbsc_other_settings';
    protected $fillable = ['multishipment_preference'];
}
