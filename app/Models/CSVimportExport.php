<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CSVimportExport extends Model
{
    use HasFactory;
    protected $table = 'csv_import';
    protected $fillable = [
        'store_id',
    ];
}
