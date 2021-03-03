<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AddonSettings extends Model
{
    use HasFactory;

    protected $fillable = [
        'installed_addon_id',
        'value',
    ];
}
