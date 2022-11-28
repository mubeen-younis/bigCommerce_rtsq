<?php

namespace App\Models\DBSC;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShippingClass extends Model
{
    use HasFactory;
    protected $table = 'dbsc_shipping_class';
    protected $fillable = [
        'class_name',
        'slug',
        'description',
    ];

    public static function getSlug($className)
    {
        $slug = preg_replace('/[^A-Za-z0-9-]+/', '_', $className) . uniqid();
        return $slug;
    }
}