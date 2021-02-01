<?php


namespace App\CustomClasses;
use App\Constants\constant;
use App\CurlRequest;
use App\Models\Locations;

class Origin
{
    /**
     * getNearestWarehouse perform functionality to filter minimum distance b/w source(warehouse) and destination
     * warehouses: All warehouse available in our system
     * destination
     * **/
    public function getNearestWarehouse($destination){
        $wareHouses = $this->getLocationsByType('warehouse');
        $destination = $this->getFormedDestination($destination);
        foreach ($wareHouses as $wareHouse){
            $endPoint = $this->generateEndPoints($wareHouse, $destination);
            echo $endPoint."<br>";
        }
    }

    public function generateEndPoints($origin, $destination){
        $endPoints = 'https://maps.googleapis.com/maps/api/distancematrix/json?units=imperial';
        $endPoints .= '&origins='. $this->getFormedOrigin($origin);
        $endPoints .= '&destinations=' . $destination;
        $endPoints .= '&key=' . constant::GOOGLE_DISTANCE_API_KEY;
        return $endPoints;
    }

    public function getFormedOrigin($rawAddress){
        $address['city']        = $rawAddress['city'] ?? '';
        $address['state']       = $rawAddress['state'] ?? '';
        $address['zip_code']    = $rawAddress['zip_code'] ?? '';
        $address['country']     = $rawAddress['country'] ?? '';
        return implode(',',  array_filter($address));
    }

    public function getFormedDestination($rawAddress){
        $address['city']        = $rawAddress['city'] ?? '';
        $address['state']       = $rawAddress['state_iso2'] ?? '';
        $address['zip_code']    = $rawAddress['zip'] ?? '';
        $address['country']     = $rawAddress['country_iso2'] ?? '';
        return implode(',',  array_filter($address));
    }

    public function getLocationsByType($type){
        switch ($type) {
            case 'warehouse' :
                return Locations::where('type', 1)->get()->toArray();
                break;
            case 'dropships' :
                return Locations::where('type', 2)->get()->toArray();
                break;
            default:
                return Locations::get()->toArray();
        }
    }
}
