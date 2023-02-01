<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LocAssociatedAccountNo extends Model
{
    use HasFactory;
    protected $table = 'location_associated_to_account_number';
    protected $fillable = [
        'location_id',
        'carrier_id',
        'carriers_acc_number',
    ];

    public static function saveLocAssociatedAccNo($request, $locationId)
    {
        $carrAccountNumbers = [
            'xpo_id' => $request->xpo_account_number ?? '',
            'odfl_id' => $request->odfl_account_number ?? '',
            'sefl_id' => $request->sefl_account_number ?? '',
            'saia_id' => $request->saia_account_number ?? '',
            'fedex_id' => $request->fedex_account_number ?? '',
            'purolator_id' => $request->purolator_account_number ?? '',

        ];

        foreach($request->ids as $key => $carrier_id){

            if (!empty($carrier_id)){
                $locAssociatedAccNumber = self::firstOrNew(['location_id' => $locationId, 'carrier_id' => $carrier_id]);
                $locAssociatedAccNumber->location_id = $locationId;
                $locAssociatedAccNumber->carrier_id = $carrier_id;

                foreach ($carrAccountNumbers as $carrId => $carrAccountNo){

                    if($key == $carrId){
                        $locAssociatedAccNumber->carrier_acc_number = $carrAccountNo ?? '';
                        $locAssociatedAccNumber->save();
                        break;
                    }    
                }       
            }            
        }
    }

    public static function getlocAssociatedAccNo($locationId)
    {
        $locAssocitedAccNo = [];
        $locSpecificAccNo = self::leftJoin('carriers', 'location_associated_to_account_number.carrier_id', '=', 'carriers.id')
        ->where('location_id', $locationId)->select('carriers.slug', 'carrier_acc_number')->get();
        foreach($locSpecificAccNo as $loc){
            $locAssocitedAccNo[$loc['slug']] = $loc['carrier_acc_number'] ?? '';
        }
        return $locAssocitedAccNo ?? [];
    }
}
