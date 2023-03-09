<?php

namespace App\Models\DBSC;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BcZones extends Model
{
    use HasFactory;

    protected $table = 'zones';
    protected $fillable = [
        'name',
        'type',
        'bc_zone_id',
        'store_id ',
    ];

    public static function getZonesDetail($zonesArr)
    {
        return optional(self::leftjoin('zones_details', 'zones.id', 'zones_details.zone_id')
            ->whereIn('zones.bc_zone_id', $zonesArr)
            ->get())->toArray() ?? [];
    }
}
