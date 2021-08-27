<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExportProducts extends Model
{
    use HasFactory;
    protected $table = 'exportproducts';
    protected $fillable = [
        'store_id',
        'foldername',
        'hash',
        'request_time',
        'email',
        'status'
    ];
}
