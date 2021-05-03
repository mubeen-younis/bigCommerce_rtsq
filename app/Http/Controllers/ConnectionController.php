<?php

namespace App\Http\Controllers;

use App\CustomClasses\UpsLTL\UpsLtlConnectionSettings;
use App\CustomClasses\WweLTL\WweLtlConnectionSettings;
use App\CustomClasses\WWESMALL\SmallConnectionSettings;
use App\Models\Connection;
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

        $checkCarrierType = DB::table('carriers')->leftJoin('installed_carriers', 'carriers.id', 'installed_carriers.carrier_id')
            ->where('installed_carriers.id', $request->carrierId)
            ->first();

        if ($checkCarrierType === null) {
            return response()->json(["error" => true, "data" => [],
                'message' => 'Carrier Not Found']);
        }

        if (!empty($request->testType)) {
            switch ($checkCarrierType->slug) {
                case "ltl-quotes":
                    $response = $this->wweLtlTestCon->testLtlConnection($request);
                    return response()->json($response);
                case "small-package":
                    $response = $this->wweSmallTestCon->testSmallConnection($request);
                    return response()->json($response);
                case 'ups-ltl':
                    $response = $this->upsLtlTestCon->testUpsLtlConnection($request);
                    return response()->json($response);
                default:
                    return response()->json(["error" => true, "data" => [],
                        'message' => 'No carrier Matches']);
            }
        }

        $con = Connection::firstOrNew(['installed_carrier_id' => $request->carrierId]);
        $con->value = json_encode($request->all());
        $con->installed_carrier_id = $request->carrierId;
        $con->save();

        return response()->json(["error" => false, 'message' => "Connection settings has been saved.", "data" => $con]);
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
