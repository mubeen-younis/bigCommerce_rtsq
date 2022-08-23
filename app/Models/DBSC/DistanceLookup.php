<?php

namespace App\Models\DBSC;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DistanceLookup extends Model
{
    use HasFactory;

    protected $table = "distance_lookup";
    protected $fillable = [
        'origin_zip'
    ];

    public static function getDistanceData($originZip, $destinationZip)
    {
        $distanceRows = DistanceLookup::where(['origin_zip' => $originZip, 'destination_zip' => $destinationZip])->first();

        if (!blank($distanceRows)) {
            DistanceLookup::where('id', $distanceRows['id'])->increment('lookup_count');
            return $distanceRows->toArray();
        }
        return [];

    }

    public static function insertDistanceData($distanceData, $combinations)
    {
        $toBeInserted = [];
        foreach ($combinations as $key => $combination) {
            $distance = optional(reset($distanceData->rows[$key]->elements))->distance ?? null;
            if (!blank($distance)) {
                $toBeInserted[] = [
                    'origin_zip' => $combination['origin_zip'],
                    'destination_zip' => $combination['destination_zip'],
                    'distance_mi' => $distance->text,
                    'distance_m' => $distance->value,
                    'lookup_count' => 1,
                    'created_at' => now(),
                    'updated_at' => now()
                ];
            }
        }

        DistanceLookup::insert($toBeInserted);

        return $toBeInserted;

    }
}
