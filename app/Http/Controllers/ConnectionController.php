<?php

namespace App\Http\Controllers;

use App\CurlRequest;
use App\CustomClasses\Fedex\ltl\ConnectionSettings as FedexLtlConnectionSettings;
use App\CustomClasses\Fedex\small\ConnectionSettings as FedexSmallConnectionSettings;
use App\CustomClasses\GTZ\ltl\ConnectionSettings as GTZLtlConnectionSettings;
use App\CustomClasses\RL\ltl\ConnectionSettings as RNLLtlConnectionSettings;
use App\CustomClasses\UpsLTL\UpsLtlConnectionSettings;
use App\CustomClasses\UpsSmall\ConnectionSettings;
use App\CustomClasses\WweLTL\WweLtlConnectionSettings;
use App\CustomClasses\WWESMALL\SmallConnectionSettings;
use App\CustomClasses\XPO\ltl\ConnectionSettings as XPOLtlConnectionSettings;
use App\CustomClasses\Unishippers\small\ConnectionSettings as UnishippersSmallConnectionSettings;
use App\Endpoints\Endpoints;
use App\Models\Connection;
use App\Models\Coupon;
use App\Models\CouponCarrier;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ConnectionController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function __construct()
    {
        $this->wweSmallTestCon = new SmallConnectionSettings();
        $this->wweLtlTestCon = new WweLtlConnectionSettings();
        $this->upsLtlTestCon = new UpsLtlConnectionSettings();
        $this->upsSmallTestCon = new ConnectionSettings();
        $this->fedexLtlTestCon = new FedexLtlConnectionSettings();
        $this->fedexSmallTestCon = new FedexSmallConnectionSettings();
        $this->gtzLtlTestCon = new GTZLtlConnectionSettings();
        $this->xpoLtlTestCon = new XPOLtlConnectionSettings();
        $this->rnlLtlTestCon = new RNLLtlConnectionSettings();
        $this->unishippersSmallTestCon = new UnishippersSmallConnectionSettings();
    }

    public function index(Request $request)
    {
        $con = Connection::where('installed_carrier_id', $request->carrierId)->first();
        return response()->json(["error" => false, "data" => $con]);
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
        /* $rules = [
        'billing_account_no' => 'required',
        'meter_number' => 'required',
        'password' => 'required',
        'auth_key' => 'required',
        'shipper_account_no' => 'required',
        'billing_address' => 'required',
        ]; */

        //        $validator = Validator::make($request->all(), $rules);
        //        if ($validator->fails()) {
        //            return response()->json($validator->errors(), 400);
        //        }

        $checkCarrierType = DB::table('carriers')->select('slug', 'stores.name')
            ->leftJoin('installed_carriers', 'carriers.id', 'installed_carriers.carrier_id')
            ->leftJoin('stores', 'stores.id', '=', 'installed_carriers.store_id')
            ->where('installed_carriers.id', $request->carrierId)
            ->first();

        if ($checkCarrierType === null) {
            return response()->json(["error" => true, "data" => [],
                'message' => 'Carrier Not Found']);
        }

        if (!empty($request->testType)) {
            switch ($checkCarrierType->slug) {
                case "ltl-quotes":
                    $response = $this->wweLtlTestCon->testLtlConnection($request, $checkCarrierType->name);
                    return response()->json($response);
                case "small-package":
                    $response = $this->wweSmallTestCon->testSmallConnection($request, $checkCarrierType->name);
                    return response()->json($response);
                case 'ups-ltl':
                    $response = $this->upsLtlTestCon->testUpsLtlConnection($request, $checkCarrierType->name);
                    return response()->json($response);
                case 'ups-small':
                    $response = $this->upsSmallTestCon->testUpsLtlConnection($request, $checkCarrierType->name);
                    return response()->json($response);
                case 'fedex-ltl':
                    $response = $this->fedexLtlTestCon->testConnection($request, $checkCarrierType->name);
                    return response()->json($response);
                case 'fedex-small':
                    $response = $this->fedexSmallTestCon->testConnection($request, $checkCarrierType->name);
                    return response()->json($response);
                case 'gtz-ltl':
                    $response = $this->gtzLtlTestCon->testConnection($request, $checkCarrierType->name);
                    return response()->json($response);
                case 'xpo-ltl':
                    $response = $this->xpoLtlTestCon->testConnection($request, $checkCarrierType->name);
                    return response()->json($response);
                case 'rl-ltl':
                    $response = $this->rnlLtlTestCon->testConnection($request, $checkCarrierType->name);
                    return response()->json($response);
                case 'unishippers-small':
                    $response = $this->unishippersSmallTestCon->testConnection($request, $checkCarrierType->name);
                    return response()->json($response);
                default:
                    return response()->json(["error" => true, "data" => [],
                        'message' => 'No carrier Matches']);
            }
        }

        $carriersArr = ['ltl-quotes', 'small-package', 'gtz-ltl', 'unishippers-small'];
        if (in_array($checkCarrierType->slug, $carriersArr)) {
            $fdoCouponResponse = $this->getFDOCouponCarrierInfo($request, $checkCarrierType->slug);
        }

        $message = 'Connection settings has been saved and Promo Code is valid.';
        if (isset($fdoCouponResponse) && !empty($fdoCouponResponse)) {
            if (isset($fdoCouponResponse['status']) && $fdoCouponResponse['status'] == false) {
                $message = 'Connection settings has been saved but the Promo Code is not valid.';
            }
        }
       
        $con = Connection::firstOrNew(['installed_carrier_id' => $request->carrierId]);
        $con->value = json_encode($request->all());
        $con->installed_carrier_id = $request->carrierId;
        $con->save();

        return response()->json(["error" => false, 'message' => $message, "data" => $con]);
    }

    public function getFDOCouponCarrierInfo(Request $request, $carrierSlug)
    {
        $storeId = $request->store_id;
        $promoDetail = Coupon::getFDOCoupon($storeId);
        if (blank($promoDetail)) {
            $response = ["status" => false];
            return $response;
        }

        $id = $promoDetail->id;
        $coupon = $promoDetail->code;
        $shop = $promoDetail->shop;
        $arr = [
            'small-package' => 'WWE_PL',
            'ltl-quotes' => 'WWE_LTL',
            'gtz-ltl' => 'GTZ',
            'unishippers-small' => 'UNI_PL',
        ];

        $carrier = '';
        if (isset($arr[$carrierSlug])) {
            $carrier = $arr[$carrierSlug];
        }

        $queryParams = http_build_query(['coupon' => $coupon, 'shop' => $shop, 'carriers' => $carrier]);
        $endPoint = Endpoints::applyPromoCodeFdoEndpoint() . $queryParams;
        $curlResponse = (new CurlRequest())->enSingleCurlRequest($endPoint, [], [], 'GET');
        $response = json_decode($curlResponse['response'], true);

        if (isset($response['promo'])) {
            $carrier = CouponCarrier::addOrUpdateCarrierInfo($carrierSlug, $id, $coupon, $response);
        }

        return $response;
    }

    public function testConnection($data)
    {
        $response = [
            'error' => true,
            'message' => 'Something went wrong!',
        ];
        $url = 'https://eniture-qa.com/sfws/quote-speedfreight-shipment.php'; //Constant::TEST_CONN_URL;
        $params = [
            'platform' => 'bigcommerce',
            'speed_freight_username' => $data->username,
            'speed_freight_password' => $data->password,
            'authentication_key' => $data->authentication_key,
            'world_wide_express_account_number' => $data->account_number,
            'plugin_domain_name' => 'store-uann2u.mybigcommerce.com',
            'plugin_licence_key' => $data->license_key,
        ];

        $query_string = http_build_query($params);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 1000);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $query_string);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Expect:'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $output = curl_exec($ch);
        curl_close($ch);
        $output = \GuzzleHttp\json_decode($output, true);
        if (isset($output['error']) && isset($output['error_desc'])) {
            $response = [
                'error' => true,
                'message' => $output['error_desc'],
            ];
        } elseif (isset($output['success'])) {
            $response = [
                'error' => false,
                'message' => 'Test connection successful.',
                'data' => Connection::where('installed_carrier_id', $data->carrierId)->first(),
            ];
        }
        return response()->json($response);
    }

    /**
     * Display the specified resource.
     *
     * @param \App\Connection $connection
     * @return \Illuminate\Http\Response
     */
    public function show(Connection $connection)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param \App\Connection $connection
     * @return \Illuminate\Http\Response
     */
    public function edit(Connection $connection)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Connection $connection
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Connection $connection)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param \App\Connection $connection
     * @return \Illuminate\Http\Response
     */
    public function destroy(Connection $connection)
    {
        //
    }
}
