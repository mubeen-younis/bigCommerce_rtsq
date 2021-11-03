<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MultiplePackagingBoxes extends Model
{
    use HasFactory;
    protected $table = 'multiple_packaging_boxes';

    protected $fillable = [
        'product_id',
        'quantity',
        'nickname',
        'length',
        'width',
        'height',
        'weight',
        'box_fee',
        'status'
    ];
}
