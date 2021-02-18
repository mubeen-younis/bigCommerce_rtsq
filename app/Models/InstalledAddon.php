<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InstalledAddon extends Model
{
    use HasFactory;
    protected $table = 'installed_addons';

    protected $fillable = [
        'store_id',
        'addon_id',
        'is_enabled',
        'is_expired'
    ];
}
