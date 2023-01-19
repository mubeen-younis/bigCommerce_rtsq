<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WeightThresholdSettings extends Model
{
    use HasFactory;
    protected $table = 'weight_threshold_settings';
    protected $fillable = [
        'store_id',
    ];
}
