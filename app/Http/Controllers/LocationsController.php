<?php

namespace App\Http\Controllers;

use App\CurlRequest;
use App\Models\Locations;
use App\Models\ProductSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\LocAssociatedAccountNo;
use App\Models\ShippingRule;
use App\CustomClasses\Functions;
use App\Models\CacheAddressLookup;
use App\Constants\Constant;

class LocationsController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public $googleURL = 'https://ws001.eniture.com/addon/google-location.php';
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

    public function getGoogleLocation(Request $request) {}

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
            'zip_code' => 'required',
            'country' => 'required',
            'location_type' => 'required',
            'xpo_account_number' => 'max:49',
            'odfl_account_number' => 'max:49',
            'sefl_account_number' => 'max:49',
            'saia_account_number' => 'max:49',
            'fedex_account_number' => 'max:49',
            'purolator_account_number' => 'max:49',
        ];

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json(
                [
                    'error' => true,
                    'data' => $validator->errors()->all(),
                    'message' => $validator->errors()->first(),
                ],
                200
            );
        }

        // Normalize country before saving
        if (!empty($request->country)) {
            $country = strtolower(trim($request->country));

            if ($country === 'united states') {
                $request->merge(['country' => 'US']);
            } elseif ($country === 'canada') {
                $request->merge(['country' => 'CA']);
            }
        }

        if (isset($request->location_type) && $request->location_type == 1) {
            $resp = $this->saveWarehouseAddress($request);
        } elseif (isset($request->location_type) && $request->location_type == 2) {
            $resp = $this->saveDropshipAddress($request);
        } else {
            return response()->json([
                'error' => true,
                'data' => [],
                'message' => 'Request Not Properly Formatted',
            ], 404);
        }

        $status = $resp['status'];
        unset($resp['status']);

        return response()->json($resp, $status);
    }

    public function saveWarehouseAddress($request)
    {
        $method = "added";
        $callBy = "Warehouse";

        if (!empty($request->location_id)) {
            $method = "updated";
            $location = Locations::where('id', $request->location_id)->where('type', 1)->first();

            if ($location === null) {
                return [
                    'error' => true,
                    'data' => [],
                    'message' => 'Incorrect Location Id',
                    'status' => 200,
                ];
            }
            // Checking if zipcode matches with current location saved record
            // its important when updating to know whether a zip exists on any other record or not
            if ($location->zip_code != $request->zip_code) {
                if (Locations::where('zip_code', $request->zip_code)->where('store_id', $request->store_id)->where('type', 1)->exists()) {
                    return [
                        'error' => true,
                        'data' => [],
                        'message' => 'Error! Zip code already exists.',
                        'status' => 200,
                    ];
                }
            }
        } else {
            $location = new Locations();

            if (Locations::where('zip_code', $request->zip_code)->where('store_id', $request->store_id)->where('type', 1)->exists()) {
                return [
                    'error' => true,
                    'data' => [],
                    'message' => 'Error! Zip code already exists',
                    'status' => 200,
                ];
            }
        }

        return $this->saveLocationRequest($location, $request, $method, $callBy);
    }

    public function saveDropshipAddress($request)
    {
        $method = "added";
        $callBy = "Drop ship";

        if (!empty($request->location_id)) {
            $method = "updated";
            $location = Locations::where('id', $request->location_id)->where('store_id', $request->store_id)->where('type', 2)->first();
            if ($location === null) {
                return [
                    'error' => true,
                    'data' => [],
                    'message' => 'Incorrect Location Id',
                    'status' => 200,
                ];
            }
            // Checking if zipcode matches with current location saved record
            // its important when updating to know whether a zip exists on any other record or not
            if ($location->zip_code != $request->zip_code) {
                if (Locations::where('zip_code', $request->zip_code)->where('store_id', $request->store_id)->where('type', 2)->exists()) {
                    return [
                        'error' => true,
                        'data' => [],
                        'message' => 'Error! Zip code already exists.',
                        'status' => 200,
                    ];
                }
            }
        } else {
            $location = new Locations();
            if (empty($request->nickname)) {
                $nickname = $request->zip_code . '_' . $request->city . '_' . $request->state;
            } else {
                $nickname = $request->nickname;
            }
            if (Locations::where('zip_code', $request->zip_code)->where('store_id', $request->store_id)->where('nickname', $nickname)->where('type', 2)->exists()) {
                return [
                    'error' => true,
                    'data' => [],
                    'message' => 'Error! Zip code already exists',
                    'status' => 200,
                ];
            }
        }
        return $this->saveLocationRequest($location, $request, $method, $callBy);
    }

    public function saveLocationRequest($location, $request, $method, $callBy)
    {
        if (empty($request->nickname)) {
            $nickname =  $request->city . ', ' . $request->state . ' ' . $request->zip_code;
        } else {
            $nickname = $request->nickname;
        }
        try {
            if (Locations::where('id', $request->id)->exists()) {
                $location = Locations::where('id', $request->id)->first();
            }
            $location->nickname = $nickname;
            $location->store_id = $request->store_id;
            $location->type = $request->location_type;
            $location->address = $request->address;
            $location->phone = $request->phone;
            $location->zip_code = $request->zip_code;
            $location->city = $request->city;
            $location->state = $request->state;
            $location->country = $request->country;
            $location->default_location_id = $request->default_location_id ?? '';
            $location->origin_markup = $request->origin_markup ?? '';


            $additionals = [
                'instore_pickup' => $request->enable_instore ?? '',
                'enable_instore_distance' => $request->enable_instore_distance ?? false,
                'enable_instore_address' => $request->enable_instore_address ?? false,
                'enable_instore_phone' => $request->enable_instore_phone ?? false,
                'local_delivery' => $request->enable_ld ?? '',
                'ld_enable_supress' => $request->ld_enable_supress ?? '',
                'instore_pickup_data' => [
                    'miles' => $request->instore_miles ?? '',
                    'postalCodes' => (!empty($request->instore_zipcodes)) ? implode(',', $request->instore_zipcodes) : '',
                    'checkout_description' => $request->instock_description ?? '',
                    //'default_location' => $request->default_location ?? '',
                    'instore_postalCode' => $request->instore_postalCode ?? '',
                    'instore_city' => $request->instore_city ?? '',
                    'instore_state' => $request->instore_state ?? '',
                    'instore_country' => $request->instore_country ?? '',
                ],
                'local_delivery_data' => [
                    'miles' => $request->ld_miles ?? '',
                    'postalCodes' => (!empty($request->ld_zipcodes)) ? implode(',', $request->ld_zipcodes) : '',
                    'local_delivery_fee' => $request->ld_fee ?? '',
                    'checkout_description' => $request->ld_description ?? '',
                ],
            ];
            $location->additionals = json_encode($additionals);
            $location->save();
            $locAccNo = LocAssociatedAccountNo::saveLocAssociatedAccNo($request, $location->id);

            $callBy = $method == 'added' ? 'New ' . lcfirst($callBy) : ucfirst($callBy);

            return [
                'error' => false,
                'data' => Locations::find($location->id),
                'message' => 'Success! ' . $callBy . ' ' . $method . ' successfully.',
                'status' => 200,
            ];
        } catch (\Exception $exception) {
            return [
                'error' => true,
                'data' => [],
                //Todo: change message
                'message' => $exception->getMessage(),
                'status' => 500,
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
            return response()->json([
                'error' => true,
                'data' => [],
                'message' => 'No Location Id',
            ], 404);
        }
        $location = Locations::where('id', $request->location_id)
            ->first();
        $locAssociatedAccNo = LocAssociatedAccountNo::getlocAssociatedAccNo($request->location_id);
        $location->loc_associated_acc_no = json_encode($locAssociatedAccNo);

        if ($location === null) {
            return response()->json([
                'error' => true,
                'data' => [],
                'message' => 'No Locations Available',
            ], 404);
        }
        return response()->json([
            'error' => false,
            'data' => $location,
            'message' => '',
        ], 200);
    }

    public function getLocations(Request $request)
    {
        $locations = Locations::where('store_id', $request->store_id)
            ->get();
        if ($locations->isEmpty()) {
            return response()->json([
                'error' => true,
                'data' => [],
                'message' => 'No Locations Available',
            ], 200);
        }
        return response()->json([
            'error' => false,
            'data' => $locations,
            'message' => '',
        ], 200);
    }

    public function deleteLocation(Request $request)
    {
        try {
            if (empty($request->location_id)) {
                return response()->json([
                    'error' => true,
                    'data' => [],
                    'message' => 'No Location Id',
                ], 404);
            }

            if (Locations::where('id', $request->location_id)->exists()) {
                if ($request->location_type == "Drop ship") {
                    $this->deleteDropshippedProduct($request->location_id);
                }
                Locations::where('default_location_id', $request->location_id)->update(['default_location_id' => 'default']);
                // Check: is warehouse added in shipping rule or not
                if ($request->location_type == 'Warehouse') {
                    $location = Locations::where(['id' => $request->location_id, 'type' => 1])->first()->toArray() ?? [];
                    $shippigRule = ShippingRule::getStoreShippingRules($request->store_id);

                    $shippingRuleLocations = collect($shippigRule)->filter(function ($rule) use ($location) {
                        return $rule['rule_type'] == 5 && in_array($location['zip_code'], $rule['warehouses']);
                    })->toArray() ?? [];

                    if (!empty($shippingRuleLocations)) {
                        return response()->json([
                            'error' => true,
                            'data' => [],
                            'message' => 'Warehouse inclusion detected in the "Restrict to Origin Locations" shipping rule. Please remove it from the shipping rule to proceed.',
                        ], 200);
                    }
                }

                Locations::where('id', $request->location_id)->delete();
                return response()->json([
                    'error' => false,
                    'data' => [],
                    'message' => 'Success! ' . $request->location_type . ' deleted successfully',
                ], 200);
            } else {
                return response()->json([
                    'error' => true,
                    'data' => [],
                    'message' => 'No Location exists against this Id',
                ], 404);
            }
        } catch (\Exception $exception) {
            return response()->json([
                'error' => true,
                'data' => [
                    'line' => $exception->getLine(),
                    'file' => $exception->getFile(),
                    'message' => $exception->getMessage()
                ],
                'message' => 'Something went Wrong',
            ], 404);
        }
    }

    public function deleteDropshippedProduct($dropshipId)
    {
        ProductSetting::deleteIfDropProduct($dropshipId);
    }

    public function getLocationFromZip(Request $request)
    {
        if (empty($request->zip_code)) {
            return response()->json([
                'error' => true,
                'data' => [],
                'message' => 'No Valid Zip Code Provided',
            ], 200);
        }
        $zipCode = $request->zip_code;
        // To check is address already exist in the database then no api call
        $addressLookupData = CacheAddressLookup::getAddressLookupDatabase($zipCode);
        if (empty($addressLookupData)) {
            $url = Constant::wsRemoteBaseUrl . "&";
            $url .= "storeName=" . $request['store_name'] . "&";
            $url .= "address=" . urlencode($zipCode);
            $zipcodeDetail = $this->curlRequest->enSingleCurlRequest($url, [], [], 'GET', false);
            if ($zipcodeDetail['info']['http_code'] != 200) {
                return response()->json([
                    'error' => true,
                    'data' => [],
                    'message' => 'Unable to connect to server',
                ], $zipcodeDetail['info']['http_code']);
            }

            $mapResult = json_decode($zipcodeDetail['response'], true);

            if (isset($mapResult['error_message']) || $mapResult['status'] != 'OK') {
                return response()->json([
                    'error' => true,
                    'data' => [],
                    'message' => isset($mapResult['error_message']) ? $mapResult['error_message'] : " Error! Please enter valid US or Canada zip code.",
                ], 200);
            }

            CacheAddressLookup::insertAddressData($zipCode, $mapResult);
        } else {
            $mapResult = json_decode($addressLookupData, true);
        }

        $city = [];
        $state = "";
        $country = "";
        if (count($mapResult['results']) > 0) {

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
            return response()->json([
                'error' => false,
                'data' => ['postal_code' => $zipCode, 'city' => $city, 'state' => $state, 'country' => $country],
                'message' => '',
            ], 200);
        } else {
            return response()->json([
                'error' => true,
                'data' => [],
                'message' => 'Something Went Wrong',
            ], 500);
        }
    }

    public static function getLocationById($id)
    {
        $location = Locations::where('id', $id)->first();
        if (!empty($location)) {
            return $location;
        }
        return [];
    }

    public static function getAllLocations($storeId, $type)
    {
        return Locations::where(['store_id' => $storeId, 'type' => $type])->get();
    }
}
