<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class NestingItemsDetail extends Model
{
    use HasFactory;

    protected $table = 'nesting_items_details';
    protected $fillable = [
        'product_settings_id',
    ];

}
