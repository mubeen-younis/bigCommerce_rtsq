<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuoteSetting extends Model
{
    use HasFactory;
    protected $table = 'qoute_settings';
    protected $fillable = [
        'installed_carrier_id',
        'value'
    ];
}
