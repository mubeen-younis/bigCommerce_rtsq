<?php

namespace App\Http\Controllers;

use App\Constants\Constant;

use App\Jobs\ProductWebhookImport;
use App\Models\AccessTokens;
use App\Models\HubSpot;
use App\Models\ProductSetting;
use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Http\Request;
use GuzzleHttp\Psr7;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Bigcommerce\Api\Client as Bigcommerce;
use Illuminate\Support\Facades\Redirect;
use App\Models\Carrier;
use App\Http\Controllers\CarrierController;
use App\Models\Addons;
use App\Http\Controllers\AddonsController;


class MainController extends BaseController
{
    protected $baseURL;

    public function __construct()
    {
        $this->baseURL = env('APP_URL');
    }

    public function getAppClientId()
    {
        if (env('APP_ENV') === 'local') {
            return env('BC_LOCAL_CLIENT_ID');
        } else {
            return env('BC_APP_CLIENT_ID');
        }
    }

    public function getCustAccessTok($storeId)
    {
        $store = Store::where('id', $storeId)->first();
        if (!empty($store)) {
            return $store->access_token;
        }
        return ['status' => false, 'response' => 'Not Found'];
    }

    public function getAppSecret($request)
    {
        if (env('APP_ENV') === 'local') {
            return env('BC_LOCAL_SECRET');
        } else {
            return env('BC_APP_SECRET');
        }
    }


    public function addTestStore(Request $request)
    {

        $storeHash = $request->store_hash ?? null;
        if (blank($storeHash)) {
            return "Store hash is required";
        }
        if (DB::table('test_stores')->where('store_hash', $storeHash)->exists()) {
            return "Test Store hash already exists";
        }
        DB::table('test_stores')->insert(['store_hash' => $storeHash]);
        return "Added Store to DB";

    }

    public function getAccessToken(Request $request)
    {
        if (env('APP_ENV') === 'local') {
            return env('BC_LOCAL_ACCESS_TOKEN');
        } else {
            return AccessTokens::all()->last()->access_token;
//            return $request->session()->get('access_token');
        }
    }

    public function getStoreHash(Request $request)
    {
        return env('BC_LOCAL_STORE_HASH');
//        if (env('APP_ENV') === 'local') {
//            return env('BC_LOCAL_STORE_HASH');
//        } else {
//            return $request->session()->get('store_hash');
//        }
    }


    public function install(Request $request)
    {
        Log::info('BIgCOmmerce INstallation Request' . json_encode($request->all()) . 'CLient ID ' . $this->getAppClientId() .
            'client_secret ' . $this->getAppSecret($request));
        // Make sure all required query params have been passed
        if (!$request->has('code') || !$request->has('scope') || !$request->has('context')) {
            return redirect()->action([MainController::class, 'error'])->with('error_message', 'Not enough information was passed to install this app.');
        }

        try {
            $client = new Client();
            $result = $client->request('POST', 'https://login.bigcommerce.com/oauth2/token', [
                'json' => [
                    'client_id' => $this->getAppClientId(),
                    'client_secret' => $this->getAppSecret($request),
                    'redirect_uri' => $this->baseURL . '/auth/install',
                    'grant_type' => 'authorization_code',
                    'code' => $request->input('code'),
                    'scope' => $request->input('scope'),
                    'context' => $request->input('context'),
                ]
            ]);

            $statusCode = $result->getStatusCode();
            $data = json_decode($result->getBody(), true);
            Log::info('BigCommerce Installation Request Data' . json_encode($data));
            if ($statusCode == 200) {
                $storeHash = explode('/', $data['context']);
                $storeHash = $storeHash[1] ?? $data['context'];
                $toAppendHash = Crypt::encryptString($storeHash);
                $store = Store::where('hash', $storeHash)->first();
                if (empty($store)) {
                    $store = new Store();
                }
                $store->name = 'store-' . $storeHash . '.mybigcommerce.com';
                $store->url = 'store-' . $storeHash . '.mybigcommerce.com';
                $store->access_token = $data['access_token'];
                $store->token = $toAppendHash;
                $store->hash = $storeHash;
                $store->owner_id = $data['user']['id'];
                $store->owner_email = $data['user']['email'];
                $store->app_status = 1;
                $store->save();
                //$accTok = Store::create(['access_token' => $data['access_token'], 'token' => $toAppendHash, 'hash' => $data['context'], 'owner_id' => $data['user']['id'], 'owner_email' => $data['user']['email']]);
                if (!empty($store)) {
                    $this->registerWebHook([
                        'store_id' => $store->id,
                        'store_name' => $store->hash
                    ]);
                    // Install all carriers on app installation
                    $carrierController = new CarrierController();
                    $carriers = optional(Carrier::get())->toArray() ?? [];
                    $carrierController->carriersOnAppInstallation($carriers, $store);
                    // Install all Add-ons on app installation
                    $addonsController = new AddonsController();
                    $addons = optional(Addons::get())->toArray() ?? [];
                    $addonsController->addonsOnAppInstallation($addons, $store);
                }
                /*
                 * Update WS graph data
                 * */
                SaleGraphController::updateGraphData();

                // If the merchant installed the app via an external link, redirect back to the
                // BC installation success page for this app
                if ($request->has('external_install')) {
                    return redirect('https://login.bigcommerce.com/app/' . $this->getAppClientId() . '/install/succeeded');
                }
            }
            return Redirect::to(Constant::FRONTEND_URL . '/?store=' . $toAppendHash);
        } catch (RequestException $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            $errorMessage = "An error occurred.";

            if ($e->hasResponse()) {
                if ($statusCode != 500) {
                    $errorMessage = Psr7\str($e->getResponse());
                }
            }

            // If the merchant installed the app via an external link, redirect back to the
            // BC installation failure page for this app
            if ($request->has('external_install')) {
                return redirect('https://login.bigcommerce.com/app/' . $this->getAppClientId() . '/install/failed');
            }
        }
    }

    public function uninstall(Request $request)
    {
        $signedPayload = $request->input('signed_payload');
        if (!empty($signedPayload)) {
            $verifiedSignedRequestData = $this->verifySignedRequest($signedPayload, $request);
            if ($verifiedSignedRequestData !== null) {
                $storeHash = explode('/', $verifiedSignedRequestData['context']);
                $storeHash = $storeHash[1] ?? $verifiedSignedRequestData['context'];

                //Store::where('hash', $storeHash)->update(['app_status', 0]);
                $store = Store::where('hash', $storeHash)->first();
                $store->app_status = 0;
                $store->save();
                $store = Store::where('hash', $storeHash)->first()->toArray();
                $hubspotData = optional(HubSpot::where('store_id', $store['id'])->first())->toArray() ?? [];
                if(!blank($hubspotData)){
                    $user = ['email' => $hubspotData['email']];
                    $status = ['products_lost' => true];
                    $hubSpotController = new HubSpotController();
                    $hubSpotController->createUpdateHubSpotUser($store['id'], $user, $status);
                }
                /*
                 * Update WS graph data
                 * */
                SaleGraphController::updateGraphData();
            }
        }
        echo 'uninstall';
        return app()->version();
    }


    public function load(Request $request)
    {
        $signedPayload = $request->input('signed_payload');

        if (!empty($signedPayload)) {
            $verifiedSignedRequestData = $this->verifySignedRequest($signedPayload, $request);
            if ($verifiedSignedRequestData !== null) {
                $storeHash = explode('/', $verifiedSignedRequestData['context']);
                $storeHash = $storeHash[1] ?? $verifiedSignedRequestData['context'];
                /*Function for checking time of the token update and getting token from db*/
                $toAppendHash = $this->getAndUpdateToken($storeHash);
                if (Store::where('hash', $verifiedSignedRequestData['store_hash'])->where('is_webhook_created', false)->exists()) {
                    $store = Store::where('hash', $verifiedSignedRequestData['store_hash'])->where('is_webhook_created', false)->first();
                    $this->registerWebHook([
                        'store_id' => $store->id,
                        'store_name' => $store->hash
                    ]);
                }
            } else {
                return Redirect::action([MainController::class, 'error'])->with('error_message', 'The signed request from BigCommerce could not be validated.');
            }
        } else {
            return Redirect::action([MainController::class, 'error'])->with('error_message', 'The signed request from BigCommerce was empty.');
        }
        //header('location: http://bc-fe.eniture-dev3.com/?store='.$toAppendHash);
        //return redirect('/');
        return Redirect::to(Constant::FRONTEND_URL . '/?store=' . $toAppendHash);
    }


    /**
     * @param $storeHash
     * @return mixed|string|null
     * This function is written for a bug of only one person can use app at a particular time
     */
    public function getAndUpdateToken($storeHash)
    {
        $toAppendHash = Crypt::encryptString($storeHash);
        $storeDetail = optional(Store::where('hash', $storeHash)->first())->toArray() ?? [];
        if (blank($storeDetail)) {
            return $toAppendHash;
        }
        $token = $storeDetail['token'] ?? null;
        $updatedAt = $storeDetail['updated_at'] ?? null;
        if (blank($token) || blank($updatedAt)) {
            Store::where('hash', $storeHash)->update(['token' => $toAppendHash]);
            return $toAppendHash;
        }
        if ($this->isTimeToUpdateToken($updatedAt)) {
            Store::where('hash', $storeHash)->update(['token' => $toAppendHash]);
            return $toAppendHash;
        }
        /*
         * Same token will be returned
         * */
        return $token;

    }


    /**
     * @param $updatedAt
     * @return bool
     */
    public function isTimeToUpdateToken($updatedAt): bool
    {
        $t1 = strtotime(now());
        $t2 = strtotime($updatedAt);
        $diff = $t1 - $t2;
        $hours = $diff / (60 * 60);
        if ($hours > 24) {
            return true;
        }
        return false;
    }

    /*   public function registerWebHook($request)
       {
           $webHooks = new WebHooksController();
           $webHooks->registerWebHook($request);
           $webHooks->registerOrderWebHook($request);
           $webHooks->registerSkuWebHook($request);
       }*/


    /**
     * @param $request
     * @return void
     */
    public function registerWebHook($request)
    {
        (new WebHooksController())->registerAllBcWebhooks($request);
    }

    public function error(Request $request)
    {
        $errorMessage = "Internal Application Error";
        if ($request->session()->has('error_message')) {
            $errorMessage = $request->session()->get('error_message');
        }

        echo '<h4>An issue has occurred:</h4> <p>' . $errorMessage . '</p> <a href="' . $this->baseURL . '">Go back to home</a>';
    }

    private function verifySignedRequest($signedRequest, $appRequest)
    {
        list($encodedData, $encodedSignature) = explode('.', $signedRequest, 2);

        // decode the data
        $signature = base64_decode($encodedSignature);
        $jsonStr = base64_decode($encodedData);
        $data = json_decode($jsonStr, true);

        // confirm the signature
        $expectedSignature = hash_hmac('sha256', $jsonStr, $this->getAppSecret($appRequest), $raw = false);
        if (!hash_equals($expectedSignature, $signature)) {
            error_log('Bad signed request from BigCommerce!');
            return null;
        }
        return $data;
    }

    public function makeBigCommerceAPIRequest(Request $request, $endpoint)
    {
        $requestConfig = [
            'headers' => [
                'X-Auth-Client' => $this->getAppClientId(),
                'X-Auth-Token' => $this->getAccessToken($request),
                'Content-Type' => 'application/json',
            ]
        ];

        if ($request->method() === 'PUT') {
            $requestConfig['body'] = $request->getContent();
        }

        $client = new Client();
        return $client->request($request->method(), 'https://api.bigcommerce.com/' . $this->getStoreHash($request) . '/' . $endpoint, $requestConfig);
    }

    public function proxyBigCommerceAPIRequest(Request $request, $endpoint)
    {
        if (strrpos($endpoint, 'v2') !== false) {
            // For v2 endpoints, add a .json to the end of each endpoint, to normalize against the v3 API standards
            $endpoint .= '.json';
        }
        $result = $this->makeBigCommerceAPIRequest($request, $endpoint);
        return response($result->getBody(), $result->getStatusCode())->header('Content-Type', 'application/json');
    }


    public function addAndUpdateProductFromWebHook(Request $request)
    {
        try {
            $postData = file_get_contents("php://input");
            $postData = json_decode($postData, true);
            return $this->productWebhookProcess($postData);

        } catch (\Exception $exception) {
            Log::info('Products data Exception ' . $exception->getMessage());
            return response()->json(true, 200);
        }

    }


    public function productWebhookProcess($postData)
    {
        try {
            $storeHash = explode('/', $postData['producer']);
            $storeHash = $storeHash[1];
            $productId = $postData['data']['id'];
            // Update,delete,create from  webhook
            $scope = $postData['scope'];
            $storeID = Store::where('hash', $storeHash)->first();
            if ($storeID === null || ($storeID->app_status == 0)) {
                return null;
            }
            $toRequest['store_id'] = $storeID->id;
            $toRequest['store_name'] = $storeHash;
            $toRequest['product_id'] = $productId;
            // If product is deleted through webhook
            if ($scope == "store/product/deleted") {
                ProductSetting::where('source_product_id', $productId)->where('store_id', $storeID->id)->delete();
                return true;
            }
            $prodSetCon = new ProductSettingController();
            $prodSetCon->getSingleProductFromApi($toRequest, $scope);
            return response()->json(true, 200);
        } catch (\Exception $exception) {
            Log::info('Products data Exception ' . $exception->getMessage());
        }

    }

    public function rate(Request $request)
    {
        echo 'I am from Webhook';
        exit;
    }

    public function getProducts(Request $request)
    {
        $store = DB::table('access_tokens')->get()->toArray();
        $storeHash = explode('/', $store[0]->store_hash);
        Bigcommerce::configure(array(
            'client_id' => env('BC_APP_CLIENT_ID'),
            'auth_token' => $store[0]->access_token,
            'store_hash' => $storeHash[1]
        ));
        $products = Bigcommerce::getProducts();
        //Debug point added by @Azeem
        echo '$products';
        echo '<pre>';
        print_r($products);
        echo '</pre>';
        exit();
    }

    public function getDom()
    {
        $connectionSettings = [
            [
                'element' => 'Input',
                'attributes' => [ // This array will contain element specific attributes
                    'label' => "Billing Account Number",
                    'type' => "text",
                    'name' => "billing_account_no",
                    // 'required' => true,
                    // 'maxLength' => "1",

                ],
            ],

            [
                'element' => 'Input',
                'attributes' => [ // This array will contain element specific attributes
                    'label' => "Meter Number",
                    'type' => "text",
                    'name' => "meter_number",
                ],
            ],
            [
                'element' => 'Input',
                'attributes' => [ // This array will contain element specific attributes
                    'label' => "Password",
                    'type' => "password",
                    'name' => "password",
                ],
            ],
            [
                'element' => 'Input',
                'attributes' => [ // This array will contain element specific attributes
                    'label' => "Authentication Key",
                    'type' => "text",
                    'name' => "auth_key",
                ],
            ],
            [
                'element' => 'Input',
                'attributes' => [ // This array will contain element specific attributes
                    'label' => "Shipper Account Number",
                    'type' => "text",
                    'name' => "shipper_account_no",
                ],
            ],

            [
                'element' => 'Heading',
                'type' => 4,
                'label' => 'Billing Address'
            ],

            [
                'element' => 'Multiple',
                'count' => 2,
                'attributes' => [ // This array will contain element specific attributes
                    'type' => "text",
                    'name' => "billing_address",
                    'id' => "billing_address",
                    'placeholder' => 'Billing Address'
                ],
                'sub_attributes' => [
                    'type' => "text",
                    'name' => "city",
                    'id' => "city",
                    'placeholder' => 'City'
                ]
            ],
            [
                'element' => 'Multiple',
                'count' => 2,
                'attributes' => [ // This array will contain element specific attributes
                    'type' => "text",
                    'name' => "state",
                    // 'maxlength' => "10",
                    'id' => "state",
                    'placeholder' => 'State e.g.CA'
                ],
                'sub_attributes' => [
                    'type' => "text",
                    'name' => "zip_code",
                    // 'maxlength' => "6",
                    'id' => "zip_code",
                    'placeholder' => 'Zip Code'
                ]
            ],
            [
                'element' => 'Input',
                'attributes' => [ // This array will contain element specific attributes
                    'type' => "text",
                    'name' => "country",
                    // 'maxlength' => "2",
                    'id' => "country",
                    'placeholder' => 'Country e.g. US'
                ]
            ],

            [
                'element' => 'Checkbox',
                'type' => 'checkbox',
                'attributes' => [ // This array will contain element specific attributes
                    'label' => 'Copy billing address to physical address.',
                    'name' => 'physical_address_checked',
                ]
            ],

            [
                'element' => 'Heading',
                'type' => 4,
                'label' => 'Physical Address'
            ],

            [
                'element' => 'Multiple',
                'count' => 2,
                'attributes' => [ // This array will contain element specific attributes
                    'type' => "text",
                    'id' => "physical_address",
                    'placeholder' => 'Physical Address'
                ],
                'sub_attributes' => [
                    'type' => "text",
                    'name' => "city",
                    'id' => "physical_city",
                    'placeholder' => 'City'
                ]
            ],

            [
                'element' => 'Multiple',
                'count' => 2,
                'attributes' => [ // This array will contain element specific attributes
                    'type' => "text",
                    'name' => "physical_state",
                    'id' => "physical_state",
                    'placeholder' => 'State e.g.CA'
                ],
                'sub_attributes' => [
                    'type' => "text",
                    'name' => "physical_zip_code",
                    'id' => "physical_zip_code",
                    'placeholder' => 'Zip Code'
                ]
            ],

            [
                'element' => 'Input',
                'attributes' => [ // This array will contain element specific attributes
                    'type' => "text",
                    'name' => "physical_country",
                    'id' => "physical_country",
                    'placeholder' => 'Country e.g. US'
                ]
            ],

            [
                'element' => 'Input',
                'attributes' => [ // This array will contain element specific attributes
                    'type' => "text",
                    'name' => "third_party_account_no",
                    'label' => 'Third Party Account Number'
                ]
            ],
            [
                'element' => 'Button',
                'label' => 'Test Connection',
                'attributes' => [],
            ],
            [
                'element' => 'Button',
                'label' => 'Save Settings',
                'attributes' => [],
            ]
        ];
        return json_encode($connectionSettings);
    }
}
