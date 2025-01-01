<?php

namespace App\CustomClasses;

use App\Constants\Constant;
use App\CustomClasses\Fedex\ltl\QuotesResults as fedexLtlQuotesResults;
use App\CustomClasses\Fedex\small\QuotesResults as fedexSmallQuotesResults;
use App\CustomClasses\GTZ\ltl\QuotesResults as globalTranzQuotesResults;
use App\CustomClasses\RL\ltl\QuotesResults as rnlLtlQuotesResults;
use App\CustomClasses\WWESMALL\WweSmallQuoteResults;
use App\CustomClasses\Shipping;
use App\CustomClasses\UpsSmall\QuotesResults as upsSmallQuotesResults;
use App\CustomClasses\PurolatorSmall\QuotesResults as purolatorSmallQuotesResults;
use App\CustomClasses\XPO\ltl\QuotesResults as xpoLtlQuotesResults;
use App\CustomClasses\Unishippers\small\QuotesResults as unishippersSmallQuotesResults;
use App\CustomClasses\YrcLTL\QuotesResults as yrcLtlQuotesResults;
use App\CustomClasses\DayRossLTL\QuotesResults as dayRossLtlQuotesResults;
use App\CustomClasses\SaiaLTL\QuotesResults as saiaLtlQuotesResults;
use App\CustomClasses\AbfLtl\QuotesResults as abfLtlQuotesResults;
use App\CustomClasses\UpsLandCostApi\QuotesResults as UPSLandedCostResults;
use App\CustomClasses\TQLLtl\QuotesResults as tqlLtlQuotesResults;
use App\CustomClasses\SouthEasternLtl\QuotesResults as SouthEasternQuotesResults;
use App\CustomClasses\Priority1Ltl\QuotesResults as Priority1QuotesResults;
use App\CustomClasses\UspsSmall\QuotesResults as uspsSmallQuotesResults;
use App\CustomClasses\EchoLogisticsLtl\QuotesResults as echoLogisticsLtlQuotesResults;
use App\CustomClasses\DayLightLtl\QuotesResults as dayLightLtlQuotesResults;
use App\CustomClasses\FreightQuote\ChrLtl\QuotesResults as FQChrQuotesResults;
use App\CustomClasses\FreightQuote\Ltl\QuotesResults as FQQuotesResults;
use App\CustomClasses\EstesLTL\QuotesResults as estesLtlQuotesResults;
use App\CustomClasses\UpsShipEngineSmall\QuotesResults as upsShipEngineSmallQuotesResults;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\ShippingRuleController;


use App\Http\Controllers\RADController;
use App\Models\Locations;
use Carbon\Carbon;
use Facade\Ignition\DumpRecorder\Dump;
use Illuminate\Support\Facades\DB;

class CompileQuotes
{
    /**
     * @var Modulemanager Object
     */
    private $moduleManager;
    /**
     * @var Conn Object
     */
    private $connection;
    /**
     * @var Warehouse Table
     */
    private $WHTableName;
    /**
     * @var ship Config Object
     */
    private $shippingConfig;
    /**
     * @var context
     */
    private $context;
    /**
     * @var bool
     */
    public $canAddWh = 1;
    /**
     * @var Country
     */
    private $warehouseFactory;
    /**
     * @var Curl
     */
    private $curl;

    /**
     * @var Registry
     */
    private $registry;

    /**
     * @var bool
     */
    private $isResi = false;

    public $residential = [];
    private $residentialDelivery;
    private $residentialDlvry;
    /**
     * @var SessionManagerInterface
     */
    public $coreSession;
    /**
     * @var string
     */
    private $resiLabel;
    /**
     * @var string
     */
    private $lgLabel;
    /**
     * @var string
     */
    private $resiLgLabel;
    /**
     * @var Manager
     */
    private $cacheManager;

    public $isMultiShipment = false;
    public $multiOrigins = false;

    private $quoteSettings = [];
    public $carriers = [];

    public $accessorialsIndexes = [
        'simple' => '',
        'liftgate' => '+LG',
        'insideDelivery' => '+ID',
        'notifydelivery' => '+NBD',
        'limitedaccess' => '+LAD',
        'insideLiftGateDelivery' => '+LG+ID',
        'lgnotifydelivery' => '+LG+NBD',
        'limitedaccessLG' => '+LG+LAD',
        'insidenotifydelivery' => '+ID+NBD',
        'laccessinsidedelivery' => '+ID+LAD',
        'laccessnotifydelivery' => '+LAD+NBD',
        'lginsidenotifydelivery' => '+LG+ID+NBD',
        'lglaccessnotifydelivery' => '+LG+LAD+NBD',
        'lglaccessinsidedelivery' => '+LG+ID+LAD',
        'laccessinsideNotifydelivery' => '+ID+LAD+NBD',
        'lglaccessinsideNotifydelivery' => '+LG+ID+LAD+NBD',
        'Truckload' => '+TL',
        'hat' => '+HAT'
    ];

    /*
     * @var configSettings
     * */
    public $configSettings;
    public $returnSingleShip = false;

    private $carrierServices = [];
    private $alwaysResi = false;

    private $isGTZCerasis = false;
    private $isOverrideRates = false;
    private $isSurchargeRates = false;
    private $isPriority1 = false;
    private $isGTZNewApi = false;
    private $isUsNewApi = false;
    public function __construct()
    {
        $this->wweSmallQuoteRes = new WweSmallQuoteResults();
        $this->returnSingleShip = false;
        // $this->upsSmallQuotesResults = new upsSmallQuotesResults();
    }

    /**
     * =======================================================
     * *********** Warehouse & DropShips Section *************
     * =======================================================
     * */

    /**
     * @param string $location
     * @return array
     */
    public function fetchWarehouseSecData(string $location)
    {
        $whCollection = $this->warehouseFactory->create()->getCollection()->addFilter('location', ['eq' => $location]);
        return $this->purifyCollectionData($whCollection);
    }

    /**
     * @param $location
     * @param $warehouseId
     * @return array
     */
    public function fetchWarehouseWithID($location, $warehouseId)
    {
        try {
            $location = Locations::where('id', $warehouseId)->first();
            return $location;
            return json_decode($location->additionals, true);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @param $data
     * @param $whereClause
     * @return int
     */
    public function updateWarehouseData($data, $whereClause)
    {
        return $this->connection->update("$this->WHTableName", $data, "$whereClause");
    }

    /**
     * @param $data
     * @param $id
     * @return array
     */
    public function insertWarehouseData($data, $id)
    {
        $insertQry = $this->connection->insert("$this->WHTableName", $data);
        if ($insertQry == 0) {
            $lastId = $id;
        } else {
            $lastId = $this->connection->lastInsertId();
        }
        return ['insertId' => $insertQry, 'lastId' => $lastId];
    }

    /**
     * @param $data
     * @return int
     */
    public function deleteWarehouseSecData($data)
    {
        try {
            $response = $this->connection->delete("$this->WHTableName", $data);
        } catch (\Throwable $e) {
            $response = 0;
        }
        return $response;
    }

    /**
     * Data Array
     * @param $inputData
     * @return array
     */

    public function originArray($inputData)
    {
        $dataArr = [
            'city' => $inputData['city'],
            'state' => $inputData['state'],
            'zip' => $inputData['zip'],
            'country' => $inputData['country'],
            'location' => $inputData['location'],
            'nickname' => (isset($inputData['nickname'])) ? $inputData['nickname'] : '',
            'in_store' => 'null',
            'local_delivery' => 'null',
        ];
        $plan = $this->planInfo();
        if ($plan['planNumber'] == 3) {
            $suppressOption = ($inputData['ld_sup_rates'] === 'on') ? 1 : 0;
            //if (isset($inputData['instore_enable'])) {
            $pickupDeliveryArr = [
                'enable_store_pickup' => ($inputData['instore_enable'] === 'on') ? 1 : 0,
                'miles_store_pickup' => $inputData['is_within_miles'],
                'match_postal_store_pickup' => $inputData['is_postcode_match'],
                'checkout_desc_store_pickup' => $inputData['is_checkout_descp'],
                'suppress_other' => $suppressOption,
            ];
            $dataArr['in_store'] = json_encode($pickupDeliveryArr);

            //if ($inputData['ld_enable'] === 'on') {
            $localDeliveryArr = [
                'enable_local_delivery' => ($inputData['ld_enable'] === 'on') ? 1 : 0,
                'miles_local_delivery' => $inputData['ld_within_miles'],
                'match_postal_local_delivery' => $inputData['ld_postcode_match'],
                'checkout_desc_local_delivery' => $inputData['ld_checkout_descp'],
                'fee_local_delivery' => $inputData['ld_fee'],
                'suppress_other' => $suppressOption,
            ];
            $dataArr['local_delivery'] = json_encode($localDeliveryArr);
        }
        return $dataArr;
    }

    /**
     *
     * @param array $getWarehouse
     * @param array $validateData
     * @return string
     */
    public function checkUpdateInStorePickupDelivery($getWarehouse, $validateData)
    {
        $update = 'no';

        if (empty($getWarehouse)) {
            return $update;
        }

        $newData = [];
        $oldData = [];

        $getWarehouse = reset($getWarehouse);
        unset($getWarehouse['warehouse_id']);
        unset($getWarehouse['nickname']);
        unset($validateData['nickname']);

        foreach ($getWarehouse as $key => $value) {
            if (empty($value) || is_null($value)) {
                $newData[$key] = 'empty';
            } else {
                $oldData[$key] = trim($value);
            }
        }

        $whData = array_merge($newData, $oldData);
        $diff1 = array_diff($whData, $validateData);
        $diff2 = array_diff($validateData, $whData);

        if ((is_array($diff1) && !empty($diff1)) || (is_array($diff2) && !empty($diff2))) {
            $update = 'yes';
        }
        return $update;
    }

    /**
     * @param $quotesArray
     * @param $inStoreLd
     * @param $allOrigins
     * @return array
     */
    public function inStoreLocalDeliveryQuotes($quotesArray, $inStoreLd, $allOrigins)
    {
        $count = 0;
        foreach ($allOrigins as $array) {
            if ($count == 1) {
                break;
            }
            $count++;
            $warehouseData = $this->getWarehouseData($array);

            /**
             * Quotes array only to be made empty if Suppress other rates is ON and In-store
             *  Pickup or Local Delivery also carries some quotes. Else if In-store Pickup or
             *  Local Delivery does not have any quotes i.e Postal code or within miles does
             *  not match then the Quotes Array should be returned as it is.
             * */
            if (isset($warehouseData['suppress_other']) && $warehouseData['suppress_other']) {
                if (
                    (isset($inStoreLd['inStorePickup']['status']) && $inStoreLd['inStorePickup']['status'] == 1) ||
                    (isset($inStoreLd['localDelivery']['status']) && $inStoreLd['localDelivery']['status'] == 1)
                ) {
                    $quotesArray = [];
                }
            }

            //  Fetching SUppress Status From WS and make quotes array empty
            if (isset($inStoreLd['suppress']) && $inStoreLd['suppress'] == 1) {
                $quotesArray = [];
            }

            if (isset($inStoreLd['inStorePickup']['status']) && $inStoreLd['inStorePickup']['status'] == 1) {
                $title = $warehouseData['inStoreTitle'] ?? '';

                if (isset($inStoreLd['totalDistance']) && $inStoreLd['totalDistance'] > 0 && isset($warehouseData['enable_instore_distance']) && $warehouseData['enable_instore_distance']) {
                    $title .= " | " . $inStoreLd['totalDistance'] . " away";
                }
                if(isset($warehouseData['enable_instore_address']) && $warehouseData['enable_instore_address']){
                    $title .= " | " . $this->getShortStreetAddress($warehouseData['address']) . " " . $warehouseData['senderCity'] . ", " . $warehouseData['senderState'] . ", " . $warehouseData['senderZip'];
                }

                if (isset($array['phone']) && $array['phone'] && isset($warehouseData['enable_instore_phone']) && $warehouseData['enable_instore_phone']) {
                    $title .= " | " . $array['phone'];
                }
                $quotesArray['INSP'][] = [
                    'code' => 'INSP',
                    'rate' => 0,
                    'transitTime' => '',
                    'title' => $title,
                ];
            }

            if (isset($inStoreLd['localDelivery']['status']) && $inStoreLd['localDelivery']['status'] == 1) {
                $quotesArray['LOCDEL'][] = [
                    'code' => 'LOCDEL',
                    'rate' => $warehouseData['fee_local_delivery'] ?? 0,
                    'transitTime' => '',
                    'title' => $warehouseData['locDelTitle'] ?? '',
                ];
            }
        }
        
        return $quotesArray;
    }

    function getShortStreetAddress($address)
    {
        if (!$address) {
            return '';
        }
        if (strlen($address) > 20) {
            $address = substr(trim($address), 0, 17) . '...,';
        } else {
            $address = $address . ',';
        }
        return $address;
    }

    /**
     * @param $data
     * @return array
     */
    public function getWarehouseData($data)
    {
        $return = [];
        $locationDetails = $this->fetchWarehouseWithID($data['location'], $data['locationId']);
        $whCollection = json_decode($locationDetails->additionals, true);
        $return['address'] = $locationDetails->address;
        $return['senderCity'] = $locationDetails->city;
        $return['senderState'] = $locationDetails->state;
        $return['senderZip'] = $locationDetails->zip_code;
        $return['country'] = $locationDetails->country;
        $inStore = $whCollection['instore_pickup_data'] ?? false;
        $locDel = $whCollection['local_delivery_data'] ?? false;

        if ($inStore) {
            $inStoreTitle = $inStore['checkout_description'];
            if (empty($inStoreTitle)) {
                $inStoreTitle = "Instore Pick Up";
            }
            $return['inStoreTitle'] = $inStoreTitle;
            $return['suppress_other'] = isset($whCollection['ld_enable_supress']) && $whCollection['ld_enable_supress'] == true ? true : false;
            $return['enable_instore_distance'] = isset($whCollection['enable_instore_distance']) && $whCollection['enable_instore_distance'] == true ? true : false;
            $return['enable_instore_address'] = isset($whCollection['enable_instore_address']) && $whCollection['enable_instore_address'] == true ? true : false;
            $return['enable_instore_phone'] = isset($whCollection['enable_instore_phone']) && $whCollection['enable_instore_phone'] == true ? true : false;
        }
        if ($locDel) {
            $locDelTitle = $locDel['checkout_description'];
            if (empty($locDelTitle)) {
                $locDelTitle = "Local delivery";
            }
            $return['locDelTitle'] = $locDelTitle;
            $return['fee_local_delivery'] = $locDel['local_delivery_fee'];
            $return['suppress_other'] = $whCollection['ld_enable_supress'] == true ? true : false;
        }
        return $return;
    }

    /**
     * =======================================================
     * ******************** Plans Section ********************
     * =======================================================
     * */

    /**
     * @return string
     */
    public function setPlanNotice()
    {
        $planPackage = $this->planInfo();
        if ($planPackage['storeType'] == '') {
            $planPackage = [];
        }
        return $this->displayPlanMessages($planPackage);
    }

    /**
     * @param $planPackage
     * @return string
     */
    public function displayPlanMessages($planPackage)
    {
        $planMsg = __('Eniture - Worldwide Express LTL Freight Quotes plan subscription is inactive. Please activate plan subscription from <a target="_blank" href="https://eniture.com/magento2-worldwide-express-ltl-freight/">here</a>.');
        if (isset($planPackage) && !empty($planPackage)) {
            if ($planPackage['planNumber'] != null && $planPackage['planNumber'] != -1) {
                $planMsg = __('Eniture - Worldwide Express LTL Freight Quotes is currently on the ' . $planPackage['planName'] . '. Your plan will expire within ' . $planPackage['expireDays'] . ' days and plan renews on ' . $planPackage['expiryDate'] . '.');
            }
        }
        return $planMsg;
    }

    /**
     * @return int
     */
    public function whPlanRestriction()
    {
    }

    /**
     * =======================================================
     * ***************** Validation Section ******************
     * =======================================================
     * */

    /**
     * @param $whCollection
     * @return array
     */
    public function purifyCollectionData($whCollection)
    {
        $warehouseSecData = [];
        foreach ($whCollection as $wh) {
            $warehouseSecData[] = $wh->getData();
        }
        return $warehouseSecData;
    }

    /**
     * validate Input Post
     * @param $sPostData
     * @return mixed
     */
    public function validatedPostData($sPostData)
    {
        $dataArray = ['city', 'state', 'zip', 'country'];
        $data = [];
        foreach ($sPostData as $key => $tag) {
            $preg = '/[#$%@^&_*!()+=\-\[\]\';,.\/{}|":<>?~\\\\]/';
            $check_characters = (in_array($key, $dataArray)) ? preg_match($preg, $tag) : '';

            if ($check_characters != 1) {
                if ($key === 'city' || $key === 'nickname' || $key === 'in_store' || $key === 'local_delivery') {
                    $data[$key] = $tag;
                } else {
                    $data[$key] = preg_replace('/\s+/', '', $tag);
                }
            } else {
                $data[$key] = 'Error';
            }
        }

        return $data;
    }

    /**
     * =======================================================
     * ************ Order detail widget Section **************
     * =======================================================
     * */

    /**
     * @param array $servicesArr
     * @param $hazShipmentArr
     */
    public function setOrderDetailWidgetData(array $servicesArr, $hazShipmentArr)
    {
        $setPkgForOrderDetailReg = $this->registry->registry('setPackageDataForOrderDetail') ?? [];
        $planNumber = $this->planInfo()['planNumber'];

        if ($planNumber > 1 && $setPkgForOrderDetailReg && $hazShipmentArr) {
            foreach ($hazShipmentArr as $origin => $value) {
                foreach ($setPkgForOrderDetailReg[$origin]['item'] as $key => $data) {
                    $setPkgForOrderDetailReg[$origin]['item'][$key]['isHazmatLineItem'] = $value;
                    break;
                }
            }
        }
        $orderDetail['shipmentData'] = array_replace_recursive($setPkgForOrderDetailReg, $servicesArr);

        // set order detail widget data
        $this->coreSession->start();
        $this->coreSession->setOrderDetailSession($orderDetail);
    }

    /**
     * =======================================================
     * ********* Settings and configuration Section **********
     * =======================================================
     * */

    /**
     * setting properties dynamically
     */
    public function quoteSettingsData()
    {
        $fields = [
            'labelAs' => 'labelAs',
            'options' => 'options',
            'ratingMethod' => 'ratingMethod',
            'dlrvyEstimates' => 'dlrvyEstimates',
            'ownArangement' => 'ownArangement',
            'ownArangementText' => 'ownArangementText',
            'residentialDlvry' => 'residentialDlvry',
            'liftGate' => 'liftGate',
            'OfferLiftgateAsAnOption' => 'OfferLiftgateAsAnOption',
            'RADforLiftgate' => 'RADforLiftgate',
            'hndlngFee' => 'hndlngFee',
            'symbolicHndlngFee' => 'symbolicHndlngFee',
        ];
        foreach ($fields as $key => $field) {
            $this->$key = $this->configSettings[$field] ?? '';
        }
        $this->resiLabel = Constant::RESI_LABEL;
        $this->lgLabel = Constant::LIFT_LABEL;
        $this->resiLgLabel = Constant::RESI_LIFT_LABEL;
        $this->LADelLabel = Functions::$limitedAccesDelLabel;
        $this->LimitedAccLGDelLabel = Functions::$limitedAccessLGDelLable;
    }

    /**
     * @param $confPath
     * @return mixed
     */
    public function getConfigData($confPath)
    {
        return $this->scopeConfig->getValue($confPath, ScopeInterface::SCOPE_STORE);
    }

    /**
     * This function send request and return response
     * $isAssocArray Parameter When TRUE, then returned objects will
     * be converted into associative arrays, otherwise its an object
     * @param string $url
     * @param array $postData
     * @param bool $isAssocArray
     * @return object|array
     */
    public function sendCurlRequest($url, $postData, $isAssocArray = false)
    {
        $fieldString = http_build_query($postData);
        try {
            $this->curl->post($url, $fieldString);
            $output = $this->curl->getBody();
            $result = json_decode($output, $isAssocArray);
        } catch (\Throwable $e) {
            $result = [];
        }
        return $result;
    }

    /**
     * =======================================================
     * ******************** RAD Section **********************
     * =======================================================
     * */

    /**
     * @param string $resi
     * @return string
     */
    public function getAutoResidentialTitle($resi)
    {
        // Todo: check RAD is enabled or not
        $isRadEnabled = $this->isRADEnabledandActive();

        if (!empty($isRadEnabled) && $isRadEnabled['is_enabled'] && $isRadEnabled['is_suspend'] !== 1) {
            $isRadSuspend = $isRadEnabled['is_suspend'] == 0 ? 'no' : ''; //$this->getConfigData("resaddressdetection/suspend/value");
            if ($this->residentialDlvry == "1") {
                $this->residentialDlvry = $isRadSuspend == "no" ? '0' : '1';
            } else {
                $this->residentialDlvry = $isRadSuspend == "no" ? '0' : $this->residentialDlvry;
            }
            $resi = 'r';
            if ($this->residentialDlvry == null || $this->residentialDlvry == '0') {
                if ($resi == 'r') {
                    $this->isResi = true;
                }
            }
        }
    }

    public function isRADEnabledandActive()
    {
        $quoteSettings = $this->quoteSettings;
        $installed_addon = (array) DB::table('installed_carriers')->where('installed_carriers.id', $quoteSettings['carrierId'])
            ->Join('installed_addons', 'installed_addons.store_id', '=', 'installed_carriers.store_id')->Join('stores', 'stores.id', '=', 'installed_carriers.store_id')->select('installed_addons.is_enabled', 'installed_addons.is_suspend', 'installed_addons.store_id', 'stores.name')->first();
        if (empty($installed_addon)) {
            return [];
        }
        $RADController = new RADController();
        $request = new \Illuminate\Http\Request();
        $request->store_id = $installed_addon['store_id'];
        $request->store_name = $installed_addon['name'];
        $RADplan = $RADController->getPlans($request)->original['data']['current_plan'];

        $now = Carbon::createFromFormat('Y-d-m H:i:s', now());
        if (isset($RADplan->status->subscriptionInfo->expiryTime)) {
            $expiry = Carbon::createFromFormat('Y-d-m H:i:s', $RADplan->status->subscriptionInfo->expiryTime);

            $isRadNotActive = $RADplan->severity !== 'SUCCESS' || $now->gt($expiry) || $RADplan->status->subscriptionInfo->subscriptionStatus != 1;
        } else {
            $isRadNotActive = true;
        }

        if ($isRadNotActive) {
            return [];
        } else {
            return [
                'rad' => 1,
                'is_enabled' => $installed_addon['is_enabled'],
                'is_suspend' => $installed_addon['is_suspend'],
            ];
        }
    }
    /**
     * =======================================================
     * ***************** Get Quotes Section ******************
     * =======================================================
     * */

    /**
     * @param $quotes
     * @param $quoteSettings
     * @param $allOrigins
     * @return array
     *
     * @info: This function will compile all quotes according to the origin.
     * After getting from quotes almost all type of compilation happened in this function
     */
    public function newGetQuotesResults(
        $quotes,
        $connectionSettings,
        $allOrigins,
        $isHazmat,
        $smalLtlHazmat,
        $hazmatAllItems,
        $residential,
        $freeRNL,
        $destination,
        $items,
        $SuppressParcelRates,
        $store_id,
        $totalHazmatBoxes,
    ) {
        $this->residential = $residential;
        $this->items = $items;
        $this->allOrigins = $allOrigins;
        $this->SuppressParcelRates = $SuppressParcelRates;
        $this->storeId = $store_id;
        $this->totalHazmatBoxes = $totalHazmatBoxes;
        if ($quotes == null) {
            return [];
        }

        $quotesRes = [];
        $quotesTemp = [];
        $quotes = $this->filterShipmentsWithError($quotes);
        $this->shippingRule = new ShippingRuleController();
        foreach ($quotes as $key => $shipment) {
            $this->carrierName = $key;
            $this->multiOrigins = $this->carriers[$key]['shipmentsCount'] > 1 ? true : false;
            switch ($key) {
                case "wweLTL":
                    $resp = $this->compileWweLtlQuotes($shipment, $connectionSettings, $allOrigins);
                    $quotesTemp['wweLTL'] = $resp;
                    if ((!empty($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (isset($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (!isset($resp['multiShipmentQuotes']) && !empty($resp))) {
                        $quotesRes = array_merge($quotesRes, $resp);
                    }
                    break;
                case "wweLTLN":
                    if(isset($this->residential['gtzLtl'])){
                        $resp = $this->compileGtzNewApiQuotes($shipment, $connectionSettings, $allOrigins);
                        $quotesTemp['wweLTLN'] = $resp;
                    } 
                    if(isset($this->residential['uniLtl'])){
                        $resp = $this->compileunishipperNewApiQuotes($shipment, $connectionSettings, $allOrigins);
                        $quotesTemp['uniLTL'] = $resp;
                    }
                    if ((!empty($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (isset($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (!isset($resp['multiShipmentQuotes']) && !empty($resp))) {
                        $quotesRes = array_merge($quotesRes, $resp);
                    }
                    break;
                case "wweSmall":
                    $resp = $this->compileWweSmallQuotes($shipment, $connectionSettings, $allOrigins, $isHazmat, $smalLtlHazmat, $hazmatAllItems);
                    $this->returnSingleShip = $this->multiOrigins && empty($resp['multiShipmentQuotes']) && empty($resp['checkoutQuotes']) ? true : false;
                    $quotesTemp['wweSmall'] = $resp;
                    if ((!empty($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (isset($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (!isset($resp['multiShipmentQuotes']) && !empty($resp))) {
                        //$quotesRes['wwe'] = $quotesRes['wwe'] ?? [];
                        $quotesRes = array_merge($quotesRes, $resp);
                    }
                    break;
                case "wweSmallN":
                    $resp = $this->compileUnishipSmallNewApiQuotes($shipment, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential);
                    $this->returnSingleShip = $this->multiOrigins && empty($resp['multiShipmentQuotes']) && empty($resp['checkoutQuotes']) ? true : false;
                    $quotesTemp['wweSmallN'] = $resp;
                    if ((!empty($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (isset($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (!isset($resp['multiShipmentQuotes']) && !empty($resp))) {
                        $quotesRes = array_merge($quotesRes, $resp);
                    }
                    break;
                case "upsLTL":
                    $resp = $this->compileUpsLtlQuotes($shipment, $connectionSettings, $allOrigins);
                    $quotesTemp['upsLTL'] = $resp;
                    if ((!empty($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (isset($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (!isset($resp['multiShipmentQuotes']) && !empty($resp))) {
                        $quotesRes = array_merge($quotesRes, $resp);
                    }
                    break;
                case "upsSmall":
                    $resp = $this->compileUpsSmallQuotes($shipment, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential);
                    $this->returnSingleShip = $this->multiOrigins && empty($resp['multiShipmentQuotes']) && empty($resp['checkoutQuotes']) ? true : false;
                    $quotesTemp['upsSmall'] = $resp;
                    if ((!empty($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (isset($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (!isset($resp['multiShipmentQuotes']) && !empty($resp))) {
                        $quotesRes = array_merge($quotesRes, $resp);
                    }
                    break;
                case "shipEngine":
                    $resp = $this->compileUpsShipEngineQuotes($shipment, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential);
                    $this->returnSingleShip = $this->multiOrigins && empty($resp['multiShipmentQuotes']) && empty($resp['checkoutQuotes']) ? true : false;
                    $quotesTemp['shipEngine'] = $resp;
                    if ((!empty($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (isset($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (!isset($resp['multiShipmentQuotes']) && !empty($resp))) {
                        $quotesRes = array_merge($quotesRes, $resp);
                    }
                    break;
                case "fedexLTL":
                    $resp = $this->compileFedexLtlQuotes($shipment, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential);
                    $quotesTemp['fedexLTL'] = $resp;
                    if ((!empty($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (isset($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (!isset($resp['multiShipmentQuotes']) && !empty($resp))) {
                        $quotesRes = array_merge($quotesRes, $resp);
                    }
                    break;

                case "fedexSmall":
                    $resp = $this->compileFedexSmallQuotes($shipment, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential, $destination);
                    $this->returnSingleShip = $this->multiOrigins && empty($resp['multiShipmentQuotes']) && empty($resp['checkoutQuotes']) ? true : false;
                    $quotesTemp['fedexSmall'] = $resp;
                    if ((!empty($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (isset($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (!isset($resp['multiShipmentQuotes']) && !empty($resp))) {
                        $quotesRes = array_merge($quotesRes, $resp);
                    }
                    break;

                case "globalTranz":
                    $resp = $this->compileGlobalTranzLtlQuotes($shipment, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential);
                    $quotesTemp['globalTranz'] = $resp;
                    if ((!empty($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (isset($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (!isset($resp['multiShipmentQuotes']) && !empty($resp))) {
                        $quotesRes = array_merge($quotesRes, $resp);
                    }
                    break;
                case "cerasis":
                    $resp = $this->compileCerasisLtlQuotes($shipment, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential);
                    $quotesTemp['cerasis'] = $resp;
                    if ((!empty($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (isset($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (!isset($resp['multiShipmentQuotes']) && !empty($resp))) {
                        $quotesRes = array_merge($quotesRes, $resp);
                    }
                    break;
                case "xpoLogistics":
                    $resp = $this->compileXPOLtlQuotes($shipment, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential);
                    $quotesTemp['xpoLTL'] = $resp;
                    if ((!empty($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (isset($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (!isset($resp['multiShipmentQuotes']) && !empty($resp))) {
                        $quotesRes = array_merge($quotesRes, $resp);
                    }
                    break;
                case "rnl":
                    $resp = $this->compileRNLLtlQuotes($shipment, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential, $freeRNL);
                    $quotesTemp['rnlLTL'] = $resp;
                    if ((!empty($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (isset($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (!isset($resp['multiShipmentQuotes']) && !empty($resp))) {
                        $quotesRes = array_merge($quotesRes, $resp);
                    }
                    break;
                case "unishippersSmall":
                    $resp = $this->compileUnishippersSmallQuotes($shipment, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential);
                    $this->returnSingleShip = $this->multiOrigins && empty($resp['multiShipmentQuotes']) && empty($resp['checkoutQuotes']) ? true : false;
                    $quotesTemp['unishippersSmall'] = $resp;
                    if ((!empty($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (isset($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (!isset($resp['multiShipmentQuotes']) && !empty($resp))) {
                        $quotesRes = array_merge($quotesRes, $resp);
                    }
                    break;
                case 'yrc':
                    $resp = $this->compileYRCLtlQuotes($shipment, $connectionSettings, $allOrigins, $residential);
                    $quotesTemp['yrc'] = $resp;
                    if ((!empty($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (isset($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (!isset($resp['multiShipmentQuotes']) && !empty($resp))) {
                        $quotesRes = array_merge($quotesRes, $resp);
                    }
                    break;
                case 'freightQuote':
                    $resp = $this->compileFreightQuoteLtlQuotes($shipment, $connectionSettings, $allOrigins);
                    $quotesTemp['freightQuote'] = $resp;
                    if ((!empty($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (isset($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (!isset($resp['multiShipmentQuotes']) && !empty($resp))) {
                        $quotesRes = array_merge($quotesRes, $resp);
                    }
                    break;
                case "estes":
                    $resp = $this->compileEstesltlQuotes($shipment, $connectionSettings, $allOrigins);
                    $quotesTemp['estesLtl'] = $resp;
                    if ((!empty($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (isset($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (!isset($resp['multiShipmentQuotes']) && !empty($resp))) {
                        $quotesRes = array_merge($quotesRes, $resp);
                    }
                    break;
                case "dayross":
                    $resp = $this->compileDayRossLtlQuotes($shipment, $connectionSettings, $allOrigins, $hazmatAllItems, $residential);
                    $quotesTemp['dayross'] = $resp;
                    if ((!empty($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (isset($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (!isset($resp['multiShipmentQuotes']) && !empty($resp))) {
                        $quotesRes = array_merge($quotesRes, $resp);
                    }
                    break;
                case "odfl4me":
                    $resp = $this->compileOdflLtlQuotes($shipment, $connectionSettings, $allOrigins);
                    $quotesTemp['OdflLTL'] = $resp;
                    if ((!empty($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (isset($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (!isset($resp['multiShipmentQuotes']) && !empty($resp))) {
                        $quotesRes = array_merge($quotesRes, $resp);
                    }
                    break;
                case 'saia':
                    $resp = $this->compileSaiaLtlQuotes($shipment, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential);
                    $quotesTemp['saia'] = $resp;
                    if ((!empty($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (isset($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (!isset($resp['multiShipmentQuotes']) && !empty($resp))) {
                        $quotesRes = array_merge($quotesRes, $resp);
                    }
                    break;
                case "purolator":
                    $resp = $this->compilePurolatorSmallQuotes($shipment, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential);
                    $this->returnSingleShip = $this->multiOrigins && empty($resp['multiShipmentQuotes']) && empty($resp['checkoutQuotes']) ? true : false;
                    $quotesTemp['purolator'] = $resp;
                    if ((!empty($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (isset($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (!isset($resp['multiShipmentQuotes']) && !empty($resp))) {
                        $quotesRes = array_merge($quotesRes, $resp);
                    }
                    break;
                case 'abf':
                    $resp = $this->compileABFLtlQuotes($shipment, $connectionSettings, $allOrigins, $residential);
                    $quotesTemp['abf'] = $resp;
                    if ((!empty($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (isset($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (!isset($resp['multiShipmentQuotes']) && !empty($resp))) {
                        $quotesRes = array_merge($quotesRes, $resp);
                    }
                    break;
                case 'southeastern':
                    $resp = $this->compileSouthEasternQuotes($shipment, $connectionSettings, $allOrigins, $residential);
                    $quotesTemp['southeastern'] = $resp;
                    if ((!empty($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (isset($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (!isset($resp['multiShipmentQuotes']) && !empty($resp))) {
                        $quotesRes = array_merge($quotesRes, $resp);
                    }
                    break;
                case 'usps':
                    $resp = $this->compileUspsSmallQuotes($shipment, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential);
                    $this->returnSingleShip = $this->multiOrigins && empty($resp['multiShipmentQuotes']) && empty($resp['checkoutQuotes']) ? true : false;
                    $quotesTemp['usps'] = $resp;
                    if ((!empty($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (isset($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (!isset($resp['multiShipmentQuotes']) && !empty($resp))) {
                        $quotesRes = array_merge($quotesRes, $resp);
                    }
                    break;
                case "tql":
                    $resp = $this->compileTqlLtlQuotes($shipment, $connectionSettings, $allOrigins);
                    $quotesTemp['tql'] = $resp;
                    if ((!empty($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (isset($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (!isset($resp['multiShipmentQuotes']) && !empty($resp))) {
                        $quotesRes = array_merge($quotesRes, $resp);
                    }
                    break;
                case 'echoLogistics':
                    $resp = $this->compileEchoLogisticsLtlQuotes($shipment, $connectionSettings, $allOrigins, $hazmatAllItems, $residential);
                    $quotesTemp['echoLogistics'] = $resp;
                    if ((!empty($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (isset($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (!isset($resp['multiShipmentQuotes']) && !empty($resp))) {
                        $quotesRes = array_merge($quotesRes, $resp);
                    }
                    break;
                case 'daylight':
                    $resp = $this->compileDayLightLtlQuotes($shipment, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential);
                    $quotesTemp['daylight'] = $resp;
                    if ((!empty($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (isset($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (!isset($resp['multiShipmentQuotes']) && !empty($resp))) {
                        $quotesRes = array_merge($quotesRes, $resp);
                    }
                    break;
                case 'chr':
                    $resp = $this->compileFreightQuoteChrLtlQuotes($shipment, $connectionSettings, $allOrigins);
                    $quotesTemp['chr'] = $resp;
                    if ((!empty($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (isset($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (!isset($resp['multiShipmentQuotes']) && !empty($resp))) {
                        $quotesRes = array_merge($quotesRes, $resp);
                    }
                    break;
                case 'priority1':
                    $resp = $this->compilePriority1LtlQuotes($shipment, $connectionSettings, $allOrigins);
                    $quotesTemp['priority1'] = $resp;
                    if ((!empty($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (isset($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (!isset($resp['multiShipmentQuotes']) && !empty($resp))) {
                        $quotesRes = array_merge($quotesRes, $resp);
                    }
                    break;
                case 'UPSLandedCost':
                    $resp = $this->compileUPSLandedCostQuotes($shipment, $connectionSettings, $allOrigins);
                    $quotesTemp['UPSLandedCost'] = $resp;
                    if ((!empty($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (isset($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (!isset($resp['multiShipmentQuotes']) && !empty($resp))) {
                        $quotesRes = array_merge($quotesRes, $resp);
                    }
                    break;
            }
        }
// dump($quotesTemp);
        $quotesRes = $this->handleMultiCarriersResp($quotesTemp);

        if (isset($quotesRes['multiShipmentQuotes']) && !empty($quotesRes['multiShipmentQuotes']) && isset($quotesRes['checkoutQuotes']) && !empty($quotesRes['checkoutQuotes'])) {
            $quotesRes = Functions::addUpCheapestQuotes($quotesRes, $this->storeId);
        }
// dd($quotesRes);
        return $quotesRes;
    }

    private function handleMultiCarriersResp($carrierQuotes)
    {
        $newArr = $cheapestArr = $finalQuotesArr = [];
        foreach ($carrierQuotes as $car => $originQuotes) {
            foreach($originQuotes as $locId => $combinationQuotes){
                foreach ($combinationQuotes as $key => $quotes) {
                    foreach($quotes as $quote){
                        $newArr[$locId][$key][] = $quote;
                    }
                }
            }
        }

        if(!empty($newArr) && count($newArr) == 1){
            $finalSingleShipQuotes = [];
            foreach($newArr as $quotes){
                foreach($quotes as $quote){
                    $finalSingleShipQuotes = array_merge($finalSingleShipQuotes, $quote) ?? [];
                }
            }
            return array_values($finalSingleShipQuotes);
        }


        foreach($newArr as $locId => $quotes){
            $indexesCount = count($quotes); $count = 0;
            foreach(Functions::getEnableFeaturesArr() as $key => $value){
                if(isset($quotes[$key])){
                    $quote = $this->getCheapestQuotes($quotes[$key]);
                    $cheapestArr[$locId][$key] = $quote;
                    $count++;
                }
                if($indexesCount == $count){
                    break;
                }
            }
        }

        $allOrigins = collect($this->allOrigins)->unique('locationId')->toArray();

        if(count($allOrigins) == count($cheapestArr)){
            $finalQuotesArr = $this->finalMultiShipmentResp($cheapestArr) ?? [];
        }
        
        return $finalQuotesArr ?? [];

    }

    private function getCheapestQuotes($quotes): array
    {
        if (isset($quotes) && !empty($quotes)) {
            $minRate = min(array_column($quotes, 'rate'));
            foreach ($quotes as $quote) {
                if ($quote['rate'] == $minRate) {
                    return $quote;
                }
            }
        }
    }

    public function finalMultiShipmentResp($locations)
    {


        // Final array to hold the combined sums for each rate type
        $finalArray = $finalCheckoutResp = $multiShipmentArr = [];
        
        // First, find all rate types across all locations
        $allRateTypes = [];

        $isLTL =  false;
        
        // Loop through each location and gather all rate types
        foreach ($locations as $locationId => $types) {
            // dd($locations);
            foreach ($types as $rateType => $rateData) {
                // Add the rate type to the allRateTypes array if it's not already added
                if (!in_array($rateType, $allRateTypes)) {
                    $allRateTypes[] = $rateType;
                }
            }
        }

        // Now, for each rate type, check if all locations have it and sum the rates
        foreach ($allRateTypes as $rateType) {
            $allHaveRateType = true; // Flag to check if all locations have this rate type
            $totalRate = 0;
        
            // Loop through each location to check if it has this rate type
            foreach ($locations as $locationId => $types) {
                // If the location does not have the current rate type, skip this type
                if (!isset($types[$rateType])) {
                    $allHaveRateType = false;
                    break; // Exit early if one location is missing this rate type
                }
        
                // Add the rate to the total if the location has the rate type
                $totalRate += $types[$rateType]['rate'];
                $arr[$rateType][$locationId] = $types[$rateType];

                // To check that LTL shipment exist or not
                if(isset($types[$rateType]['code']) && (strpos($types[$rateType]['code'], 'ltl') != false) && substr($types[$rateType]['code'], 0, 9) != 'parcel_12'){
                    $isLTL = true;
                }
            }

            $accessorials = Functions::getEnabledAccessorials($rateType);

            $title = $this->getTitle(Functions::$ltlMultiTitle, $accessorials['isLG'], true, '', [], [], $accessorials['isID'], $accessorials['isLAD'], false, $accessorials['isTMD'], $accessorials['isAPD'], false, $accessorials['isNBD'], $this->isResi);

            if($rateType == 'Truckload'){
                $title = $title . ' w/ truckload delivery';
            }

            if ($rateType == 'hat') {
                $hatLabel = explode('|', $types[$rateType]['title']);
                unset($hatLabel[0]);
                $title = Functions::$ltlMultiTitle . ' |' . implode('|', $hatLabel);
            }

            $resi = $this->isResi && $rateType !== 'hat' ? '+R' : '';

            // If all locations have this rate type, add the total rate to the final array
            if ($allHaveRateType) {
                $finalArray['code'] = 'Multi' . $resi . $this->accessorialsIndexes[$rateType];
                $finalArray['rate'] = $totalRate;
                $finalArray['title'] = $isLTL ? $title : Functions::$smallMultiTitle;
                $finalCheckoutResp['checkoutQuotes'][] = $finalArray;
                $multiShipmentArr['multiShipmentQuotes'][] = $arr;
            }
        }
        
        // return the final array
        return array_merge($finalCheckoutResp, $multiShipmentArr);
        
    }

    private function filterShipmentsWithError($quotes)
    {
        $errorMsgs = [Functions::$ltlErrorMessage, Functions::$smallErrorMessage];
        $newQuotes = $quotes ?? [];

        foreach ($newQuotes as $carrier => $shipments) {
            $this->carriers[$carrier]['shipmentsCount'] = count($shipments) ?? 0;
            foreach ($shipments as $locId => $quote) {
                if (isset($quote['severity']) && $quote['severity'] == 'ERROR' && isset($quote['Message']) && in_array($quote['Message'], $errorMsgs)) {
                    unset($newQuotes[$carrier][$locId]);
                }
            }
        }

        return $newQuotes;
    }

    private function handleMultiCarrResp($quotes)
    {
        $newQuotes = [];
        $quotes = array_filter($quotes);
        $ownArrangement = [];
        $shipping = new Shipping();

        if ($this->isMultiShipment) {
            $newQuotes['checkoutQuotes'] = $newQuotes['multiShipmentQuotes'] = [];
            foreach ($quotes as $car => $quote) {
                if (isset($quote['checkoutQuotes'])) {
                    foreach ($quote['checkoutQuotes'] as $key => $quot) {

                        if ($quot['code'] !== 'own_arrangement') {
                            array_push($newQuotes['checkoutQuotes'], $quot);
                            array_push($newQuotes['multiShipmentQuotes'], $quote['multiShipmentQuotes']);
                        } else {
                            $ownArrangement = $quot;
                        }
                    }
                } else {
                    $ownArrangement = $quote[0];
                }
            }
            if (!empty($ownArrangement) && !array_search('own_arrangement', array_column($newQuotes['checkoutQuotes'], 'code'))) {
                array_push($newQuotes['checkoutQuotes'], $ownArrangement);
            }
            if (empty($newQuotes['multiShipmentQuotes']) || empty($newQuotes['checkoutQuotes'])) {
                return [];
            }
        } else {
            $outputArray = [];
            foreach ($quotes as $car => $quote) {
                unset($quote['checkoutQuotes']);
                foreach ($quote as $key => $quot) {
                    if($this->returnSingleShip && $key == 'multiShipmentQuotes'){
                        foreach ($quot as $key => $value) {
                            // Get the first element from the nested array
                            $outputArray[$key] = reset($value);
                        }
                        continue;
                    }
                    $newQuotes[] = $quot;
                }
            }
            $newQuotes = array_values(array_merge($newQuotes, $outputArray));
        }

        return $newQuotes;
    }

    public function applyOverrideRatesRule($connectionSettings, $data){
        $this->isOverrideRates = false;
        $overrideRatesData = $this->shippingRule->overrideRates($this->storeId, $this->items, $connectionSettings, $data, $this->carrierName, $this->originKey, $this->allOrigins);
        if(isset($overrideRatesData['isOverrideRates']) && $overrideRatesData['isOverrideRates']){
            $data = $overrideRatesData['data'] ?? [];
            $this->isOverrideRates = $overrideRatesData['isOverrideRates'];
        }

        return $data;
    }

    public function applySurchargeRatesRule($connectionSettings, $data){
        $surchargeRatesData = $this->shippingRule->surchargeRates($this->storeId, $this->items, $connectionSettings, $data, $this->carrierName, $this->originKey, $this->allOrigins);

        if(isset($surchargeRatesData['isSurchargeRates']) && $surchargeRatesData['isSurchargeRates']){
            $data = $surchargeRatesData['data'] ?? [];
            $this->isSurchargeRates = $surchargeRatesData['isSurchargeRates'];
        }
        return $data;
    }

    public function compileWweLtlQuotes($shipments, $connectionSettings, $allOrigins)
    {
        if ($this->residential['wweLtl'] == 'Y') {
            $this->isResi = true;
            $this->residentialDlvry = 1;
        } else {
            $this->isResi = false;
            $this->residentialDlvry = 0;
        }
        $this->alwaysResi = $this->residential['alwaysResi']['wweLtl'] ?? false;
        $this->quoteSettings = $connectionSettings['ltl-quotes']['quote_settings'] ?? [];
        $allConfigServices = $connectionSettings['ltl-quotes']['carrier_services'] ?? [];
        $this->quoteSettingsData();
        $allQuotes = $odwArr = $hazShipmentArr = $multiShipmentQuotes = $originQuotes = [];
        $count = 0;
        $lgQuotes = $allowOwnArrangement = false;
        $numberOfShipments = 0;
        foreach ($shipments as $ship) {
            if (!isset($ship['severity'])) {
                $numberOfShipments++;
            }
        }
        if (!$this->isMultiShipment) {
            $this->isMultiShipment = is_countable($shipments) && $numberOfShipments > 1;
        }

        foreach ($shipments as $origin => $quote) {
            $this->originKey = $origin;
            if (isset($quote['severity'])) {
                return $instoreLocDelQuotes = $this->getInsPicAndLocDelQuotes($quote, $allOrigins);
            }

            $resiPickup = $lgPickup = '';
            if ($count == 0) { //To be checked only once
                $isRad = $quote['autoResidentialsStatus'] ?? '';
                $inStoreLdData = $quote['InstorPickupLocalDelivery'] ?? false;
                unset($quote['InstorPickupLocalDelivery']);

                $lgQuotes =
                    (isset($this->quoteSettings['alwaysLiftGateDelivery']) && $this->quoteSettings['alwaysLiftGateDelivery']) ||
                    (isset($this->quoteSettings['offerLiftGateDelivery']) && $this->quoteSettings['offerLiftGateDelivery']);
                if (!$lgQuotes) {
                    $lgQuotes = (isset($this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->isResi;
                }
                $resiPickup = isset($this->quoteSettings['residentialPickup']) && $this->quoteSettings['residentialPickup'] ? '+pu' : '';
                $insideDelivery = (isset($this->quoteSettings['offer_inside_delivery']) && $this->quoteSettings['offer_inside_delivery']) ||
                    (isset($this->quoteSettings['always_inside_delivery']) && $this->quoteSettings['always_inside_delivery']);
                $lgPickup = isset($this->quoteSettings['liftGatePickup']) && $this->quoteSettings['liftGatePickup'] ? '+lfgp' : '';

                $notifyDelivery =
                    (isset($this->quoteSettings['always_quote_notify']) && $this->quoteSettings['always_quote_notify']) ||
                    (isset($this->quoteSettings['offer_notify_as_option']) && $this->quoteSettings['offer_notify_as_option']);

                $limitedAccess =
                    (isset($this->quoteSettings['offer_limited_access_delivery']) && $this->quoteSettings['offer_limited_access_delivery']) ||
                    (isset($this->quoteSettings['always_limited_access_delivery']) && $this->quoteSettings['always_limited_access_delivery']) ?? false;
            }
            
            $arraySorting = [];
            if (isset($quote['q'])) {
                if (isset($quote['hazardousStatus'])) {
                    $hazShipmentArr[$origin] = $quote['hazardousStatus'] == 'y' ? 'Y' : 'N';
                }
                $allowOwnArrangement = isset($quote['allowOwnArrangement']) && $quote['allowOwnArrangement'] ?? false;
                foreach ($quote['q'] as $key => $data) {

                    if (isset($data['serviceType']) && in_array($data['serviceType'], $allConfigServices) && isset($data['GuaranteedDaysToDelivery']) && $data['GuaranteedDaysToDelivery'] != 'Y') {
                        if ($limitedAccess && isset($this->quoteSettings['limited_access_fee'])) {
                            $data['totalNetCharge']['Amount'] += $this->quoteSettings['limited_access_fee'];
                            $data['surcharges']['limitedAccessDeliveryFee'] = (float) $this->quoteSettings['limited_access_fee'];
                        }
                        
                        // Check : if API not return surcharge rates or array
                        if(!isset($data['surcharges'])){
                            $data['surcharges'] = [
                                'liftgateFee' => 0,
                                'limitedAccessDeliveryFee' => 0,
                                'notifyDeliveryFee' => 0,
                                'insideDeliveryFee' => 0,
                            ];
                        }
                        // Apply Override rates shipping rule
                        $data = $this->applyOverrideRatesRule($connectionSettings, $data);
                        // Apply Surcharge rates shipping rule
                        //$data = $this->applySurchargeRatesRule($connectionSettings, $data);
                        
                        $issetLiftgateFee = isset($data['surcharges']['liftgateFee']) && !empty($data['surcharges']['liftgateFee']);
                        $issetLimitedFee = isset($data['surcharges']['limitedAccessDeliveryFee']) && !empty($data['surcharges']['limitedAccessDeliveryFee']);
                        $issetNotifyFee = isset($data['surcharges']['notifyDeliveryFee']) && !empty($data['surcharges']['notifyDeliveryFee']);
                        $issetInsideFee = isset($data['surcharges']['insideDeliveryFee']) && !empty($data['surcharges']['insideDeliveryFee']);

                        /*
                         * Date 01-07-22
                         * Adding Functionality of Delivery Estimate Options
                         * */
                        $date = $data['deliveryTimestamp'] ?? null;
                        $days = $data['totalTransitTimeInDays'] ?? null;
                        $dateAndDays = ['deliveryDate' => $date, 'totalTransitTimeInDays' => $days];
                        $enableFeaturesArray = Functions::getEnableFeaturesArr($lgQuotes && $issetLiftgateFee, $insideDelivery && $issetInsideFee, $notifyDelivery && $issetNotifyFee, $limitedAccess && $issetLimitedFee);
                        foreach ($enableFeaturesArray as $index => $feature) {
                            if ($feature['isEnable']) {
                                $compileNotifyDeliveryQuotes = Functions::getOriginQuotes(
                                    $index, $data['serviceDesc'],
                                    $originQuotes,
                                    $data,
                                    $origin,
                                    $key, $data['totalTransitTimeInDays'],
                                    $dateAndDays, $feature['index']['isLG'] ?? false,
                                    "wweltl", $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings,
                                    $this->isResi, $this->alwaysResi, $feature['index']['isID'] ?? false, $feature['index']['isLAD'] ?? false, $feature['index']['isNBD'] ?? false,
                                    $resiPickup,
                                    $lgPickup,
                                    $this->storeId,
                                );

                                $arraySorting[$index][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                                $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                            }
                        }
                    }
                }
            }

            $compiledQuotes = $this->getCompiledQuotes($originQuotes, $arraySorting, $lgQuotes, $resiPickup, $lgPickup, $insideDelivery, $notifyDelivery, $limitedAccess);
            
            if (isset($inStoreLdData) && !empty($inStoreLdData)) {
                $compiledQuotes = $this->inStoreLocalDeliveryQuotes($compiledQuotes, $inStoreLdData, $allOrigins);
            }

            $finalCompiledQuotes[$origin] = $compiledQuotes;

            $count++;
        }

        $allQuotes = $this->getFinalQuotesArray($finalCompiledQuotes);
        return $allQuotes;
    }

    public function compileGtzNewApiQuotes($shipments, $connectionSettings, $allOrigins)
    {
        $this->isOverrideRates = false;
        $this->isGTZNewApi = true;
        $this->GTZLtlQuotesResults = new globalTranzQuotesResults();
        if ($this->residential['gtzLtl'] == 'Y') {
            $this->isResi = true;
            $this->residentialDlvry = 1;
        } else {
            $this->isResi = false;
            $this->residentialDlvry = 0;
        }
        $this->alwaysResi = $this->residential['alwaysResi']['gtzLtl'] ?? false;

        $access = $this->getAccessorialCodeSmall();
        $shipments = $this->GTZLtlQuotesResults->newApiFormateQuoteBeforeCompile($shipments);

        $this->quoteSettings = $connectionSettings['gtz-ltl']['quote_settings'] ?? [];
        $this->quoteSettings['method'] = $this->quoteSettings['new_api_rating_method'] ?? $this->quoteSettings['method'] ?? 1;

        $allConfigServices = $connectionSettings['gtz-ltl']['carrier_services']['NEWAPI'] ?? [];
        foreach ($allConfigServices as $key => $allConfigService) {
            $allConfigServices[$key] = explode('-', $allConfigService)[0];
        }
        $this->quoteSettingsData();
        $allQuotes = $odwArr = $hazShipmentArr = $multiShipmentQuotes = [];
        $count = 0;
        $lgQuotes = $notifyDelivery = $limitedAccess = $insideDelivery = $isResi = false;
        if ($this->residentialDlvry == '1' || $this->isResi || $this->alwaysResi) {
            $isResi = '+R';
        }
        $numberOfShipments = 0;
        $resiPickup = $lgPickup = '';
        foreach ($shipments as $ship) {
            if (!isset($ship['severity'])) {
                $numberOfShipments++;
            }
        }
        if (!$this->isMultiShipment) {
            $this->isMultiShipment = is_countable($shipments) && $numberOfShipments > 1;
        }
        foreach ($shipments as $origin => $quote) {
            $this->originKey = $origin;
            if (isset($quote['severity'])) {
                return $this->getInsPicAndLocDelQuotes($quote, $allOrigins);
            }

            if ($count == 0) { //To be checked only once
                $isRad = $quote['autoResidentialsStatus'] ?? '';
                $inStoreLdData = $quote['InstorPickupLocalDelivery'] ?? false;
                unset($quote['InstorPickupLocalDelivery']);

                $lgQuotes =
                    (isset($this->quoteSettings['alwaysLiftGateDelivery']) && $this->quoteSettings['alwaysLiftGateDelivery']) ||
                    (isset($this->quoteSettings['offerLiftGateDelivery']) && $this->quoteSettings['offerLiftGateDelivery']);
                if (!$lgQuotes) {
                    $lgQuotes = (isset($this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->isResi;
                }
                $resiPickup = isset($this->quoteSettings['residentialPickup']) && $this->quoteSettings['residentialPickup'] ? '+pu' : '';
                $insideDelivery = (isset($this->quoteSettings['offer_inside_delivery']) && $this->quoteSettings['offer_inside_delivery']) ||
                    (isset($this->quoteSettings['always_inside_delivery']) && $this->quoteSettings['always_inside_delivery']);
                $lgPickup = isset($this->quoteSettings['liftGatePickup']) && $this->quoteSettings['liftGatePickup'] ? '+lfgp' : '';

                $notifyDelivery =
                    (isset($this->quoteSettings['always_quote_notify']) && $this->quoteSettings['always_quote_notify']) ||
                    (isset($this->quoteSettings['offer_notify_as_option']) && $this->quoteSettings['offer_notify_as_option']);

                $limitedAccess =
                    (isset($this->quoteSettings['offer_limited_access_delivery']) && $this->quoteSettings['offer_limited_access_delivery']) ||
                    (isset($this->quoteSettings['always_limited_access_delivery']) && $this->quoteSettings['always_limited_access_delivery']) ?? false;
            }
            $originQuotes = [];
            $arraySorting = [];
            $preCode = 'gtzltl';
            if (isset($quote['q'])) {
                if (isset($quote['hazardousStatus'])) {
                    $hazShipmentArr[$origin] = $quote['hazardousStatus'] == 'y' ? 'Y' : 'N';
                }
                foreach ($quote['q'] as $key => $data) {
                    if (isset($data['serviceType']) && in_array($data['serviceType'], $allConfigServices)) {
                        if ($limitedAccess && isset($this->quoteSettings['limited_access_fee'])) {
                            $data['totalNetCharge']['Amount'] += $this->quoteSettings['limited_access_fee'];
                            $data['surcharges']['limitedAccessDeliveryFee'] = (float) $this->quoteSettings['limited_access_fee'];
                        }

                        $issetLiftgateFee = isset($data['surcharges']['liftgateFee']) && !empty($data['surcharges']['liftgateFee']);
                        $issetLimitedFee = isset($data['surcharges']['limitedAccessDeliveryFee']) && !empty($data['surcharges']['limitedAccessDeliveryFee']);
                        $issetNotifyFee = isset($data['surcharges']['notifyDeliveryFee']) && !empty($data['surcharges']['notifyDeliveryFee']);
                        $issetInsideFee = isset($data['surcharges']['insideDeliveryFee']) && !empty($data['surcharges']['insideDeliveryFee']);
                        /*
                         * Date 01-07-22
                         * Adding Functionality of Delivery Estimate Options
                         * */
                        // Below commit use for future.
                        //$data = $this->applyOverrideRatesRule($connectionSettings, $data);

                        // Apply Surcharge rates shipping rule
                        //$data = $this->applySurchargeRatesRule($connectionSettings, $data);
                        $date = $data['EstimatedDeliveryDate'] ?? null;
                        $days = $data['totalTransitTimeInDays'] ?? null;
                        $dateAndDays = ['deliveryDate' => $date, 'totalTransitTimeInDays' => $days];
                        $title = $this->getGTitle($data['serviceDesc'], false, false, false, false, $data['totalTransitTimeInDays'], $this->quoteSettings, false, $dateAndDays);
                        $enableFeaturesArray = Functions::getEnableFeaturesArr($lgQuotes && $issetLiftgateFee, $insideDelivery && $issetInsideFee, $notifyDelivery && $issetNotifyFee, $limitedAccess && $issetLimitedFee);
                        foreach ($enableFeaturesArray as $index => $feature) {
                            if ($feature['isEnable']) {
                                $compileNotifyDeliveryQuotes = Functions::getOriginQuotes(
                                    $index, $data['serviceDesc'],
                                    $originQuotes,
                                    $data,
                                    $key, $data['totalTransitTimeInDays'],
                                    $dateAndDays, $feature['index']['isLG'] ?? false,
                                    "gtzltl_new", $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings,
                                    $this->isResi, $this->alwaysResi, $feature['index']['isID'] ?? false, $feature['index']['isLAD'] ?? false, $feature['index']['isNBD'] ?? false,
                                    $resiPickup,
                                    $lgPickup,
                                    $this->storeId,
                                );

                                $arraySorting[$index][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                                $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                            }
                        }
                    }
                }
            }

            $compiledQuotes = $this->getCompiledQuotes($originQuotes, $arraySorting, $lgQuotes, $resiPickup, $lgPickup, $insideDelivery, $notifyDelivery, $limitedAccess);
            if ($compiledQuotes !== null && !empty($compiledQuotes)) {
                if (count($compiledQuotes) > 1) {
                    foreach ($compiledQuotes as $k => $service) {
                        foreach ($service as $serKey => $ser) {
                            $quotes = Functions::getQuotesArray($service, $allQuotes, $multiShipmentQuotes, $origin, $serKey);
                            $allQuotes = $quotes['allQuotes'];
                            $multiShipmentQuotes = $quotes['multiShipmentQuotes'];
                        }
                    }
                } else {
                    $service = reset($compiledQuotes);
                    foreach ($service as $serKey => $ser) {
                        $quotes = Functions::getQuotesArray($service, $allQuotes, $multiShipmentQuotes, $origin, $serKey);
                        $allQuotes = $quotes['allQuotes'];
                        $multiShipmentQuotes = $quotes['multiShipmentQuotes'];
                    }
                }
            }

            if ($this->isMultiShipment) {
                $odwArr[$origin]['quotes'] = $compiledQuotes;
            }
            $count++;
        }

        $allQuotes = $this->getFinalQuotesArray($allQuotes);

        if (!$this->isMultiShipment && isset($inStoreLdData) && !empty($inStoreLdData)) {
            $allQuotes = $this->inStoreLocalDeliveryQuotes($allQuotes, $inStoreLdData, $allOrigins);
        }
        if ($this->multiOrigins && ((!empty($multiShipmentQuotes['simple']) && count($multiShipmentQuotes['simple']) > 1) || (!empty($multiShipmentQuotes['liftgate']) && count($multiShipmentQuotes['liftgate']) > 1))) {

            $allQuotes = $this->forceChangeTitle($allQuotes);
            $resp = [
                'checkoutQuotes' => $allQuotes,
                'multiShipmentQuotes' => $multiShipmentQuotes,
            ];
            return $resp;
        }

        return $allQuotes;
    }

    public function compileunishipperNewApiQuotes($shipments, $connectionSettings, $allOrigins)
    {
        $this->isUsNewApi = true;
        $this->GTZLtlQuotesResults = new globalTranzQuotesResults();
        if ($this->residential['uniLtl'] == 'Y') {
            $this->isResi = true;
            $this->residentialDlvry = 1;
        } else {
            $this->isResi = false;
            $this->residentialDlvry = 0;
        }
        $this->alwaysResi = $this->residential['alwaysResi']['uniLtl'] ?? false;

        $access = $this->getAccessorialCodeSmall();
        $shipments = $this->GTZLtlQuotesResults->newApiFormateQuoteBeforeCompile($shipments);

        $this->quoteSettings = $connectionSettings['unishipper-ltl']['quote_settings'] ?? [];
        $this->quoteSettings['method'] = $this->quoteSettings['new_api_rating_method'] ?? $this->quoteSettings['method'] ?? 1;

        $allConfigServices = $connectionSettings['unishipper-ltl']['carrier_services'] ?? [];
        foreach ($allConfigServices as $key => $allConfigService) {
            $allConfigServices[$key] = explode('-', $allConfigService)[0];
        }
        $this->quoteSettingsData();
        $allQuotes = $odwArr = $hazShipmentArr = $multiShipmentQuotes = [];
        $count = 0;
        $lgQuotes = $notifyDelivery = $limitedAccess = $insideDelivery = $isResi = $allowOwnArrangement  = false;

        if ($this->residentialDlvry == '1' || $this->isResi || $this->alwaysResi) {
            $isResi = '+R';
        }
        $numberOfShipments = 0;
        $resiPickup = $lgPickup = '';
        $originQuotes = [];
        foreach ($shipments as $ship) {
            if (!isset($ship['severity'])) {
                $numberOfShipments++;
            }
        }
        if (!$this->isMultiShipment) {
            $this->isMultiShipment = is_countable($shipments) && $numberOfShipments > 1;
        }
        foreach ($shipments as $origin => $quote) {
            $this->originKey = $origin;
            if (isset($quote['severity'])) {
                return $this->getInsPicAndLocDelQuotes($quote, $allOrigins);
            }

            if ($count == 0) { //To be checked only once
                $isRad = $quote['autoResidentialsStatus'] ?? '';
                $inStoreLdData = $quote['InstorPickupLocalDelivery'] ?? false;
                unset($quote['InstorPickupLocalDelivery']);

                $lgQuotes =
                    (isset($this->quoteSettings['alwaysLiftGateDelivery']) && $this->quoteSettings['alwaysLiftGateDelivery']) ||
                    (isset($this->quoteSettings['offerLiftGateDelivery']) && $this->quoteSettings['offerLiftGateDelivery']);
                if (!$lgQuotes) {
                    $lgQuotes = (isset($this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->isResi;
                }
                $resiPickup = isset($this->quoteSettings['residentialPickup']) && $this->quoteSettings['residentialPickup'] ? '+pu' : '';
                $insideDelivery = (isset($this->quoteSettings['offer_inside_delivery']) && $this->quoteSettings['offer_inside_delivery']) ||
                    (isset($this->quoteSettings['always_inside_delivery']) && $this->quoteSettings['always_inside_delivery']);
                $lgPickup = isset($this->quoteSettings['liftGatePickup']) && $this->quoteSettings['liftGatePickup'] ? '+lfgp' : '';

                $notifyDelivery =
                    (isset($this->quoteSettings['always_quote_notify']) && $this->quoteSettings['always_quote_notify']) ||
                    (isset($this->quoteSettings['offer_notify_as_option']) && $this->quoteSettings['offer_notify_as_option']);

                $limitedAccess =
                    (isset($this->quoteSettings['offer_limited_access_delivery']) && $this->quoteSettings['offer_limited_access_delivery']) ||
                    (isset($this->quoteSettings['always_limited_access_delivery']) && $this->quoteSettings['always_limited_access_delivery']) ?? false;
            }
            
            $arraySorting = [];
            $preCode = 'unishipperLtl';
            if (isset($quote['q'])) {
                if (isset($quote['hazardousStatus'])) {
                    $hazShipmentArr[$origin] = $quote['hazardousStatus'] == 'y' ? 'Y' : 'N';
                }
                $allowOwnArrangement = isset($quote['allowOwnArrangement']) && $quote['allowOwnArrangement'] ?? false;
                foreach ($quote['q'] as $key => $data) {
                    if (isset($data['serviceType']) && in_array($data['serviceType'], $allConfigServices)) {
                        if ($limitedAccess && isset($this->quoteSettings['limited_access_fee'])) {
                            $data['totalNetCharge']['Amount'] += $this->quoteSettings['limited_access_fee'];
                            $data['surcharges']['limitedAccessDeliveryFee'] = (float) $this->quoteSettings['limited_access_fee'];
                        }
                        $isSurcharges = isset($data['surcharges']) && !empty($data['surcharges']);

                        if (($insideDelivery || $lgQuotes || $notifyDelivery || $limitedAccess) && !isset($data['surcharges'])) {
                            continue;
                        }
                        /*
                         * Date 01-07-22
                         * Adding Functionality of Delivery Estimate Options
                         * */
                        $date = $data['EstimatedDeliveryDate'] ?? null;
                        $days = $data['totalTransitTimeInDays'] ?? null;
                        $dateAndDays = ['deliveryDate' => $date, 'totalTransitTimeInDays' => $days];
                        $title = $this->getGTitle($data['serviceDesc'], false, false, false, false, $data['totalTransitTimeInDays'], $this->quoteSettings, false, $dateAndDays);
                        $enableFeaturesArray = Functions::getEnableFeaturesArr($lgQuotes && $isSurcharges, $insideDelivery && $isSurcharges, $notifyDelivery && $isSurcharges, $limitedAccess && $isSurcharges);
                        foreach ($enableFeaturesArray as $index => $feature) {
                            if ($feature['isEnable']) {
                                $compileNotifyDeliveryQuotes = Functions::getOriginQuotes(
                                    $index, $data['serviceDesc'],
                                    $originQuotes,
                                    $data,
                                    $origin,
                                    $key, $data['totalTransitTimeInDays'],
                                    $dateAndDays, $feature['index']['isLG'] ?? false,
                                    "unlltl", $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings,
                                    $this->isResi, $this->alwaysResi, $feature['index']['isID'] ?? false, $feature['index']['isLAD'] ?? false, $feature['index']['isNBD'] ?? false,
                                    $resiPickup,
                                    $lgPickup,
                                    $this->storeId,
                                );

                                $arraySorting[$index][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                                $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                            }
                        }
                    }
                }
            }

            $compiledQuotes = $this->getCompiledQuotes($originQuotes, $arraySorting, $lgQuotes, $resiPickup, $lgPickup, $insideDelivery, $notifyDelivery, $limitedAccess);

            if (isset($inStoreLdData) && !empty($inStoreLdData)) {
                $compiledQuotes = $this->inStoreLocalDeliveryQuotes($compiledQuotes, $inStoreLdData, $allOrigins);
            }

            $finalCompiledQuotes[$origin] = $compiledQuotes;
            $count++;
        }

        $allQuotes = $this->getFinalQuotesArray($finalCompiledQuotes);
        return $allQuotes;
    }

    // For ODFL LTL Quotes
    public function compileOdflLtlQuotes($shipments, $connectionSettings, $allOrigins)
    {
        $this->isResi = $this->residential['odflLtl'] == 'Y';
        $this->residentialDlvry = $this->residential['odflLtl'] == 'Y' ? 1 : 0;
        $this->alwaysResi = $this->residential['alwaysResi']['odflLtl'] ?? false;
        $this->quoteSettings = $connectionSettings['odfl-ltl']['quote_settings'] ?? [];
        $this->quoteSettingsData();

        $allQuotes = $odwArr = $hazShipmentArr = $multiShipmentQuotes = [];
        $count = 0;
        $lgQuotes = false;
        $originQuotes = [];

        $numberOfShipments = 0;
        foreach ($shipments as $ship) {
            if (!isset($ship['severity'])) {
                $numberOfShipments++;
            }
        }

        if (!$this->isMultiShipment) {
            $this->isMultiShipment = is_countable($shipments) && $numberOfShipments > 1;
        }

        $lableAs = $this->quoteSettings['label_as'] ?? 'Freight';
        $key = 0;

        foreach ($shipments as $origin => $quote) {
            $this->originKey = $origin;
            $this->isSurchargeRates = false;

            if ((isset($quote['severity']) || !isset($quote['q']) || (isset($quote['q']) && empty($quote['q'])))) {
                $instoreResp[$origin] = $this->getInsPicAndLocDelQuotes($quote, $allOrigins) ?? [];                
                return $instoreResp;
            }

            if ($count == 0) {
                $inStoreLdData = $quote['InstorPickupLocalDelivery'] ?? false;
                unset($quote['InstorPickupLocalDelivery']);

                $lgQuotes =
                    (isset($this->quoteSettings['alwaysLiftGateDelivery']) && $this->quoteSettings['alwaysLiftGateDelivery']) ||
                    (isset($this->quoteSettings['offerLiftGateDelivery']) && $this->quoteSettings['offerLiftGateDelivery']);

                if (!$lgQuotes) {
                    $lgQuotes = (isset($this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->isResi;
                }
                $notifyDelivery =
                    (isset($this->quoteSettings['always_quote_notify']) && $this->quoteSettings['always_quote_notify']) ||
                    (isset($this->quoteSettings['offer_notify_as_option']) && $this->quoteSettings['offer_notify_as_option']);
            }

            $arraySorting = [];

            if (isset($quote['q']) && isset($quote['q']['success']) && $quote['q']['success'] == "true") {
                if (isset($quote['hazardousStatus'])) {
                    $hazShipmentArr[$origin] = $quote['hazardousStatus'] == 'y' ? 'Y' : 'N';
                }

                $data = $quote['q'];

                if (isset($data['rateEstimate']['netFreightCharge'])) {
                    $data['totalNetCharge']['Amount'] = (float) $data['rateEstimate']['netFreightCharge'] ?? 0;
                }

                if (isset($data['rateEstimate']['accessorialCharges']) && !empty($data['rateEstimate']['accessorialCharges'])) {
                    $accessorialCharges = $data['rateEstimate']['accessorialCharges'];
                    foreach ($accessorialCharges as $accessorialCharge) {
                        if (
                            isset($accessorialCharge['description']) && $accessorialCharge['description'] == 'Notification Prior to Delivery' ||
                            isset($accessorialCharges['description']) && $accessorialCharges['description'] == 'Notification Prior to Delivery'
                        ) {

                            $data['surcharges']['notifyDeliveryFee'] = isset($accessorialCharge['amount']) ? (float) $accessorialCharge['amount'] : (float) $accessorialCharges['amount'] ?? 0;
                        }
                    }
                }

                $access = $this->getAccessorialCode();
                // Apply override rates shipping rule
                $data = $this->applyOverrideRatesRule($connectionSettings, $data);
                // Apply Surcharge rates shipping rule
                $data = $this->applySurchargeRatesRule($connectionSettings, $data);

                $price = $this->calculatePrice($data);
                $access = $this->getAccessorialCode();

                $date = $data['deliveryDate'] ?? null;
                $days = $data['totalTransitTimeInDays'] ?? null;
                $dateAndDays = ['deliveryDate' => $date, 'totalTransitTimeInDays' => $days];
                $title = $this->getTitle($lableAs, false, false, $days, [], $dateAndDays);
                $arraySorting['simple'][$origin] = $price;
                $originQuotes[$origin]['simple'][$key]['code'] = 'odflltl' . $access;
                $originQuotes[$origin]['simple'][$key]['rate'] = $price;
                $originQuotes[$origin]['simple'][$key]['title'] = $title;

                if ($lgQuotes) {
                    $lgAccess = $this->getAccessorialCode(true);
                    $lgPrice = $this->calculatePrice($data, $lgOption = 1);
                    $lgTitle = $this->getTitle($lableAs, true, false, $days, [], $dateAndDays);
                    $arraySorting['liftgate'][$origin] = $lgPrice;
                    $originQuotes[$origin]['liftgate'][$key]['code'] = 'odflltl' . $lgAccess;
                    $originQuotes[$origin]['liftgate'][$key]['rate'] = $lgPrice;
                    $originQuotes[$origin]['liftgate'][$key]['title'] = $lgTitle;
                }
                // Get Notify Before Delivery Origin Quotes
                if ($notifyDelivery) {
                    $compileNotifyDeliveryQuotes = Functions::getOriginQuotes(
                        'notifydelivery',
                        $lableAs,
                        $originQuotes,
                        $data,
                        $origin, 
                        $key, $data['totalTransitTimeInDays'],
                        $dateAndDays,
                        false,
                        'odflltl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi,
                        false,
                        false,
                        $notifyDelivery,
                        false,
                        false,
                        $this->storeId,
                        $this->isSurchargeRates,
                    );

                    $arraySorting['notifydelivery'][$origin] = $compileNotifyDeliveryQuotes['ndPrice'];
                    $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                }
                if ($notifyDelivery && $lgQuotes) {
                    $compileNotifyDeliveryQuotes = Functions::getOriginQuotes(
                        'lgnotifydelivery',
                        $lableAs,
                        $originQuotes,
                        $data,
                        $origin,
                        $key, $data['totalTransitTimeInDays'],
                        $dateAndDays,
                        true,
                        'odflltl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi,
                        false,
                        false,
                        $notifyDelivery,
                        false,
                        false,
                        $this->storeId,
                        $this->isSurchargeRates,
                    );

                    $arraySorting['lgnotifydelivery'][$origin] = $compileNotifyDeliveryQuotes['ndPrice'];
                    $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                }

                $key++;
            }

            if (isset($inStoreLdData) && !empty($inStoreLdData)) {
                $originQuotes[$origin] = $this->inStoreLocalDeliveryQuotes($originQuotes[$origin], $inStoreLdData, $allOrigins);
            }
            $count++;
        }

        $allQuotes = $this->getFinalQuotesArray($originQuotes);
        return $allQuotes;
    }

    public function compileTqlLtlQuotes($shipments, $connectionSettings, $allOrigins)
    {
        $tqlLtl = new tqlLtlQuotesResults();
        $this->isOverrideRates = false;
        $this->TQL = true;
        if ($this->residential['tqlLtl'] == 'Y') {
            $this->isResi = true;
            $this->residentialDlvry = 1;
        } else {
            $this->isResi = false;
            $this->residentialDlvry = 0;
        }
        $this->alwaysResi = $this->residential['alwaysResi']['tqlLtl'] ?? false;
        $shipments = $tqlLtl->formateQuoteBeforeCompile($shipments, $connectionSettings['tql-ltl']);
        $this->quoteSettings = $connectionSettings['tql-ltl']['quote_settings'] ?? [];
        $this->allConfigServices = $connectionSettings['tql-ltl']['carrier_services'] ?? [];
        $ratingMethod = $this->quoteSettings['method'] ?? 1;
        $isStandardChecked = $connectionSettings['tql-ltl']['quote_settings']['standard_check'] ?? false;
        $isGuaranteedChecked = $connectionSettings['tql-ltl']['quote_settings']['guaranteed_check'] ?? false;
        $this->quoteSettingsData();
        $standardLabel = isset($this->quoteSettings['standard']) && !empty($this->quoteSettings['standard']) ? $this->quoteSettings['standard'] : '';
        $guaranteedLabel = isset($this->quoteSettings['guaranteed']) && !empty($this->quoteSettings['guaranteed']) ? $this->quoteSettings['guaranteed'] : '';
        $allQuotes = $odwArr = $hazShipmentArr = $multiShipmentQuotes = [];
        $count = 0;
        $lgQuotes = false;
        $numberOfShipments = 0;
        foreach ($shipments as $ship) {
            if (!isset($ship['severity'])) {
                $numberOfShipments++;
            }
        }
        if (!$this->isMultiShipment) {
            $this->isMultiShipment = is_countable($shipments) && $numberOfShipments > 1;
        }
        foreach ($shipments as $origin => $quote) {
            $this->originKey = $origin;
            if (isset($quote['severity'])) {
                return $this->getInsPicAndLocDelQuotes($quote, $allOrigins);
            }

            if ($count == 0) { //To be checked only once
                $isRad = $quote['autoResidentialsStatus'] ?? '';
                $inStoreLdData = $quote['InstorPickupLocalDelivery'] ?? [];

                $this->lgQuotes =
                    (isset($this->quoteSettings['alwaysLiftGateDelivery']) && $this->quoteSettings['alwaysLiftGateDelivery']) ||
                    (isset($this->quoteSettings['offerLiftGateDelivery']) && $this->quoteSettings['offerLiftGateDelivery']);
                if (!$this->lgQuotes) {
                    $this->lgQuotes = (isset($this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->isResi;
                }
                $resiPickup = isset($this->quoteSettings['residentialPickup']) && $this->quoteSettings['residentialPickup'] ? '+pu' : '';

                $this->notifyDelivery =
                    (isset($this->quoteSettings['always_quote_notify']) && $this->quoteSettings['always_quote_notify']) ||
                    (isset($this->quoteSettings['offer_notify_as_option']) && $this->quoteSettings['offer_notify_as_option']);
            }
            $originQuotes = $standard = $guaranteed = [];
            $arraySorting = [];

            $standardQuotes = collect($quote['q'])->filter(function ($q) {
                if (isset($q['scac']) && in_array($q['scac'], $this->allConfigServices)) {
                    if ($this->lgQuotes || $this->notifyDelivery) {
                        foreach ($q['priceCharges'] as $charge) {
                            if (isset($charge['description']) && $charge['description'] === 'Lift Gate' || isset($charge['description']) && $charge['description'] === 'Delivery Call Ahead') {
                                return isset($q["serviceLevel"]) && $q["serviceLevel"] == 'Standard';
                            }
                        }
                    } else {
                        return isset($q["serviceLevel"]) && $q["serviceLevel"] == 'Standard';
                    }
                }
            })->toArray() ?? [];

            $guaranteedQuotes = collect($quote['q'])->filter(function ($q) {
                if (isset($q['scac']) && in_array($q['scac'], $this->allConfigServices)) {
                    if ($this->lgQuotes || $this->notifyDelivery) {
                        foreach ($q['priceCharges'] as $charge) {
                            if (isset($charge['description']) && $charge['description'] === 'Lift Gate' || isset($charge['description']) && $charge['description'] === 'Delivery Call Ahead') {
                                return isset($q["serviceLevel"]) && ($q["serviceLevel"] == 'Guaranteed 5 PM' || $q["serviceLevel"] == 'Guaranteed 12 PM');
                            }
                        }
                    } else {
                        return isset($q["serviceLevel"]) && ($q["serviceLevel"] == 'Guaranteed 5 PM' || $q["serviceLevel"] == 'Guaranteed 12 PM');
                    }
                }
            })->toArray() ?? [];

            if ($isStandardChecked && !$isGuaranteedChecked) {
                $quote['q'] = $standardQuotes;
            } elseif (!$isStandardChecked && $isGuaranteedChecked) {
                $quote['q'] = $guaranteedQuotes;
            } elseif ($isStandardChecked && $isGuaranteedChecked) {
                if ($ratingMethod == 1 || $ratingMethod == 2 || $ratingMethod == 3) {
                    $quote['q'] = $quote['q'];
                } elseif ($ratingMethod == 4) {
                    $quotes['q'] = $standardQuotes;
                    $standard[] = $this->getCheapestQuotesArr($quotes);
                    if (!$this->isMultiShipment){
                        $quotes['q'] = $guaranteedQuotes;
                        $guaranteed[] = $this->getCheapestQuotesArr($quotes);
                    } else {
                        $guaranteed = [];
                    }
                    $bothService = array_merge($standard, $guaranteed);
                    $quote['q'] = $bothService;

                } elseif ($ratingMethod == 5) {
                    $options = (int) $this->quoteSettings['number_of_options'];
                    $standardSort = collect($standardQuotes)->sortBy('customerRate')->toArray();
                    $standardSliced = array_slice($standardSort, 0, $options, true);
                    $guaranteedSort = collect($guaranteedQuotes)->sortBy('customerRate')->toArray();
                    $guaranteedSliced = array_slice($guaranteedSort, 0, $options, true);
                    $bothService = array_merge($standardSliced, $guaranteedSliced);
                    unset($quote['q']);
                    $quote['q'] = $bothService;

                } elseif ($ratingMethod == 6) {
                    $options = (int) $this->quoteSettings['number_of_options'];
                    $standardSort = collect($standardQuotes)->sortBy('customerRate')->toArray();
                    $standardPrice = $this->averageOfEachService($standardSort, $options, $this->allConfigServices, $this->lgQuotes, $this->notifyDelivery, false, $standardLabel);
                    if (!$this->isMultiShipment){
                        $guaranteedSort = collect($guaranteedQuotes)->sortBy('customerRate')->toArray();
                        $guaranteedPrice = $this->averageOfEachService($guaranteedSort, $options, $this->allConfigServices, $this->lgQuotes, $this->notifyDelivery, false, $guaranteedLabel);
                    } else {
                        $guaranteedPrice = [];
                    }
                    $quote['q'] = $originQuotes = array_merge($standardPrice, $guaranteedPrice);
                }
            }

            if (isset($quote['q'])) {
                foreach ($quote['q'] as $key => $data) {
                    if (isset($data['scac']) && in_array($data['scac'], $this->allConfigServices)) {
                        $data['totalNetCharge']['Amount'] = $data['customerRate'] ?? 0;
                        foreach ($data['priceCharges'] as $index => $value) {
                            if ($value['description'] == "Lift Gate") {
                                $data['surcharges']['liftgateFee'] = $value['amount'] ?? 0;
                            }
                            if ($value['description'] == "Residential") {
                                $data['surcharges']['residentialFee'] = $value['amount'] ?? 0;
                            }
                            if (isset($value['description'])) {
                                $hazShipmentArr[$origin] = $value['description'] == "Hazardous Materials" ? 'Y' : 'N';
                            }
                            if ($value['description'] == "Delivery Call Ahead") {
                                $data['surcharges']['notifyDeliveryFee'] = $value['amount'] ?? 0;
                            }
                        }
                        if (($this->lgQuotes || $this->notifyDelivery) && !isset($data['surcharges'])) {
                            continue;
                        }
                        // Below commit use for future.
                        //$data = $this->applyOverrideRatesRule($connectionSettings, $data);
                        $access = $data['scac'] . $this->getAccessorialCode() . $resiPickup;
                        $isLgSurcharges = isset($data['surcharges']['liftgateFee']) && $data['surcharges']['liftgateFee'];
                        $isNbdSurcharges = isset($data['surcharges']['notifyDeliveryFee']);
                        $price = $this->calculatePrice($data);

                        $serviceType = $ratingMethod === 5 ? ' ' . $data['serviceLevel'] : '';
                        $date = $data['deliveryTimestamp'] ?? null;
                        $days = $data['totalCalenderDaysInTransit'] ?? null;
                        $dateAndDays = ['deliveryDate' => $date, 'totalTransitTimeInDays' => $days];
                        $title = $this->getTitle($data['carrier'] . $serviceType, false, false, $data['totalCalenderDaysInTransit'], [], $dateAndDays);
                        $arraySorting['simple'][$key] = $price ?? [];
                        $method = $this->quoteSettings['method'];

                        $originQuotes[$key]['simple']['code'] = 'tqlltl' . $access;
                        $originQuotes[$key]['simple']['rate'] = $price;
                        $originQuotes[$key]['simple']['title'] = $title;

                        //if(!$this->isOverrideRates){
                        if ($this->lgQuotes && $isLgSurcharges) {
                            $lgAccess = $data['scac'] . 'tqlltl' . $this->getAccessorialCode(true) . $resiPickup;
                            $lgPrice = $this->calculatePrice($data, true);
                            $lgTitle = $this->getTitle($data['carrier'] . $serviceType, true, false, $data['totalCalenderDaysInTransit'], [], $dateAndDays);
                            $arraySorting['liftgate'][$key] = $lgPrice ?? [];
                            $originQuotes[$key]['liftgate']['code'] = $lgAccess;
                            $originQuotes[$key]['liftgate']['rate'] = $lgPrice;
                            $originQuotes[$key]['liftgate']['title'] = $lgTitle;
                        }
                        // Get Notify Before Delivery Origin Quotes
                        if ($this->notifyDelivery && $isNbdSurcharges) {
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes(
                                'notifydelivery', $data['carrier'] . $serviceType,
                                $originQuotes,
                                $data,
                                $key, $data['totalCalenderDaysInTransit'],
                                $dateAndDays,
                                false,
                                'tqlltl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi,
                                false,
                                false, $this->notifyDelivery,
                                $resiPickup,
                                false,
                                $this->storeId,
                            );

                            $arraySorting['notifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }
                        if ($this->notifyDelivery && $this->lgQuotes && $isNbdSurcharges && $isLgSurcharges) {
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes(
                                'lgnotifydelivery', $data['carrier'] . $serviceType,
                                $originQuotes,
                                $data,
                                $key, $data['totalCalenderDaysInTransit'],
                                $dateAndDays,
                                $this->lgQuotes,
                                'tqlltl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi,
                                false,
                                false, $this->notifyDelivery,
                                $resiPickup,
                                false,
                                $this->storeId,
                            );

                            $arraySorting['lgnotifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }
                        //}
                    }

                }
            }
            if ($ratingMethod == 1 || $ratingMethod == 2 || $ratingMethod == 3) {
                $compiledQuotes = $this->getCompiledQuotesTQL($originQuotes, $arraySorting, $this->lgQuotes, $this->notifyDelivery);
            } elseif($this->isMultiShipment && $ratingMethod == 5) {
                $compiledQuotes = $this->getCompiledQuotesTQL($originQuotes, $arraySorting, $this->lgQuotes, $this->notifyDelivery);
            } else {
                $compiledQuotes = $originQuotes;
            }
            if ($compiledQuotes !== null && !empty($compiledQuotes)) {
                if (count($compiledQuotes) > 1) {
                    foreach ($compiledQuotes as $k => $service) {
                        foreach ($service as $serKey => $ser) {
                            $quotes = Functions::getQuotesArray($service, $allQuotes, $multiShipmentQuotes, $origin, $serKey);
                            $allQuotes = $quotes['allQuotes'];
                            $multiShipmentQuotes = $quotes['multiShipmentQuotes'];
                        }
                    }
                } else {
                    $service = reset($compiledQuotes);
                    foreach ($service as $serKey => $ser) {
                        $quotes = Functions::getQuotesArray($service, $allQuotes, $multiShipmentQuotes, $origin, $serKey);
                        $allQuotes = $quotes['allQuotes'];
                        $multiShipmentQuotes = $quotes['multiShipmentQuotes'];
                    }
                }
            }

            if ($this->isMultiShipment) {
                $odwArr[$origin]['quotes'] = $compiledQuotes;
            }
            $count++;
        }

        $allQuotes = $this->getFinalQuotesArray($allQuotes);
        if (!$this->isMultiShipment && isset($inStoreLdData) && !empty($inStoreLdData)) {
            $allQuotes = $this->inStoreLocalDeliveryQuotes($allQuotes, $inStoreLdData, $allOrigins);
        }
        if ($this->multiOrigins && ((!empty($multiShipmentQuotes['simple']) && count($multiShipmentQuotes['simple']) > 1) || (!empty($multiShipmentQuotes['liftgate']) && count($multiShipmentQuotes['liftgate']) > 1))) {
            $allQuotes = $this->forceChangeTitle($allQuotes);
            $resp = [
                'checkoutQuotes' => $this->arrangeOwnFreight($allQuotes),
                'multiShipmentQuotes' => $multiShipmentQuotes
            ];
            return $resp;
        }
        return $this->arrangeOwnFreight($allQuotes);
    }

    private function getCheapestQuotesArr($quotes): array
    {
        $cheapestQuote = [];
        $quotes = $quotes['q'] ?? [];
        if (isset($quotes) && !empty($quotes)) {
            $minRate = min(array_column($quotes, 'customerRate'));
            foreach ($quotes as $q) {
                if ($q['customerRate'] == $minRate) {
                    $cheapestQuote = $q;
                    break;
                }
            }
            if ($cheapestQuote['serviceLevel'] === 'Standard' && isset($this->quoteSettings['standard'])) {

                $cheapestQuote['carrier'] = $this->quoteSettings['standard'] ?? 'Freight';

            } elseif ($cheapestQuote['serviceLevel'] == 'Guaranteed 5 PM' || $cheapestQuote['serviceLevel'] == 'Guaranteed 12 PM' && isset($this->quoteSettings['guaranteed'])) {

                $cheapestQuote['carrier'] = $this->quoteSettings['guaranteed'] ?? 'Freight';

            }
        }

        return $cheapestQuote;
    }

    public function forceChangeTitle($allQuotes)
    {
        if (!empty($allQuotes)) {
            foreach ($allQuotes as $key => $quote) {
                if ($quote['code'] === 'own_arrangement') {
                    continue;
                }
                $title = explode('(', $quote['title'])[0];
                $title = explode('w/', $title);
                $title[0] = Functions::$ltlMultiTitle;
                $allQuotes[$key]['title'] = implode(' w/', $title);
            }
        }
        return $allQuotes;
    }

    public function compileUpsSmallQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential)
    {
        $this->upsSmallQuotesResults = new upsSmallQuotesResults($this->SuppressParcelRates);
        if ($residential['upsSmall'] == 'Y') {
            $this->isResi = true;
            $this->residentialDlvry = 1;
        } else {
            $this->isResi = false;
            $this->residentialDlvry = 0;
        }
        $this->alwaysResi = $this->residential['alwaysResi']['upsSmall'] ?? false;
        $isSbsEnable = isset($residential['isSbsEnable']) ? $residential['isSbsEnable'] : false;

        return $res = $this->upsSmallQuotesResults->compileQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $this->isResi, $isSbsEnable, $this->isMultiShipment, $this->items, $this->storeId, $this->carrierName, $this->totalHazmatBoxes);
        if (!$this->isMultiShipment) {
            $this->isMultiShipment = $res['isMultiShipment'] ?? false;
        }

        return $res['resp'] ?? [];
    }

    public function compileUnishipSmallNewApiQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential)
    {
        $this->unishippersSmallQuotesResults = new unishippersSmallQuotesResults($this->SuppressParcelRates);
        
        if ($residential['unishippersSmallNewApi'] == 'Y') {
            $this->isResi = true;
            $this->residentialDlvry = 1;
        } else {
            $this->isResi = false;
            $this->residentialDlvry = 0;
        }
        $this->alwaysResi = $this->residential['alwaysResi']['unishippersSmallNewApi'] ?? false;
        $isSbsEnable = isset($residential['isSbsEnable']) ? $residential['isSbsEnable'] : false;

        $res = $this->unishippersSmallQuotesResults->compileQuotesNewApi($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $this->isResi, $isSbsEnable, $this->isMultiShipment, $this->items, $this->storeId, $this->carrierName, $this->totalHazmatBoxes);
        if (!$this->isMultiShipment) {
            $this->isMultiShipment = $res['isMultiShipment'] ?? false;
        }

        return $res['resp'] ?? [];
    }

    /**
     * Compiling ups shipengine quotes
     * @param $shipments
     * @param $connectionSettings
     * @param $allOrigins
     * @param $smalLtlHazmat
     * @param $hazmatAllItems
     * @param $residential
     * @return array|bool|mixed
     */
    public function compileUpsShipEngineQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential)
    {
        $quoteResults = new upsShipEngineSmallQuotesResults($this->SuppressParcelRates);
        if ($residential['shipEngine'] == 'Y') {
            $this->isResi = true;
            $this->residentialDlvry = 1;
        } else {
            $this->isResi = false;
            $this->residentialDlvry = 0;
        }
        $this->alwaysResi = $this->residential['alwaysResi']['shipEngine'] ?? false;
        $isSbsEnable = isset($residential['isSbsEnable']) ? $residential['isSbsEnable'] : false;

        try {
            $res = $quoteResults->compileQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $this->isResi, $isSbsEnable, $this->isMultiShipment, $this->items, $this->storeId, $this->carrierName, $this->totalHazmatBoxes);
        } catch (\Exception $exception) {
            Log::info('Exception on shipengine results ' . json_encode([
                'line' => $exception->getLine(),
                'message' => $exception->getMessage()
            ]));

            return [];
        }

        if (!$this->isMultiShipment) {
            $this->isMultiShipment = $res['isMultiShipment'] ?? false;
        }

        return $res['resp'] ?? [];
    }

    public function compilePurolatorSmallQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential)
    {
        $this->purolatorSmallQuotesResults = new purolatorSmallQuotesResults($this->SuppressParcelRates);
        if ($residential['purolatorSmall'] == 'Y') {
            $this->isResi = true;
            $this->residentialDlvry = 1;
        } else {
            $this->isResi = false;
            $this->residentialDlvry = 0;
        }
        $this->alwaysResi = $this->residential['alwaysResi']['purolatorSmall'] ?? false;
        $isSbsEnable = isset($residential['isSbsEnable']) ? $residential['isSbsEnable'] : false;
        $res = $this->purolatorSmallQuotesResults->compileQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $this->isResi, $isSbsEnable, $this->isMultiShipment, $this->items, $this->storeId, $this->carrierName, $this->totalHazmatBoxes);
        if (!$this->isMultiShipment) {
            $this->isMultiShipment = isset($res['isMultiShipment']) ? $res['isMultiShipment'] : [];
        }

        return $res['resp'];
    }

    public function compileEstesltlQuotes($shipments, $connectionSettings, $allOrigins)
    {
        $estesLtl = new estesLtlQuotesResults();

        $this->isResi = $this->residential['estesLtl'] == 'Y';
        $this->residentialDlvry = $this->residential['estesLtl'] == 'Y' ? 1 : 0;
        $this->alwaysResi = $this->residential['alwaysResi']['estesLtl'] ?? false;
        $this->quoteSettings = $connectionSettings['estes-ltl']['quote_settings'] ?? [];
        $labelAs = $this->quoteSettings['label_as'] ?? '';
        $labelAs = empty($labelAs) ? "Freight" : $labelAs;
        $this->quoteSettingsData();


        $allQuotes = $odwArr = $hazShipmentArr = $multiShipmentQuotes = [];
        $count = 0;
        $lgQuotes = false;
        $hatShipments = [];
        $originQuotes = [];

        $numberOfShipments = 0;
        foreach ($shipments as $ship) {
            if (!isset($ship['severity'])) {
                $numberOfShipments++;
            }
        }

        if (!$this->isMultiShipment) {
            $this->isMultiShipment = is_countable($shipments) && $numberOfShipments > 1;
        }

        foreach ($shipments as $origin => $quote) {
            $this->originKey = $origin;
            $hatShipments[$origin]['hat'][] = $estesLtl->HatQuoteCompile($quote, $this->quoteSettings);
            if ((isset($quote['severity']) || !isset($quote['q']) || (isset($quote['q']) && empty($quote['q'])))) {
                $instoreResp[$origin] = $this->getInsPicAndLocDelQuotes($quote, $allOrigins) ?? [];                
                return $instoreResp;
            }

            if ($count == 0) {
                //To be checked only once
                $isRad = $quote['autoResidentialsStatus'] ?? '';
                $inStoreLdData = $quote['InstorPickupLocalDelivery'] ?? false;
                unset($quote['InstorPickupLocalDelivery']);
                $lgQuotes =
                    (isset($this->quoteSettings['alwaysLiftGateDelivery']) && $this->quoteSettings['alwaysLiftGateDelivery']) ||
                    (isset($this->quoteSettings['offerLiftGateDelivery']) && $this->quoteSettings['offerLiftGateDelivery']);
                if (!$lgQuotes) {
                    $lgQuotes = (isset($this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->isResi;
                }
                $resiPickup = isset($this->quoteSettings['residentialPickup']) && $this->quoteSettings['residentialPickup'] ? '+pu' : '';

                $notifyDelivery =
                    (isset($this->quoteSettings['always_quote_notify']) && $this->quoteSettings['always_quote_notify']) ||
                    (isset($this->quoteSettings['offer_notify_as_option']) && $this->quoteSettings['offer_notify_as_option']);
            }
            $arraySorting = [];
            if (isset($quote['q'])) {
                if (isset($quote['hazardousStatus'])) {
                    $hazShipmentArr[$origin] = $quote['hazardousStatus'] == 'y' ? 'Y' : 'N';
                }
                foreach ($quote['q'] as $key => $data) {
                    $isStandardService = isset($data['ratserviceLevel']) && isset($data['ratserviceLevel']['rattext']) && $data['ratserviceLevel']['rattext'] == 'LTL Standard Transit';

                    if (!$isStandardService) {
                        continue;
                    }

                    if (isset($data['ratquoteNumber'])) {
                        if (isset($data['rataccessorialInfo'])) {
                            foreach ($data['rataccessorialInfo'] as $rateEstes) {
                                foreach ($rateEstes as $rateEstesfecth) {
                                    if (isset($rateEstesfecth['ratcode']) && $rateEstesfecth['ratcode'] == "LGATE") {
                                        $data['surcharges']['liftgateFee'] = $rateEstesfecth['ratcharge'];
                                    }
                                    if (isset($rateEstesfecth['ratcode']) && $rateEstesfecth['ratcode'] == "NCM" || isset($rateEstesfecth['ratcode']) && $rateEstesfecth['ratcode'] == "HDSIG") {
                                        $data['surcharges']['notifyDeliveryFee'] = $rateEstesfecth['ratcharge'];
                                    }
                                    if (isset($rateEstesfecth['ratcode']) && $rateEstesfecth['ratcode'] == "HD") {
                                        $data['surcharges']['residentialFee'] = $rateEstesfecth['ratcharge'];
                                    }
                                    if (isset($rateEstesfecth['ratcode']) && $rateEstesfecth['ratcode'] == "HAZ") {
                                        $data['surcharges']['hazardousMaterialsFee'] = $rateEstesfecth['ratcharge'];
                                    }

                                }

                            }
                        }
                        if (isset($data['ratpricing']) && !(isset($data['totalNetCharge']))) {
                            $data['totalNetCharge']['Amount'] = (float) $data['ratpricing']['rattotalPrice'] ?? 0;
                        } else if (isset($data['totalNetCharge'])) {
                            $chargeWithPalletFee = $data['totalNetCharge'] ?? 0;
                            unset($data['totalNetCharge']);
                            $data['totalNetCharge']['Amount'] = $chargeWithPalletFee;
                        }
                        // Apply override rates shipping rule
                        $data = $this->applyOverrideRatesRule($connectionSettings, $data);
                        // Apply Surcharge rates shipping rule
                        //$data = $this->applySurchargeRatesRule($connectionSettings, $data);
                        $price = $this->calculatePrice($data);
                        $access = $this->getAccessorialCode() . $resiPickup;

                        /*
                         * Date 01-07-22
                         * Adding Functionality of Delivery Estimate Options
                         * */
                        $date = $data['ratdelivery']['ratdate'] ?? null;
                        $days = $data['ratdelivery']['totalTransitTimeInDays'] ?? null;
                        $dateAndDays = ['deliveryDate' => $date, 'totalTransitTimeInDays' => $days];
                        $title = $this->getTitle($labelAs, false, false, $data['ratdelivery']['totalTransitTimeInDays'], [], $dateAndDays);
                        $arraySorting['simple'][$key] = $price;
                        $originQuotes[$origin]['simple'][$key]['code'] = 'estesltl' . $data['ratquoteNumber'] . $access;
                        $originQuotes[$origin]['simple'][$key]['rate'] = $price;
                        $originQuotes[$origin]['simple'][$key]['title'] = $title;

                        if ($lgQuotes) {
                            $lgAccess = 'estesltl' . $this->getAccessorialCode(true) . $resiPickup;
                            $lgPrice = $this->calculatePrice($data, true);
                            $lgTitle = $this->getTitle($labelAs, true, false, $data['ratdelivery']['totalTransitTimeInDays'], [], $dateAndDays);
                            $arraySorting['liftgate'][$key] = $lgPrice;
                            $originQuotes[$origin]['liftgate'][$key]['code'] = $data['ratquoteNumber'] . $lgAccess;
                            $originQuotes[$origin]['liftgate'][$key]['rate'] = $lgPrice;
                            $originQuotes[$origin]['liftgate'][$key]['title'] = $lgTitle;
                        }
                        // Get Notify Before Delivery Origin Quotes
                        if ($notifyDelivery) {
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes(
                                'notifydelivery',
                                $labelAs,
                                $originQuotes,
                                $data,
                                $origin,
                                $key, $data['ratdelivery']['totalTransitTimeInDays'],
                                $dateAndDays,
                                false,
                                'estesltl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi,
                                false,
                                false,
                                $notifyDelivery,
                                $resiPickup,
                                false,
                                $this->storeId,
                            );

                            $arraySorting['notifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }
                        if ($notifyDelivery && $lgQuotes) {
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes(
                                'lgnotifydelivery',
                                $labelAs,
                                $originQuotes,
                                $data,
                                $origin,
                                $key, $data['ratdelivery']['totalTransitTimeInDays'],
                                $dateAndDays,
                                true,
                                'estesltl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi,
                                false,
                                false,
                                $notifyDelivery,
                                $resiPickup,
                                false,
                                $this->storeId,
                            );

                            $arraySorting['lgnotifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }
                    }
                }
            }

            if (isset($inStoreLdData) && !empty($inStoreLdData)) {
                $originQuotes[$origin] = $this->inStoreLocalDeliveryQuotes($originQuotes[$origin], $inStoreLdData, $allOrigins);
            }
        }

        $allQuotes = $this->getFinalQuotesArray($originQuotes);

        if (!empty($hatShipments)) {
            return $this->arrangeHATFreight($allQuotes, $hatShipments);
        }

        return $allQuotes;
    }

    public function compileFedexSmallQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential, $destination)
    {
        $this->fedexSmallQuotesResults = new fedexSmallQuotesResults($this->SuppressParcelRates);
        if ($residential['fedexSmall'] == 'Y') {
            $this->isResi = true;
            $this->residentialDlvry = 1;
        } else {
            $this->isResi = false;
            $this->residentialDlvry = 0;
        }
        $this->alwaysResi = $this->residential['alwaysResi']['fedexSmall'] ?? false;
        $isSbsEnable = isset($residential['isSbsEnable']) ? $residential['isSbsEnable'] : false;
        $res = $this->fedexSmallQuotesResults->compileQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $this->isResi, $isSbsEnable, $this->isMultiShipment, $destination, $this->items, $this->storeId, $this->carrierName, $this->totalHazmatBoxes);
        if (!$this->isMultiShipment) {
            $this->isMultiShipment = $res['isMultiShipment'] ?? false;
        }
        return $res['resp'] ?? [];
    }

    public function compileGlobalTranzLtlQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential)
    {
        $this->isOverrideRates = false;
        $this->GTZLtlQuotesResults = new globalTranzQuotesResults();
        if ($residential['gtzLtl'] == 'Y') {
            $this->isResi = true;
            $this->residentialDlvry = 1;
        } else {
            $this->isResi = false;
            $this->residentialDlvry = 0;
        }
        $this->alwaysResi = $this->residential['alwaysResi']['gtzLtl'] ?? false;

        $access = $this->getAccessorialCodeSmall();
        $shipments = $this->GTZLtlQuotesResults->formateQuoteBeforeCompile($shipments);

        $this->quoteSettings = $connectionSettings['gtz-ltl']['quote_settings'] ?? [];

        $allConfigServices = $connectionSettings['gtz-ltl']['carrier_services']['GTZ'] ?? [];
        foreach ($allConfigServices as $key => $allConfigService) {
            $allConfigServices[$key] = explode('-', $allConfigService)[0];
        }
        $this->quoteSettingsData();
        $allQuotes = $odwArr = $hazShipmentArr = $multiShipmentQuotes = [];
        $count = 0;
        $lgQuotes = $notifyDelivery = $limitedAccess = $isResi = false;
        if ($this->residentialDlvry == '1' || $this->isResi || $this->alwaysResi) {
            $isResi = '+R';
        }
        $numberOfShipments = 0;
        foreach ($shipments as $ship) {
            if (!isset($ship['severity'])) {
                $numberOfShipments++;
            }
        }
        if (!$this->isMultiShipment) {
            $this->isMultiShipment = is_countable($shipments) && $numberOfShipments > 1;
        }
        foreach ($shipments as $origin => $quote) {
            $this->originKey = $origin;
            if (isset($quote['severity'])) {
                return $this->getInsPicAndLocDelQuotes($quote, $allOrigins);
            }

            if ($count == 0) { //To be checked only once
                $isRad = $quote['autoResidentialsStatus'] ?? '';
                $inStoreLdData = $quote['InstorPickupLocalDelivery'] ?? false;
                unset($quote['InstorPickupLocalDelivery']);

                $lgQuotes =
                    (isset($this->quoteSettings['alwaysLiftGateDelivery']) && $this->quoteSettings['alwaysLiftGateDelivery']) ||
                    (isset($this->quoteSettings['offerLiftGateDelivery']) && $this->quoteSettings['offerLiftGateDelivery']);
                if (!$lgQuotes) {
                    $lgQuotes = (isset($this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->isResi;
                }

                $notifyDelivery =
                    (isset($this->quoteSettings['always_quote_notify']) && $this->quoteSettings['always_quote_notify']) ||
                    (isset($this->quoteSettings['offer_notify_as_option']) && $this->quoteSettings['offer_notify_as_option']);

                $limitedAccess = (isset($this->quoteSettings['offer_limited_access_delivery']) && $this->quoteSettings['offer_limited_access_delivery']);
                // Todo: remove the below 2 lines after integrate limited access feature in GTZ
                $this->quoteSettings['offer_limited_access_delivery'] = false;
                $this->quoteSettings['always_limited_access_delivery'] = false;
            }
            $originQuotes = [];
            $arraySorting = [];
            $preCode = 'gtzltl';
            if (isset($quote['q'])) {
                if (isset($quote['hazardousStatus'])) {
                    $hazShipmentArr[$origin] = $quote['hazardousStatus'] == 'y' ? 'Y' : 'N';
                }
                foreach ($quote['q'] as $key => $data) {
                    if (isset($data['serviceType']) && in_array($data['serviceType'], $allConfigServices) /*&& isset($data['GuaranteedDaysToDelivery']) && $data['GuaranteedDaysToDelivery'] != 'Y' */) {
        
                        // Below commit use for future.
                        //$data = $this->applyOverrideRatesRule($connectionSettings, $data);

                        /*
                         * Date 01-07-22
                         * Adding Functionality of Delivery Estimate Options
                         * */
                        $date = $data['EstimatedDeliveryDate'] ?? null;
                        $days = $data['totalTransitTimeInDays'] ?? null;
                        $dateAndDays = ['deliveryDate' => $date, 'totalTransitTimeInDays' => $days];

                        $enableFeaturesArray = Functions::getEnableFeaturesArr($lgQuotes, $insideDelivery ?? false, $notifyDelivery, $limitedAccess ?? false);
                        foreach ($enableFeaturesArray as $index => $feature) {
                            if($feature['isEnable']){
                                $compileNotifyDeliveryQuotes = Functions::getOriginQuotes(
                                    $index, $data['serviceDesc'], $originQuotes, $data, $origin, $key, $data['totalTransitTimeInDays'], 
                                    $dateAndDays, $feature['index']['isLG'] ?? false, $preCode, $this->originKey, $this->items, 
                                    $this->allOrigins, $this->quoteSettings, 
                                    $this->isResi, $this->alwaysResi, $feature['index']['isID'] ?? false, 
                                    $feature['index']['isLAD'] ?? false, $feature['index']['isNBD'] ?? false,
                                    false,
                                    false,
                                    $this->storeId,
                                );

                                $arraySorting[$index][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                                $arraySorting['quickest'][$index][$key] = $data['totalTransitTimeInDays'];
                                $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                            }

                        }
                    }
                }
            }
            if (!$this->isMultiShipment) {
                $compiledQuotes = $this->getGTZCompiledQuotes($originQuotes, $arraySorting, $lgQuotes, $notifyDelivery, $limitedAccess);
            } else {
                if (isset($this->quoteSettings['quickest_service']) && $this->quoteSettings['quickest_service'] == 1 && isset($this->quoteSettings['method']) && $this->quoteSettings['method'] == 0) {
                    $arraySorting = $arraySorting['quickest'] ?? $arraySorting;
                }
                $compiledQuotes = $this->getGTZQuotes($originQuotes, $arraySorting, $lgQuotes, $notifyDelivery);
            }

            if (isset($inStoreLdData) && !empty($inStoreLdData)) {
                $compiledQuotes = $this->inStoreLocalDeliveryQuotes($compiledQuotes, $inStoreLdData, $allOrigins);
            }

            $finalCompiledQuotes[$origin] = $compiledQuotes;
            $count++;
        }

        $allQuotes = $this->getFinalQuotesArray($finalCompiledQuotes);
        return $allQuotes;
    }

    public function compileCerasisLtlQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential)
    {
        $this->isOverrideRates = false;
        $this->isGTZCerasis = true;
        $this->GTZLtlQuotesResults = new globalTranzQuotesResults();
        if ($residential['gtzLtl'] == 'Y') {
            $this->isResi = true;
            $this->residentialDlvry = 1;
        } else {
            $this->isResi = false;
            $this->residentialDlvry = 0;
        }
        $this->alwaysResi = $this->residential['alwaysResi']['gtzLtl'] ?? false;

        $access = $this->getAccessorialCodeSmall();
        $shipments = $this->GTZLtlQuotesResults->formateCerasisQuoteBeforeCompile($shipments);

        $this->quoteSettings = $connectionSettings['gtz-ltl']['quote_settings'] ?? [];
        $this->quoteSettings['method'] = $this->quoteSettings['rating_method'] ?? $this->quoteSettings['method'] ?? 1;

        $allConfigServices = $connectionSettings['gtz-ltl']['carrier_services']['CRS'] ?? [];

        $this->quoteSettingsData();
        $allQuotes = $odwArr = $hazShipmentArr = $multiShipmentQuotes = [];
        $count = 0;
        $lgQuotes = $notify = $laccess = $isResi = false;
        if ($this->residentialDlvry == '1' || $this->isResi || $this->alwaysResi) {
            $isResi = '+R';
        }
        $numberOfShipments = 0;
        foreach ($shipments as $ship) {
            if (!isset($ship['severity'])) {
                $numberOfShipments++;
            }
        }
        $isShippingFinalMile = isset($this->quoteSettings['shipping_service']) && $this->quoteSettings['shipping_service'] == 'final_mile';
        $labelAs = '';
        if ($isShippingFinalMile && isset($this->quoteSettings['final_mile_service_level'])) {
            if ($this->quoteSettings['final_mile_service_level'] == 'premium') {
                $labelAs = $this->quoteSettings['premium_label'] ?? 'Premium';
            } else if ($this->quoteSettings['final_mile_service_level'] == 'threshold') {
                $labelAs = $this->quoteSettings['threshold_label'] ?? 'Threshold';
            } else if ($this->quoteSettings['final_mile_service_level'] == 'room_of_choice') {
                $labelAs = $this->quoteSettings['room_of_choice_label'] ?? 'Room of Choice';
            }
        } else {
            if (isset($this->quoteSettings['rating_method']) && $this->quoteSettings['rating_method'] == 3) { //Average
                $this->quoteSettings['label_as'] = $this->quoteSettings['average_rate_label'] ?? '';
            } else if (isset($this->quoteSettings['rating_method']) && $this->quoteSettings['rating_method'] == 1) { //cheapest
                $this->quoteSettings['label_as'] = $this->quoteSettings['cheapest_label'] ?? '';
            }
        }
        if (!$this->isMultiShipment) {
            $this->isMultiShipment = is_countable($shipments) && $numberOfShipments > 1;
        }
        foreach ($shipments as $origin => $quote) {
            $this->originKey = $origin;
            if (isset($quote['severity'])) {
                return $this->getInsPicAndLocDelQuotes($quote, $allOrigins);
            }

            if ($count == 0) { //To be checked only once
                $isRad = $quote['autoResidentialsStatus'] ?? '';
                $inStoreLdData = $quote['InstorPickupLocalDelivery'] ?? false;
                unset($quote['InstorPickupLocalDelivery']);

                $lgQuotes =
                    (isset($this->quoteSettings['alwaysLiftGateDelivery']) && $this->quoteSettings['alwaysLiftGateDelivery']) ||
                    (isset($this->quoteSettings['offerLiftGateDelivery']) && $this->quoteSettings['offerLiftGateDelivery']);
                if (!$lgQuotes) {
                    $lgQuotes = (isset($this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->isResi;
                }
                if ($isShippingFinalMile) {
                    $lgQuotes = $this->alwaysResi = $this->isResi = $isResi = false;
                    $this->residentialDlvry = 0;
                    $this->quoteSettings['method'] = 1;
                    $this->quoteSettings['label_as'] = $labelAs;
                }

                $notifyDelivery =
                    (isset($this->quoteSettings['always_quote_notify']) && $this->quoteSettings['always_quote_notify']) ||
                    (isset($this->quoteSettings['offer_notify_as_option']) && $this->quoteSettings['offer_notify_as_option']);
            }
            $originQuotes = [];
            $arraySorting = [];
            $preCode = 'cltl';
            if (isset($quote['q'])) {
                if (isset($quote['hazardousStatus'])) {
                    $hazShipmentArr[$origin] = $quote['hazardousStatus'] == 'y' ? 'Y' : 'N';
                }
                foreach ($quote['q'] as $key => $data) {
                    if (isset($data['serviceType']) && in_array($data['serviceType'], $allConfigServices) /*&& isset($data['GuaranteedDaysToDelivery']) && $data['GuaranteedDaysToDelivery'] != 'Y' */) {
                        // Below commit use for future.
                        //$data = $this->applyOverrideRatesRule($connectionSettings, $data);
                        $access = $preCode . $this->getAccessorialCode();
                        $price = $this->calculatePrice($data);

                        /*
                         * Date 01-07-22
                         * Adding Functionality of Delivery Estimate Options
                         * */
                        $date = $data['deliveryDate'] ?? null;
                        $days = $data['totalTransitTimeInDays'] ?? null;
                        if ($isShippingFinalMile) {
                            $dateAndDays = ['deliveryDate' => null, 'totalTransitTimeInDays' => null];
                        } else {
                            $dateAndDays = ['deliveryDate' => $date, 'totalTransitTimeInDays' => $days];
                        }
                        $title = $this->getTitle($data['serviceDesc'], false, false, $data['transitTime'], [], $dateAndDays);
                        $arraySorting['simple'][$key] = $price;
                        $originQuotes[$key]['simple']['code'] = $data['serviceType'] . $access;
                        $originQuotes[$key]['simple']['rate'] = $price;
                        $originQuotes[$key]['simple']['title'] = $title;

                        //if(!$this->isOverrideRates){
                        if ($lgQuotes) {
                            $access = $preCode . $this->getAccessorialCode(true);
                            $price = $this->calculatePrice($data, true);
                            $title = $this->getTitle($data['serviceDesc'], true, false, $data['transitTime'], [], $dateAndDays);
                            $arraySorting['liftgate'][$key] = $price;
                            $originQuotes[$key]['liftgate']['code'] = $data['serviceType'] . $access;
                            $originQuotes[$key]['liftgate']['rate'] = $price;
                            $originQuotes[$key]['liftgate']['title'] = $title;
                        }
                        // Get Notify Before Delivery Origin Quotes
                        if ($notifyDelivery) {
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes(
                                'notifydelivery', $data['serviceDesc'],
                                $originQuotes,
                                $data,
                                $key, $data['transitTime'],
                                $dateAndDays,
                                false,
                                $preCode, $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi,
                                false,
                                false,
                                $notifyDelivery,
                                false,
                                false,
                                $this->storeId,
                            );

                            $arraySorting['notifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }
                        if ($notifyDelivery && $lgQuotes) {
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes(
                                'lgnotifydelivery', $data['serviceDesc'],
                                $originQuotes,
                                $data,
                                $key, $data['transitTime'],
                                $dateAndDays,
                                $lgQuotes,
                                $preCode, $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi,
                                false,
                                false,
                                $notifyDelivery,
                                false,
                                false,
                                $this->storeId,
                            );

                            $arraySorting['lgnotifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }
                        //}
                    }
                }
            }

            $compiledQuotes = $this->getCompiledQuotes($originQuotes, $arraySorting, $lgQuotes);

            if ($compiledQuotes !== null && !empty($compiledQuotes)) {
                if (count($compiledQuotes) > 1) {
                    foreach ($compiledQuotes as $k => $service) {
                        foreach ($service as $serKey => $ser) {
                            $quotes = Functions::getQuotesArray($service, $allQuotes, $multiShipmentQuotes, $origin, $serKey);
                            $allQuotes = $quotes['allQuotes'];
                            $multiShipmentQuotes = $quotes['multiShipmentQuotes'];
                        }
                    }
                } else {
                    $service = reset($compiledQuotes);
                    foreach ($service as $serKey => $ser) {
                        $quotes = Functions::getQuotesArray($service, $allQuotes, $multiShipmentQuotes, $origin, $serKey);
                        $allQuotes = $quotes['allQuotes'];
                        $multiShipmentQuotes = $quotes['multiShipmentQuotes'];
                    }
                }
            }

            if ($this->isMultiShipment) {
                $odwArr[$origin]['quotes'] = $compiledQuotes;
            }
            $count++;
        }
        $allQuotes = $this->getFinalQuotesArray($allQuotes);

        if (!$this->isMultiShipment && isset($inStoreLdData) && !empty($inStoreLdData)) {
            $allQuotes = $this->inStoreLocalDeliveryQuotes($allQuotes, $inStoreLdData, $allOrigins);
        }
        if ($this->multiOrigins && ((!empty($multiShipmentQuotes['simple']) && count($multiShipmentQuotes['simple']) > 1) || (!empty($multiShipmentQuotes['liftgate']) && count($multiShipmentQuotes['liftgate']) > 1))) {

            $allQuotes = $this->forceChangeTitle($allQuotes);
            $resp = [
                'checkoutQuotes' => $allQuotes,
                'multiShipmentQuotes' => $multiShipmentQuotes,
            ];
            return $resp;
        }

        return $allQuotes;
    }

    public function compileFedexLtlQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential)
    {
        $fedexLtl = new fedexLtlQuotesResults();

        if ($residential['fedexLtl'] == 'Y') {
            $this->isResi = true;
            $this->residentialDlvry = 1;
        } else {
            $this->isResi = false;
            $this->residentialDlvry = 0;
        }

        $this->alwaysResi = $this->residential['alwaysResi']['fedexLtl'] ?? false;
        $this->quoteSettings = $connectionSettings['fedex-ltl']['quote_settings'] ?? [];
        $shipments = $fedexLtl->formateQuoteBeforeCompile($shipments, $this->quoteSettings);
        $allConfigServices = [];

        if (isset($this->quoteSettings['fedex_freight_economy']) && $this->quoteSettings['fedex_freight_economy']) {
            array_push($allConfigServices, 'FEDEX_FREIGHT_ECONOMY');
            array_push($allConfigServices, 'fedexltl+HAT+EC');
        }
        if (isset($this->quoteSettings['fedex_freight_priority']) && $this->quoteSettings['fedex_freight_priority']) {
            array_push($allConfigServices, 'FEDEX_FREIGHT_PRIORITY');
            array_push($allConfigServices, 'fedexltl+HAT+PR');
        }

        $this->quoteSettingsData();
        $allQuotes = $odwArr = $hazShipmentArr = $multiShipmentQuotes = [];
        $count = 0;
        $lgQuotes = false;
        $numberOfShipments = 0;

        foreach ($shipments as $ship) {
            if (!isset($ship['severity'])) {
                $numberOfShipments++;
            }
        }

        if (!$this->isMultiShipment) {
            $this->isMultiShipment = is_countable($shipments) && $numberOfShipments > 1;
        }

        $freightEconomyLableAs = $this->quoteSettings['fedex_freight_economy_label'] ?? '';
        $freightPriorityLableAs = $this->quoteSettings['fedex_freight_priority_label'] ?? '';
        $hatShipments = [];
        $hatArraySorting = [];

        foreach ($shipments as $origin => $quote) {
            $this->originKey = $origin;
            if (isset($quote['severity'])) {
                return $this->getInsPicAndLocDelQuotes($quote, $allOrigins);
            }

            if ($count == 0) { //To be checked only once
                $isRad = $quote['autoResidentialsStatus'] ?? '';
                $inStoreLdData = $quote['InstorPickupLocalDelivery'] ?? false;
                unset($quote['InstorPickupLocalDelivery']);
                $lgQuotes =
                    (isset($this->quoteSettings['alwaysLiftGateDelivery']) && $this->quoteSettings['alwaysLiftGateDelivery']) ||
                    (isset($this->quoteSettings['offerLiftGateDelivery']) && $this->quoteSettings['offerLiftGateDelivery']);
                if (!$lgQuotes) {
                    $lgQuotes = (isset($this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->isResi;
                }
                $notifyDelivery =
                    (isset($this->quoteSettings['always_quote_notify']) && $this->quoteSettings['always_quote_notify']) ||
                    (isset($this->quoteSettings['offer_notify_as_option']) && $this->quoteSettings['offer_notify_as_option']);
            }
            $originQuotes = [];
            $arraySorting = [];
            $hatArraySorting = [];

            if (isset($quote['q'])) {
                if (isset($quote['hazardousStatus'])) {
                    $hazShipmentArr[$origin] = $quote['hazardousStatus'] == 'y' ? 'Y' : 'N';
                }

                foreach ($quote['q'] as $key => $data) {
                    $isHatSrvc = isset($data['serviceType']) && strpos($data['serviceType'], 'HAT+') !== false;
                    if (isset($data['serviceType']) && isset($data['serviceDesc']) && in_array($data['serviceType'], $allConfigServices)) {
                        if ($isHatSrvc) {
                            $data['totalNetCharge']['Amount'] = $this->calculatePrice($data);
                            $hatShipments[$key] = $data;
                            $hatArraySorting['simple'][$key] = $data['totalNetCharge']['Amount'];
                            continue;
                        }
                        // Apply override rates shipping rule
                        $data = $this->applyOverrideRatesRule($connectionSettings, $data);
                        $price = $this->calculatePrice($data);
                        $access = $this->getAccessorialCode();

                        if (isset($data['serviceType']) && $data['serviceType'] === 'FEDEX_FREIGHT_ECONOMY') {
                            $this->quoteSettings['label_as'] = !blank($freightEconomyLableAs) ? $freightEconomyLableAs : 'LTL Freight Economy';
                        }
                        if (isset($data['serviceType']) && $data['serviceType'] === 'FEDEX_FREIGHT_PRIORITY') {
                            $this->quoteSettings['label_as'] = !blank($freightPriorityLableAs) ? $freightPriorityLableAs : 'LTL Freight Priority';
                        }
                        /*
                         * Date 01-07-22
                         * Adding Functionality of Delivery Estimate Options
                         * */
                        $date = $data['deliveryTimestamp'] ?? null;
                        $days = $data['totalTransitTimeInDays'] ?? null;
                        $dateAndDays = ['deliveryDate' => $date, 'totalTransitTimeInDays' => $days];
                        $title = $this->getTitle($data['serviceDesc'], false, false, $data['transitTime'], [], $dateAndDays);

                        $holdAtTerminal = false;
                        $holdAtTerminalQuotes = [];
                        if (isset($data['holdAtTerminalResponse']) && !empty($data['holdAtTerminalResponse'])) {
                            $holdAtTerminal = true;

                            $terminalDateAndDays = $fedexLtl->terminalData($data['holdAtTerminalResponse']);
                            $terminalTitle = $this->quoteSettings['label_as'] . ' (T)' . $this->getDeliveryEstimates($terminalDateAndDays);
                            $holdAtTerminalQuotes = $fedexLtl->holdAtTerminalResponse($data['holdAtTerminalResponse'], $terminalTitle, $this->quoteSettings);
                        }

                        $arraySorting['simple'][$key] = $price;
                        $originQuotes[$key]['simple']['code'] = 'fedexltl' . $access;
                        $originQuotes[$key]['simple']['rate'] = $price;
                        $originQuotes[$key]['simple']['title'] = $title;

                        if ($lgQuotes) {
                            $lgAccess = $this->getAccessorialCode(true);
                            $lgPrice = $this->calculatePrice($data, true);
                            $lgTitle = $this->getTitle($data['serviceDesc'], true, false, $data['transitTime'], [], $dateAndDays);
                            $arraySorting['liftgate'][$key] = $lgPrice;
                            $originQuotes[$key]['liftgate']['code'] = 'fedexltl' . $lgAccess;
                            $originQuotes[$key]['liftgate']['rate'] = $lgPrice;
                            $originQuotes[$key]['liftgate']['title'] = $lgTitle;
                        }
                        // Get Notify Before Delivery Origin Quotes
                        if ($notifyDelivery) {
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes(
                                'notifydelivery', $data['serviceDesc'],
                                $originQuotes,
                                $data,
                                $key, $data['transitTime'],
                                $dateAndDays,
                                false,
                                'fedexltl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi,
                                false,
                                false,
                                $notifyDelivery,
                                false,
                                false,
                                $this->storeId,
                            );

                            $arraySorting['notifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }
                        if ($notifyDelivery && $lgQuotes) {
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes(
                                'lgnotifydelivery', $data['serviceDesc'],
                                $originQuotes,
                                $data,
                                $key, $data['transitTime'],
                                $dateAndDays,
                                true,
                                'fedexltl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi,
                                false,
                                false,
                                $notifyDelivery,
                                false,
                                false,
                                $this->storeId
                            );

                            $arraySorting['lgnotifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }
                    }
                }
            }

            $compiledQuotes = $fedexLtl->getCompiledQuotes($originQuotes, $arraySorting, $lgQuotes, $this->isMultiShipment);
            $hatShipment = array_values($fedexLtl->getCompiledQuotes($hatShipments, $hatArraySorting, $lgQuotes, $this->isMultiShipment));
            if ($this->isMultiShipment && !empty($hatShipment)) {
                $HAT[] = $hatShipment[0];
            } else {
                $HAT = $hatShipment;
            }

            if ($compiledQuotes !== null && !empty($compiledQuotes)) {
                // Get Quotes Array
                if (count($compiledQuotes) > 1) {
                    foreach ($compiledQuotes as $k => $service) {
                        foreach ($service as $serKey => $ser) {
                            $quotes = Functions::getQuotesArray($service, $allQuotes, $multiShipmentQuotes, $origin, $serKey);
                            $allQuotes = $quotes['allQuotes'];
                            $multiShipmentQuotes = $quotes['multiShipmentQuotes'];
                        }
                    }
                } else {
                    $service = reset($compiledQuotes);
                    foreach ($service as $serKey => $ser) {
                        $quotes = Functions::getQuotesArray($service, $allQuotes, $multiShipmentQuotes, $origin, $serKey);
                        $allQuotes = $quotes['allQuotes'];
                        $multiShipmentQuotes = $quotes['multiShipmentQuotes'];
                    }
                }
            }
            if ($this->isMultiShipment) {
                $odwArr[$origin]['quotes'] = $compiledQuotes;
            }
            $count++;
        }

        $allQuotes = $this->getFinalQuotesArray($allQuotes);
        if (!$this->isMultiShipment && isset($inStoreLdData) && !empty($inStoreLdData)) {
            $allQuotes = $this->inStoreLocalDeliveryQuotes($allQuotes, $inStoreLdData, $allOrigins);
            $HAT = Functions::setEmptyHATQuotesArray($allOrigins, $inStoreLdData, $HAT);
        }
        
        if ($this->multiOrigins && ((!empty($multiShipmentQuotes['simple']) && count($multiShipmentQuotes['simple']) > 1) || (!empty($multiShipmentQuotes['liftgate']) && count($multiShipmentQuotes['liftgate']) > 1))) {
            if (!empty($HAT)) {
                $allQuotes = $this->forceChangeTitle($allQuotes);
                $hatLabel = explode('|', $HAT[0]['serviceDesc']);
                unset($hatLabel[0]);
                $lableAs = 'Freight |' . implode('|', $hatLabel);
                $resp = [
                    'checkoutQuotes' => Functions::arrangeHATFreight($allQuotes, $HAT, $lableAs),
                    'multiShipmentQuotes' => Functions::arrangeHATMulti($multiShipmentQuotes, $HAT),
                ];
            } else {
                $allQuotes = $this->forceChangeTitle($allQuotes);
                $resp = [
                    'checkoutQuotes' => Functions::arrangeHATFreight($allQuotes, $HAT, Functions::$ltlMultiTitle),
                    'multiShipmentQuotes' => $multiShipmentQuotes,
                ];
            }

            return $resp;
        }

        if (!empty($HAT)) {
            return $fedexLtl->arrangeHATFreight($allQuotes, $HAT);
        }

        return $this->arrangeOwnFreight($allQuotes);
    }

    public function compileXPOLtlQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential)
    {
        $xpoLtl = new xpoLtlQuotesResults();
        if ($residential['xpoLtl'] == 'Y') {
            $this->isResi = true;
            $this->residentialDlvry = 1;
        } else {
            $this->isResi = false;
            $this->residentialDlvry = 0;
        }
        
        $this->alwaysResi = $this->residential['alwaysResi']['xpoLtl'] ?? false;
        $this->quoteSettingsData();
        $this->quoteSettings = $connectionSettings['xpo-ltl']['quote_settings'] ?? [];
        $shipments = $xpoLtl->formateQuoteBeforeCompile($shipments, $this->quoteSettings);
        $allQuotes = $odwArr = $hazShipmentArr = $multiShipmentQuotes = [];
        $count = 0;
        $lgQuotes = false;
        $numberOfShipments = 0;
        $originQuotes = [];
        foreach ($shipments as $ship) {
            if (!isset($ship['severity'])) {
                $numberOfShipments++;
            }
        }
        if (!$this->isMultiShipment) {
            $this->isMultiShipment = is_countable($shipments) && $numberOfShipments > 1;
        }
        $lableAs = $this->quoteSettings['label_as'] ?? '';
        $hatShipments = [];

        foreach ($shipments as $origin => $quote) {
            $this->originKey = $origin;
            $this->isSurchargeRates = false;
            if ((isset($quote['severity']) || !isset($quote['q']) || (isset($quote['q']) && empty($quote['q'])))) {
                $instoreResp[$origin] = $this->getInsPicAndLocDelQuotes($quote, $allOrigins) ?? [];                
                return $instoreResp;
            }

            if ($count == 0) { //To be checked only once
                $isRad = $quote['autoResidentialsStatus'] ?? '';
                $inStoreLdData = $quote['InstorPickupLocalDelivery'] ?? false;
                unset($quote['InstorPickupLocalDelivery']);
                $lgQuotes =
                    (isset($this->quoteSettings['alwaysLiftGateDelivery']) && $this->quoteSettings['alwaysLiftGateDelivery']) ||
                    (isset($this->quoteSettings['offerLiftGateDelivery']) && $this->quoteSettings['offerLiftGateDelivery']);
                if (!$lgQuotes) {
                    $lgQuotes = (isset($this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->isResi;
                }

                $notifyDelivery =
                    (isset($this->quoteSettings['always_quote_notify']) && $this->quoteSettings['always_quote_notify']) ||
                    (isset($this->quoteSettings['offer_notify_as_option']) && $this->quoteSettings['offer_notify_as_option']);
            }
            
            $arraySorting = [];

            if (isset($quote['q'])) {
                if (isset($quote['hazardousStatus'])) {
                    $hazShipmentArr[$origin] = $quote['hazardousStatus'] == 'y' ? 'Y' : 'N';
                }
                foreach ($quote['q'] as $key => $data) {
                    $isHATQuote = isset($data['serviceType']) && strpos($data['serviceType'], 'HAT+') !== false;
                    if ($isHATQuote) {
                        $hatShipments[$origin]['hat'][] = $data;
                        continue;
                    }
                    // Apply override rates shipping rule
                    $data = $this->applyOverrideRatesRule($connectionSettings, $data);
                    // Apply Surcharge rates shipping rule
                    $data = $this->applySurchargeRatesRule($connectionSettings, $data);
                    $price = $this->calculatePrice($data);
                    $access = $this->getAccessorialCode();

                    /*
                     * Date 01-07-22
                     * Adding Functionality of Delivery Estimate Options
                     * */
                    $date = $data['deliveryDate'] ?? $data['deliveryTimestamp'] ?? null;
                    $days = $data['totalTransitTimeInDays'] ?? null;
                    $dateAndDays = ['deliveryDate' => $date, 'totalTransitTimeInDays' => $days];
                    $title = $this->getTitle($data['serviceDesc'], false, false, $data['totalTransitTimeInDays'], [], $dateAndDays);

                    $arraySorting['simple'][$key] = $price;
                    $originQuotes[$origin]['simple'][$key]['code'] = 'xpoltl' . $access;
                    $originQuotes[$origin]['simple'][$key]['rate'] = $price;
                    $originQuotes[$origin]['simple'][$key]['title'] = $title;

                    if ($lgQuotes) {
                        $lgAccess = $this->getAccessorialCode(true);
                        $lgPrice = $this->calculatePrice($data, true);
                        $lgTitle = $this->getTitle($data['serviceDesc'], true, false, $data['totalTransitTimeInDays'], [], $dateAndDays);
                        $arraySorting['liftgate'][$key] = $lgPrice;
                        $originQuotes[$origin]['liftgate'][$key]['code'] = 'xpoltl' . $lgAccess;
                        $originQuotes[$origin]['liftgate'][$key]['rate'] = $lgPrice;
                        $originQuotes[$origin]['liftgate'][$key]['title'] = $lgTitle;
                    }
                    // Get Notify Before Delivery Origin Quotes
                    if ($notifyDelivery) {
                        $compileNotifyDeliveryQuotes = Functions::getOriginQuotes(
                            'notifydelivery', $data['serviceDesc'],
                            $originQuotes,
                            $data,
                            $origin,
                            $key, $data['totalTransitTimeInDays'],
                            $dateAndDays,
                            false,
                            'xpoltl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi,
                            false,
                            false,
                            $notifyDelivery,
                            false,
                            false,
                            $this->storeId,
                            $this->isSurchargeRates,
                        );

                        $arraySorting['notifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                        $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                    }
                    if ($notifyDelivery && $lgQuotes) {
                        $compileNotifyDeliveryQuotes = Functions::getOriginQuotes(
                            'lgnotifydelivery', $data['serviceDesc'],
                            $originQuotes,
                            $data,
                            $origin,
                            $key, $data['totalTransitTimeInDays'],
                            $dateAndDays,
                            true,
                            'xpoltl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi,
                            false,
                            false,
                            $notifyDelivery,
                            false,
                            false,
                            $this->storeId,
                            $this->isSurchargeRates,
                        );

                        $arraySorting['lgnotifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                        $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                    }
                }
            }

            if (isset($inStoreLdData) && !empty($inStoreLdData)) {
                $originQuotes[$origin] = $this->inStoreLocalDeliveryQuotes($originQuotes[$origin], $inStoreLdData, $allOrigins);
            }
            
            $count++;
        }

        $allQuotes = $this->getFinalQuotesArray($originQuotes);

        if (!empty($hatShipments)) {
            return $this->arrangeHATFreight($allQuotes, $hatShipments);
        }

        return $allQuotes;
    }

    public function compileRNLLtlQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential, $freeRNL)
    {
        if ($freeRNL) {
            return $this->arrangeFreeRNL([]);
        }
        $rnlLtl = new rnlLtlQuotesResults();
        if ($residential['rnlLtl'] == 'Y') {
            $this->isResi = true;
            $this->residentialDlvry = 1;
        } else {
            $this->isResi = false;
            $this->residentialDlvry = 0;
        }
        $this->alwaysResi = $this->residential['alwaysResi']['rnlLtl'] ?? false;
        $this->quoteSettings = $connectionSettings['rl-ltl']['quote_settings'] ?? [];
        $shipments = $rnlLtl->formateQuoteBeforeCompile($shipments, $this->quoteSettings);

        $allConfigServices = [];
        if (isset($this->quoteSettings['standard_service']) && $this->quoteSettings['standard_service']) {
            array_push($allConfigServices, 'STD');
        }
        if (isset($this->quoteSettings['guaranteed_pm']) && $this->quoteSettings['guaranteed_pm']) {
            array_push($allConfigServices, 'GSDS');
        }
        if (isset($this->quoteSettings['guaranteed_am']) && $this->quoteSettings['guaranteed_am']) {
            array_push($allConfigServices, 'GSAM');
        }
        if (isset($this->quoteSettings['guaranteed_hourly_window']) && $this->quoteSettings['guaranteed_hourly_window']) {
            array_push($allConfigServices, 'GSHW');
        }
        $this->quoteSettingsData();
        $allQuotes = $odwArr = $hazShipmentArr = $multiShipmentQuotes = [];
        $count = 0;
        $lgQuotes = false;
        $numberOfShipments = 0;
        foreach ($shipments as $ship) {
            if (!isset($ship['severity'])) {
                $numberOfShipments++;
            }
        }
        if (!$this->isMultiShipment) {
            $this->isMultiShipment = is_countable($shipments) && $numberOfShipments > 1;
        }
        $lableAs = $this->quoteSettings['label_as'] ?? '';
        $preAccess = 'rnlltl';
        $HAT = [];
        $originQuotes = [];
        foreach ($shipments as $origin => $quote) {
            $this->originKey = $origin;
            if ((isset($quote['severity']) || !isset($quote['q']) || (isset($quote['q']) && empty($quote['q'])))) {
                $instoreResp[$origin] = $this->getInsPicAndLocDelQuotes($quote, $allOrigins) ?? [];                
                return $instoreResp;
            }
            if ($count == 0) { //To be checked only once
                $isRad = $quote['autoResidentialsStatus'] ?? '';
                $inStoreLdData = $quote['InstorPickupLocalDelivery'] ?? false;
                unset($quote['InstorPickupLocalDelivery']);
                $lgQuotes =
                    (isset($this->quoteSettings['alwaysLiftGateDelivery']) && $this->quoteSettings['alwaysLiftGateDelivery']) ||
                    (isset($this->quoteSettings['offerLiftGateDelivery']) && $this->quoteSettings['offerLiftGateDelivery']);
                if (!$lgQuotes) {
                    $lgQuotes = (isset($this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->isResi;
                }
                $insideDelivery = (isset($this->quoteSettings['offer_inside_delivery']) && $this->quoteSettings['offer_inside_delivery']) ||
                    (isset($this->quoteSettings['always_inside_delivery']) && $this->quoteSettings['always_inside_delivery']);

                $notifyDelivery =
                    (isset($this->quoteSettings['always_quote_notify']) && $this->quoteSettings['always_quote_notify']) ||
                    (isset($this->quoteSettings['offer_notify_as_option']) && $this->quoteSettings['offer_notify_as_option']);
            }

            $arraySorting = [];

            if (isset($quote['q'])) {
                if (isset($quote['hazardousStatus'])) {
                    $hazShipmentArr[$origin] = $quote['hazardousStatus'] == 'y' ? 'Y' : 'N';
                }

                foreach ($quote['q'] as $key => $data) {
                    if (!in_array($data['Code'], $allConfigServices)) {
                        continue;
                    }
                    $isHat = strpos($data['serviceType'], 'HAT+') !== false;
                    if ($isHat) {
                        $HAT[$origin]['hat'][] = $data;
                        continue;
                    }
                    // Apply Override rates shipping rule
                    $data = $this->applyOverrideRatesRule($connectionSettings, $data);
                    $price = $this->calculatePrice($data);
                    $access = $this->getAccessorialCode();

                    $this->quoteSettings['label_as'] = (!empty($lableAs) ? $lableAs . ' ' : '') . $data['serviceDesc'];
                    $date = $data['deliveryDate'] ?? null;
                    $days = $data['totalTransitTimeInDays'] ?? null;
                    $dateAndDays = ['deliveryDate' => $date, 'totalTransitTimeInDays' => $days];
                    $title = $this->getTitle($data['serviceDesc'], false, false, $data['totalTransitTimeInDays'], [], $dateAndDays);
                    $arraySorting['simple'][$key] = $price;
                    $originQuotes[$origin]['simple'][$key]['code'] = $data['serviceType'] . $preAccess . $access;
                    $originQuotes[$origin]['simple'][$key]['rate'] = $price;
                    $originQuotes[$origin]['simple'][$key]['title'] = $title;

                    if ($lgQuotes && !$isHat) {
                        $lgAccess = $this->getAccessorialCode(true);
                        $lgPrice = $this->calculatePrice($data, true);
                        $lgTitle = $this->getTitle($data['serviceDesc'], true, false, $data['totalTransitTimeInDays'], [], $dateAndDays);
                        $arraySorting['liftgate'][$key] = $lgPrice;
                        $originQuotes[$origin]['liftgate'][$key]['code'] = $data['serviceType'] . $preAccess . $lgAccess;
                        $originQuotes[$origin]['liftgate'][$key]['rate'] = $lgPrice;
                        $originQuotes[$origin]['liftgate'][$key]['title'] = $lgTitle;
                    }
                    if ($insideDelivery && !$isHat) {
                        $access = $this->getAccessorialCode(false, true, false, false);
                        $price = $this->calculatePrice($data, false, false, false, true);
                        $title = $this->getTitle($data['serviceDesc'], false, false, $data['totalTransitTimeInDays'], [], $dateAndDays, true);
                        $arraySorting['insideDelivery'][$key] = $price;
                        $originQuotes[$origin]['insideDelivery'][$key]['code'] = $data['serviceType'] . $access;
                        $originQuotes[$origin]['insideDelivery'][$key]['rate'] = $price;
                        $originQuotes[$origin]['insideDelivery'][$key]['title'] = $title;
                    }
                    if ($insideDelivery && $lgQuotes && !$isHat) {
                        $access = $this->getAccessorialCode(true, true, false, false);
                        $price = $this->calculatePrice($data, true, false, false, true);
                        $title = $this->getTitle($data['serviceDesc'], true, false, $data['totalTransitTimeInDays'], [], $dateAndDays, true);
                        $arraySorting['insideLiftGateDelivery'][$key] = $price;
                        $originQuotes[$origin]['insideLiftGateDelivery'][$key]['code'] = $data['serviceType'] . $access;
                        $originQuotes[$origin]['insideLiftGateDelivery'][$key]['rate'] = $price;
                        $originQuotes[$origin]['insideLiftGateDelivery'][$key]['title'] = $title;
                    }
                    // Get Notify Before Delivery Origin Quotes
                    if ($notifyDelivery && !$isHat) {
                        $compileNotifyDeliveryQuotes = Functions::getOriginQuotes(
                            'notifydelivery', $data['serviceDesc'],
                            $originQuotes,
                            $data,
                            $origin,
                            $key, $data['totalTransitTimeInDays'],
                            $dateAndDays,
                            false,
                            $preAccess, $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi,
                            false,
                            false,
                            $notifyDelivery,
                            false,
                            false,
                            $this->storeId,
                        );

                        $arraySorting['notifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                        $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                    }
                    if ($notifyDelivery && $lgQuotes && !$isHat) {
                        $compileNotifyDeliveryQuotes = Functions::getOriginQuotes(
                            'lgnotifydelivery', $data['serviceDesc'],
                            $originQuotes,
                            $data,
                            $origin,
                            $key, $data['totalTransitTimeInDays'],
                            $dateAndDays,
                            true,
                            $preAccess, $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi,
                            false,
                            false,
                            $notifyDelivery,
                            false,
                            false,
                            $this->storeId,
                        );

                        $arraySorting['lgnotifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                        $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                    }
                    if ($notifyDelivery && $insideDelivery && !$isHat) {
                        $compileNotifyDeliveryQuotes = Functions::getOriginQuotes(
                            'insidenotifydelivery', $data['serviceDesc'],
                            $originQuotes,
                            $data,
                            $origin,
                            $key, $data['totalTransitTimeInDays'],
                            $dateAndDays,
                            false,
                            $preAccess, $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi,
                            $insideDelivery,
                            false,
                            $notifyDelivery,
                            false,
                            false,
                            $this->storeId,
                        );

                        $arraySorting['insidenotifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                        $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                    }
                    if ($notifyDelivery && $insideDelivery && $lgQuotes && !$isHat) {
                        $compileNotifyDeliveryQuotes = Functions::getOriginQuotes(
                            'lginsidenotifydelivery', $data['serviceDesc'],
                            $originQuotes,
                            $data,
                            $origin,
                            $key, $data['totalTransitTimeInDays'],
                            $dateAndDays,
                            true,
                            $preAccess, $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi,
                            $insideDelivery,
                            false,
                            $notifyDelivery,
                            false,
                            false,
                            $this->storeId,
                        );

                        $arraySorting['lginsidenotifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                        $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                    }
                }
            }

            if (isset($inStoreLdData) && !empty($inStoreLdData)) {
                $originQuotes[$origin] = $this->inStoreLocalDeliveryQuotes($originQuotes[$origin], $inStoreLdData, $allOrigins);
            }
            
            $count++;
        }

        $allQuotes = $this->getFinalQuotesArray($originQuotes);

        if (!empty($HAT)) {
            return $this->arrangeHATFreight($allQuotes, $HAT);
        }

        return $allQuotes;
    }

    public function compileWweSmallQuotes($shipments, $connectionSettings, $allOrigins, $isHazmat, $smalLtlHazmat, $hazmatAllItems)
    {
        if ($this->residential['wweSmall'] == 'Y') {
            $this->isResi = true;
            $this->residentialDlvry = 1;
        } else {
            $this->isResi = false;
            $this->residentialDlvry = 0;
        }
        $this->alwaysResi = $this->residential['alwaysResi']['wweSmall'] ?? false;
        $isSbsEnable = isset($this->residential['isSbsEnable']) ? $this->residential['isSbsEnable'] : false;
        $this->quoteSettings = [];
        //$isHazmat = $isHazmat == "Y" ? true : false;
        $isHazmat = $smalLtlHazmat['smallHazmat'] ?? false;
        $this->quoteSettings = $connectionSettings['small-package']['quote_settings'] ?? '';
        $allConfigServices = $connectionSettings['small-package']['quote_settings']['carrier_services'] ?? [];

        // Removing Markup indexes from services
        $allConfigServices = $this->wweSmallQuoteRes->filterWweSmallServicesFromMarkup($allConfigServices);
        $enabledServices = $this->wweSmallQuoteRes->getEnabledServicesCodes($allConfigServices);
        if (empty($enabledServices)) {
            return [];
        }
       

        $rad_settings = Functions::getRADsettings($this->storeId) ?? [];
        $isRadNotation = isset($rad_settings['suppress_rad_notation']) && $rad_settings['suppress_rad_notation'];

        $numberOfShipments = 0;
        foreach ($shipments as $key => $ship) {
            if (!isset($ship['severity']) && !in_array($key, ['air', 'ground', 'oneRate'])) {
                $numberOfShipments++;
            }
        }
        if (!$this->isMultiShipment) {
            $this->isMultiShipment = is_countable($shipments) && $numberOfShipments > 1;
        }

        $originQuotes = $multiShipmentQuotes = [];
        $shipmentCount = 0;
        $count = 0;
        foreach ($shipments as $origin => $quote) {
            $this->originKey = $origin;

            if(in_array($origin, $this->SuppressParcelRates)){
                continue;
            }
            
            if (isset($quote['severity'])) {
                return $this->getInsPicAndLocDelQuotes($quote, $allOrigins);
            }
            if ($count == 0) { //To be checked only once
                $inStoreLdData = $quote['InstorPickupLocalDelivery'] ?? false;
                unset($quote['InstorPickupLocalDelivery']);
            }
            $lowestAmount = 0;

            if (isset($quote['q'])) {
                foreach ($quote['q'] as $key => $data) {
                    // Check if service type is checked to show
                    if (!isset($enabledServices[$data['serviceType']])) {
                        continue;
                    }
                    //  CHeck FOr Ups ground transit days
                    if ($data['serviceType'] == "GND" || $data['serviceType'] == "3DS" || $data['serviceType'] == "03") {
                        // TODO: ALso We have to check plan here
                        if (isset($this->quoteSettings['number_of_transit_days']) && $this->quoteSettings['number_of_transit_days'] != null && isset($this->quoteSettings['ground_metric']) && $this->quoteSettings['ground_metric'] != null) {
                            $islimited = $this->wweSmallQuoteRes->checkGroundTransit($data, $this->quoteSettings);
                            if ($islimited) {
                                continue;
                            }
                        }
                    }
                    //  CHecks FOr Only quote ground service if hazardous
                    if ($isHazmat && isset($this->quoteSettings['ground_service_for_hazardous_material']) && $this->quoteSettings['ground_service_for_hazardous_material']) {
                        if ($data['serviceType'] != "GND" && $data['serviceType'] != "3DS" && $data['serviceType'] != "03") {
                            continue;
                        }
                    }

                    $access = $this->getAccessorialCodeSmall();
                    
                    // Apply override rates shipping rule
                    $overrideRates = $this->shippingRule->overrideRates($this->storeId, $this->items, $connectionSettings, $data, $this->carrierName, $this->originKey, $this->allOrigins);
                    $data = isset($overrideRates['data']) ? $overrideRates['data'] : $data;

                    // Adding Product and Origin Markup in services if added
                    $productOriginMarkupFee = Functions::calProductOriginMarkupFee($data['totalNetCharge']['Amount'], $this->originKey, $this->items, $this->allOrigins);
                    $data['totalNetCharge']['Amount'] = $data['totalNetCharge']['Amount'] + $productOriginMarkupFee;
                    // Adding Markup in services if enabled
                    $quoteSettings = $this->quoteSettings;

                    $data['totalNetCharge']['Amount'] = $this->wweSmallQuoteRes->addHandlingMarkupOfHazmat($data['totalNetCharge']['Amount'], $quoteSettings['handling_fee_markup'] ?? 0);
                    $price = $data['totalNetCharge']['Amount'];
                    // Checking hazmat and adding hazmat amounts in services
                    if ($isHazmat) {
                        $hazmatBoxes = isset($this->totalHazmatBoxes['totalHazmatBoxes'][$origin]) ? $this->totalHazmatBoxes['totalHazmatBoxes'][$origin]['normal'] : 1;
                        if ($this->isMultiShipment) {
                            if ($hazmatAllItems[$origin] == 'Y') {
                                $price = $this->wweSmallQuoteRes->addHazmatAmountsInServices($price, $data['serviceType'], $this->quoteSettings, $isSbsEnable, $this->items, $hazmatBoxes);
                            }
                        } else {
                            $price = $this->wweSmallQuoteRes->addHazmatAmountsInServices($price, $data['serviceType'], $this->quoteSettings, $isSbsEnable, $this->items, $hazmatBoxes);
                        }
                    }

                    $price = $this->wweSmallQuoteRes->getServiceRate($price, $data['serviceType'], $this->quoteSettings);
                
                    $date = $data['deliveryTimestamp'] ?? null;
                    $days = $data['totalTransitTimeInDays'] ?? null;
                    $dateAndDays = ['deliveryDate' => $date, 'totalTransitTimeInDays' => $days];
                    $title = $this->wweSmallQuoteRes->getServiceTitle($data['serviceDesc'], $dateAndDays, $data['serviceType'], $this->quoteSettings, $this->isResi, $isRadNotation);
                    $price = (float) str_replace(',', '', $price);
                    $originQuotes[$shipmentCount]['shipment'][$key]['simple']['code'] = 'parcel_12wwe' . $data['serviceType'] . $access;
                    $originQuotes[$shipmentCount]['shipment'][$key]['simple']['rate'] = $price;
                    $originQuotes[$shipmentCount]['shipment'][$key]['simple']['title'] = $title;
                    $multiShipmentQuotes[$origin][$key] = $originQuotes[$shipmentCount]['shipment'][$key]['simple'];
                }
            }
            $shipmentCount++;
        }
        // $multiShipmentQuotes
        // Check for mukti shipment finding lowest price in each shipment and adding them for multi shipment
        if ($this->isMultiShipment && count($multiShipmentQuotes) > 1) {
            $originQuotesMulti = $multiShipmentQuote = [];
            $multiShipPrice = 0;
            foreach ($originQuotes as $shipmentKey => $shipment) {
                $netChargeArray = array_column($shipment['shipment'], 'simple');
                $minValueFromNetChargeArr = min(array_column($netChargeArray, 'rate'));
                $multiShipPrice += str_replace(',', '', $minValueFromNetChargeArr);
                $originQuotesMulti[0]['code'] = $this->isResi || $this->alwaysResi ? 'Multi+R' : 'Multi';
                $originQuotesMulti[0]['rate'] = number_format($multiShipPrice, 2);
                $originQuotesMulti[0]['title'] = $this->isResi ? Functions::$smallMultiTitle . ' ' . Constant::RESI_LABEL : Functions::$smallMultiTitle;
            }
            foreach ($multiShipmentQuotes as $shipmentKey => $shipment) {
                $keys = array_column($shipment, 'rate');
                array_multisort($keys, SORT_ASC, $shipment);
                $multiShipmentQuote['simple'][$shipmentKey] = array_values($shipment)[0];
            }
            $resp = [
                'checkoutQuotes' => $originQuotesMulti,
                'multiShipmentQuotes' => $multiShipmentQuote,
            ];
            return $resp;
        }
        // Doing For SIngle Shipment
        if (!empty($originQuotes)) {
            $originQuotes = array_column(array_values($originQuotes), 'shipment');
            $originQuotes = reset($originQuotes);
            $originQuotes = array_column(array_values($originQuotes), 'simple');
            // Checkking for instore pickup
            if (!$this->isMultiShipment && isset($inStoreLdData) && $inStoreLdData) {
                $allQuotes = $this->inStoreLocalDeliveryQuotes($originQuotes, $inStoreLdData, $allOrigins);
                return $allQuotes;
            }
            return $originQuotes;
        }
        /**
         * get quotes if supress is enables
         * refferce issue: https://eniture.atlassian.net/browse/QA-5458
         */
        if (!$this->isMultiShipment && isset($inStoreLdData) && $inStoreLdData) {
            $allQuotes = $this->inStoreLocalDeliveryQuotes($quote, $inStoreLdData, $allOrigins);
            return $allQuotes;
        }

        return [];
    }

    private function compileUpsLtlQuotes($shipments, $connectionSettings, $allOrigins)
    {
        if ($this->residential['upsLtl'] == 'Y') {
            $this->isResi = true;
            $this->residentialDlvry = 1;
        } else {
            $this->isResi = false;
            $this->residentialDlvry = 0;
        }

        $this->alwaysResi = $this->residential['alwaysResi']['upsLtl'] ?? false;
        $this->quoteSettings = $connectionSettings['ups-ltl']['quote_settings'] ?? [];
        $this->quoteSettingsData();
        $allQuotes = $odwArr = $hazShipmentArr = $multiShipmentQuotes = [];
        $count = 0;
        $lgQuotes = false;
        $numberOfShipments = 0;
        $originQuotes = [];

        foreach ($shipments as $ship) {
            if (!isset($ship['severity'])) {
                $numberOfShipments++;
            }
        }
        if (!$this->isMultiShipment) {
            $this->isMultiShipment = is_countable($shipments) && $numberOfShipments > 1;
        }
        $lableAs = $this->quoteSettings['label_as'] ?? 'Freight';
        $key = 0;
        foreach ($shipments as $origin => $quote) {
            $this->originKey = $origin;
            $this->isSurchargeRates = false;
            if (isset($quote['severity'])) {
                return $this->getInsPicAndLocDelQuotes($quote, $allOrigins);
            }

            if ($count == 0) { //To be checked only once
                $isRad = $quote['autoResidentialsStatus'] ?? '';
                $inStoreLdData = $quote['InstorPickupLocalDelivery'] ?? false;
                unset($quote['InstorPickupLocalDelivery']);
                $lgQuotes =
                    (isset($this->quoteSettings['alwaysLiftGateDelivery']) && $this->quoteSettings['alwaysLiftGateDelivery']) ||
                    (isset($this->quoteSettings['offerLiftGateDelivery']) && $this->quoteSettings['offerLiftGateDelivery']);
                if (!$lgQuotes) {
                    $lgQuotes = (isset($this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->isResi;
                }

                $notifyDelivery = !($this->isResi || $this->alwaysResi) && (
                    (isset($this->quoteSettings['always_quote_notify']) && $this->quoteSettings['always_quote_notify']) ||
                    (isset($this->quoteSettings['offer_notify_as_option']) && $this->quoteSettings['offer_notify_as_option']));
                
                if(!$notifyDelivery){
                    $this->quoteSettings['offer_notify_as_option'] = false;
                    $this->quoteSettings['always_quote_notify'] = false;
                }
            }
            
            $arraySorting = [];
            if (isset($quote['q'])) {
                if (isset($quote['hazardousStatus'])) {
                    $hazShipmentArr[$origin] = $quote['hazardousStatus'] == 'y' ? 'Y' : 'N';
                }
                $data = $quote['q'];

                $surcharges = $data['surcharges'] ?? [];
                unset($data['surcharges']);
                $data['surcharges'] = ['residentialFee' => 0, 'liftgateFee' => 0, 'notifyDeliveryFee' => 0];
                foreach ($surcharges as $surcharge) {
                    if ((isset($surcharge['Type']['Code']) && $surcharge['Type']['Code'] === 'RESI_PU_DEL') || (isset($surcharge['code']) && $surcharge['code'] == 'RESD')) {
                        $data['surcharges']['residentialFee'] = $surcharge['Factor']['Value'] ?? $surcharge['value'] ?? 0;
                    }
                    if (isset($surcharge['Type']['Code']) && $surcharge['Type']['Code'] === 'HAZMAT') {
                        $data['surcharges']['hazardousMaterialsFee'] = $surcharge['Factor']['Value'] ?? 0;
                    }
                    if ((isset($surcharge['Type']['Code']) && $surcharge['Type']['Code'] === 'LIFTGATE') || (isset($surcharge['code']) && $surcharge['code'] == 'LIFD')) {
                        $data['surcharges']['liftgateFee'] = $surcharge['Factor']['Value'] ?? $surcharge['value'] ?? 0;
                    }
                    if ((isset($surcharge['Type']['Code']) && $surcharge['Type']['Code'] === 'ADV_NOTF') || (isset($surcharge['code']) && $surcharge['code'] == 'NTFN')) {
                        $data['surcharges']['notifyDeliveryFee'] = $surcharge['Factor']['Value'] ?? $surcharge['value'] ?? 0;
                    }
                }
                // Apply override rates shipping rule
                $data = $this->applyOverrideRatesRule($connectionSettings, $data);
                // Apply Surcharge rates shipping rule
                $data = $this->applySurchargeRatesRule($connectionSettings, $data);
                $price = $this->calculatePrice($data);
                $access = $this->getAccessorialCode();

                /*
                 * Date 01-07-22
                 * Adding Functionality of Delivery Estimate Options
                 * */

                $date = $data['deliveryTimestamp'] ?? null;
                $days = $data['totalTransitTimeInDays'] ?? null;
                $dateAndDays = ['deliveryDate' => $date, 'totalTransitTimeInDays' => $days];
                $title = $this->getTitle($lableAs, false, false, $data['totalTransitTimeInDays'], [], $dateAndDays);

                $arraySorting['simple'][$key] = $price;
                $originQuotes[$origin]['simple'][$key]['code'] = 'upsltl' . $access;
                $originQuotes[$origin]['simple'][$key]['rate'] = $price;
                $originQuotes[$origin]['simple'][$key]['title'] = $title;

                if ($lgQuotes) {
                    $lgAccess = $this->getAccessorialCode(true);
                    $lgPrice = $this->calculatePrice($data, true);
                    $lgTitle = $this->getTitle($lableAs, true, false, $data['totalTransitTimeInDays'], [], $dateAndDays);
                    $arraySorting['liftgate'][$key] = $lgPrice;
                    $originQuotes[$origin]['liftgate'][$key]['code'] = 'upsltl' . $lgAccess;
                    $originQuotes[$origin]['liftgate'][$key]['rate'] = $lgPrice;
                    $originQuotes[$origin]['liftgate'][$key]['title'] = $lgTitle;
                }
                // Get Notify Before Delivery Origin Quotes
                if ($notifyDelivery) {
                    $compileNotifyDeliveryQuotes = Functions::getOriginQuotes(
                        'notifydelivery',
                        $lableAs,
                        $originQuotes,
                        $data,
                        $origin,
                        $key, $data['totalTransitTimeInDays'],
                        $dateAndDays,
                        false,
                        'upsltl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi,
                        false,
                        false,
                        $notifyDelivery,
                        false,
                        false,
                        $this->storeId,
                        $this->isSurchargeRates,
                    );

                    $arraySorting['notifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                    $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                }
                if ($notifyDelivery && $lgQuotes) {
                    $compileNotifyDeliveryQuotes = Functions::getOriginQuotes(
                        'lgnotifydelivery',
                        $lableAs,
                        $originQuotes,
                        $data,
                        $origin,
                        $key, $data['totalTransitTimeInDays'],
                        $dateAndDays,
                        $lgQuotes,
                        'upsltl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi,
                        false,
                        false,
                        $notifyDelivery,
                        false,
                        false,
                        $this->storeId,
                        $this->isSurchargeRates,
                    );

                    $arraySorting['lgnotifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                    $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                }
            }

            if (isset($inStoreLdData) && !empty($inStoreLdData)) {
                $originQuotes[$origin] = $this->inStoreLocalDeliveryQuotes($originQuotes[$origin], $inStoreLdData, $allOrigins);
            }

            $count++;
        }
        $allQuotes = $this->getFinalQuotesArray($originQuotes);
        return $allQuotes;
    }

    private function compileUnishippersSmallQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential)
    {
        $this->unishippersSmallQuotesResults = new unishippersSmallQuotesResults($this->SuppressParcelRates);
        if ($residential['unishippersSmall'] == 'Y') {
            $this->isResi = true;
            $this->residentialDlvry = 1;
        } else {
            $this->isResi = false;
            $this->residentialDlvry = 0;
        }
        $this->alwaysResi = $this->residential['alwaysResi']['unishippersSmall'] ?? false;
        $isSbsEnable = isset($residential['isSbsEnable']) ? $residential['isSbsEnable'] : false;
        $res = $this->unishippersSmallQuotesResults->compileQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $this->isResi, $isSbsEnable, $this->isMultiShipment, $this->items, $this->storeId, $this->carrierName, $this->totalHazmatBoxes);

        if (!$this->isMultiShipment) {
            $this->isMultiShipment = $res['isMultiShipment'] ?? false;
        }

        return $res['resp'] ?? [];
    }

    private function compileDayRossLtlQuotes($shipments, $connectionSettings, $allOrigins, $hazmatAllItems, $residential)
    {
        $this->isOverrideRates = false;
        $this->isSameDayApi = isset($connectionSettings['dayross-ltl']['creds']['api_type']) && $connectionSettings['dayross-ltl']['creds']['api_type'] == 'sameday' ? true : false;
        $dayRossLtl = new dayRossLtlQuotesResults();

        if (!$this->isSameDayApi) {
            $this->isResi = $residential['dayrossLtl'] == 'Y';
            $this->residentialDlvry = $residential['dayrossLtl'] == 'Y' ? 1 : 0;
        } else {
            $this->isResi = false;
            $this->residentialDlvry = false;
        }

        $this->alwaysResi = $this->residential['alwaysResi']['dayrossLtl'] ?? false;
        $this->quoteSettings = $connectionSettings['dayross-ltl']['quote_settings'] ?? [];
        $shipments = $dayRossLtl->formateQuoteBeforeCompile($shipments, $this->quoteSettings);
        $this->quoteSettingsData();

        $allQuotes = $odwArr = $hazShipmentArr = $multiShipmentQuotes = [];
        $count = 0;
        $lgQuotes = false;
        $twoManQuotes = $appointmentQuotes = false;

        if (!$this->isMultiShipment) {
            $this->isMultiShipment = $dayRossLtl->isMultiShipment($shipments);
        }

        $labelAs = $this->quoteSettings['label_as'] ?? '';
        $hatShipments = $this->isSameDayApi ? [] : $dayRossLtl->getAndformatHATQuotes($this->quoteSettings, $shipments);

        /* Quotes compilation */
        foreach ($shipments as $origin => $quote) {
            $this->originKey = $origin;
            $isError = isset($quote['severity']) || isset($quote['error']) || isset($quote['q']['soapBody']['soapFault']);
            if ($isError) {
                return $this->getInsPicAndLocDelQuotes($quote, $allOrigins);
            }

            if ($count == 0) {
                $inStoreLdData = $quote['InstorPickupLocalDelivery'] ?? false;
                unset($quote['InstorPickupLocalDelivery']);

                $lgQuotes = $dayRossLtl->isLGQuotes($this->quoteSettings);
                if (!$lgQuotes && !$this->isSameDayApi) {
                    $lgQuotes = $dayRossLtl->isRADEnabled($this->quoteSettings, $this->isResi);
                }

                if ($this->isSameDayApi) {
                    $twoManQuotes = $dayRossLtl->isTwoManDeliveryEnabled($connectionSettings['dayross-ltl']);
                    $appointmentQuotes = $dayRossLtl->isAppointmentManDeliveryEnabled($connectionSettings['dayross-ltl']);
                }
            }

            $originQuotes = $arraySorting = $quotesArr = [];

            if (isset($quote['q'])) {
                $items = $quote['q']['lineItems'] ?? [];
                foreach ($items as $key => $item) {
                    if ($item['hazardous'] == 'Y') {
                        $hazShipmentArr[$origin] = 'Y';
                        break;
                    }
                    $hazShipmentArr[$origin] = 'N';
                }

                
                if(!isset($quote['q'][0])){
                    $quotesArr[] = $quote['q'];
                } else {
                    $quotesArr = $quote['q'];
                }
                foreach ($quotesArr as $key => $data) {
                    $srvcType = $data['serviceType'] ?? '';
                    if(isset($data['soapBody']['soapFault'])){
                        continue;
                    }
                    if (isset($srvcType)) {
                        // Below commit use for future.
                        //$data = $this->applyOverrideRatesRule($connectionSettings, $data);
                        $price = $this->calculatePrice($data);

                        $this->quoteSettings['label_as'] = !blank($labelAs) ? $labelAs : 'Freight';

                        if ($this->isSameDayApi) {
                            $this->quoteSettings['label_as'] = '';
                            if (isset($data['ServiceLevelCode']) && ($data['ServiceLevelCode'] == "H1" || $data['ServiceLevelCode'] == "H2")) {
                                $access = $this->getAccessorialCode();
                            } else {
                                $access = '';
                            }
                        } else {
                            $access = $this->getAccessorialCode();
                        }

                        $days = $data['totalTransitTimeInDays'] ?? null;
                        $dateAndDays = $dayRossLtl->getShipmentDateAndDays($data);
                        $title = $this->getTitle($data['serviceDesc'], false, false, $days, [], $dateAndDays);
                        $isLGFee = !empty($data['surcharges']['liftgateFee']) ? true : false;

                        $arraySorting['simple'][$key] = $price;
                        $originQuotes[$key]['simple']['code'] = 'dayrossltl' . $access;
                        $originQuotes[$key]['simple']['rate'] = $price;
                        $originQuotes[$key]['simple']['title'] = $title;

                        //if(!$this->isOverrideRates){
                        if ($lgQuotes && $isLGFee) {
                            $lgAccess = $this->getAccessorialCode(true);
                            $lgPrice = $this->calculatePrice($data, true);
                            $lgTitle = $this->getTitle($data['serviceDesc'], true, false, $days, [], $dateAndDays);
                            $arraySorting['liftgate'][$key] = $lgPrice;
                            $originQuotes[$key]['liftgate']['code'] = 'dayrossltl' . $lgAccess;
                            $originQuotes[$key]['liftgate']['rate'] = $lgPrice;
                            $originQuotes[$key]['liftgate']['title'] = $lgTitle;
                        }

                        if ($this->isSameDayApi) {
                            $offerTwoManDelAsOpt = isset($this->quoteSettings['offer_two_man_delivery']) && $this->quoteSettings['offer_two_man_delivery'] ? true : false;
                            $offerAppDelAsOpt = isset($this->quoteSettings['offer_appointment_delivery']) && $this->quoteSettings['offer_appointment_delivery'] ? true : false;
                            $this->quoteSettings['label_as'] = '';
                            $isTwoManFee = !empty($data['surcharges']['twoManFee']) ? true : false;
                            $isAppointFee = !empty($data['surcharges']['appointmentFee']) ? true : false;

                            if ($twoManQuotes && $isTwoManFee && !$lgQuotes) {
                                $tmAccess = $this->getAccessorialCode(false, false, '', '', false, true, false);
                                $tmPrice = $this->calculatePrice($data, false, false, false, false, false, true, false);
                                $tmTitle = $this->getTitle($data['serviceDesc'], false, false, $days, [], $dateAndDays, false, false, false, $offerTwoManDelAsOpt, false);

                                $arraySorting['twoManDel'][$key] = $tmPrice;
                                $originQuotes[$key]['twoManDel']['code'] = 'dayrossltl' . $tmAccess;
                                $originQuotes[$key]['twoManDel']['rate'] = $tmPrice;
                                $originQuotes[$key]['twoManDel']['title'] = $tmTitle;
                            }

                            if ($appointmentQuotes && $isAppointFee && !$lgQuotes) {
                                $aptAccess = $this->getAccessorialCode(false, false, '', '', false, false, true);
                                $aptPrice = $this->calculatePrice($data, false, false, false, false, false, false, true);
                                $tmTitle = $this->getTitle($data['serviceDesc'], false, false, $days, [], $dateAndDays, false, false, false, false, $offerAppDelAsOpt);

                                $arraySorting['aptDel'][$key] = $aptPrice;
                                $originQuotes[$key]['aptDel']['code'] = 'dayrossltl' . $aptAccess;
                                $originQuotes[$key]['aptDel']['rate'] = $aptPrice;
                                $originQuotes[$key]['aptDel']['title'] = $tmTitle;
                            }

                            if ($twoManQuotes && $isTwoManFee && $appointmentQuotes && $isAppointFee && !$lgQuotes) {
                                $aptAccess = $this->getAccessorialCode(false, false, '', '', false, true, true);
                                $aptPrice = $this->calculatePrice($data, false, false, false, false, false, true, true);
                                $tmTitle = $this->getTitle($data['serviceDesc'], false, false, $days, [], $dateAndDays, false, false, false, $offerTwoManDelAsOpt, $offerAppDelAsOpt);

                                $arraySorting['twoManAptDel'][$key] = $aptPrice;
                                $originQuotes[$key]['twoManAptDel']['code'] = 'dayrossltl' . $aptAccess;
                                $originQuotes[$key]['twoManAptDel']['rate'] = $aptPrice;
                                $originQuotes[$key]['twoManAptDel']['title'] = $tmTitle;
                            }
                            //}
                        }
                    }
                }
            }

            if($this->isMultiShipment){
                $sliced = [];
                asort($arraySorting['simple']);
    
                foreach ($arraySorting as $key => $value) {
                    $sliced =  array_slice($arraySorting[$key], 0, 1, true);
                }
    
                $compiledQuotes = array_intersect_key($originQuotes, $sliced);
            } else {
                $compiledQuotes = $originQuotes;
            }

            if ($compiledQuotes !== null && !empty($compiledQuotes)) {
                if (count($compiledQuotes) > 1) {
                    foreach ($compiledQuotes as $k => $service) {
                        foreach ($service as $serKey => $ser) {
                            $quotes = Functions::getQuotesArray($service, $allQuotes, $multiShipmentQuotes, $origin, $serKey);
                            $allQuotes = $quotes['allQuotes'];
                            $multiShipmentQuotes = $quotes['multiShipmentQuotes'];
                        }
                    }
                } else {
                    $service = reset($compiledQuotes);
                    foreach ($service as $serKey => $ser) {
                        $quotes = Functions::getQuotesArray($service, $allQuotes, $multiShipmentQuotes, $origin, $serKey);
                        $allQuotes = $quotes['allQuotes'];
                        $multiShipmentQuotes = $quotes['multiShipmentQuotes'];
                    }
                }
            }

            if ($this->isMultiShipment) {
                $odwArr[$origin]['quotes'] = $compiledQuotes;
            }

            $count++;
        }

        $allQuotes = $this->getFinalQuotesArray($allQuotes);
        /* Quotes for instore delivery */
        if (!$this->isMultiShipment && isset($inStoreLdData) && !empty($inStoreLdData)) {
            $allQuotes = $this->inStoreLocalDeliveryQuotes($allQuotes, $inStoreLdData, $allOrigins);
            $hatShipments = Functions::setEmptyHATQuotesArray($allOrigins, $inStoreLdData, $hatShipments);
        }
        
        // if($this->isOverrideRates){
        //     $hatShipments = [];
        // }

        /* Multishipment quotes with LGD  */
        if ($this->multiOrigins && ((!empty($multiShipmentQuotes['simple']) && count($multiShipmentQuotes['simple']) > 1) || (!empty($multiShipmentQuotes['liftgate']) && count($multiShipmentQuotes['liftgate']) > 1))) {
            if (!empty($hatShipments)) {
                $allQuotes = $this->forceChangeTitle($allQuotes);
                $hatLabel = explode('|', $hatShipments[0]['serviceDesc']);
                unset($hatLabel[0]);
                $lableAs = 'Freight |' . implode('|', $hatLabel);

                $resp = [
                    'checkoutQuotes' => Functions::arrangeHATFreight($allQuotes, $hatShipments, $lableAs),
                    'multiShipmentQuotes' => Functions::arrangeHATMulti($multiShipmentQuotes, $hatShipments),
                ];
            } else {
                $allQuotes = $this->forceChangeTitle($allQuotes);
                $resp = [
                    'checkoutQuotes' => $this->arrangeOwnFreight($allQuotes),
                    'multiShipmentQuotes' => $multiShipmentQuotes,
                ];
            }

            return $resp;
        }

        if (!empty($hatShipments)) {
            return $dayRossLtl->arrangeHATFreight($allQuotes, $hatShipments);
        }

        return $this->arrangeOwnFreight($allQuotes);
    }

    private function compileYRCLtlQuotes($shipments, $connectionSettings, $allOrigins, $residential)
    {
        $this->isOverrideRates = false;
        $yrcLtl = new yrcLtlQuotesResults();

        if ($residential['yrcLtl'] == 'Y') {
            $this->isResi = true;
            $this->residentialDlvry = 1;
        } else {
            $this->isResi = false;
            $this->residentialDlvry = 0;
        }

        $this->alwaysResi = $this->residential['alwaysResi']['yrcLtl'] ?? false;
        $shipments = $yrcLtl->formateQuoteBeforeCompile($shipments, $connectionSettings['yrc-ltl']['creds']);
        $this->quoteSettings = $connectionSettings['yrc-ltl']['quote_settings'] ?? [];
        $this->quoteSettingsData();

        $allQuotes = $odwArr = $hazShipmentArr = $multiShipmentQuotes = [];
        $count = 0;
        $lgQuotes = $laccess = $notifyDelivery = false;

        $numberOfShipments = 0;
        foreach ($shipments as $ship) {
            if (!isset($ship['severity']) && (isset($ship['q']) && !isset($ship['q']['error']))) {
                $numberOfShipments++;
            }
        }

        if (!$this->isMultiShipment) {
            $this->isMultiShipment = is_countable($shipments) && $numberOfShipments > 1;
        }
        $labelAs = $this->quoteSettings['label_as'] ?? '';

        foreach ($shipments as $origin => $quote) {
            $this->originKey = $origin;
            if (isset($quote['severity']) || (isset($quote['q']) && isset($quote['q']['error']))) {
                return $this->getInsPicAndLocDelQuotes($quote, $allOrigins);
            }

            if ($count == 0) {
                $inStoreLdData = $yrcLtl->isSuppressedRatesShipment($shipments) ? $quote['InstorPickupLocalDelivery'] : $quote['q']['InstorPickupLocalDelivery'] ?? false;
                unset($quote['InstorPickupLocalDelivery']);
                unset($quote['q']['InstorPickupLocalDelivery']);

                $lgQuotes =
                    (isset($this->quoteSettings['alwaysLiftGateDelivery']) && $this->quoteSettings['alwaysLiftGateDelivery']) ||
                    (isset($this->quoteSettings['offerLiftGateDelivery']) && $this->quoteSettings['offerLiftGateDelivery']);

                if (!$lgQuotes) {
                    $lgQuotes = (isset($this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->isResi;
                }
                if (!$laccess) {
                    $laccess = ((isset($this->quoteSettings['offer_limited_access_delivery']) && $this->quoteSettings['offer_limited_access_delivery']) ||
                        (isset($this->quoteSettings['always_limited_access_delivery']) && $this->quoteSettings['always_limited_access_delivery']));
                }

                if (!$notifyDelivery) {
                    $notifyDelivery = (isset($this->quoteSettings['always_quote_notify']) && $this->quoteSettings['always_quote_notify']) ||
                        (isset($this->quoteSettings['offer_notify_as_option']) && $this->quoteSettings['offer_notify_as_option']);
                }
            }

            $originQuotes = [];
            $arraySorting = [];

            if (isset($quote['q']) && !$yrcLtl->isSuppressedRatesShipment($shipments)) {
                $items = $quote['q']['lineItems'] ?? [];
                foreach ($items as $key => $item) {
                    if ($item['hazardous'] == 'Y') {
                        $hazShipmentArr[$origin] = 'Y';
                        break;
                    }
                    $hazShipmentArr[$origin] = 'N';
                }

                $quotesArr[] = $quote['q'];
                foreach ($quotesArr as $key => $data) {
                    $srvcType = $data['serviceType'] ?? '';
                    if (isset($srvcType)) {
                        // Below commit use for future.
                        //$data = $this->applyOverrideRatesRule($connectionSettings, $data);
                        $access = $this->getAccessorialCode();
                        $price = $this->calculatePrice($data);

                        $this->quoteSettings['label_as'] = !blank($labelAs) ? $labelAs : 'Freight';
                        $date = $quote['q']['deliveryDate'] ?? null;
                        $days = $quote['q']['totalTransitTimeInDays'] ?? null;
                        $dateAndDays = ['deliveryDate' => $date, 'totalTransitTimeInDays' => $days];
                        $title = $this->getTitle($data['serviceDesc'], false, false, $days, [], $dateAndDays);

                        $arraySorting['simple'][$origin] = $price;
                        $originQuotes[$origin]['simple']['code'] = 'yrcltl' . $access;
                        $originQuotes[$origin]['simple']['rate'] = $price;
                        $originQuotes[$origin]['simple']['title'] = $title;

                        //if(!$this->isOverrideRates){
                        if ($lgQuotes) {
                            $lgAccess = $this->getAccessorialCode(true);
                            $lgPrice = $this->calculatePrice($data, true);
                            $lgTitle = $this->getTitle($data['serviceDesc'], true, false, $days, [], $dateAndDays);
                            $arraySorting['liftgate'][$origin] = $lgPrice;
                            $originQuotes[$origin]['liftgate']['code'] = 'yrcltl' . $lgAccess;
                            $originQuotes[$origin]['liftgate']['rate'] = $lgPrice;
                            $originQuotes[$origin]['liftgate']['title'] = $lgTitle;
                        }
                        if ($laccess) {
                            $laAccess = $this->getAccessorialCode(false, false, false, false, $laccess);
                            $laPrice = $this->calculatePrice($data, false, false, false, false, $laccess);
                            $laTitle = $this->getTitle($data['serviceDesc'], false, false, $days, [], $dateAndDays, false, $laccess);
                            $arraySorting['limitedaccess'][$origin] = $laPrice;
                            $originQuotes[$origin]['limitedaccess']['code'] = 'yrcltl' . $laAccess;
                            $originQuotes[$origin]['limitedaccess']['rate'] = $laPrice;
                            $originQuotes[$origin]['limitedaccess']['title'] = $laTitle;
                        }
                        if ($laccess && $lgQuotes) {
                            $laAccess = $this->getAccessorialCode($lgQuotes, false, false, false, $laccess);
                            $laPrice = $this->calculatePrice($data, $lgQuotes, false, false, false, $laccess);
                            $laTitle = $this->getTitle($data['serviceDesc'], $lgQuotes, false, $days, [], $dateAndDays, false, $laccess);
                            $arraySorting['limitedaccessLG'][$origin] = $laPrice;
                            $originQuotes[$origin]['limitedaccessLG']['code'] = 'yrcltl' . $laAccess;
                            $originQuotes[$origin]['limitedaccessLG']['rate'] = $laPrice;
                            $originQuotes[$origin]['limitedaccessLG']['title'] = $laTitle;
                        }
                        // Get Notify Before Delivery Origin Quotes
                        if ($notifyDelivery) {
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes(
                                'notifydelivery', $data['serviceDesc'],
                                $originQuotes,
                                $data,
                                $origin,
                                $days,
                                $dateAndDays,
                                false,
                                'yrcltl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi,
                                false,
                                false,
                                $notifyDelivery,
                                false,
                                false,
                                $this->storeId,
                            );

                            $arraySorting['notifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }
                        if ($notifyDelivery && $lgQuotes) {
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes(
                                'lgnotifydelivery', $data['serviceDesc'],
                                $originQuotes,
                                $data,
                                $origin,
                                $days,
                                $dateAndDays,
                                true,
                                'yrcltl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi,
                                false,
                                false,
                                $notifyDelivery,
                                false,
                                false,
                                $this->storeId,
                            );

                            $arraySorting['lgnotifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }
                        if ($notifyDelivery && $laccess) {
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes(
                                'laccessnotifydelivery', $data['serviceDesc'],
                                $originQuotes,
                                $data,
                                $origin,
                                $days,
                                $dateAndDays,
                                false,
                                'yrcltl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi,
                                false,
                                $laccess,
                                $notifyDelivery,
                                false,
                                false,
                                $this->storeId,
                            );

                            $arraySorting['laccessnotifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }
                        if ($notifyDelivery && $lgQuotes && $laccess) {
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes(
                                'lglaccessnotifydelivery', $data['serviceDesc'],
                                $originQuotes,
                                $data,
                                $origin,
                                $days,
                                $dateAndDays,
                                true,
                                'yrcltl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi,
                                false,
                                $laccess,
                                $notifyDelivery,
                                false,
                                false,
                                $this->storeId,
                            );

                            $arraySorting['lglaccessnotifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }
                        //}
                    }
                }
            }
            $compiledQuotes = $yrcLtl->getCompiledQuotes($originQuotes, $arraySorting, $this->isMultiShipment);

            if ($compiledQuotes !== null && !empty($compiledQuotes)) {
                if (count($compiledQuotes) > 1) {
                    foreach ($compiledQuotes as $k => $service) {
                        foreach ($service as $serKey => $ser) {
                            $quotes = Functions::getQuotesArray($service, $allQuotes, $multiShipmentQuotes, $origin, $serKey);
                            $allQuotes = $quotes['allQuotes'];
                            $multiShipmentQuotes = $quotes['multiShipmentQuotes'];
                        }
                    }
                } else {
                    $service = reset($compiledQuotes);
                    foreach ($service as $serKey => $ser) {
                        $quotes = Functions::getQuotesArray($service, $allQuotes, $multiShipmentQuotes, $origin, $serKey);
                        $allQuotes = $quotes['allQuotes'];
                        $multiShipmentQuotes = $quotes['multiShipmentQuotes'];
                    }
                }
            }

            if ($this->isMultiShipment) {
                $odwArr[$origin]['quotes'] = $compiledQuotes;
            }

            $count++;
        }

        $allQuotes = $this->getFinalQuotesArray($allQuotes);

        if (!$this->isMultiShipment && isset($inStoreLdData) && !empty($inStoreLdData)) {
            $allQuotes = $this->inStoreLocalDeliveryQuotes($allQuotes, $inStoreLdData, $allOrigins);
        }

        if ((!empty($multiShipmentQuotes['simple']) && count($multiShipmentQuotes['simple']) > 1) || (!empty($multiShipmentQuotes['liftgate']) && count($multiShipmentQuotes['liftgate']) > 1)) {
            $allQuotes = $this->forceChangeTitle($allQuotes);
            $resp = [
                'checkoutQuotes' => $allQuotes,
                'multiShipmentQuotes' => $multiShipmentQuotes,
            ];

            return $resp;
        }

        $resp = $allQuotes;
        return $resp;
    }

    private function compileFreightQuoteLtlQuotes($shipments, $connectionSettings, $allOrigins)
    {
        $freightQuote = new FQQuotesResults();
        $this->isFQ = true;

        if ($this->residential['freightQuoteLtl'] == 'Y') {
            $this->isResi = true;
            $this->residentialDlvry = 1;
        } else {
            $this->isResi = false;
            $this->residentialDlvry = 0;
        }

        $this->alwaysResi = $this->residential['alwaysResi']['freightQuoteLtl'] ?? false;
        $this->quoteSettings = $connectionSettings['freightquote-ltl']['quote_settings'] ?? [];
        $allConfigServices = $connectionSettings['freightquote-ltl']['carrier_services'] ?? [];
        $this->quoteSettingsData();
        $allQuotes = $odwArr = $hazShipmentArr = $multiShipmentQuotes = [];
        $count = 0;
        $lgQuotes = false;

        $numberOfShipments = 0;
        foreach ($shipments as $ship) {
            if (!isset($ship['severity'])) {
                $numberOfShipments++;
            }
        }

        if (!$this->isMultiShipment) {
            $this->isMultiShipment = is_countable($shipments) && $numberOfShipments > 1;
        }

        foreach ($shipments as $origin => $quote) {
            $this->originKey = $origin;
            if (isset($quote['q']['severity']) || isset($quote['severity'])) {
                return $this->getInsPicAndLocDelQuotes($quote, $allOrigins);
            }

            if ($count == 0) {
                $isRad = $quote['autoResidentialsStatus'] ?? '';
                $inStoreLdData = $quote['InstorPickupLocalDelivery'] ?? false;
                unset($quote['InstorPickupLocalDelivery']);
                $lgQuotes =
                    (isset($this->quoteSettings['alwaysLiftGateDelivery']) && $this->quoteSettings['alwaysLiftGateDelivery']) ||
                    (isset($this->quoteSettings['offerLiftGateDelivery']) && $this->quoteSettings['offerLiftGateDelivery']);
                if (!$lgQuotes) {
                    $lgQuotes = (isset($this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->isResi;
                }
                $resiPickup = isset($this->quoteSettings['residentialPickup']) && $this->quoteSettings['residentialPickup'] ? '+pu' : '';
                $isTlQuotes = isset($this->quoteSettings['truckload_weight_threshold']) && $this->quoteSettings['truckload_weight_threshold'] ?? null;
            }

            $originQuotes = [];
            $arraySorting = [];
            $TLquotes = $freightQuote->truckLoadQuotes($quote, $allConfigServices, $connectionSettings, $origin, $this->items, $allOrigins, $this->carrierName);
            $TLquotes = $this->getCompiledQuotes($TLquotes[0], $TLquotes[1], false);

            if (isset($quote['q'])) {
                if (isset($quote['hazardousStatus'])) {
                    $hazShipmentArr[$origin] = $quote['hazardousStatus'] == 'y' ? 'Y' : 'N';
                }

                foreach ($quote['q'] as $key => $data) {
                    if (isset($data['serviceType']) && in_array($data['serviceType'], $allConfigServices)) {
                        
                        $charges = array(
                            'totalNetCharge' => array(
                                'Amount' => $data['totalNetCharge'],
                            ),
                            'surcharges' => $data['surcharges'],
                        );
                        $data = array_merge($data, $charges);

                        // Apply Override rates shipping rule
                        $data = $this->applyOverrideRatesRule($connectionSettings, $data);
                        // Apply Surcharge rates shipping rule
                        //$data = $this->applySurchargeRatesRule($connectionSettings, $data);

                        $access = $this->getAccessorialCode() . $resiPickup;
                        $price = $this->calculatePrice($data);

                        /*
                         * Adding Functionality of Delivery Estimate Options
                         * */
                        $date = $data['deliveryTimestamp'] ?? null;
                        $days = $data['totalTransitTimeInDays'] ?? null;
                        $dateAndDays = ['deliveryDate' => $date, 'totalTransitTimeInDays' => $days];

                        $title = $this->getTitle($data['serviceDesc'], false, false, $data['totalTransitTimeInDays'], [], $dateAndDays);
                        $arraySorting['simple'][$key] = $price;
                        $originQuotes[$key]['simple']['code'] = 'fqltl' . $data['serviceType'] . $access;
                        $originQuotes[$key]['simple']['rate'] = $price;
                        $originQuotes[$key]['simple']['title'] = $title;

                        if ($lgQuotes) {
                            $lgAccess = 'fqltl' . $this->getAccessorialCode(true) . $resiPickup;
                            $lgPrice = $this->calculatePrice($data, true);
                            $lgTitle = $this->getTitle($data['serviceDesc'], true, false, $data['totalTransitTimeInDays'], [], $dateAndDays);
                            $arraySorting['liftgate'][$key] = $lgPrice;
                            $originQuotes[$key]['liftgate']['code'] = $data['serviceType'] . $lgAccess;
                            $originQuotes[$key]['liftgate']['rate'] = $lgPrice;
                            $originQuotes[$key]['liftgate']['title'] = $lgTitle;
                        }
                    }
                }
            }
            $compiledQuotes = $this->getCompiledQuotes($originQuotes, $arraySorting, $lgQuotes);

            if ($compiledQuotes !== null && !empty($compiledQuotes)) {
                if (count($compiledQuotes) > 1) {
                    foreach ($compiledQuotes as $k => $service) {
                        foreach ($service as $serKey => $ser) {
                            $quotes = Functions::getQuotesArray($service, $allQuotes, $multiShipmentQuotes, $origin, $serKey);
                            $allQuotes = $quotes['allQuotes'];
                            $multiShipmentQuotes = $quotes['multiShipmentQuotes'];
                        }
                    }
                } else {
                    $service = reset($compiledQuotes);
                    foreach ($service as $serKey => $ser) {
                        $quotes = Functions::getQuotesArray($service, $allQuotes, $multiShipmentQuotes, $origin, $serKey);
                        $allQuotes = $quotes['allQuotes'];
                        $multiShipmentQuotes = $quotes['multiShipmentQuotes'];
                    }
                }
            }
            if ($TLquotes !== null && !empty($TLquotes)) {
                foreach ($TLquotes as $key => $TLservice) {
                    $isTlQuotes ? $allQuotes['Truckload'][] = $TLservice['Truckload'] : null;
                    $isTlQuotes ? $multiShipmentQuotes['Truckload'][$origin] = $TLservice['Truckload'] : null;
                }
            }

            if ($this->isMultiShipment) {
                $odwArr[$origin]['quotes'] = $compiledQuotes;
            }

            $count++;
        }

        if (!(isset($this->quoteSettings['quoteltl_and_truckload']) && $this->quoteSettings['quoteltl_and_truckload']) && $this->isMultiShipment) {

            $ltlTruckloadQuotes = Functions::quotesLtlTruckLoad($allQuotes, $shipments);
            $allQuotes = $ltlTruckloadQuotes[0];
            $multiShipmentQuotes = $ltlTruckloadQuotes[1];

        }

        $allQuotes = $this->getFinalQuotesArray($allQuotes);
        if (!$this->isMultiShipment && isset($inStoreLdData) && !empty($inStoreLdData)) {
            $allQuotes = $this->inStoreLocalDeliveryQuotes($allQuotes, $inStoreLdData, $allOrigins);
        }

        if ($this->multiOrigins && ((!empty($multiShipmentQuotes['simple']) && count($multiShipmentQuotes['simple']) > 1) || (!empty($multiShipmentQuotes['liftgate']) && count($multiShipmentQuotes['liftgate']) > 1) || (!empty($multiShipmentQuotes['Truckload']) && count($multiShipmentQuotes['Truckload']) > 1))) {
            $allQuotes = $this->forceChangeTitle($allQuotes);
            $resp = [
                'checkoutQuotes' => $allQuotes,
                'multiShipmentQuotes' => $multiShipmentQuotes,
            ];

            return $resp;
        }

        $resp = $allQuotes;
        return $resp;
    }

    private function compileSaiaLtlQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential)
    {
        $saiaLtl = new saiaLtlQuotesResults();

        $this->isResi = $residential['saiaLtl'] == 'Y';
        $this->residentialDlvry = $residential['saiaLtl'] == 'Y' ? 1 : 0;
        $this->alwaysResi = $this->residential['alwaysResi']['saiaLtl'] ?? false;
        $this->quoteSettings = $connectionSettings['saia-ltl']['quote_settings'] ?? [];
        $this->quoteSettingsData();

        $shipments = $saiaLtl->formatQuotesBeforeCompilation($shipments);
        $allQuotes = $odwArr = $multiShipmentQuotes = $originQuotes = [];
        $count = 0;
        $lgQuotes = false;

        if (!$this->isMultiShipment) {
            $this->isMultiShipment = $saiaLtl->isMultiShipment($shipments);
        }
        $labelAs = $this->quoteSettings['label_as'] ?? '';
        $labelAs = !blank($labelAs) ? $labelAs : 'Freight';

        /* Quotes compilation */
        foreach ($shipments as $origin => $quote) {
            $this->originKey = $origin;

            if ((isset($quote['severity']) || !isset($quote['q']) || (isset($quote['q']) && empty($quote['q'])))) {
                $instoreResp[$origin] = $this->getInsPicAndLocDelQuotes($quote, $allOrigins) ?? [];                
                return $instoreResp;
            }

            if ($count == 0) {
                $inStoreLdData = $quote['InstorPickupLocalDelivery'] ?? false;
                unset($quote['InstorPickupLocalDelivery']);
                $lgQuotes = $saiaLtl->isLGQuotes($this->quoteSettings, $this->isResi);

                $notifyDelivery =
                    (isset($this->quoteSettings['always_quote_notify']) && $this->quoteSettings['always_quote_notify']) ||
                    (isset($this->quoteSettings['offer_notify_as_option']) && $this->quoteSettings['offer_notify_as_option']);
            }

            $arraySorting = $quotesArr = [];

            if (isset($quote['q'])) {
                $quotesArr[] = $quote['q'];

                foreach ($quotesArr as $key => $data) {
                    $srvcType = $data['serviceType'] ?? '';

                    if (isset($srvcType)) {                   
                        // Apply override rates shipping rule
                        $data = $this->applyOverrideRatesRule($connectionSettings, $data);
                        $price = $this->calculatePrice($data);
                        $access = $this->getAccessorialCode();
                        $this->quoteSettings['label_as'] = $labelAs;

                        $days = $data['totalTransitTimeInDays'] ?? null;
                        $dateAndDays = $saiaLtl->getShipmentDateAndDays($data);
                        $title = $this->getTitle($data['serviceDesc'], false, false, $days, [], $dateAndDays);

                        $arraySorting['simple'][$origin] = $price;
                        $originQuotes[$origin]['simple'][$key]['code'] = 'saialtl' . $access;
                        $originQuotes[$origin]['simple'][$key]['rate'] = $price;
                        $originQuotes[$origin]['simple'][$key]['title'] = $title;

                        if ($lgQuotes) {
                            $lgAccess = $this->getAccessorialCode(true);
                            $lgPrice = $this->calculatePrice($data, true);
                            $lgTitle = $this->getTitle($data['serviceDesc'], true, false, $days, [], $dateAndDays);
                            $arraySorting['liftgate'][$origin] = $lgPrice;
                            $originQuotes[$origin]['liftgate'][$key]['code'] = 'saialtl' . $lgAccess;
                            $originQuotes[$origin]['liftgate'][$key]['rate'] = $lgPrice;
                            $originQuotes[$origin]['liftgate'][$key]['title'] = $lgTitle;
                        }
                        // Get Notify Before Delivery Origin Quotes
                        if ($notifyDelivery) {
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes(
                                'notifydelivery', $data['serviceDesc'],
                                $originQuotes,
                                $data,
                                $origin,
                                $key,
                                $days,
                                $dateAndDays,
                                false,
                                'saialtl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi,
                                false,
                                false,
                                $notifyDelivery,
                                false,
                                false,
                                $this->storeId,
                            );

                            $arraySorting['notifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }
                        if ($notifyDelivery && $lgQuotes) {
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes(
                                'lgnotifydelivery', $data['serviceDesc'],
                                $originQuotes,
                                $data,
                                $origin,
                                $key,
                                $days,
                                $dateAndDays,
                                true,
                                'saialtl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi,
                                false,
                                false,
                                $notifyDelivery,
                                false,
                                false,
                                $this->storeId,
                            );

                            $arraySorting['lgnotifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }
                    }
                }
            }

            if (isset($inStoreLdData) && !empty($inStoreLdData)) {
                $originQuotes[$origin] = $this->inStoreLocalDeliveryQuotes($originQuotes[$origin], $inStoreLdData, $allOrigins);
            }

            $count++;
        }

        $allQuotes = $this->getFinalQuotesArray($originQuotes);

        return $allQuotes;
    }

    private function compileABFLtlQuotes($shipments, $connectionSettings, $allOrigins, $residential)
    {
        $abfLtl = new abfLtlQuotesResults();

        if ($residential['abfLtl'] == 'Y') {
            $this->isResi = true;
            $this->residentialDlvry = 1;
        } else {
            $this->isResi = false;
            $this->residentialDlvry = 0;
        }
        $this->alwaysResi = $this->residential['alwaysResi']['abfLtl'] ?? false;
        $shipments = $abfLtl->formateQuoteBeforeCompile($shipments, $connectionSettings['abf-ltl']);
        $this->quoteSettings = $connectionSettings['abf-ltl']['quote_settings'] ?? [];
        $this->quoteSettingsData();

        $allQuotes = $odwArr = $hazShipmentArr = $multiShipmentQuotes = [];
        $count = 0;
        $lgQuotes = $notifyDelivery = false;

        $numberOfShipments = 0;
        foreach ($shipments as $ship) {
            if (!isset($ship['severity'])) {
                $numberOfShipments++;
            }
        }

        if (!$this->isMultiShipment) {
            $this->isMultiShipment = is_countable($shipments) && $numberOfShipments > 1;
        }

        $labelAs = $this->quoteSettings['label_as'] ?? '';
        $hatShipments = $finalCompiledQuotes = $compiledQuotes = $originQuotes = [];

        foreach ($shipments as $origin => $quote) {
            $this->originKey = $origin;

            if ((isset($quote['severity']) || !isset($quote['q']) || (isset($quote['q']) && empty($quote['q'])))) {
                $instoreResp[$origin] = $this->getInsPicAndLocDelQuotes($quote, $allOrigins) ?? [];                
                return $instoreResp;
            }

            if ($count == 0) {
                $inStoreLdData = $abfLtl->isSuppressedRatesShipment($shipments) ? $quote['InstorPickupLocalDelivery'] : $quote['q']['InstorPickupLocalDelivery'] ?? false;
                unset($quote['InstorPickupLocalDelivery']);
                unset($quote['q']['InstorPickupLocalDelivery']);

                $lgQuotes =
                    (isset($this->quoteSettings['alwaysLiftGateDelivery']) && $this->quoteSettings['alwaysLiftGateDelivery']) ||
                    (isset($this->quoteSettings['offerLiftGateDelivery']) && $this->quoteSettings['offerLiftGateDelivery']);

                if (!$lgQuotes) {
                    $lgQuotes = (isset($this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->isResi;
                }

                $notifyDelivery =
                    (isset($this->quoteSettings['always_quote_notify']) && $this->quoteSettings['always_quote_notify']) ||
                    (isset($this->quoteSettings['offer_notify_as_option']) && $this->quoteSettings['offer_notify_as_option']);
            }
            $arraySorting = [];

            if (isset($quote['q']) && !$abfLtl->isSuppressedRatesShipment($shipments)) {
                $items = $quote['q']['lineItems'];
                foreach ($items as $key => $item) {
                    if ($item['hazardous'] == 'Y') {
                        $hazShipmentArr[$origin] = 'Y';
                        break;
                    }
                    $hazShipmentArr[$origin] = 'N';
                }


                foreach ($quote as $key => $data) {
                    $isHATQuote = isset($data['holdAtTerminalResponse']['serviceType']) && strpos($data['holdAtTerminalResponse']['serviceType'], 'HAT+') !== false;
                    if ($isHATQuote) {
                        $hatShipments[$origin]['hat'][] = $data['holdAtTerminalResponse'];
                    }

                    $srvcType = $data['serviceType'] ?? '';
                    if (isset($srvcType)) {
                        // Apply override rates shipping rule
                        $data = $this->applyOverrideRatesRule($connectionSettings, $data);
                        // Apply Surcharge rates shipping rule
                        //$data = $this->applySurchargeRatesRule($connectionSettings, $data);
                        $price = $this->calculatePrice($data);
                        $access = $this->getAccessorialCode();
                        $this->quoteSettings['label_as'] = !blank($labelAs) ? $labelAs : 'Freight';
                        /*
                         * Date 01-07-22
                         * Adding Functionality of Delivery Estimate Options
                         * */
                        $date = $quote['q']['deliveryDate'] ?? null;
                        $days = $quote['q']['totalTransitTimeInDays'] ?? null;
                        $dateAndDays = ['deliveryDate' => $date, 'totalTransitTimeInDays' => $days];
                        $title = $this->getTitle($data['serviceDesc'], false, false, $days, [], $dateAndDays);

                        $arraySorting['simple'][$origin] = $price;
                        $originQuotes[$origin]['simple'][$count]['code'] = 'abfltl' . $access;
                        $originQuotes[$origin]['simple'][$count]['rate'] = $price;
                        $originQuotes[$origin]['simple'][$count]['title'] = $title;

                        if ($lgQuotes) {
                            $lgAccess = $this->getAccessorialCode(true);
                            $lgPrice = $this->calculatePrice($data, true);
                            $lgTitle = $this->getTitle($data['serviceDesc'], true, false, $days, [], $dateAndDays);
                            $arraySorting['liftgate'][$origin] = $lgPrice;
                            $originQuotes[$origin]['liftgate'][$count]['code'] = 'abfltl' . $lgAccess;
                            $originQuotes[$origin]['liftgate'][$count]['rate'] = $lgPrice;
                            $originQuotes[$origin]['liftgate'][$count]['title'] = $lgTitle;
                        }
                        // Get Notify Before Delivery Origin Quotes
                        if ($notifyDelivery) {
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes(
                                'notifydelivery', $data['serviceDesc'],
                                $originQuotes,
                                $data,
                                $origin,
                                $count,
                                $days,
                                $dateAndDays,
                                false,
                                'abfltl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi,
                                false,
                                false,
                                $notifyDelivery,
                                false,
                                false,
                                $this->storeId
                            );

                            $arraySorting['notifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }
                        if ($notifyDelivery && $lgQuotes) {
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes(
                                'lgnotifydelivery', $data['serviceDesc'],
                                $originQuotes,
                                $data,
                                $origin,
                                $count,
                                $days,
                                $dateAndDays,
                                true,
                                'abfltl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi,
                                false,
                                false,
                                $notifyDelivery,
                                false,
                                false,
                                $this->storeId
                            );

                            $arraySorting['lgnotifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }
                    }
                }
            }

            if (isset($inStoreLdData) && !empty($inStoreLdData)) {
                $originQuotes[$origin] = $this->inStoreLocalDeliveryQuotes($originQuotes[$origin], $inStoreLdData, $allOrigins);
            }
            $count++;
        }

        $allQuotes = $this->getFinalQuotesArray($originQuotes);

        if (!empty($hatShipments)) {
            return $abfLtl->arrangeHATFreight($allQuotes, $hatShipments);
        }

        return $allQuotes;
    }

    private function compileSouthEasternQuotes($shipments, $connectionSettings, $allOrigins, $residential)
    {
        $SouthEastern = new SouthEasternQuotesResults();

        if ($residential['SouthEastern'] == 'Y') {
            $this->isResi = true;
            $this->residentialDlvry = 1;
        } else {
            $this->isResi = false;
            $this->residentialDlvry = 0;
        }
        $this->alwaysResi = $this->residential['alwaysResi']['SouthEastern'] ?? false;
        $shipments = $SouthEastern->formateQuoteBeforeCompile($shipments, $connectionSettings['southeastern-ltl']['creds']);
        $this->quoteSettings = $connectionSettings['southeastern-ltl']['quote_settings'] ?? [];
        $this->quoteSettingsData();

        $allQuotes = $odwArr = $hazShipmentArr = $multiShipmentQuotes = [];
        $count = 0;
        $lgQuotes = false;

        $numberOfShipments = 0;
        foreach ($shipments as $ship) {
            if (!isset($ship['severity'])) {
                $numberOfShipments++;
            }
        }

        if (!$this->isMultiShipment) {
            $this->isMultiShipment = is_countable($shipments) && $numberOfShipments > 1;
        }

        $labelAs = $this->quoteSettings['label_as'] ?? '';
        foreach ($shipments as $origin => $quote) {
            $this->originKey = $origin;
            if (isset($quote['severity'])) {
                return $this->getInsPicAndLocDelQuotes($quote, $allOrigins);
            }

            if ($count == 0) {
                $inStoreLdData = $quote['InstorPickupLocalDelivery'] ?? $quote['q']['InstorPickupLocalDelivery'] ?? false;

                $lgQuotes =
                    (isset($this->quoteSettings['alwaysLiftGateDelivery']) && $this->quoteSettings['alwaysLiftGateDelivery']) ||
                    (isset($this->quoteSettings['offerLiftGateDelivery']) && $this->quoteSettings['offerLiftGateDelivery']);

                if (!$lgQuotes) {
                    $lgQuotes = (isset($this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->isResi;
                }

                $notifyDelivery =
                    (isset($this->quoteSettings['always_quote_notify']) && $this->quoteSettings['always_quote_notify']) ||
                    (isset($this->quoteSettings['offer_notify_as_option']) && $this->quoteSettings['offer_notify_as_option']);
            }

            $originQuotes = [];
            $arraySorting = $quotesArr = [];

            if (isset($quote['q']) && !$SouthEastern->isSuppressedRatesShipment($shipments)) {
                $items = $quote['q']['lineItems'];
                foreach ($items as $key => $item) {
                    if ($item['hazardous'] == 'Y') {
                        $hazShipmentArr[$origin] = 'Y';
                        break;
                    }
                    $hazShipmentArr[$origin] = 'N';
                }

                $quotesArr[] = $quote['q'];
                foreach ($quotesArr as $key => $data) {
                    $srvcType = $data['serviceType'] ?? '';
                    if (isset($srvcType)) {
                        // Apply Override rates shipping rule
                        $data = $this->applyOverrideRatesRule($connectionSettings, $data);
                        $price = $this->calculatePrice($data);
                        $access = $this->getAccessorialCode();
                        $isNbdSurcharges = isset($data['surcharges']['notifyDeliveryFee']);

                        $this->quoteSettings['label_as'] = !blank($labelAs) ? $labelAs : 'Freight';
                        $date = $quote['q']['deliveryDate'] ?? null;
                        $days = $quote['q']['totalTransitTimeInDays'] ?? null;
                        $dateAndDays = ['deliveryDate' => $date, 'totalTransitTimeInDays' => $days];
                        $title = $this->getTitle($data['serviceDesc'], false, false, $days, [], $dateAndDays);
                        $arraySorting['simple'][$origin] = $price;
                        $originQuotes[$origin]['simple']['code'] = 'seflltl' . $access;
                        $originQuotes[$origin]['simple']['rate'] = $price;
                        $originQuotes[$origin]['simple']['title'] = $title;

                        if ($lgQuotes) {
                            $lgAccess = $this->getAccessorialCode(true);
                            $lgPrice = $this->calculatePrice($data, true);
                            $lgTitle = $this->getTitle($data['serviceDesc'], true, false, $days, [], $dateAndDays);
                            $arraySorting['liftgate'][$origin] = $lgPrice;
                            $originQuotes[$origin]['liftgate']['code'] = 'seflltl' . $lgAccess;
                            $originQuotes[$origin]['liftgate']['rate'] = $lgPrice;
                            $originQuotes[$origin]['liftgate']['title'] = $lgTitle;
                        }
                        // Get Notify Before Delivery Origin Quotes
                        if ($notifyDelivery && $isNbdSurcharges) {
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes(
                                'notifydelivery', $data['serviceDesc'],
                                $originQuotes,
                                $data,
                                $origin,
                                $days,
                                $dateAndDays,
                                false,
                                'seflltl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi,
                                false,
                                false,
                                $notifyDelivery,
                                false,
                                false,
                                $this->storeId,
                            );

                            $arraySorting['notifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }
                        if ($notifyDelivery && $lgQuotes && $isNbdSurcharges) {
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes(
                                'lgnotifydelivery', $data['serviceDesc'],
                                $originQuotes,
                                $data,
                                $origin,
                                $days,
                                $dateAndDays,
                                true,
                                'seflltl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi,
                                false,
                                false,
                                $notifyDelivery,
                                false,
                                false,
                                $this->storeId,
                            );

                            $arraySorting['lgnotifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }
                    }
                }
            }

            $compiledQuotes = $SouthEastern->getCompiledQuotes($originQuotes, $arraySorting, $this->isMultiShipment);

            if ($compiledQuotes !== null && !empty($compiledQuotes)) {
                // Get Quotes Array
                if (count($compiledQuotes) > 1) {
                    foreach ($compiledQuotes as $k => $service) {
                        foreach ($service as $serKey => $ser) {
                            $quotes = Functions::getQuotesArray($service, $allQuotes, $multiShipmentQuotes, $origin, $serKey);
                            $allQuotes = $quotes['allQuotes'];
                            $multiShipmentQuotes = $quotes['multiShipmentQuotes'];
                        }
                    }
                } else {
                    $service = reset($compiledQuotes);
                    foreach ($service as $serKey => $ser) {
                        $quotes = Functions::getQuotesArray($service, $allQuotes, $multiShipmentQuotes, $origin, $serKey);
                        $allQuotes = $quotes['allQuotes'];
                        $multiShipmentQuotes = $quotes['multiShipmentQuotes'];
                    }
                }
            }

            if ($this->isMultiShipment) {
                $odwArr[$origin]['quotes'] = $compiledQuotes;
            }

            $count++;
        }

        $allQuotes = $this->getFinalQuotesArray($allQuotes);

        if (!$this->isMultiShipment && isset($inStoreLdData) && !empty($inStoreLdData)) {
            $allQuotes = $this->inStoreLocalDeliveryQuotes($allQuotes, $inStoreLdData, $allOrigins);
        }

        if ($this->multiOrigins && ((!empty($multiShipmentQuotes['simple']) && count($multiShipmentQuotes['simple']) > 1) || (!empty($multiShipmentQuotes['liftgate']) && count($multiShipmentQuotes['liftgate']) > 1))) {
            $allQuotes = $this->forceChangeTitle($allQuotes);
            $resp = [
                'checkoutQuotes' => $allQuotes,
                'multiShipmentQuotes' => $multiShipmentQuotes,
            ];

            return $resp;
        }

        $resp = $allQuotes;
        return $resp;
    }

    private function compileUspsSmallQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential)
    {
        $uspsSmallQuotesResults = new uspsSmallQuotesResults($this->SuppressParcelRates);
        $this->isResi = false;
        $this->residentialDlvry = 0;
        $this->alwaysResi = false;

        $access = $this->getAccessorialCodeSmall();
        $res = $uspsSmallQuotesResults->compileQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $this->isResi, $access, $this->isMultiShipment, $this->items, $this->storeId, $this->carrierName);

        if (!$this->isMultiShipment) {
            $this->isMultiShipment = $res['isMultiShipment'] ?? false;
        }

        return $res['resp'] ?? [];
    }

    private function compileEchoLogisticsLtlQuotes($shipments, $connectionSettings, $allOrigins, $hazmatAllItems, $residential)
    {
        $this->EchoLogistics = true;
        $this->isResi = $residential['echoLtl'] == 'Y';
        $this->residentialDlvry = $residential['echoLtl'] == 'Y' ? 1 : 0;
        $this->alwaysResi = $this->residential['alwaysResi']['echoLtl'] ?? false;
        $this->quoteSettings = $connectionSettings['echo-ltl']['quote_settings'] ?? [];
        $carrierServices = $connectionSettings['echo-ltl']['carrier_services'] ?? [];
        $this->quoteSettingsData();

        if (empty($carrierServices)) {
            return [];
        }

        $allQuotes = $odwArr = $hazShipmentArr = $multiShipmentQuotes = [];
        $count = 0;
        $lgQuotes = false;
        $originQuotes = [];

        $labelAs = $this->quoteSettings['label_as'] ?? '';
        $echoLtl = new echoLogisticsLtlQuotesResults();
        if (!$this->isMultiShipment) {
            $this->isMultiShipment = $echoLtl->isMultiShipment($shipments);
        }

        /* Quotes compilation */
        foreach ($shipments as $origin => $quote) {
            $this->originKey = $origin;
            if (isset($quote['severity'])) {
                return $this->getInsPicAndLocDelQuotes($quote, $allOrigins);
            }

            if ($count == 0) {
                $inStoreLdData = $quote['InstorPickupLocalDelivery'] ?? false;

                $lgQuotes = $echoLtl->isLGQuotes($this->quoteSettings);
                if (!$lgQuotes) {
                    $lgQuotes = $echoLtl->isRADEnabled($this->quoteSettings, $this->isResi);
                }

                $notifyDelivery =
                    (isset($this->quoteSettings['always_quote_notify']) && $this->quoteSettings['always_quote_notify']) ||
                    (isset($this->quoteSettings['offer_notify_as_option']) && $this->quoteSettings['offer_notify_as_option']);
            }

            $arraySorting = [];

            if (isset($quote['q'])) {
                $items = $quote['q']['lineItems'] ?? [];
                foreach ($items as $key => $item) {
                    if ($item['hazardous'] == 'Y') {
                        $hazShipmentArr[$origin] = 'Y';
                        break;
                    }
                    $hazShipmentArr[$origin] = 'N';
                }

                foreach ($quote['q'] as $key => $data) {
                    $srvcType = $data['CarrierSCAC'] ?? '';

                    if (!empty($srvcType) && in_array($srvcType, $carrierServices)) {

                        $data['totalNetCharge']['Amount'] = $data['TotalCharge'] ?? 0;
                        $data['surcharges']['liftgateFee'] = $echoLtl->getLGFee($data['Accessorials'] ?? []) ?? 0;
                        $data['surcharges']['notifyDeliveryFee'] = $echoLtl->getNBDFee($data['Accessorials'] ?? []) ?? 0;
                        $data['surcharges']['residentialFee'] = $echoLtl->getResiFee($data['Accessorials'] ?? []) ?? 0;
                        $data['surcharges']['hazardousMaterialsFee'] = $echoLtl->getHazardousMaterialsFee($data['Accessorials'] ?? []) ?? 0;
                        // Apply override rates shipping rule
                        $data = $this->applyOverrideRatesRule($connectionSettings, $data);
                        $access = $this->getAccessorialCode();
                        $price = $this->calculatePrice($data);

                        $days = $data['totalTransitTimeInDays'] ?? null;
                        $dateAndDays = $echoLtl->getShipmentDateAndDays($data);
                        $title = $this->getTitle($data['CarrierName'], false, false, $days, [], $dateAndDays);

                        $arraySorting['simple'][$key] = $price;
                        $originQuotes[$origin]['simple'][$key]['code'] = 'echoltl' . $access . $srvcType;
                        $originQuotes[$origin]['simple'][$key]['rate'] = $price;
                        $originQuotes[$origin]['simple'][$key]['title'] = $title;

                        if ($lgQuotes) {
                            $lgAccess = $this->getAccessorialCode(true);
                            $lgPrice = $this->calculatePrice($data, true);
                            $lgTitle = $this->getTitle($data['CarrierName'], true, false, $days, [], $dateAndDays);
                            $arraySorting['liftgate'][$key] = $lgPrice;
                            $originQuotes[$origin]['liftgate'][$key]['code'] = 'echoltl' . $lgAccess . $srvcType;
                            $originQuotes[$origin]['liftgate'][$key]['rate'] = $lgPrice;
                            $originQuotes[$origin]['liftgate'][$key]['title'] = $lgTitle;
                        }
                        // Get Notify Before Delivery Origin Quotes
                        if ($notifyDelivery) {
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes(
                                'notifydelivery', $data['CarrierName'],
                                $originQuotes,
                                $data,
                                $origin,
                                $key,
                                $days,
                                $dateAndDays,
                                false,
                                'echoltl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi,
                                false,
                                false,
                                $notifyDelivery,
                                false,
                                false,
                                $this->storeId,
                            );

                            $arraySorting['notifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }
                        if ($notifyDelivery && $lgQuotes) {
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes(
                                'lgnotifydelivery', $data['CarrierName'],
                                $originQuotes,
                                $data,
                                $origin,
                                $key,
                                $days,
                                $dateAndDays,
                                true,
                                'echoltl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi,
                                false,
                                false,
                                $notifyDelivery,
                                false,
                                false,
                                $this->storeId,
                            );

                            $arraySorting['lgnotifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }
                    }
                }
            }

            $compiledQuotes = $this->getCompiledQuotes($originQuotes, $arraySorting, $lgQuotes);

            if (isset($inStoreLdData) && !empty($inStoreLdData)) {
                $compiledQuotes = $this->inStoreLocalDeliveryQuotes($compiledQuotes, $inStoreLdData, $allOrigins);
            }

            $finalCompiledQuotes[$origin] = $compiledQuotes;
        }

        $allQuotes = $this->getFinalQuotesArray($finalCompiledQuotes);
        return $allQuotes;
    }

    private function compileDayLightLtlQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential)
    {
        $dayLightQuotes = new dayLightLtlQuotesResults();

        $this->isResi = $residential['dayLightLtl'] == 'Y';
        $this->residentialDlvry = $residential['dayLightLtl'] == 'Y' ? 1 : 0;
        $this->alwaysResi = $this->residential['alwaysResi']['dayLightLtl'] ?? false;
        $this->quoteSettings = $connectionSettings['daylight-ltl']['quote_settings'] ?? [];
        $this->quoteSettingsData();
        $shipments = $dayLightQuotes->formatQuotesBeforeCompilation($shipments);

        $allQuotes = $odwArr = $multiShipmentQuotes = [];
        $count = 0;
        $lgQuotes = false;
        $originQuotes = [];

        if (!$this->isMultiShipment) {
            $this->isMultiShipment = $dayLightQuotes->isMultiShipment($shipments);
        }
        $labelAs = $this->quoteSettings['label_as'] ?? '';
        $labelAs = !blank($labelAs) ? $labelAs : 'Freight';

        /* Quotes compilation */
        foreach ($shipments as $origin => $quote) {
            $this->originKey = $origin;
            if (isset($quote['severity']) || (!isset($quote['q']) && isset($quote['InstorPickupLocalDelivery']))) {
                $instoreResp[$origin] = $this->getInsPicAndLocDelQuotes($quote, $allOrigins) ?? [];                
                return $instoreResp;
            }

            if ($count == 0) {
                $inStoreLdData = $quote['InstorPickupLocalDelivery'] ?? false;
                $lgQuotes = $dayLightQuotes->isLGQuotes($this->quoteSettings, $this->isResi);
            }

            $arraySorting = $quotesArr = [];

            if (isset($quote['q'])) {
                $quotesArr[] = $quote['q'];

                foreach ($quotesArr as $key => $data) {
                    // Apply Override rates shipping rule
                    $data = $this->applyOverrideRatesRule($connectionSettings, $data);
                    $price = $this->calculatePrice($data);
                    $access = $this->getAccessorialCode();

                    $this->quoteSettings['label_as'] = $labelAs;

                    $dateAndDays = $dayLightQuotes->getShipmentDateAndDays($data);
                    $title = $this->getTitle($data['serviceDesc'], false, false, '', [], $dateAndDays);
                    $arraySorting['simple'][$origin] = $price;
                    $originQuotes[$origin]['simple'][$count]['code'] = 'daylightltl' . $access;
                    $originQuotes[$origin]['simple'][$count]['rate'] = $price;
                    $originQuotes[$origin]['simple'][$count]['title'] = $title;

                    if ($lgQuotes) {
                        $lgAccess = $this->getAccessorialCode(true);
                        $lgPrice = $this->calculatePrice($data, true);
                        $lgTitle = $this->getTitle($data['serviceDesc'], true, false, '', [], $dateAndDays);
                        $arraySorting['liftgate'][$origin] = $lgPrice;
                        $originQuotes[$origin]['liftgate'][$count]['code'] = 'daylightltl' . $lgAccess;
                        $originQuotes[$origin]['liftgate'][$count]['rate'] = $lgPrice;
                        $originQuotes[$origin]['liftgate'][$count]['title'] = $lgTitle;
                    }
                }
            }

            if (isset($inStoreLdData) && !empty($inStoreLdData)) {
                $originQuotes[$origin] = $this->inStoreLocalDeliveryQuotes($originQuotes[$origin], $inStoreLdData, $allOrigins);
            }
            $count++;
        }

        $allQuotes = $this->getFinalQuotesArray($originQuotes);
        return $allQuotes;
    }

    private function compileFreightQuoteChrLtlQuotes($shipments, $connectionSettings, $allOrigins)
    {
        $fqChrQuotes = new FQChrQuotesResults();

        $this->isFQChr = true;
        $this->isResi = $this->residential['freightQuoteChrLtl'] == 'Y' ? true : false;
        $this->residentialDlvry = $this->residential['freightQuoteChrLtl'] == 'Y' ? 1 : 0;
        $this->alwaysResi = $this->residential['alwaysResi']['freightQuoteChrLtl'] ?? false;
        $this->quoteSettings = $connectionSettings['freightquote-chr-ltl']['quote_settings'] ?? [];
        $allConfigServices = $connectionSettings['freightquote-chr-ltl']['carrier_services'] ?? [];
        $this->quoteSettingsData();

        $allQuotes = $odwArr = $hazShipmentArr = $multiShipmentQuotes = [];
        $count = 0;
        $lgQuotes = false;

        if (!$this->isMultiShipment) {
            $this->isMultiShipment = $fqChrQuotes->isMultiShipment($shipments);
        }
        $originQuotes = $finalCompiledQuotes = [];
        foreach ($shipments as $origin => $quote) {
            $this->originKey = $origin;
            if (isset($quote['severity'])) {
                return $this->getInsPicAndLocDelQuotes($quote, $allOrigins);
            }

            if ($count == 0) {
                $inStoreLdData = $quote['InstorPickupLocalDelivery'] ?? false;
                $lgQuotes = $fqChrQuotes->isLGQuotes($this->quoteSettings, $this->isResi);
                $isTlQuotes = isset($this->quoteSettings['truckload_weight_threshold']) && $this->quoteSettings['truckload_weight_threshold'] ?? null;
            }

            
            $arraySorting = [];
            $TLquotes = $fqChrQuotes->truckLoadQuotes($quote, $connectionSettings, $origin, $this->items, $this->allOrigins, $this->carrierName);
            $compiledTLquotes = $this->getCompiledQuotes($TLquotes[0], $TLquotes[1], false);

            if (isset($quote['q'])) {
                if (isset($quote['hazardousStatus'])) {
                    $hazShipmentArr[$origin] = $quote['hazardousStatus'] == 'y' ? 'Y' : 'N';
                }

                foreach ($quote['q'] as $key => $data) {
                    if (isset($data['serviceType']) && in_array($data['serviceType'], $allConfigServices)) {
                        
                        $charges = array(
                            'totalNetCharge' => array(
                                'Amount' => $data['totalNetCharge'],
                            ),
                            'surcharges' => $data['surcharges'],
                        );
                        $data = array_merge($data, $charges);
                        
                        // Apply Override rates shipping rule
                        $data = $this->applyOverrideRatesRule($connectionSettings, $data);
                        // Apply Surcharge rates shipping rule
                        //$data = $this->applySurchargeRatesRule($connectionSettings, $data);
                        
                        $access = $this->getAccessorialCode();
                        $price = $this->calculatePrice($data);

                        $dateAndDays = $fqChrQuotes->getShipmentDateAndDays($data);
                        $title = $this->getTitle($data['serviceDesc'], false, false, $data['totalTransitTimeInDays'], [], $dateAndDays);

                        $arraySorting['simple'][$key] = $price;
                        $originQuotes[$origin]['simple'][$key]['code'] = $data['serviceType'] . 'fqchrltl' . $access;
                        $originQuotes[$origin]['simple'][$key]['rate'] = $price;
                        $originQuotes[$origin]['simple'][$key]['title'] = $title;

                        if ($lgQuotes) {
                            $lgAccess = $data['serviceType'] . 'fqchrltl' . $this->getAccessorialCode(true);
                            $lgPrice = $this->calculatePrice($data, true);
                            $lgTitle = $this->getTitle($data['serviceDesc'], true, false, $data['totalTransitTimeInDays'], [], $dateAndDays);

                            $arraySorting['liftgate'][$key] = $lgPrice;
                            $originQuotes[$origin]['liftgate'][$key]['code'] = $lgAccess;
                            $originQuotes[$origin]['liftgate'][$key]['rate'] = $lgPrice;
                            $originQuotes[$origin]['liftgate'][$key]['title'] = $lgTitle;
                        }
                    }
                }
            }

            $compiledQuotes = $this->getCompiledQuotes($originQuotes, $arraySorting, $lgQuotes);
            if ($compiledTLquotes !== null && !empty($compiledTLquotes)) {
                $compiledQuotes = array_merge($compiledQuotes, $compiledTLquotes);
            }

            if (isset($inStoreLdData) && !empty($inStoreLdData)) {
                $compiledQuotes = $this->inStoreLocalDeliveryQuotes($compiledQuotes, $inStoreLdData, $allOrigins);
            }

            $finalCompiledQuotes[$origin] = $compiledQuotes;
        }

        $allQuotes = $this->getFinalQuotesArray($finalCompiledQuotes);
        return $allQuotes;
    }

    public function compilePriority1LtlQuotes($shipments, $connectionSettings, $allOrigins)
    {
        $priority1Ltl = new Priority1QuotesResults();
        $this->isPriority1 = true;
        if ($this->residential['priority1Ltl'] == 'Y') {
            $this->isResi = true;
            $this->residentialDlvry = 1;
        } else {
            $this->isResi = false;
            $this->residentialDlvry = 0;
        }
        $this->alwaysResi = $this->residential['alwaysResi']['priority1Ltl'] ?? false;
        $this->quoteSettings = $connectionSettings['priority-one-ltl']['quote_settings'] ?? [];
        $allConfigServices = $connectionSettings['priority-one-ltl']['carrier_services'] ?? [];
        $shipments = $priority1Ltl->formateQuoteBeforeCompile($shipments);
        $this->quoteSettingsData();
        $allQuotes = $odwArr = $hazShipmentArr = $multiShipmentQuotes = [];
        $count = 0;
        $lgQuotes = $resiPickup = $lgPickup = false;
        $numberOfShipments = 0;
        $originQuotes = [];
        foreach ($shipments as $ship) {
            if (!isset($ship['severity'])) {
                $numberOfShipments++;
            }
        }
        if (!$this->isMultiShipment) {
            $this->isMultiShipment = is_countable($shipments) && $numberOfShipments > 1;
        }

        foreach ($shipments as $origin => $quote) {
            $this->originKey = $origin;
            if (isset($quote['severity'])) {
                return $instoreLocDelQuotes = $this->getInsPicAndLocDelQuotes($quote, $allOrigins);
            }

            if ($count == 0) { //To be checked only once
                $isRad = $quote['autoResidentialsStatus'] ?? '';
                $inStoreLdData = $quote['InstorPickupLocalDelivery'] ?? false;
                unset($quote['InstorPickupLocalDelivery']);

                $lgQuotes =
                    (isset($this->quoteSettings['alwaysLiftGateDelivery']) && $this->quoteSettings['alwaysLiftGateDelivery']) ||
                    (isset($this->quoteSettings['offerLiftGateDelivery']) && $this->quoteSettings['offerLiftGateDelivery']);
                if (!$lgQuotes) {
                    $lgQuotes = (isset($this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->isResi;
                }
                
                $notifyDelivery =
                    (isset($this->quoteSettings['always_quote_notify']) && $this->quoteSettings['always_quote_notify']) ||
                    (isset($this->quoteSettings['offer_notify_as_option']) && $this->quoteSettings['offer_notify_as_option']);
                // Below committed code will use for future 
                // $insideDelivery = (isset($this->quoteSettings['offer_inside_delivery']) && $this->quoteSettings['offer_inside_delivery']) ||
                //     (isset($this->quoteSettings['always_inside_delivery']) && $this->quoteSettings['always_inside_delivery']);

                // $limitedAccess =
                //     (isset($this->quoteSettings['offer_limited_access_delivery']) && $this->quoteSettings['offer_limited_access_delivery']) ||
                //     (isset($this->quoteSettings['always_limited_access_delivery']) && $this->quoteSettings['always_limited_access_delivery']) ?? false;
            }

            $arraySorting = [];
            if (isset($quote['q'])) {
                if (isset($quote['hazardousStatus'])) {
                    $hazShipmentArr[$origin] = $quote['hazardousStatus'] == 'y' ? 'Y' : 'N';
                }
                foreach ($quote['q'] as $key => $data) {

                    if (isset($data['serviceType']) && in_array($data['serviceType'], $allConfigServices)) {

                        $isliftgateFee = isset($data['surcharges']['liftgateFee']);
                        $isnotifyDeliveryFee = isset($data['surcharges']['notifyDeliveryFee']);
                        $isResidentialFee = !isset($data['surcharges']['residentialFee']) && ($this->isResi || $this->alwaysResi);

                        /*
                         * Date 01-07-22
                         * Adding Functionality of Delivery Estimate Options
                         * */
                        // Apply Override rates shipping rule
                        $data = $this->applyOverrideRatesRule($connectionSettings, $data);
                        $date = $data['deliveryDate'] ?? null;
                        $days = $data['totalTransitTimeInDays'] ?? null;
                        $dateAndDays = ['deliveryDate' => $date, 'totalTransitTimeInDays' => $days];
                        $enableFeaturesArray = Functions::getEnableFeaturesArr($lgQuotes && $isliftgateFee, false, $notifyDelivery && $isnotifyDeliveryFee, false);
                        foreach ($enableFeaturesArray as $index => $feature) {
                            if ($feature['isEnable']) {
                                $compileNotifyDeliveryQuotes = Functions::getOriginQuotes(
                                    $index, $data['serviceDesc'],
                                    $originQuotes,
                                    $data,
                                    $origin,
                                    $key, $data['totalTransitTimeInDays'],
                                    $dateAndDays, $feature['index']['isLG'] ?? false,
                                    "priority1ltl", $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings,
                                    $this->isResi, $this->alwaysResi, $feature['index']['isID'] ?? false, $feature['index']['isLAD'] ?? false, $feature['index']['isNBD'] ?? false,
                                    $resiPickup,
                                    $lgPickup,
                                    $this->storeId,
                                );

                                $arraySorting[$index][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                                $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                            }
                        }
                    }
                }
            }

            $compiledQuotes = $this->getCompiledQuotes($originQuotes, $arraySorting, $lgQuotes, $resiPickup, $lgPickup, false, $notifyDelivery, false);

            if (isset($inStoreLdData) && !empty($inStoreLdData)) {
                $compiledQuotes = $this->inStoreLocalDeliveryQuotes($compiledQuotes, $inStoreLdData, $allOrigins);
            }

            $finalCompiledQuotes[$origin] = $compiledQuotes;

            $count++;
        }

        $allQuotes = $this->getFinalQuotesArray($finalCompiledQuotes);        
        return $allQuotes;
    }

    private function compileUPSLandedCostQuotes($shipments, $connectionSettings, $allOrigins)
    {
        $UPSLandedCostApi = new UPSLandedCostResults();
        $shipments = $UPSLandedCostApi->formateQuoteBeforeCompile($shipments, $connectionSettings['ups-land-cost-small']);

        $this->quoteSettings = $connectionSettings['ups-land-cost-small']['quote_settings'] ?? [];

        $allQuotes = $odwArr = $hazShipmentArr = $multiShipmentQuotes = [];
        $count = 0;

        $numberOfShipments = 0;
        foreach ($shipments as $ship) {
            if (!isset($ship['severity'])) {
                $numberOfShipments++;
            }
        }

        if (!$this->isMultiShipment) {
            $this->isMultiShipment = is_countable($shipments) && $numberOfShipments > 1;
        }

        foreach ($shipments as $origin => $quote) {
            $this->originKey = $origin;

            if ((isset($quote['severity']) || !isset($quote['q']) || (isset($quote['q']) && empty($quote['q'])))) {
                return $this->getInsPicAndLocDelQuotes($quote, $allOrigins);
            }

            if ($count == 0) {
                $inStoreLdData = $UPSLandedCostApi->isSuppressedRatesShipment($shipments) ? $quote['InstorPickupLocalDelivery'] : $quote['q']['InstorPickupLocalDelivery'] ?? false;
                unset($quote['InstorPickupLocalDelivery']);
                unset($quote['q']['InstorPickupLocalDelivery']);
            }

            $originQuotes = [];
            $arraySorting = [];

            if (isset($quote['q']) && !$UPSLandedCostApi->isSuppressedRatesShipment($shipments)) {

                foreach ($quote as $key => $data) {

                    $srvcType = $data['serviceType'] ?? '';
                    if (!empty($srvcType)) {
                        // Apply override rates shipping rule
                        //$data = $this->applyOverrideRatesRule($connectionSettings, $data);

                        $price = $this->calculatePrice($data);
                        $access = $this->getAccessorialCode();
                        $title = $this->getTitle($data['serviceDesc']);

                        $arraySorting['simple'][$origin] = $price;
                        $originQuotes[$origin]['simple']['code'] = 'upslandcostapi' . $access;
                        $originQuotes[$origin]['simple']['rate'] = $price;
                        $originQuotes[$origin]['simple']['title'] = $title;
                    }
                }
            }

            $compiledQuotes = $this->getCompiledQuotes($originQuotes, $arraySorting, $this->isMultiShipment);

            if ($compiledQuotes !== null && !empty($compiledQuotes)) {
                // Get Quotes Array
                if (count($compiledQuotes) > 1) {
                    foreach ($compiledQuotes as $k => $service) {
                        foreach ($service as $serKey => $ser) {
                            $quotes = Functions::getQuotesArray($service, $allQuotes, $multiShipmentQuotes, $origin, $serKey);
                            $allQuotes = $quotes['allQuotes'];
                            $multiShipmentQuotes = $quotes['multiShipmentQuotes'];
                        }
                    }
                } else {
                    $service = reset($compiledQuotes);
                    foreach ($service as $serKey => $ser) {
                        $quotes = Functions::getQuotesArray($service, $allQuotes, $multiShipmentQuotes, $origin, $serKey);
                        $allQuotes = $quotes['allQuotes'];
                        $multiShipmentQuotes = $quotes['multiShipmentQuotes'];
                    }
                }
            }

            if ($this->isMultiShipment) {
                $odwArr[$origin]['quotes'] = $compiledQuotes;
            }

            $count++;
        }

        $allQuotes = $this->getFinalQuotesArray($allQuotes);

        if (!$this->isMultiShipment && isset($inStoreLdData) && !empty($inStoreLdData)) {
            $allQuotes = $this->inStoreLocalDeliveryQuotes($allQuotes, $inStoreLdData, $allOrigins);
        }
        if ((!empty($multiShipmentQuotes['simple']) && count($multiShipmentQuotes['simple']) > 1)) {
            $allQuotes = $this->forceChangeTitle($allQuotes);
            $resp = [
                'checkoutQuotes' => $allQuotes,
                'multiShipmentQuotes' => $multiShipmentQuotes,
            ];
            return $resp;
        }
        return $allQuotes;
    }

    public function getInsPicAndLocDelQuotes($quote, $allOrigins): array
    {
        $inStoreLdData = $quote['InstorPickupLocalDelivery'] ?? $quote['q']['InstorPickupLocalDelivery'] ?? $quote['fedexServices']['InstorPickupLocalDelivery'] ?? [];
        $ownArrangementQoutes = isset($quote['allowOwnArrangement']) && $quote['allowOwnArrangement'] ? $this->arrangeOwnFreight() : [];
        if (!$this->isMultiShipment && (!blank($inStoreLdData) || !blank($ownArrangementQoutes))) {
            return $this->inStoreLocalDeliveryQuotes($ownArrangementQoutes, $inStoreLdData, $allOrigins);
        }

        return [];
    }

    /**
     * Calculate Handling Fee
     * @param $cost
     * @return float
     */
    public function calculateHandlingFee($cost, $quoteSettings = [])
    {
        $handlingFeeMarkup = 0;
        $symbolicHandlingFee = '';
        if (!empty($quoteSettings)) {
            $this->quoteSettings = $quoteSettings;
        }
        if (isset($this->quoteSettings['handling_free_markup'])) {
            $handlingFeeMarkup = (float) $this->quoteSettings['handling_free_markup'] ?? 0;
            $symbolicHandlingFee = strpos($this->quoteSettings['handling_free_markup'], '%') ? '%' : '';
        }

        if (strlen($handlingFeeMarkup) > 0) {
            if ($symbolicHandlingFee === '%') {
                $percentVal = $handlingFeeMarkup / 100 * $cost;
                $grandTotal = $percentVal + $cost;
            } else {
                $grandTotal = $handlingFeeMarkup + $cost;
            }
        } else {
            $grandTotal = $cost;
        }
        return $grandTotal;
    }

    /**
     * =======================================================
     * ************ Extension's Native Section ***************
     * =======================================================
     * */

    /**
     * @param $quotes
     * @return array
     *
     * @info: This function will arrange array of quotes according to the accessorials.
     * This function will handle single shipment and multi shipment both for return final array.
     */
    public function getFinalQuotesArray($finalQuotes)
    {
        if (empty($finalQuotes)) {
            return [];
        }

        $lfg = (isset($this->quoteSettings['alwaysLiftGateDelivery']) && $this->quoteSettings['alwaysLiftGateDelivery'] == 1  ) || (  $this->isResi && isset($this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->quoteSettings['autoDetectedResidentialAddressesLfg']);
        $TMD_or_APD = (isset($this->quoteSettings['always_two_man_delivery']) && $this->quoteSettings['always_two_man_delivery'] == 1  ) || (isset($this->quoteSettings['always_appointment_delivery']) && $this->quoteSettings['always_appointment_delivery'] == 1  );
        $TMD_and_APD = (isset($this->quoteSettings['always_two_man_delivery']) && $this->quoteSettings['always_two_man_delivery'] == 1) && (isset($this->quoteSettings['always_appointment_delivery']) && $this->quoteSettings['always_appointment_delivery'] == 1  );
        $alwaysNotifyDel = (isset($this->quoteSettings['always_quote_notify']) && $this->quoteSettings['always_quote_notify'] == 1  );
        $alwaysInsideDel = (isset($this->quoteSettings['always_inside_delivery']) && $this->quoteSettings['always_inside_delivery'] == 1  );
        $alwaysLimitedDel = (isset($this->quoteSettings['always_limited_access_delivery']) && $this->quoteSettings['always_limited_access_delivery'] == 1  );
        $overrideQuotes = [];

        foreach($finalQuotes as $locId => $quotes){

            if ($lfg && $alwaysNotifyDel && $alwaysInsideDel && $alwaysLimitedDel) {
                /**
                 * Condition for Always lift gate, notify before delivery, limited access and inside delivery (Multi Shipment)
                 * */
                unset($quotes['simple'], $quotes['liftgate'], $quotes['insideDelivery'], $quotes['insideLiftGateDelivery'], $quotes['limitedaccess'], $quotes['limitedaccessLG'],
                    $quotes['Truckload'], $quotes['notifydelivery'], $quotes['lgnotifydelivery'], $quotes['insidenotifydelivery'], $quotes['lginsidenotifydelivery'], $quotes['laccessnotifydelivery'],
                    $quotes['lglaccessnotifydelivery'], $quotes['laccessinsidedelivery'], $quotes['lglaccessinsidedelivery'], $quotes['laccessinsideNotifydelivery']);
    
            } elseif ($alwaysNotifyDel && $alwaysInsideDel && $alwaysLimitedDel) {
                /**
                 * Condition for Always notify before delivery, limited access and inside delivery (Multi Shipment)
                 * */
                unset($quotes['simple'], $quotes['liftgate'], $quotes['insideDelivery'], $quotes['insideLiftGateDelivery'], $quotes['limitedaccess'], $quotes['limitedaccessLG'],
                    $quotes['Truckload'], $quotes['notifydelivery'], $quotes['lgnotifydelivery'], $quotes['insidenotifydelivery'], $quotes['lginsidenotifydelivery'],
                    $quotes['laccessnotifydelivery'], $quotes['lglaccessnotifydelivery'], $quotes['laccessinsidedelivery'], $quotes['lglaccessinsidedelivery']);
    
            } elseif ($lfg && $alwaysNotifyDel && $alwaysInsideDel) {
                /**
                 * Condition for Always lift gate, notify before delivery and inside delivery (Multi Shipment)
                 * */
                unset($quotes['simple'], $quotes['liftgate'], $quotes['insideDelivery'], $quotes['insideLiftGateDelivery'], $quotes['limitedaccess'], $quotes['limitedaccessLG'],
                    $quotes['Truckload'], $quotes['notifydelivery'], $quotes['lgnotifydelivery'], $quotes['insidenotifydelivery'], $quotes['laccessnotifydelivery'],
                    $quotes['lglaccessnotifydelivery'], $quotes['laccessinsidedelivery'], $quotes['lglaccessinsidedelivery'], $quotes['laccessinsideNotifydelivery']);
    
            } elseif ($lfg && $alwaysInsideDel && $alwaysLimitedDel) {
                /**
                 * Condition for Always lift gate, inside delivery and limited access delivery (Multi Shipment)
                 * */
                unset($quotes['simple'], $quotes['liftgate'], $quotes['insideDelivery'], $quotes['insideLiftGateDelivery'], $quotes['limitedaccess'], $quotes['limitedaccessLG'],
                    $quotes['Truckload'], $quotes['notifydelivery'], $quotes['lgnotifydelivery'], $quotes['insidenotifydelivery'], $quotes['lginsidenotifydelivery'],
                    $quotes['laccessnotifydelivery'], $quotes['lglaccessnotifydelivery'], $quotes['laccessinsidedelivery'], $quotes['laccessinsideNotifydelivery']);
    
            } elseif ($lfg && $alwaysNotifyDel && $alwaysLimitedDel) {
                /**
                 * Condition for Always lift gate, notify before delivery and limited access delivery (Multi Shipment)
                 * */
                unset($quotes['simple'], $quotes['liftgate'], $quotes['insideDelivery'], $quotes['insideLiftGateDelivery'], $quotes['limitedaccess'], $quotes['limitedaccessLG'],
                    $quotes['Truckload'], $quotes['notifydelivery'], $quotes['lgnotifydelivery'], $quotes['insidenotifydelivery'], $quotes['lginsidenotifydelivery'],
                    $quotes['laccessnotifydelivery'], $quotes['laccessinsidedelivery'], $quotes['lglaccessinsidedelivery'], $quotes['laccessinsideNotifydelivery']);
    
            } elseif ($lfg && $alwaysInsideDel) {
                /**
                 * Condition for Always lift gate, inside delivery and lift gate for residential (Multi Shipment)
                 * */
                unset($quotes['simple'], $quotes['liftgate'], $quotes['insideDelivery'], $quotes['limitedaccess'], $quotes['limitedaccessLG'],
                    $quotes['Truckload'], $quotes['notifydelivery'], $quotes['lgnotifydelivery'], $quotes['insidenotifydelivery'], $quotes['laccessnotifydelivery'],
                    $quotes['lglaccessnotifydelivery'], $quotes['laccessinsidedelivery'], $quotes['laccessinsideNotifydelivery']);
    
            } elseif ($lfg && $alwaysLimitedDel) {
                /**
                 * Condition for Always lift gate, limited access delivery and lift gate (Multi Shipment)
                 * */
                unset($quotes['simple'], $quotes['liftgate'], $quotes['insideDelivery'], $quotes['insideLiftGateDelivery'], $quotes['limitedaccess'],
                    $quotes['Truckload'], $quotes['notifydelivery'], $quotes['lgnotifydelivery'], $quotes['insidenotifydelivery'], $quotes['lginsidenotifydelivery'],
                    $quotes['laccessnotifydelivery'], $quotes['laccessinsidedelivery'], $quotes['laccessinsideNotifydelivery']);
    
            } elseif ($alwaysInsideDel && $alwaysNotifyDel) {
                /**
                 * Condition for Always inside, notify before delivery and (Multi Shipment)
                 * */
                unset($quotes['simple'], $quotes['liftgate'], $quotes['insideDelivery'], $quotes['insideLiftGateDelivery'], $quotes['limitedaccess'],
                    $quotes['limitedaccessLG'], $quotes['Truckload'], $quotes['notifydelivery'], $quotes['lgnotifydelivery'], $quotes['laccessnotifydelivery'],
                    $quotes['lglaccessnotifydelivery'], $quotes['laccessinsidedelivery'], $quotes['lglaccessinsidedelivery']);
    
            } elseif ($alwaysLimitedDel && $alwaysInsideDel) {
                /**
                 * Condition for Always limited access and inside delivery and (Multi Shipment)
                 * */
                unset($quotes['simple'], $quotes['liftgate'], $quotes['insideDelivery'], $quotes['insideLiftGateDelivery'], $quotes['limitedaccess'], $quotes['limitedaccessLG'],
                    $quotes['Truckload'], $quotes['notifydelivery'], $quotes['lgnotifydelivery'], $quotes['insidenotifydelivery'], $quotes['lginsidenotifydelivery'],
                    $quotes['laccessnotifydelivery'], $quotes['lglaccessnotifydelivery']);
    
            } elseif ($alwaysLimitedDel && $alwaysNotifyDel) {
                /**
                 * Condition for Always limited access and notify before delivery and (Multi Shipment)
                 * */
                unset($quotes['simple'], $quotes['liftgate'], $quotes['insideDelivery'], $quotes['insideLiftGateDelivery'], $quotes['limitedaccess'], $quotes['limitedaccessLG'],
                    $quotes['Truckload'], $quotes['notifydelivery'], $quotes['lgnotifydelivery'], $quotes['insidenotifydelivery'], $quotes['lginsidenotifydelivery'],
                    $quotes['laccessinsidedelivery'], $quotes['lglaccessinsidedelivery']);
    
            } elseif ($lfg && $alwaysNotifyDel) {
                /**
                 * Condition for Always lift gate, notify before delivery and lift gate for residential (Multi Shipment)
                 * */
                unset($quotes['simple'], $quotes['liftgate'], $quotes['insideDelivery'], $quotes['insideLiftGateDelivery'], $quotes['limitedaccess'],
                    $quotes['limitedaccessLG'], $quotes['Truckload'], $quotes['notifydelivery'], $quotes['insidenotifydelivery'], $quotes['laccessnotifydelivery'],
                    $quotes['laccessinsidedelivery'], $quotes['lglaccessinsidedelivery'], $quotes['laccessinsideNotifydelivery']);
    
            } elseif ($TMD_and_APD) {
                /**
                 * Condition for Always two man and appointment delivery (Multi Shipment)
                 * */
                unset($quotes['simple'], $quotes['insideDelivery'], $quotes['limitedaccess'], $quotes['twoManDel'], $quotes['aptDel']);
            } elseif ($alwaysInsideDel) {
                /**
                 * Condition for Always inside delivery (Multi Shipment)
                 * */
                unset($quotes['simple'], $quotes['liftgate'], $quotes['limitedaccess'], $quotes['limitedaccessLG'],
                    $quotes['Truckload'], $quotes['notifydelivery'], $quotes['lgnotifydelivery'], $quotes['laccessnotifydelivery'],
                    $quotes['lglaccessnotifydelivery'], $quotes['twoManDel'], $quotes['aptDel']);
    
            } elseif ($alwaysNotifyDel) {
                /**
                 * Condition for Always notify before delivery (Multi Shipment)
                 * */
                unset($quotes['simple'], $quotes['liftgate'], $quotes['insideDelivery'], $quotes['insideLiftGateDelivery'], $quotes['limitedaccess'], $quotes['limitedaccessLG'],
                    $quotes['Truckload'], $quotes['laccessinsidedelivery'], $quotes['lglaccessinsidedelivery'], $quotes['twoManDel'], $quotes['aptDel']);
    
            } elseif ($alwaysLimitedDel) {
                /**
                 * Condition for Always limited access delivery (Multi Shipment)
                 * */
                unset($quotes['simple'], $quotes['liftgate'], $quotes['insideDelivery'], $quotes['insideLiftGateDelivery'],
                    $quotes['Truckload'], $quotes['notifydelivery'], $quotes['lgnotifydelivery'], $quotes['insidenotifydelivery'],
                    $quotes['lginsidenotifydelivery'], $quotes['twoManDel'], $quotes['aptDel']);
    
            } elseif ($lfg || $TMD_or_APD) {
                /**
                 * Condition for always ;8lift gate and lift gate for residential (Multi Shipment)
                 * Condition for Always two man or appointment delivery (Multi Shipment)
                 * */
                unset($quotes['simple'], $quotes['insideDelivery'], $quotes['limitedaccess'], $quotes['notifydelivery'],
                    $quotes['insidenotifydelivery'], $quotes['laccessnotifydelivery'], $quotes['laccessinsidedelivery'],
                    $quotes['laccessinsideNotifydelivery'], $quotes['twoManAptDel']);
            }

            $finalQuotes[$locId] = $quotes;
        }
        return $finalQuotes;
    }    

    public function checkAccessorial($code, $lg)
    {
        $return = 'CFMS';
        $lg ? $return = $return . '+LG' : '';
        $arr = (explode('+', $code));
        if (in_array('R', $arr)) {
            $return = $return . '+R';
        }
        return $return;
    }

    public function arraySortByColumn(&$arr, $col, $dir = SORT_ASC)
    {
        $sort_col = [];
        foreach ($arr as $key => $row) {
            $sort_col[$key] = $row[$col];
        }

        array_multisort($sort_col, $dir, $arr);
    }

    /**
     *
     * @return string
     */
    public function getRatingMethod()
    {
        $ratingMethod = 'Cheapest';
        switch ($this->ratingMethod) {
            case 1:
                $ratingMethod = 'Cheapest';
                break;
            case 2:
                $ratingMethod = 'cheapestOptions';
                break;
            case 3:
                $ratingMethod = 'averageRate';
                break;
        }
        return $ratingMethod;
    }

    /**
     * @param bool $lgOption
     * @return string
     *
     * @info: This will return specific code according to the accessorials for appending with the service code.
     */
    public function getAccessorialCode($lgOption = false, $insideDel = false, $resiPickup = '', $lgPickup = '', $laccess = false, $twoManDel = false, $appDel = false, $notifyDelivery = false, $isResi = false, $isAlwaysResidential = false, $isSurchargeRates = false)
    {
        $access = '';
        $isAlwaysResi = isset($this->isSameDayApi) && $this->isSameDayApi && $lgOption ? false : $this->alwaysResi;
        if ($this->residentialDlvry == '1' || $this->isResi || $isAlwaysResi || $isResi || $isAlwaysResidential) {
            $access .= '+R';
        }
        if (($lgOption || (isset($this->liftGate) && $this->liftGate == '1')) || (isset($this->RADforLiftgate) && $this->RADforLiftgate && $this->isResi)) {
            $access .= '+LG';
        }
        if ($insideDel) {
            $access .= '+ID';
        }
        if ($laccess) {
            $access .= '+LAD';
        }
        if ($notifyDelivery) {
            $access .= '+NBD';
        }
        if($this->isSurchargeRates || $isSurchargeRates){
            $access .= '+SC';
        }
        if ($twoManDel && $appDel) {
            $access .= Functions::$twoManAptDelAccess;
        } elseif ($twoManDel) {
            $access .= Functions::$twoManDelAccess;
        } elseif ($appDel) {
            $access .= Functions::$appointmentDelAccess;
        }

        if (!empty($resiPickup)) {
            $access .= Functions::$resiPickupTitle;
        }
        if (!empty($lgPickup)) {
            $access .= Functions::$lgPickupTitle;
        }

        return $access;
    }

    /**
     * @param $data
     * @param bool $lgOption
     * @param bool $getCost
     * @return float
     *
     * @info: This function will calculate all prices and return price against a specific service
     */
    public function calculatePrice($data, $lgOption = false, $getCost = false, $isUpsLtl = false, $insideDel = false, $laccess = false, $twoManDel = false, $appDel = false, $notifyDelivery = false, $originKey = '', $items = [], $allOrigins = [], $quoteSettings = [], $isResi = false)
    {
        $lgCost = $lgOption ? 0 : $this->getLiftGateCost($data, $getCost, $isUpsLtl);
        $IDCost = $insideDel ? 0 : $this->getInsideDeliveryCost($data);
        $LADCost = $laccess ? 0 : $data['limitedAccessDeliveryFee'] ?? $data['surcharges']['limitedAccessDeliveryFee'] ?? 0;
        $ResiCost = ($this->isResi || $this->alwaysResi || $isResi) ? 0 : $data['surcharges']['residentialFee'] ?? 0;
        $TMDCost = $twoManDel ? 0 : $data['surcharges']['twoManFee'] ?? 0;
        $APDCost = $appDel ? 0 : $data['surcharges']['appointmentFee'] ?? 0;
        $NBDCost = $notifyDelivery ? 0 : $this->getNotifyDeliveryCost($data, $isUpsLtl);
        $basePrice = str_replace(',', '', $data['totalNetCharge']['Amount']);
        $basePrice = (float) $basePrice;
        $basePrice = $basePrice - $lgCost - $LADCost - $IDCost - $TMDCost - $APDCost - $NBDCost - $ResiCost;
        $productOriginMarkupFee = Functions::calProductOriginMarkupFee($basePrice, $this->originKey ?? $originKey, $this->items ?? $items, $this->allOrigins ?? $allOrigins);
        $basePrice = $basePrice + $productOriginMarkupFee;
        $basePrice = $this->calculateHandlingFee($basePrice, $quoteSettings);
        return $basePrice;
    }

    /**
     * @param $quotes
     * @param bool $getCost
     * @return float
     */
    public function getLiftGateCost($quotes, $getCost = false, $isUpsLtl = false, $isOdflLtl = false)
    {
        $lgCost = 0;
        if (
            !(($this->isResi && isset($this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->quoteSettings['autoDetectedResidentialAddressesLfg']) ||
                (isset($this->quoteSettings['alwaysLiftGateDelivery']) && $this->quoteSettings['alwaysLiftGateDelivery'] == '1' && !(isset($this->quoteSettings['insideDelivery']) && $this->quoteSettings['insideDelivery']))) || $getCost
        ) {
            if (isset($quotes['surcharges']) && isset($quotes['surcharges']['liftgateFee'])) {
                $lgCost = (float) $quotes['surcharges']['liftgateFee'];
            }
            if ($isUpsLtl) {

                $surcharges = $quotes['surcharges'] ?? [];
                foreach ($surcharges as $surcharge) {
                    if (isset($surcharge['Type']['Code']) && $surcharge['Type']['Code'] === 'LIFTGATE') {
                        $lgCost = (float) $surcharge['Factor']['Value'] ?? 0;
                        break;
                    }
                    //check : Tforce new api
                    if (isset($surcharge['code']) && $surcharge['code'] === 'LIFD') {
                        $lgCost = (float) $surcharge['value'] ?? 0;
                        break;
                    }
                }
            }
        }
        return $lgCost;
    }

    public function getInsideDeliveryCost($quotes)
    {
        $lgCost = 0;
        if (isset($quotes['surcharges']) && isset($quotes['surcharges']['insideDeliveryFee'])) {
            $lgCost = $quotes['surcharges']['insideDeliveryFee'];
        }
        return $lgCost;
    }

    public function getNotifyDeliveryCost($quotes, $isUpsLtl)
    {
        $ndCost = 0;
        if (isset($quotes['surcharges']) && isset($quotes['surcharges']['notifyDeliveryFee'])) {
            $ndCost = (float) $quotes['surcharges']['notifyDeliveryFee'];
        } elseif (isset($quotes['surcharges']) && isset($quotes['surcharges']['notifyBeforeDeliveryFee'])) {
            $ndCost = (float) $quotes['surcharges']['notifyBeforeDeliveryFee'];
        }


        if ($isUpsLtl) {

            $surcharges = $quotes['surcharges'] ?? [];
            foreach ($surcharges as $surcharge) {
                if (isset($surcharge['Type']['Code']) && $surcharge['Type']['Code'] === 'ADV_NOTF') {
                    $ndCost = $surcharge['Factor']['Value'] ?? 0;
                    break;
                }
                //check : Tforce new api
                if (isset($surcharge['code']) && $surcharge['code'] === 'NTFN') {
                    $ndCost = $surcharge['value'] ?? 0;
                    break;
                }
            }
        }

        return $ndCost;
    }

    /**
     * Calculate Handling Fee
     * @param $cost
     * @return float
     */
    public function calculateHandlingFeeAz($cost)
    {
        $handlingFeeMarkup = $this->hndlngFee;
        $symbolicHandlingFee = $this->symbolicHndlngFee;

        if (strlen($handlingFeeMarkup) > 0) {
            if ($symbolicHandlingFee == '%') {
                $percentVal = $handlingFeeMarkup / 100 * $cost;
                $grandTotal = $percentVal + $cost;
            } else {
                $grandTotal = $handlingFeeMarkup + $cost;
            }
        } else {
            $grandTotal = $cost;
        }
        return $grandTotal;
    }

    /**
     * @param $serviceName
     * @param bool $lgOption
     * @param bool $from
     * @param string $deliveryEstimate
     * @return string
     *
     * @info: This function will compile name of a service and return service name according to the settings enabled.
     */
    public function getTitle($serviceName, $lgOption = false, $from = false, $deliveryEstimate = '', $quoteSetting = [], $daysAndDate = [], $insideDel = false, $laccess = false, $laccessLG = false, $twoManDel = false, $appDel = false, $twoManAptDel = false, $notifyDelivery = false, $isResi = false, $storeId = '')
    {
        // Here  Making service title
        if (!empty($quoteSetting)) {
            $this->quoteSettings = $quoteSetting;
        }
        $serviceTitle = $this->customLabel($serviceName);
        $deliveryEstimateLabel = $this->getDeliveryEstimates($daysAndDate);

        if ($from) {
            $serviceTitle = $serviceName;
        }
        // Here  Making Delivery estimate title

        // Here  Making Access title
        $accessTitle = '';
        $isResi = $isResi ? $isResi : $this->isResi;

        // Get Access Title
        $accessTitle = Functions::getAccessTitle($this->quoteSettings, $isResi, $lgOption, $insideDel, $notifyDelivery, $laccess, $twoManDel, $appDel, $this->storeId ?? $storeId);

        $resp = $serviceTitle . $accessTitle . $deliveryEstimateLabel;
        return $resp;
    }

    public function getDeliveryEstimates($dateAndDays): string
    {
        $date = $dateAndDays['deliveryDate'] ?? null;
        $days = $dateAndDays['totalTransitTimeInDays'] ?? null;
        /**
         * Delivery estimation changed to date from days count
         *
         *
         * delivery_estimate_options == 1 means Don't display delivery estimates.
         * delivery_estimate_options == 2 means Display estimated number of days.
         * delivery_estimate_options == 3 means Display estimated delivery date.
         */
        $deliveryEstimates = "";
        if (isset($this->quoteSettings['delivery_estimate_options']) && $this->quoteSettings['delivery_estimate_options'] == 2) {
            $deliveryEstimates = !blank($days) ? " (Intransit days: " . $days . ")" : "";
        } elseif (isset($this->quoteSettings['delivery_estimate_options']) && $this->quoteSettings['delivery_estimate_options'] == 3) {
            $deliveryEstimates = !blank($date) ? " (Delivery by " . date('M d', strtotime($date)) . ")" : "";
        }

        return $deliveryEstimates;
    }

    /**
     * Title for GTZ
     */
    public function getGTitle($serviceName, $lgOption = false, $notify = false, $laccess = false, $from = false, $deliveryEstimate = '', $quoteSetting = [], $quckest = false, $dateAndDays = [])
    {
        // Here  Making service title
        if (!empty($quoteSetting)) {
            $this->quoteSettings = $quoteSetting;
        }
        /*
         * Check if quickest enabled -> set lable_as manaully
         */
        $quoteSetting = $this->quoteSettings ?? [];
        if ($quckest) {
            $this->quoteSettings['method'] = 1;
            $this->quoteSettings['label_as'] = $this->quoteSettings['quickest_service_label'] ?? '';
        }
        $serviceTitle = $this->customLabel($serviceName);
        $this->quoteSettings['method'] = $quoteSetting['method'] ?? 0;
        $this->quoteSettings['label_as'] = $quoteSetting['label_as'] ?? '';
        $deliveryEstimateLabel = $this->getDeliveryEstimates($dateAndDays);

        $rad_settings = Functions::getRADsettings($this->storeId) ?? [];
        $showRadNotation = isset($rad_settings['suppress_rad_notation']) && $rad_settings['suppress_rad_notation'];

        if ($this->isMultiShipment && $from == false) {
            return $serviceName . $deliveryEstimateLabel;
        }
        // Here  Making Delivery estimate title

        // Here  Making Access title
        $accessTitle = '';
        $title[] = '';
        if ($laccess && isset($this->quoteSettings['offer_limited_access_delivery']) && $this->quoteSettings['offer_limited_access_delivery']) {
            $title[] = 'A';
        }
        if ($notify && isset($this->quoteSettings['offer_notify_as_option']) && $this->quoteSettings['offer_notify_as_option']) {
            $title[] = 'N';
        }
        if ($lgOption === true || (isset($this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->quoteSettings['autoDetectedResidentialAddressesLfg'])) {
            if ($lgOption && $this->quoteSettings['alwaysLiftGateDelivery'] == '0') {
                $accessTitle = ($this->isResi && $showRadNotation) ? Constant::RESI_LIFT_LABEL : Constant::LIFT_LABEL;
            }
            if (isset($this->quoteSettings['alwaysLiftGateDelivery']) && $this->quoteSettings['alwaysLiftGateDelivery'] && $this->isResi && $showRadNotation) {
                $accessTitle = Constant::RESI_LABEL; //$this->resiLabel;
            }
            if (isset($this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->quoteSettings['autoDetectedResidentialAddressesLfg'] && $this->isResi && $showRadNotation) {
                $accessTitle = Constant::RESI_LIFT_LABEL; //$this->resiLgLabel;
            }
        } elseif ($this->isResi && $showRadNotation) {
            $accessTitle = Constant::RESI_LABEL; //$this->resiLabel;
        }
        $title[] = $accessTitle;
        $title = array_filter($title);
        $accessTitle = '';
        if (!empty($title)) {
            $accessTitle = implode(' | ', $title);
        }
        $resp = $serviceTitle . $accessTitle . $deliveryEstimateLabel;
        return $resp;
    }

    /**
     * @param string $resi
     * @return string
     */
    public function getAutoResidentialTitleAz($resi)
    {
        if ($this->moduleManager->isEnabled('Eniture_ResidentialAddressDetection')) {
            $isRadSuspend = $this->getConfigData("resaddressdetection/suspend/value");
            if ($this->residentialDlvry == "1") {
                $this->residentialDlvry = $isRadSuspend == "no" ? '0' : '1';
            } else {
                $this->residentialDlvry = $isRadSuspend == "no" ? '0' : $this->residentialDlvry;
            }

            if ($this->residentialDlvry == null || $this->residentialDlvry == '0') {
                if ($resi == 'r') {
                    $this->isResi = true;
                }
            }
        }
    }

    /**
     * @param array $quotes
     * @return array
     */
    public function getOriginsMinimumQuotes($quotes)
    {
        $minIndexArr = [];
        $resiArr = ['residential' => false, 'label' => ''];
        $hazShipment = $resi = '';
        $counter = 0;
        $plan = $this->planInfo()['planNumber'];

        foreach ($quotes as $origin => $quote) {
            if (isset($quote->severity)) {
                return [];
            }
            if ($counter == 0) { //To be checked only once
                $isRad = $quote->autoResidentialsStatus ?? '';
                //$this->getAutoResidentialTitle($isRad);
                $resi = $this->isResi ? $this->resiLabel : '';
                if ($this->residentialDlvry || $this->isResi) {
                    $resiArr = ['residential' => true, 'label' => $resi];
                }
            }

            if (isset($quote->q)) {
                if ($plan > 1 && isset($quote->hazardousStatus)) {
                    $hazShipmentArr[$origin] = $quote->hazardousStatus == 'y' ? 'Y' : 'N';
                }

                foreach ($quote as $key => $data) {
                    if (isset($data->serviceType)) {
                        $currentArray = [
                            'code' => 'WWEFREIGHT',
                            'rate' => $this->calculatePrice($data, false, true),
                            'title' => $this->labelAs . ' ' . $resi,
                            'resi' => $resiArr,
                            'hazShipment' => $hazShipment,
                        ];

                        $counter++;
                    }
                }
            }
            $minIndexArr[$origin] = $currentArray;
        }
        return $minIndexArr;
    }

    /**
     * This function returns minimum array index from array
     * @param $servicesArr
     * @return array
     */
    public function findArrayMininum($servicesArr)
    {
        $counter = 1;
        $minIndex = [];
        foreach ($servicesArr as $value) {
            if ($counter == 1) {
                $minimum = $value['rate'];
                $minIndex = $value;
                $counter = 0;
            } else {
                if ($value['rate'] < $minimum) {
                    $minimum = $value['rate'];
                    $minIndex = $value;
                }
            }
        }
        return $minIndex;
    }

    /**
     * This Function returns all active services array from configurations
     * @param $scopeConfig
     * @return array
     */
    public function getAllConfigServicesArray()
    {
        return explode(',', $this->configSettings['carrierList']);
    }

    /**
     * Final quotes array
     * @param $grandTotal
     * @param $code
     * @param $title
     * @param $appendLabel
     * @return array
     */
    public function getFinalQuoteArray($grandTotal, $code, $title, $appendLabel)
    {
        $allowed = [];

        if ($grandTotal > 0) {
            $allowed = [
                'code' => $code,
                // or carrier name
                'title' => $title . $appendLabel,
                'rate' => $grandTotal,
            ];
        }

        return $allowed;
    }

    public function checkOwnArrangement($finalArr)
    {
        if (isset($this->ownArangement) && $this->ownArangement == 1) {
            $title = (isset($this->ownArangementText) && trim($this->ownArangementText) != '') ? $this->ownArangementText :
                "I'll Arrange My Own Freight";
            $finalArr[] = [
                'code' => 'OWAR',
                // or carrier name
                'title' => $title,
                'rate' => 0,
            ];
        }

        return $finalArr;
    }

    public function adminConfigData($fieldId, $scopeConfig)
    {
        return $scopeConfig->getValue("cerasisQuoteSetting/fourth/$fieldId", ScopeInterface::SCOPE_STORE);
    }

    /**
     *
     * @return AbstractCarrierInterface[]
     */
    public function getActiveCarriersForENCount()
    {
        return $this->shippingConfig->getActiveCarriers();
    }

    /**
     * function return service data
     * @param $fieldId
     * @return string
     */
    public function getTestConnConfigData($fieldId)
    {
        $sectionId = 'cerasisConnSettings';
        $groupId = 'first';

        return $this->scopeConfig->getValue("$sectionId/$groupId/$fieldId", ScopeInterface::SCOPE_STORE);
    }

    /**
     * @param $cerriersRes
     * @return array
     */
    public function carrierResult($cerriersRes)
    {
        $status = [];
        if (isset($cerriersRes) && !empty($cerriersRes->carriers)) {
            $date = $this->timezoneInterface->date()->format('m/d/y H:i:s');
            $this->configWriter->save('cerasisLtlCarriers/second/requestTime', json_encode($date), $scope = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $scopeId = 0);
            $this->configWriter->save('cerasisLtlCarriers/second/carriers', json_encode($cerriersRes), $scope = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $scopeId = 0);
            $status['SUCCESS'] = true;
        } else {
            $status['ERROR'] = true;
        }

        return $status;
    }

    /**
     * validate Input Post
     * @param $sPostData
     * @return mixed
     */
    public function LTLValidatedPostData($sPostData)
    {
        $dataArray = ['city', 'state', 'zip', 'country'];
        $data = [];
        foreach ($sPostData as $key => $tag) {
            $preg = '/[#$%@^&_*!()+=\-\[\]\';,.\/{}|":<>?~\\\\]/';
            $check_characters = (in_array($key, $dataArray)) ? preg_match($preg, $tag) : '';

            if ($check_characters != 1) {
                if ($key === 'city' || $key === 'nickname' || $key === 'in_store' || $key === 'local_delivery') {
                    $data[$key] = $tag;
                } else {
                    $data[$key] = preg_replace('/\s+/', '', $tag);
                }
            } else {
                $data[$key] = 'Error';
            }
        }

        return $data;
    }

    /**
     * Get Plan detail
     * @return array
     */
    public function planInfo()
    {
        $planData = $this->coreSession->getPlanDetail();
        if ($planData == null) {
            $appData = $this->getConfigData("eniture/ENWweLTL");
            $plan = $appData["plan"] ?? '-1';
            $storeType = $appData["storetype"] ?? '';
            $expireDays = $appData["expireday"] ?? '';
            $expiryDate = $appData["expiredate"] ?? '';
            $planName = "";
            switch ($plan) {
                case 3:
                    $planName = "Advanced Plan";
                    break;
                case 2:
                    $planName = "Standard Plan";
                    break;
                case 1:
                    $planName = "Basic Plan";
                    break;
                case 0:
                    $planName = "Trial Plan";
                    break;
            }
            $planData = [
                'planNumber' => $plan,
                'planName' => $planName,
                'expireDays' => $expireDays,
                'expiryDate' => $expiryDate,
                'storeType' => $storeType,
            ];
            $this->coreSession->setPlanDetail($planData);
        }
        return $planData;
    }

    /**
     * @return string
     */
    public function ltlSetPlanNotice()
    {
        $planPackage = $this->planInfo();
        if ($planPackage['storeType'] == '') {
            $planPackage = [];
        }
        return $this->displayPlanMessages($planPackage);
    }

    /**
     *
     */
    public function clearCache()
    {
        $types = $this->cacheManager->getAvailableTypes();
        $this->cacheManager->flush($types);
        $this->cacheManager->clean($types);
    }

    /**
     * @param null $msg
     * @param bool $type
     * @return array
     */
    public function generateResponse($msg = null, $type = false)
    {
        $defaultError = 'Something went wrong. Please try again!';
        return [
            'error' => ($type == true) ? 1 : 0,
            'msg' => ($msg != null) ? $msg : $defaultError,
        ];
    }

    public function unsetPlanSession()
    {
        $this->coreSession->unsPlanDetail();
    }

    /**
     * @inheritDoc
     */
    public function getLiftGateDeliveryOptions($orderDetail)
    {
        // TODO: Implement getLiftGateDeliveryOptions() method.
    }

    /**
     * @param $services
     * @param $arraySorting
     * @param $lgQuotes
     * @return array
     *
     * @info: This function will compile quotes according the selected rating method.
     */
    public function getGTZCompiledQuotes($services, $arraySorting, $lgQuotes, $notifyDelivery)
    {
        $servicesOriginal = $services;
        $quickest = $quotes = $newArray = [];
        if (isset($this->quoteSettings['quickest_service']) && $this->quoteSettings['quickest_service'] == 1 && isset($this->quoteSettings['method']) && $this->quoteSettings['method'] != 2 && !$this->isMultiShipment) {
            if (isset($arraySorting['quickest']['simple'])) {
                $minIndex = array_search(min($arraySorting['quickest']['simple']), $arraySorting['quickest']['simple']);
                foreach($services[$this->originKey] as $key => $service){
                    $quickest[$key] = $service[$minIndex];
                    if (isset($quickest[$key]['title'])) {
                        $quickest[$key]['title'] = $quickest[$key]['titleQuickest'];
                    }
                    $newArray[$key][] = $quickest[$key];
                    $quickest = $newArray;
                }
            }
        }
        if (isset($this->quoteSettings['method']) && $this->quoteSettings['method'] != 0) {

            $quotes = $this->getGTZQuotes($servicesOriginal, $arraySorting, $lgQuotes, $notifyDelivery);
            $quotes = collect($quotes)->map(function ($quote, $key) use ($quickest) {
                return collect($quote)->merge($quickest[$key] ?? [])->values()->toArray();
            })->toArray();
        } else {
            $quotes = $quickest;
        }

        $count = 0;
        foreach ($quotes as $key => $quote) {
            if (isset($quotes[$key][$count]['titleQuickest'])) {
                unset($quotes[$key][$count]['titleQuickest']);
            }
            $count++;
        }

        return $quotes;
    }

    public function getGTZQuotes($services, $arraySorting, $lgQuotes, $notifyDelivery = false)
    {
        if (empty($arraySorting) || empty($services)) {
            return [];
        }

        $this->quoteSettings['method'] = $this->quoteSettings['method'] ?? 1;
        if ($this->quoteSettings['method'] == 2) { //Cheapest method
            $options = (int) $this->quoteSettings['number_of_options'] ?? 1;
        } elseif ($this->quoteSettings['method'] == 3) { //Average rate
            $options = (int) $this->quoteSettings['number_of_options'];
        } else {
            $options = 1;
        }

        foreach ($arraySorting as $key => $value) {
            asort($arraySorting[$key]);
            $sliced =  array_slice($arraySorting[$key], 0, $options, true);
        }

        if ($this->quoteSettings['method'] == 3) {
            return $this->averageRattingMethod($arraySorting, $options, $lgQuotes);
        }
        
        $resp = collect($services[$this->originKey])->map(function ($items) use ($sliced) {
            return collect($items)
                ->only(array_keys($sliced)) // Filter the required indexes
                ->all(); // Return the filtered array
        });

        return $resp->toArray();
    }

    public function getCompiledQuotes($services, $arraySorting, $lgQuotes, $resiPickup = '', $lgPickup = '', $insideDelivery = false, $notifyDelivery = false, $limitedAccess = false)
    {

        if (empty($arraySorting) || empty($services)) {
            return [];
        }
        $sliced = [];
        $this->quoteSettings['method'] = $this->quoteSettings['method'] ?? 1;
        if ($this->quoteSettings['method'] == 2) { //Cheapest method
            $options = (int) $this->quoteSettings['number_of_options'] ?? 1;
        } elseif ($this->quoteSettings['method'] == 3) { //Average rate
            $options = (int) $this->quoteSettings['number_of_options'];
        } else {
            $options = 1;
        }

        foreach ($arraySorting as $key => $value) {
            asort($arraySorting[$key]);
            $sliced =  array_slice($arraySorting[$key], 0, $options, true);
        }

        if ($this->quoteSettings['method'] == 3) {

            if (isset($services[$this->originKey]['Truckload']) && !empty($services[$this->originKey]['Truckload'])) {
                $AVR = $this->averageRattingMethod($arraySorting, $options, $lgQuotes);

                if (isset($this->isFQChr) && $this->isFQChr) {
                    $title = $this->quoteSettings['truck_label_as'] ?? Functions::$simpleLTLTitle . ' - Truckload Service';
                } else {
                    $title = ($this->quoteSettings['label_as'] ?? Functions::$simpleLTLTitle) . ' - Truckload Service';
                }
                $averageRateService['Truckload'][0] = [
                    'title' => $title,
                    'code' => $AVR['Truckload'][0]['code'] . '+TL',
                    'rate' => $AVR['Truckload'][0]['rate'],
                ];
                return $averageRateService;
            }

            return $this->averageRattingMethod($arraySorting, $options, $lgQuotes, $resiPickup, $lgPickup, $insideDelivery, $limitedAccess, $notifyDelivery);
        }

        $resp = collect($services[$this->originKey])->map(function ($items) use ($sliced) {
            return collect($items)
                ->only(array_keys($sliced)) // Filter the required indexes
                ->all(); // Return the filtered array
        });

        return $resp->toArray();

        // $resp = array_intersect_key($services, $sliced);
        // return $resp;
    }

    public function getCompiledQuotesTQL($services, $arraySorting, $lgQuotes, $notifyDelivery)
    {
        if (empty($arraySorting) || empty($services)) {
            return [];
        }
        $sliced = [];
        $this->quoteSettings['method'] = $this->quoteSettings['method'] ?? 1;
        if ($this->quoteSettings['method'] == 2 && $this->isMultiShipment == false) { //Cheapest method
            $options = (int) $this->quoteSettings['number_of_options'] ?? 1;
        } elseif ($this->quoteSettings['method'] == 3) { //Average rate
            $options = (int) $this->quoteSettings['number_of_options'];
        } else {
            $options = 1;
        }

        foreach ($arraySorting as $key => $value) {
            asort($arraySorting[$key]);
            $sliced =  array_slice($arraySorting[$key], 0, $options, true);
        }  

        if ($this->quoteSettings['method'] == 3) {
            return $this->averageRattingMethod($arraySorting, $options, $lgQuotes, '', '', false, $notifyDelivery);
        }

        $resp = array_intersect_key($services, $sliced);
        return $resp;
    }

    /**
     * @param $ratesArray
     * @param $options
     * @param $lgQuotes
     * @return array
     */
    public function averageRattingMethod($ratesArray, $options, $lgQuotes, $resiPickup = '', $lgPickup = '', $insideDelivery = false, $limitedAccess = false, $notifyDelivery = false, $labelAs = '')
    {
        $averageRateService = [];
        $prefix = $this->isGTZCerasis ? 'AVG' : 'AVGwweltl';
        $prefix = isset($this->isFQ) && $this->isFQ ? 'AVGfqltl' : $prefix;
        $prefix = isset($this->isFQChr) && $this->isFQChr ? 'AVGfqchrltl' : $prefix;
        $prefix = isset($this->EchoLogistics) && $this->EchoLogistics ? 'AVGecholtl' : $prefix;
        $prefix = isset($this->TQL) && $this->TQL ? 'AVGTqlltl' : $prefix;
        $prefix = isset($this->isGTZNewApi) && $this->isGTZNewApi ? 'AvgGTZNewApi' : $prefix;
        $prefix = isset($this->isUsNewApi) && $this->isUsNewApi ? 'avgUSLtl' : $prefix;
        $prefix = isset($this->isPriority1) && $this->isPriority1 ? 'AvgPriority1' : $prefix;
        $serviceName = isset($this->TQL) && $this->TQL && !empty($labelAs) ? $labelAs : $this->customLabel(Functions::$simpleLTLTitle);

        foreach ($ratesArray as $key => $rates) {
            $lgQuotes = $key == 'liftgate' || $key == 'insideLiftGateDelivery' || $key == 'lgnotifydelivery' || $key == 'lginsidenotifydelivery' || 
                        $key == 'limitedaccessLG' || $key == 'lglaccessnotifydelivery' || $key == 'lglaccessinsidedelivery' || $key == 'lglaccessinsideNotifydelivery' ?? false;
            $insideDelivery = $key == 'insideDelivery' || $key == 'insideLiftGateDelivery' || $key == 'insidenotifydelivery' || $key == 'lginsidenotifydelivery' ||
                              $key == 'laccessinsidedelivery' || $key == 'lglaccessinsidedelivery' || $key == 'laccessinsideNotifydelivery' || $key == 'lglaccessinsideNotifydelivery' ?? false;
            $notifyDelivery = $key == 'notifydelivery' || $key == 'lgnotifydelivery' || $key == 'insidenotifydelivery' || $key == 'lginsidenotifydelivery' ||
                              $key == 'laccessnotifydelivery' || $key == 'lglaccessnotifydelivery' || $key == 'laccessinsideNotifydelivery' || $key == 'lglaccessinsideNotifydelivery' ?? false;
            $limitedAccessDelivery = $key == 'limitedaccess' || $key == 'limitedaccessLG' || $key == 'laccessinsidedelivery' || $key == 'laccessnotifydelivery' ||
                                     $key == 'lglaccessnotifydelivery' || $key == 'lglaccessinsidedelivery' || $key == 'laccessinsideNotifydelivery' || $key == 'lglaccessinsideNotifydelivery' ?? false;

            if (!empty($rates)) {
                asort($ratesArray[$key]);
                $sliced = array_slice($ratesArray[$key], 0, $options, true);
                $price = $this->getAveragePrice($sliced, $options);
                $averageRateService[$key][] = [
                    'title' => $this->getTitle($serviceName, $lgQuotes, false, '', [], [], $insideDelivery, $limitedAccessDelivery, false, false, false, false, $notifyDelivery),
                    'code' => $prefix . $this->getAccessorialCode($lgQuotes, $insideDelivery, $resiPickup, $lgPickup, $limitedAccessDelivery, false, false, $notifyDelivery),
                    'rate' => $price,
                ];
            }
        }

        return $averageRateService;
    }

    public function averageOfEachService($quotes, $options, $allConfigServices, $lgQuotes, $notifyDelivery, $limitedAccess, $labelAs)
    {
        $originQuotes = [];
        if (!empty($quotes)) {
            foreach ($quotes as $key => $data) {

                if (isset($data['scac']) && in_array($data['scac'], $allConfigServices)) {
                    $data['totalNetCharge']['Amount'] = $data['customerRate'] ?? 0;
                    foreach ($data['priceCharges'] as $index => $value) {

                        if ($value['description'] == "Lift Gate") {
                            $data['surcharges']['liftgateFee'] = $value['amount'] ?? 0;
                        }
                        if ($value['description'] == "Residential") {
                            $data['surcharges']['residentialFee'] = $value['amount'] ?? 0;
                        }
                        if (isset($value['description'])) {
                            $hazShipmentArr[$this->originKey] = $value['description'] == "Hazardous Materials" ? 'Y' : 'N';
                        }
                        if ($value['description'] == "Delivery Call Ahead") {
                            $data['surcharges']['notifyDeliveryFee'] = $value['amount'] ?? 0;
                        }
                        if ($value['description'] == "Limited Access") {
                            $data['surcharges']['limitedAccessDeliveryFee'] = $value['amount'] ?? 0;
                        }
                    }
                    $isLgSurcharges = isset($data['surcharges']['liftgateFee']) && $data['surcharges']['liftgateFee'];
                    $isNbdSurcharges = isset($data['surcharges']['notifyDeliveryFee']) && $data['surcharges']['notifyDeliveryFee'];
                    $isLimitedSurcharges = isset($data['surcharges']['limitedAccessDeliveryFee']) && $data['surcharges']['limitedAccessDeliveryFee'];

                    $date = $data['deliveryTimestamp'] ?? null;
                    $days = $data['totalCalenderDaysInTransit'] ?? null;
                    $dateAndDays = ['deliveryDate' => $date, 'totalTransitTimeInDays' => $days];

                    $enableFeaturesArray = Functions::getEnableFeaturesArr($lgQuotes && $isLgSurcharges, $insideDelivery ?? false, $notifyDelivery && $isNbdSurcharges, $limitedAccess && $isLimitedSurcharges);
                    foreach ($enableFeaturesArray as $index => $feature) {
                        if ($feature['isEnable']) {
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes(
                                $index, $data['carrier'],
                                $originQuotes,
                                $data,
                                $key, $data['totalCalenderDaysInTransit'],
                                $dateAndDays, $feature['index']['isLG'] ?? false,
                                "tqlltl", $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings,
                                $this->isResi, $this->alwaysResi, $feature['index']['isID'] ?? false, $feature['index']['isLAD'] ?? false, $feature['index']['isNBD'] ?? false
                            );

                            $arraySorting[$index][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                        }
                    }

                }
            }
            return $this->averageRattingMethod($arraySorting, $options, false, '', '', false, false, false, $labelAs);
        }
        return [];
    }

    public function getAveragePriceTQL($arraySorting, $options)
    {
        $sum = 0;
        $standardSliced = array_slice($arraySorting, 0, $options, true);
        foreach ($standardSliced as $key => $value) {
            $sum += $value['customerRate'];
        }
        if (!empty($standardSliced)) {
            $numOfIndexes = count($standardSliced);
            $divider = ($numOfIndexes == $options) ? $options : $numOfIndexes;
            return round($sum / $divider, 2);
        } else {
            return [];
        }
    }

    public function getAveragePrice($arraySorting, $options)
    {
        $numOfIndexes = count($arraySorting);
        $divider = ($numOfIndexes == $options) ? $options : $numOfIndexes;
        return round(array_sum($arraySorting) / $divider, 2);
    }

    public function customLabel($serviceName, $quoteSettings = [])
    {
        /*if ($this->isMultiShipment) {
        return 'Freight';
        }*/
        if (!empty($quoteSettings)) {
            $this->quoteSettings = $quoteSettings;
        }
        $this->quoteSettings['method'] = $this->quoteSettings['method'] ?? 1;
        return (($this->quoteSettings['method'] == 1 || $this->quoteSettings['method'] == 3) && (isset($this->quoteSettings['label_as']) && $this->quoteSettings['label_as'] != null)) ? $this->quoteSettings['label_as'] : $serviceName;
    }

    /**
     * @param $finalQuotes
     * @return array
     */
    public function arrangeOwnFreight($finalQuotes = [])
    {
        if (!isset($this->quoteSettings['own_arrangement']) || $this->quoteSettings['own_arrangement'] == 0) {
            return $finalQuotes;
        }
        $ownArrangement[] = [
            'code' => 'own_arrangement',
            'title' => (isset($this->quoteSettings['own_arrangement_text']) && !empty($this->quoteSettings['own_arrangement_text'])) ? $this->quoteSettings['own_arrangement_text'] : "I'll Arrange My Own Freight",
            'rate' => 0,
        ];
        return array_merge($finalQuotes, $ownArrangement);
    }

    public function arrangeHATFreight($finalQuotes, $HATQuotes)
    {
        if (empty($HATQuotes)) {
            return $finalQuotes;
        }

        $newQuotes = [];
        foreach ($HATQuotes as $locId => $quotes) {
            foreach($quotes as $quote){
                foreach($quote as $key => $data){
                    $finalQuotes[$locId]['hat'][$key] = [
                        'code' => $data['serviceType'],
                        'title' => $data['serviceDesc'],
                        'rate' => $data['totalNetCharge']['Amount'],
                    ];
                }
            }
        }

        return $finalQuotes;
    }

    public function arrangeHATMulti($mulishipment, $HAT)
    {
        $quotes = $mulishipment['simple'] ?? $mulishipment['liftgate'] ?? [];
        $count = 0;
        foreach ($quotes as $shipmentId => $quote) {
            $newQuote = [
                'code' => $HAT[$count]['serviceType'] ?? '',
                'rate' => $HAT[$count]['totalNetCharge']['Amount'] ?? '',
                'title' => $HAT[$count]['serviceDesc'] ?? '',
            ];
            $mulishipment['hat'][$shipmentId] = $newQuote;
        }
        return $mulishipment;
    }

    public function arrangeFreeRNL($finalQuotes)
    {
        $hatQuotes[] = [
            'code' => 'freernlltl',
            'title' => 'Free Shipping',
            'rate' => 0,
        ];
        return array_merge($finalQuotes, $hatQuotes);
    }

    /**
     * @return array
     */
    public function quoteSettingFieldsToRestrict()
    {
        $restriction = [];
        $currentPlan = $this->planInfo()['planNumber'];
        $standard = [
            'enableCuttOff',
        ];
        $advance = [];
        switch ($currentPlan) {
            case 2:
            case 3:
                break;

            default:
                $restriction = [
                    'standard' => $standard,
                ];
                break;
        }
        return $restriction;
    }

    public function getAccessorialCodeSmall()
    {
        return $this->alwaysResi || $this->isResi ? '+R' : '';
    }
}