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
        if (!empty($request->location_id)) {
            $method = "Updated";
            $location = Locations::where('id', $request->location_id)->where('type', 1)->first();
            if ($location === null) {
                return ['error' => true,
                    'data' => [],
                    'message' => 'Incorrect Location Id',
                    'status' => 404
                ];
            }
        } else {
            $location = new Locations();
            if (Locations::where('zip_code', $request->zipcode)->where('type', 1)->exists()) {
                return ['error' => true,
                    'data' => [],
                    'message' => 'Warehouse already exists',
                    'status' => 404
                ];
            }
        }
        $loc = $this->saveLocationRequest($location, $request);
        return ['error' => false,
            'data' => [$loc],
            'message' => 'Successfully ' . $method . ' Warehouse Address',
            'status' => 200
        ];
    }

    public function saveDropshipAddress($request)
    {
        $method = "Added";
        if (!empty($request->location_id)) {
            $method = "Updated";
            $location = Locations::where('id', $request->location_id)->where('type', 2)->first();
            if ($location === null) {
                return ['error' => true,
                    'data' => [],
                    'message' => 'Incorrect Location Id',
                    'status' => 404
                ];
            }
        } else {
            $location = new Locations();
            if (Locations::where('zip_code', $request->zipcode)->where('type', 2)->exists()) {
                return ['error' => true,
                    'data' => [],
                    'message' => 'Dropship already exists',
                    'status' => 404
                ];
            }
        }
        $loc = $this->saveLocationRequest($location, $request);
        return ['error' => false,
            'data' => [$loc],
            'message' => 'Successfully ' . $method . ' Dropship Address',
            'status' => 200
        ];
    }

    public function saveLocationRequest($location, $request)
    {
        $nickname = "";
        if (empty($request->nickname)) {
            $nickname = $request->zipcode . '_' . $request->city . '_' . $request->state;
        } else {
            $nickname = $request->nickname;
        }
        $location->nickname = $nickname;
        $location->store_id = $request->store_id;
        $location->type = $request->location_type;
        $location->zip_code = $request->zipcode;
        $location->city = $request->city;
        $location->state = $request->state;
        $location->country = $request->country;
        $location->additionals = json_encode($request->all());
        $location->save();
        return $location->id;
    }

    /**
     * Display the specified resource.
     *
     * @param \App\Locations $locations
     * @return \Illuminate\Http\Response
     */
    public function show(Locations $locations)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param \App\Locations $locations
     * @return \Illuminate\Http\Response
     */
    public function edit(Locations $locations)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Locations $locations
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Locations $locations)
    {
        $locations->update($request->all());
        return response()->json($locations, 200);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param \App\Locations $locations
     * @return \Illuminate\Http\Response
     */
    public function delete_warehouse($id)
    {
        DB::table('locations')
            ->where('id', $id)
            ->where('type', '=', 1)
            ->delete();

        return response()->json(['message' => "Record deleted Successfully"]);
    }

    public function delete_dropships($id)
    {
        DB::table('locations')
            ->where('id', $id)
            ->where('type', '=', 2)
            ->delete();
        return response()->json(['message' => "Record deleted Successfully"]);
    }

    public function getLocationFromZip(Request $request)
    {
        if (!isset($request->zip_code) || empty($request->zip_code)) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Valid Zip Code Provided'
            ], 404);
        }
        $zipCode = $request->zip_code;
        if (!empty($zipCode)) {
            $url = "https://maps.googleapis.com/maps/api/geocode/json?address=" . urlencode($zipCode) . "&key=AIzaSyADPlm4GliK0B0HpHn6kKLJ2XAH7b3hd2w";
            $zipcodeDetail = $this->curlRequest->enSingleCurlRequest($url, [], [], 'GET', false);
            if ($zipcodeDetail['info']['http_code'] != 200) {
                return response()->json(['error' => true,
                    'data' => [],
                    'message' => 'Unable to connect to server'
                ], 404);
            }

            $mapResult = json_decode($zipcodeDetail['response'], true);

            if (isset($mapResult['error_message']) || $mapResult['status'] != 'OK') {
                return response()->json(['error' => true,
                    'data' => [],
                    'message' => isset($mapResult['error_message']) ? $mapResult['error_message'] : " Zero Results"
                ], 404);
            }
            $city = [];
            $state = "";
            $country = "";
            if (count($mapResult['results']) > 0) {
                //dd($mapResult['results']);
                $arrComponents = $mapResult['results'][0]['address_components'];
                if (isset($mapResult['results'][0]['postcode_localities'])) {
                    foreach ($mapResult['results'][0]['postcode_localities'] as $index => $component) {
                        $city[] = $component;
                    }
                    $postcodeLocalities = 1;
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
                ], 404);

            }
        }
        //Added Arslan
        return ['error' => 'Zip code is not provided.'];
    }
}
