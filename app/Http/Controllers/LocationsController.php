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
        print_r($request->all());
        die();
        $rules = [
            'city' => 'required',
            'state' => 'required',
            'zip_code' => 'required',
            'country' => 'required',
        ];
        /*$validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }*/
        //$data = $request->all();
        $location = new Locations();
        return response()->json(['message' => "Form Submitted Successfully!"]);
        // return response()->json($connection, 201);
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
