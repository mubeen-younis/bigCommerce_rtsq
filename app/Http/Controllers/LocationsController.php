<?php

namespace App\Http\Controllers;

use App\CurlRequest;
use App\Models\Locations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LocationsController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public $googleURL = 'https://eniture.com/ws/addon/google-location.php';
    public $curlRequest;

    public function __construct()
    {
        $this->curlRequest = new CurlRequest();
    }

    public function index()
    {
        $locations = Locations::where('store_id', 1)->get();
        return response()->json(['error' => false, 'data' => $locations], 200);
    }

    public function warehouse()
    {
        return response()->json(Locations::where('type', '=', '1')->get(), 200);
    }

    public function dropships()
    {
        return response()->json(Locations::where('type', '=', '2')->get(), 200);
    }

    public function getGoogleLocation(Request $request)
    {

    }


    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $rules = [
            'city' => 'required',
            'state' => 'required',
            'zipcode' => 'required',
            'country' => 'required',
            'location_type' => 'required'
        ];
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json(
                ['error' => true,
                    'data' => $validator->errors()->all(),
                    'message' => 'Validation Errors'
                ], 400);
        }
        if (isset($request->location_type) && $request->location_type == 1) {
            $resp = $this->saveWarehouseAddress($request);
        } elseif (isset($request->location_type) && $request->location_type == 2) {
            $resp = $this->saveDropshipAddress($request);
        } else {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'Request Not Properly Formatted'
            ], 404);
        }
        $status = $resp['status'];
        unset($resp['status']);
        return response()->json($resp, $status);

    }

    public function saveWarehouseAddress($request)
    {
        $method = "Added";
        $callBy = "Warehouse";
        if (!empty($request->location_id)) {
            $method = "Updated";
            $location = Locations::where('id', $request->location_id)->where('type', 1)->first();
            if ($location === null) {
                return ['error' => true,
                    'data' => [],
                    'message' => 'Incorrect Location Id',
                    'status' => 200
                ];
            }
            // Checking if zipcode matches with current location saved record
            // its important when updating to know whether a zip exists on any other record or not
            if ($location->zip_code != $request->zipcode) {
                if (Locations::where('zip_code', $request->zipcode)->where('type', 1)->exists()) {
                    return ['error' => true,
                        'data' => [],
                        'message' => 'Warehouse address with this zipcode already exists',
                        'status' => 200
                    ];
                }
            }
        } else {
            $location = new Locations();
            if (Locations::where('zip_code', $request->zipcode)->where('type', 1)->exists()) {
                return ['error' => true,
                    'data' => [],
                    'message' => 'Warehouse already exists',
                    'status' => 200
                ];
            }
        }
        $loc = $this->saveLocationRequest($location, $request, $method, $callBy);
        return $loc;
    }

    public function saveDropshipAddress($request)
    {
        $method = "Added";
        $callBy = "Dropship";
        if (!empty($request->location_id)) {
            $method = "Updated";
            $location = Locations::where('id', $request->location_id)->where('type', 2)->first();
            if ($location === null) {
                return ['error' => true,
                    'data' => [],
                    'message' => 'Incorrect Location Id',
                    'status' => 200
                ];
            }
            // Checking if zipcode matches with current location saved record
            // its important when updating to know whether a zip exists on any other record or not
            if ($location->zip_code != $request->zipcode) {
                if (Locations::where('zip_code', $request->zipcode)->where('type', 2)->exists()) {
                    return ['error' => true,
                        'data' => [],
                        'message' => 'Dropship address with this zipcode already exists',
                        'status' => 200
                    ];
                }
            }
        } else {
            $location = new Locations();
            if (Locations::where('zip_code', $request->zipcode)->where('type', 2)->exists()) {
                return ['error' => true,
                    'data' => [],
                    'message' => 'Dropship already exists',
                    'status' => 200
                ];
            }
        }
        $loc = $this->saveLocationRequest($location, $request, $method, $callBy);
        return $loc;
    }

    public function saveLocationRequest($location, $request, $method, $callBy)
    {
        $nickname = "";
        if (empty($request->nickname)) {
            $nickname = $request->zipcode . '_' . $request->city . '_' . $request->state;
        } else {
            $nickname = $request->nickname;
        }
        try {
            if (Locations::where('id', $request->id)->exists()){
                $location = Locations::where('id', $request->id)->first();
            }
            $location->nickname = $nickname;
            $location->store_id = $request->store_id;
            $location->type = $request->location_type;
            $location->zip_code = $request->zip_code;
            $location->city = $request->city;
            $location->state = $request->state;
            $location->country = $request->country;
            //$location->additionals = json_encode($request->all());
            $additionals = [
                'instore_pickup' => $request->enable_instore ?? '',
                'local_delivery' => $request->enable_ld ?? '',
                'ld_enable_supress' => $request->ld_enable_supress ?? '',
                'instore_pickup_data' => [
                    'miles' => $request->instore_miles ?? '',
                    'postalCodes' => (!empty($request->instore_zipcodes)) ? implode(',',$request->instore_zipcodes ) : '',
                    'checkout_description' => $request->instock_description ?? ''
                ],
                'local_delivery_data' => [
                    'miles' => $request->ld_miles ?? '',
                    'postalCodes' => (!empty($request->ld_zipcodes)) ? implode(',',$request->ld_zipcodes ) : '',
                    'local_delivery_fee' => $request->ld_fee ?? '',
                    'checkout_description' => $request->ld_description ?? '',
                ]
            ];
            $location->additionals = json_encode($additionals);
            $location->save();
            return ['error' => false,
                'data' => [$location->id],
                'message' => 'Successfully ' . $method . ' ' . $callBy . ' Address',
                'status' => 200
            ];
        } catch (\Exception $exception) {
            return ['error' => true,
                'data' => [],
                //Todo: change message
                'message' => $exception->getMessage(),
                'status' => 500
            ];
        }

    }


    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSingleLocation(Request $request)
    {
        if (empty($request->location_id)) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Location Id'
            ], 404);
        }
        $location = Locations::where('id', $request->location_id)
            ->first();
        if ($location === null) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Locations Available'
            ], 404);
        }
        return response()->json(['error' => false,
            'data' => $location,
            'message' => ''
        ], 200);
    }

    public function getLocations(Request $request)
    {
        $locations = Locations::where('store_id', $request->store_id)
            ->get();
        if ($locations->isEmpty()) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Locations Available'
            ], 404);
        }
        return response()->json(['error' => false,
            'data' => $locations,
            'message' => ''
        ], 200);
    }

    public function deleteLocation(Request $request)
    {
        if (empty($request->location_id)) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Location Id'
            ], 404);
        }
        if (Locations::where('id', $request->location_id)->exists()) {
            Locations::where('id', $request->location_id)->delete();
            return response()->json(['error' => false,
                'data' => [],
                'message' => 'Location Deleted Successfully'
            ], 200);
        } else {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Location exists against this Id'
            ], 404);
        }
    }

    public function getLocationFromZip(Request $request)
    {
        if (empty($request->zip_code)) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Valid Zip Code Provided'
            ], 200);
        }
        $zipCode = $request->zip_code;
        $url = "https://maps.googleapis.com/maps/api/geocode/json?address=" . urlencode($zipCode) . "&key=AIzaSyADPlm4GliK0B0HpHn6kKLJ2XAH7b3hd2w";
        $zipcodeDetail = $this->curlRequest->enSingleCurlRequest($url, [], [], 'GET', false);
        if ($zipcodeDetail['info']['http_code'] != 200) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'Unable to connect to server'
            ], $zipcodeDetail['info']['http_code']);
        }

        $mapResult = json_decode($zipcodeDetail['response'], true);

        if (isset($mapResult['error_message']) || $mapResult['status'] != 'OK') {
            return response()->json(['error' => true,
                'data' => [],
                'message' => isset($mapResult['error_message']) ? $mapResult['error_message'] : " Zero Results"
            ], 200);
        }
        $city = [];
        $state = "";
        $country = "";
        if (count($mapResult['results']) > 0) {
            //dd($mapResult['results']);
            $arrComponents = $mapResult['results'][0]['address_components'] ?? [];
            if (isset($mapResult['results'][0]['postcode_localities'])) {
                foreach ($mapResult['results'][0]['postcode_localities'] as $index => $component) {
                    $city[] = $component;
                }
            } elseif ($arrComponents) {
                foreach ($arrComponents as $index => $component) {
                    $type = $component['types'][0];
                    if ($type == "sublocality_level_1" || $type == "locality") {
                        $city[] = trim($component['long_name']);
                    }
                }
            }
            if ($arrComponents) {
                $country = '';
                $state = '';
                foreach ($arrComponents as $index => $stateApp) {
                    $type = $stateApp['types'][0];
                    if ($state == "" && ($type == "administrative_area_level_1")) {
                        $state = trim($stateApp['short_name']);
                    }
                    if ($country == "" && ($type == "country")) {
                        $country = trim($stateApp['short_name']);
                    }
                }
            }
            return response()->json(['error' => false,
                'data' => ['postal_code' => $zipCode, 'city' => $city, 'state' => $state, 'country' => $country],
                'message' => ''
            ], 200);
        } else {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'Something Went Wrong'
            ], 500);

        }

    }
}
