<?php

namespace App\Http\Controllers;

use App\CurlRequest;
use App\CustomClasses\Fedex\ltl\ConnectionSettings as FedexLtlConnectionSettings;
use App\CustomClasses\Fedex\small\ConnectionSettings as FedexSmallConnectionSettings;
use App\CustomClasses\Functions;
use App\CustomClasses\GTZ\ltl\ConnectionSettings as GTZLtlConnectionSettings;
use App\CustomClasses\RL\ltl\ConnectionSettings as RNLLtlConnectionSettings;
use App\CustomClasses\UpsLTL\UpsLtlConnectionSettings;
use App\CustomClasses\UpsSmall\ConnectionSettings;
use App\CustomClasses\WweLTL\WweLtlConnectionSettings;
use App\CustomClasses\WWESMALL\SmallConnectionSettings;
use App\CustomClasses\XPO\ltl\ConnectionSettings as XPOLtlConnectionSettings;
use App\CustomClasses\Unishippers\small\ConnectionSettings as UnishippersSmallConnectionSettings;

use App\CustomClasses\YrcLTL\ConnectionSettings as YrcLtlConnectionSettings;
use App\CustomClasses\TQLLtl\ConnectionSettings as TQLLtlConnectionSettings;
use App\CustomClasses\FreightQuote\Ltl\ConnectionSettings as FreightQuoteConSett;
use App\CustomClasses\EstesLTL\ConnectionSettings as EstesLTLConnectionSettings;
use App\CustomClasses\DayRossLTL\ConnectionSettings as DayRossLtlConnectionSettings;
use App\CustomClasses\OdflLTL\ConnectionSettings as OdflLTLConnectionSettings;
use App\CustomClasses\SaiaLTL\ConnectionSettings as SaiaLTLConnectionSettings;
use App\CustomClasses\AbfLtl\ConnectionSettings as AbfLtlConnectionSettings;
use App\CustomClasses\UspsSmall\ConnectionSettings as UspsSmallConnectionSettings;
use App\CustomClasses\SouthEasternLtl\ConnectionSettings as SouthEasternLtlConnectionSettings;
use App\Endpoints\Endpoints;

use App\Models\Connection;
use App\Models\Coupon;
use App\Models\CouponCarrier;
use App\Models\Store;
use App\Models\Subscription\CarrierCount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ConnectionController extends Controller
{
    /**
     * @var FreightQuoteConSett
     */
    private $freightQuoteLtlTestCon;

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
        $this->yrcLtlTestCon = new YrcLtlConnectionSettings();
        $this->tqlLtlTestCon = new TQLLtlConnectionSettings();
        $this->freightQuoteLtlTestCon = new FreightQuoteConSett();
        $this->estesLTLConL = new EstesLTLConnectionSettings();
        $this->dayRossLtlTestCon = new DayRossLtlConnectionSettings();
        $this->odflLTLConL = new OdflLTLConnectionSettings();
        $this->saiaLtlTestCon = new SaiaLTLConnectionSettings();
        $this->AbfLtlTestCon = new AbfLtlConnectionSettings();
        $this->southEasternLtlTestCon = new SouthEasternLtlConnectionSettings();
        $this->uspsSmallTestCon = new UspsSmallConnectionSettings();
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
            return response()->json([
                "error" => true, "data" => [],
                'message' => 'Carrier Not Found'
            ]);
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
                case 'yrc-ltl':
                    $response = $this->yrcLtlTestCon->testConnection($request, $checkCarrierType->name);
                    return response()->json($response);
                case 'freightquote-ltl':
                    $response = $this->freightQuoteLtlTestCon->testConnection($request, $checkCarrierType->name);
                    return response()->json($response);
                case 'estes-ltl':
                    $response = $this->estesLTLConL->testConnection($request, $checkCarrierType->name);
                    return response()->json($response);
                case 'dayross-ltl':
                    $response = $this->dayRossLtlTestCon->testConnection($request, $checkCarrierType->name);
                    return response()->json($response);
                case 'odfl-ltl':
                    $response = $this->odflLTLConL->testConnection($request, $checkCarrierType->name);
                    return response()->json($response);
                case 'saia-ltl':
                    $response = $this->saiaLtlTestCon->testConnection($request, $checkCarrierType->name);
                    return response()->json($response);
                case 'abf-ltl':
                    $response = $this->AbfLtlTestCon->testConnection($request, $checkCarrierType->name);
                    return response()->json($response);
                case 'southeastern-ltl':
                    $response = $this->southEasternLtlTestCon->testConnection($request, $checkCarrierType->name);
                    return response()->json($response);
                case 'usps-small':
                    $response = $this->uspsSmallTestCon->testConnection($request, $checkCarrierType->name);
                    return response()->json();
                case 'tql-ltl':
                    $response = $this->tqlLtlTestCon->testConnection($request, $checkCarrierType->name);
                    return response()->json($response);
                default:
                    return response()->json([
                        "error" => true, "data" => [],
                        'message' => 'No carrier Matches'
                    ]);
            }
        }

        $message = 'Connection settings has been saved successfully';
        $carriersArr = ['ltl-quotes', 'small-package', 'gtz-ltl', 'unishippers-small'];
        if (!blank($request['promo_code']) &&
            in_array($checkCarrierType->slug, $carriersArr) &&
            ((isset($request['is_enabled']) && $request['is_enabled'] == false) || !isset($request['is_enabled']))
        ) {
            $fdoCouponResponse = $this->getFDOCouponCarrierInfo($request, $checkCarrierType->slug);
            if (isset($fdoCouponResponse['status']) && $fdoCouponResponse['status'] == true) {
                $message = 'Connection settings has been saved and the Promo Code is applied.';
            } else {
                $message = 'Connection settings has been saved but the Promo Code is not applied.';
            }
        }
        $con = Connection::firstOrNew(['installed_carrier_id' => $request->carrierId]);
        $con->value = json_encode($request->all());
        $con->installed_carrier_id = $request->carrierId;
        $con->save();

        if (in_array($checkCarrierType->slug, $carriersArr) && isset($this->coupon_code_id)) {
            $carrierCode = Functions::fdoSLugForCarriers($checkCarrierType->slug);
            $con->fdoCouponCarrierInfo = CouponCarrier::where('coupon_code_id', $this->coupon_code_id)->where('carrier_name', $checkCarrierType->slug)->where('carrier_code', $carrierCode)->first();
        }

        return response()->json(["error" => false, 'message' => $message, "data" => $con]);
    }

    public function getFDOCouponCarrierInfo(Request $request, $carrierSlug)
    {
        $storeId = $request->store_id;
        $storeDetails = Store::where('id', $storeId)->first();
        $promoDetail = Coupon::getFDOCoupon($storeId);
        if ((blank($promoDetail))) {
            return ["status" => false];
        }

        $id = $promoDetail ? $promoDetail->id : '';
        $this->coupon_code_id = $id;
        $coupon = $request['promo_code'] ?? $promoDetail->code ?? '';
        $shop = $promoDetail->shop ?? Store::getStoreUrlFromStoreId($storeId);
        $carrierNameFdo = Functions::fdoSLugForCarriers($carrierSlug);
        $queryParams = http_build_query(['coupon' => $coupon, 'shop' => $shop, 'access_token' => $storeDetails->access_token, 'store_hash' => $storeDetails->hash, 'carriers' => $carrierNameFdo]);
        $endPoint = Endpoints::applyPromoCodeFdoEndpoint() . $queryParams;
        $curlResponse = (new CurlRequest())->enSingleCurlRequest($endPoint, [], [], 'GET');
        $response = json_decode($curlResponse['response'], true);
        Log::info('Fdo Coupon Response of Carrier ' . json_encode($response) . "Endpoint " . json_encode($endPoint));

        if (isset($response['promo'])) {
            Store::where('id', $storeId)->update(['freightdesk_company_id' => $response['fdo_company_id']]);
            CouponCarrier::addOrUpdateCarrierInfo($carrierSlug, $id, $carrierNameFdo, $response);
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
