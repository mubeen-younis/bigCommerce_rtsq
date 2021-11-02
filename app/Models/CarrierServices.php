<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CarrierServices extends Model
{
    use HasFactory;
    protected $table = 'shopify_freights';
    public $timestamps = false;
}
