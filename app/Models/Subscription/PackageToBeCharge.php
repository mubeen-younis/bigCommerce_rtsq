<?php

namespace App\Models\Subscription;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PackageToBeCharge extends Model
{
    use HasFactory;
    protected $table = 'package_sub_to_be_charge';
    protected $fillable = [
        'subscription_id',
        'package_id',
        'status',
        'requested_date',
    ];
}
