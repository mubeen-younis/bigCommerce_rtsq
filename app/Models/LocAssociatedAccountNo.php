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
        foreach($request->ids as $key => $carrier_id){
            if(!empty($carrier_id)){
                $locAssociatedAccNumber = self::firstOrNew(['location_id' => $locationId, 'carrier_id' => $carrier_id]);
                $locAssociatedAccNumber->location_id = $locationId;
                $locAssociatedAccNumber->carrier_id = $carrier_id;
                if($key == 'xpo_id'){
                    $locAssociatedAccNumber->carrier_acc_number = $request->xpo_account_number ?? '';
                } elseif($key == 'odfl_id'){
                    $locAssociatedAccNumber->carrier_acc_number = $request->odfl_account_number ?? '';
                } elseif($key == 'sefl_id'){
                    $locAssociatedAccNumber->carrier_acc_number = $request->sefl_account_number ?? '';
                } elseif($key == 'saia_id'){
                    $locAssociatedAccNumber->carrier_acc_number = $request->saia_account_number ?? '';
                } elseif($key == 'fedex_id'){
                    $locAssociatedAccNumber->carrier_acc_number = $request->fedex_account_number ?? '';
                } elseif($key == 'purolator_id'){
                    $locAssociatedAccNumber->carrier_acc_number = $request->purolator_account_number ?? '';
                }
                $locAssociatedAccNumber->save();
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
