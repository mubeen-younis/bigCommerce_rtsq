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
use  App\CustomClasses\PurolatorSmall\QuotesResults as purolatorSmallQuotesResults;
use App\CustomClasses\XPO\ltl\QuotesResults as xpoLtlQuotesResults;
use App\CustomClasses\Unishippers\small\QuotesResults as unishippersSmallQuotesResults;
use App\CustomClasses\YrcLTL\QuotesResults as yrcLtlQuotesResults;
use App\CustomClasses\DayRossLTL\QuotesResults as dayRossLtlQuotesResults;
use App\CustomClasses\SaiaLTL\QuotesResults as saiaLtlQuotesResults;
use App\CustomClasses\AbfLtl\QuotesResults as abfLtlQuotesResults;
use App\CustomClasses\SouthEasternLtl\QuotesResults as SouthEasternQuotesResults;
use App\CustomClasses\UspsSmall\QuotesResults as uspsSmallQuotesResults;
use App\CustomClasses\EchoLogisticsLtl\QuotesResults as echoLogisticsLtlQuotesResults;
use App\CustomClasses\DayLightLtl\QuotesResults as dayLightLtlQuotesResults;
use App\CustomClasses\FreightQuote\ChrLtl\QuotesResults as FQChrQuotesResults;
use App\CustomClasses\FreightQuote\Ltl\QuotesResults as FQQuotesResults;
use App\CustomClasses\EstesLTL\QuotesResults as estesLtlQuotesResults;


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

    private $quoteSettings = [];

    /*
     * @var configSettings
     * */
    public $configSettings;

    private $carrierServices = [];
    private $alwaysResi = false;

    private $isGTZCerasis = false;

    public function __construct()
    {
        $this->wweSmallQuoteRes = new WweSmallQuoteResults();
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

                if (isset($inStoreLd['totalDistance']) && $inStoreLd['totalDistance'] > 0) {
                    $title .= " | " . $inStoreLd['totalDistance'] . " away ";
                }
                $title .= " | " . $this->getShortStreetAddress($warehouseData['address']) . " " . $warehouseData['senderCity'] . ", " . $warehouseData['senderState'] . ", " . $warehouseData['senderZip'];

                if (isset($array['phone']) && $array['phone']) {
                    $title .= " | " . $array['phone'];
                }
                $quotesArray[] = [
                    'code' => 'INSP',
                    'rate' => 0,
                    'transitTime' => '',
                    'title' => $title,
                ];
            }

            if (isset($inStoreLd['localDelivery']['status']) && $inStoreLd['localDelivery']['status'] == 1) {
                $quotesArray[] = [
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
                $inStoreTitle = "In-store pick up";
            }
            $return['inStoreTitle'] = $inStoreTitle;
            $return['suppress_other'] = isset($whCollection['ld_enable_supress']) && $whCollection['ld_enable_supress'] == true ? true : false;
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
        $installed_addon = (array)DB::table('installed_carriers')->where('installed_carriers.id', $quoteSettings['carrierId'])
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
    public function newGetQuotesResults($quotes, $connectionSettings, $allOrigins, $isHazmat, $smalLtlHazmat, $hazmatAllItems, $residential, $freeRNL, $destination, $items)
    {
        $this->residential = $residential;
        $this->items = $items;
        $this->allOrigins = $allOrigins;
        if ($quotes == null) {
            return [];
        }

        $quotesRes = [];
        $quotesTemp = [];
        $quotes = $this->filterShipmentsWithError($quotes);
        foreach ($quotes as $key => $shipment) {
            switch ($key) {
                case "wweLTL":
                    $resp = $this->compileWweLtlQuotes($shipment, $connectionSettings, $allOrigins);
                    $quotesTemp['wweLTL'] = $resp;
                    if ((!empty($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (isset($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (!isset($resp['multiShipmentQuotes']) && !empty($resp))) {
                        //$quotesRes['wwe'] = $quotesRes['wwe'] ?? [];
                        $quotesRes = array_merge($quotesRes, $resp);
                    }
                    break;
                case "wweSmall":
                    $resp = $this->compileWweSmallQuotes($shipment, $connectionSettings, $allOrigins, $isHazmat, $smalLtlHazmat, $hazmatAllItems);
                    $quotesTemp['wweSmall'] = $resp;
                    if ((!empty($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (isset($resp['multiShipmentQuotes']) && !empty($resp['checkoutQuotes'])) || (!isset($resp['multiShipmentQuotes']) && !empty($resp))) {
                        //$quotesRes['wwe'] = $quotesRes['wwe'] ?? [];
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
                    $quotesTemp['upsSmall'] = $resp;
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
            }
        }

        // Removing duplicate respone of quotes
        $quotesRes = $this->handleMultiCarrResp($quotesTemp);
        $quotesRes = array_map("unserialize", array_unique(array_map("serialize", $quotesRes)));

        return $quotesRes;
    }

    private function filterShipmentsWithError($quotes)
    {
        $errorMsgs = [Functions::$ltlErrorMessage, Functions::$smallErrorMessage];
        $newQuotes = $quotes ?? [];

        foreach ($newQuotes as $carrier => $shipments) {
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
            if(empty($newQuotes['multiShipmentQuotes']) || empty($newQuotes['checkoutQuotes'])){
                return [];
            }
        } else {
            foreach ($quotes as $car => $quote) {
                foreach ($quote as $key => $quot) {
                    $newQuotes[] = $quot;
                }
            }
        }

        return $newQuotes;
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
                $resiPickup = isset($this->residential['residentialPickup']) && $this->residential['residentialPickup'] == "Y" ? '+pu' : '';
                $insideDelivery = (isset($this->quoteSettings['offer_inside_delivery']) && $this->quoteSettings['offer_inside_delivery']) ||
                                  (isset($this->quoteSettings['always_inside_delivery']) && $this->quoteSettings['always_inside_delivery']);
                $lgPickup = isset($this->quoteSettings['liftGatePickup']) && $this->quoteSettings['liftGatePickup'] ? '+lfgpu' : '';

                $notifyDelivery =
                    (isset($this->quoteSettings['always_quote_notify']) && $this->quoteSettings['always_quote_notify']) ||
                    (isset($this->quoteSettings['offer_notify_as_option']) && $this->quoteSettings['offer_notify_as_option']);
            }
            $originQuotes = [];
            $arraySorting = [];
            if (isset($quote['q'])) {
                if (isset($quote['hazardousStatus'])) {
                    $hazShipmentArr[$origin] = $quote['hazardousStatus'] == 'y' ? 'Y' : 'N';
                }

                foreach ($quote['q'] as $key => $data) {

                    if (isset($data['serviceType']) && in_array($data['serviceType'], $allConfigServices) && isset($data['GuaranteedDaysToDelivery']) && $data['GuaranteedDaysToDelivery'] != 'Y') {
                        $access = $this->getAccessorialCode(false, false, $resiPickup, $lgPickup);
                        $price = $this->calculatePrice($data);
                                               
                        /*
                         * Date 01-07-22
                         * Adding Functionality of Delivery Estimate Options
                         * */
                        $date = $data['deliveryTimestamp'] ?? null;
                        $days = $data['totalTransitTimeInDays'] ?? null;
                        $dateAndDays = ['deliveryDate' => $date, 'totalTransitTimeInDays' => $days];
                        $title = $this->getTitle($data['serviceDesc'], false, false, $data['totalTransitTimeInDays'], [], $dateAndDays);
                        $arraySorting['simple'][$key] = $price;
                        $originQuotes[$key]['simple']['code'] = 'wweltl' . $data['serviceType'] . $access;
                        $originQuotes[$key]['simple']['rate'] = $price;
                        $originQuotes[$key]['simple']['title'] = $title;
                        if ($insideDelivery && $lgQuotes) {
                            $access = $this->getAccessorialCode(true, true, $resiPickup, $lgPickup);
                            $price = $this->calculatePrice($data, true, false, false, true);
                            $title = $this->getTitle($data['serviceDesc'], true, false, $data['totalTransitTimeInDays'], [], $dateAndDays, true);
                            $arraySorting['insideLiftGateDelivery'][$key] = $price;
                            $originQuotes[$key]['insideLiftGateDelivery']['code'] = "wweltl" . $data['serviceType'] . $access;
                            $originQuotes[$key]['insideLiftGateDelivery']['rate'] = $price;
                            $originQuotes[$key]['insideLiftGateDelivery']['title'] = $title;
                        }
                        if ($lgQuotes) {
                            $lgAccess = 'wweltl' . $this->getAccessorialCode(true, false, $resiPickup, $lgPickup);
                            $lgPrice = $this->calculatePrice($data, true);
                            $lgTitle = $this->getTitle($data['serviceDesc'], true, false, $data['totalTransitTimeInDays'], [], $dateAndDays);
                            $arraySorting['liftgate'][$key] = $lgPrice;
                            $originQuotes[$key]['liftgate']['code'] = $data['serviceType'] . $lgAccess;
                            $originQuotes[$key]['liftgate']['rate'] = $lgPrice;
                            $originQuotes[$key]['liftgate']['title'] = $lgTitle;
                        }
                        if ($insideDelivery) {
                            $access = $this->getAccessorialCode(false, true, $resiPickup, $lgPickup);
                            $price = $this->calculatePrice($data, false, false, false, true);
                            $title = $this->getTitle($data['serviceDesc'], false, false, $data['totalTransitTimeInDays'], [], $dateAndDays, true);
                            $arraySorting['insideDelivery'][$key] = $price;
                            $originQuotes[$key]['insideDelivery']['code'] = "wweltl" . $data['serviceType'] . $access;
                            $originQuotes[$key]['insideDelivery']['rate'] = $price;
                            $originQuotes[$key]['insideDelivery']['title'] = $title;
                        }
                        // Get Notify Before Delivery Origin Quotes
                        if($notifyDelivery){
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes('notifydelivery', $data['serviceDesc'], $originQuotes, $data, $key, $data['totalTransitTimeInDays'], 
                            $dateAndDays, false, "wweltl", $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi);
    
                            $arraySorting['notifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }
                        if($notifyDelivery && $lgQuotes){
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes('lgnotifydelivery', $data['serviceDesc'], $originQuotes, $data, $key, $data['totalTransitTimeInDays'], 
                            $dateAndDays, true, "wweltl", $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi);
    
                            $arraySorting['lgnotifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }
                        if($notifyDelivery && $insideDelivery){
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes('insidenotifydelivery', $data['serviceDesc'], $originQuotes, $data, $key, $data['totalTransitTimeInDays'], 
                            $dateAndDays, false, "wweltl", $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi, $insideDelivery);
    
                            $arraySorting['insidenotifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }
                        if($notifyDelivery && $insideDelivery && $lgQuotes){
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes('lginsidenotifydelivery', $data['serviceDesc'], $originQuotes, $data, $key, $data['totalTransitTimeInDays'], 
                            $dateAndDays, true, "wweltl", $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi, $insideDelivery);
    
                            $arraySorting['lginsidenotifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }                 
                    }
                }
            }

            $compiledQuotes = $this->getCompiledQuotes($originQuotes, $arraySorting, $lgQuotes, $resiPickup, $lgPickup, $insideDelivery, $notifyDelivery);
            if ($compiledQuotes !== null && !empty($compiledQuotes)) {
                if (count($compiledQuotes) > 1) {
                    foreach ($compiledQuotes as $k => $service) {
                        foreach($service as $serKey => $ser){
                            $quotes = Functions::getQuotesArray($service, $allQuotes, $multiShipmentQuotes, $origin, $serKey);
                            $allQuotes = $quotes['allQuotes'];
                            $multiShipmentQuotes = $quotes['multiShipmentQuotes']; 
                        }
                    }
                } else {
                    $service = reset($compiledQuotes);
                    foreach($service as $serKey => $ser){
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
                'checkoutQuotes' => $this->arrangeOwnFreight($allQuotes),
                'multiShipmentQuotes' => $multiShipmentQuotes,
            ];
            return $resp;
        }
        return $this->arrangeOwnFreight($allQuotes);
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
        $key = 1;

        foreach ($shipments as $origin => $quote) {
            $this->originKey = $origin;
            if (isset($quote['severity'])) {
                return $this->getInsPicAndLocDelQuotes($quote, $allOrigins);
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

            $originQuotes = [];
            $arraySorting = [];

            if (isset($quote['q'])) {
                if (isset($quote['hazardousStatus'])) {
                    $hazShipmentArr[$origin] = $quote['hazardousStatus'] == 'y' ? 'Y' : 'N';
                }

                $data = $quote['q'];
                
                if(isset($data['rateEstimate']['netFreightCharge'])){
                    $data['totalNetCharge']['Amount'] = (float)$data['rateEstimate']['netFreightCharge'] ?? 0;
                }
                
                if(isset($data['rateEstimate']['accessorialCharges']) && !empty($data['rateEstimate']['accessorialCharges'])){
                    $accessorialCharges = $data['rateEstimate']['accessorialCharges'];
                    foreach($accessorialCharges as $accessorialCharge){
                        if(isset($accessorialCharge['description']) && $accessorialCharge['description'] == 'Notification Prior to Delivery' ||
                           isset($accessorialCharges['description']) && $accessorialCharges['description'] == 'Notification Prior to Delivery'){
                            
                            $data['surcharges']['notifyDeliveryFee'] = isset($accessorialCharge['amount']) ? (float)$accessorialCharge['amount'] : (float)$accessorialCharges['amount'] ?? 0;
                        }
                    }
                }

                $access = $this->getAccessorialCode();
                $price = $this->calculatePrice($data);

                $date = $data['deliveryDate'] ?? null;
                $days = $data['totalTransitTimeInDays'] ?? null;
                $dateAndDays = ['deliveryDate' => $date, 'totalTransitTimeInDays' => $days];
                $title = $this->getTitle($lableAs, false, false, $days, [], $dateAndDays);

                $arraySorting['simple'][$origin] = $price;
                $originQuotes[$origin]['simple']['code'] = 'odflltl' . $access;
                $originQuotes[$origin]['simple']['rate'] = $price;
                $originQuotes[$origin]['simple']['title'] = $title;

                if ($lgQuotes) {
                    $lgAccess = $this->getAccessorialCode(true);
                    $lgPrice = $this->calculatePrice($data, true);
                    $lgTitle = $this->getTitle($lableAs, true, false, $data['totalTransitTimeInDays'], [], $dateAndDays);
                    $arraySorting['liftgate'][$origin] = $lgPrice;
                    $originQuotes[$origin]['liftgate']['code'] = 'odflltl' . $lgAccess;
                    $originQuotes[$origin]['liftgate']['rate'] = $lgPrice;
                    $originQuotes[$origin]['liftgate']['title'] = $lgTitle;
                }
                // Get Notify Before Delivery Origin Quotes
                if($notifyDelivery){
                    $compileNotifyDeliveryQuotes = Functions::getOriginQuotes('notifydelivery', $lableAs, $originQuotes, $data, $origin, $data['totalTransitTimeInDays'], 
                    $dateAndDays, false, 'odflltl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi);

                    $arraySorting['notifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                    $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                }
                if($notifyDelivery && $lgQuotes){
                    $compileNotifyDeliveryQuotes = Functions::getOriginQuotes('lgnotifydelivery', $lableAs, $originQuotes, $data, $origin, $data['totalTransitTimeInDays'], 
                    $dateAndDays, true, 'odflltl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi);

                    $arraySorting['lgnotifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                    $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                }

                $key++;
            }

            $compiledQuotes = $this->getCompiledQuotes($originQuotes, $arraySorting, $lgQuotes);

            if ($compiledQuotes !== null && !empty($compiledQuotes)) {
                // Get Quotes Array
                if (count($compiledQuotes) > 1) {
                    foreach ($compiledQuotes as $k => $service) {
                        foreach($service as $serKey => $ser){
                            $quotes = Functions::getQuotesArray($service, $allQuotes, $multiShipmentQuotes, $origin, $serKey);
                            $allQuotes = $quotes['allQuotes'];
                            $multiShipmentQuotes = $quotes['multiShipmentQuotes']; 
                        }
                    }
                } else {
                    $service = reset($compiledQuotes);
                    foreach($service as $serKey => $ser){
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
                'checkoutQuotes' => $this->arrangeOwnFreight($allQuotes),
                'multiShipmentQuotes' => $multiShipmentQuotes,
            ];

            return $resp;
        }

        return $allQuotes;
    }

    public function compileTqlLtlQuotes($shipments, $connectionSettings, $allOrigins)
    {
        $this->TQL = true;
        if ($this->residential['tqlLtl'] == 'Y') {
            $this->isResi = true;
            $this->residentialDlvry = 1;
        } else {
            $this->isResi = false;
            $this->residentialDlvry = 0;
        }
        $this->alwaysResi = $this->residential['alwaysResi']['tqlLtl'] ?? false;
        $this->quoteSettings = $connectionSettings['tql-ltl']['quote_settings'] ?? [];
        $allConfigServices = $connectionSettings['tql-ltl']['carrier_services'] ?? [];
        $ratingMethod = $this->quoteSettings['method'] ?? 1;
        $isStandardChecked = $connectionSettings['tql-ltl']['quote_settings']['standard_check'] ?? false;
        $isGuaranteedChecked = $connectionSettings['tql-ltl']['quote_settings']['guaranteed_check'] ?? false;
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

                $notifyDelivery =
                    (isset($this->quoteSettings['always_quote_notify']) && $this->quoteSettings['always_quote_notify']) ||
                    (isset($this->quoteSettings['offer_notify_as_option']) && $this->quoteSettings['offer_notify_as_option']);
            }
            $originQuotes = [];
            $arraySorting = [];

            $standardQuotes = collect($quote['q'])->filter(function ($q) {
                return isset($q["serviceLevel"]) && $q["serviceLevel"] == 'Standard';
            })->toArray() ?? [];

            $guaranteedQuotes = collect($quote['q'])->filter(function ($q) {
                return isset($q["serviceLevel"]) && ($q["serviceLevel"] == 'Guaranteed 5 PM' || $q["serviceLevel"] == 'Guaranteed 12 PM');
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
                    $quotes['q'] = $guaranteedQuotes;
                    $guaranteed[] = $this->getCheapestQuotesArr($quotes);
                    $bothService = array_merge($standard, $guaranteed);
                    $quote['q'] = $bothService;

                } elseif ($ratingMethod == 5) {
                    $options = (int)$this->quoteSettings['number_of_options'];
                    $standardSort = collect($standardQuotes)->sortBy('customerRate')->toArray();
                    $standardSliced = array_slice($standardSort, 0, $options, true);
                    $guaranteedSort = collect($guaranteedQuotes)->sortBy('customerRate')->toArray();
                    $guaranteedSliced = array_slice($guaranteedSort, 0, $options, true);
                    $bothService = array_merge($standardSliced, $guaranteedSliced);
                    $quote['q'] = $bothService;

                } elseif ($ratingMethod == 6) {
                    $options = (int)$this->quoteSettings['number_of_options'];
                    $standardSort = collect($standardQuotes)->sortBy('customerRate')->toArray();
                    $standardPrice = $this->averageRattingMethodTQL($standardSort, $options, $lgQuotes, $notifyDelivery);
                    $guaranteedSort = collect($guaranteedQuotes)->sortBy('customerRate')->toArray();
                    $guaranteedPrice = $this->averageRattingMethodTQL($guaranteedSort, $options, $lgQuotes, $notifyDelivery);
                    $quote['q'] = $originQuotes = array_merge($standardPrice, $guaranteedPrice);
                }
            }


            if (isset($quote['q'])) {
                foreach ($quote['q'] as $key => $data) {
                    if (isset($data['scac']) && in_array($data['scac'], $allConfigServices)) {
                        $access = $this->getAccessorialCode() . $resiPickup;
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
                        $price = $this->calculatePrice($data);

                        $date = $data['deliveryTimestamp'] ?? null;
                        $days = $data['totalCalenderDaysInTransit'] ?? null;
                        $dateAndDays = ['deliveryDate' => $date, 'totalTransitTimeInDays' => $days];
                        $title = $this->getTitle($data['carrier'], false, false, $data['totalCalenderDaysInTransit'], [], $dateAndDays);
                        $arraySorting['simple'][$key] = $price ?? [];
                        $method = $this->quoteSettings['method'];

                        $originQuotes[$key]['simple']['code'] = 'tqlltl' . $access;
                        $originQuotes[$key]['simple']['rate'] = $price;
                        $originQuotes[$key]['simple']['title'] = $title;
                        if ($lgQuotes) {
                            $lgAccess = 'tqlltl' . $this->getAccessorialCode(true) . $resiPickup;
                            $lgPrice = $this->calculatePrice($data, true);
                            $lgTitle = $this->getTitle($data['carrier'], true, false, $data['totalCalenderDaysInTransit'], [], $dateAndDays);
                            $arraySorting['liftgate'][$key] = $lgPrice ?? [];
                            $originQuotes[$key]['liftgate']['code'] = $lgAccess;
                            $originQuotes[$key]['liftgate']['rate'] = $lgPrice;
                            $originQuotes[$key]['liftgate']['title'] = $lgTitle;
                        }
                        // Get Notify Before Delivery Origin Quotes
                        if($notifyDelivery){
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes('notifydelivery', $data['carrier'], $originQuotes, $data, $key, $data['totalCalenderDaysInTransit'], 
                            $dateAndDays, false, 'tqlltl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi);

                            $arraySorting['notifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }
                        if($notifyDelivery && $lgQuotes){
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes('lgnotifydelivery', $data['carrier'], $originQuotes, $data, $key, $data['totalCalenderDaysInTransit'], 
                            $dateAndDays, true, 'tqlltl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi);

                            $arraySorting['lgnotifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }
                    }

                }
            }
            if ($ratingMethod == 1 || $ratingMethod == 2 || $ratingMethod == 3) {
                $compiledQuotes = $this->getCompiledQuotesTQL($originQuotes, $arraySorting, $lgQuotes, $notifyDelivery);
            } else {
                $compiledQuotes = $originQuotes;
            }
            if ($compiledQuotes !== null && !empty($compiledQuotes)) {
                if (count($compiledQuotes) > 1) {
                    foreach ($compiledQuotes as $k => $service) {
                        foreach($service as $serKey => $ser){
                            $quotes = Functions::getQuotesArray($service, $allQuotes, $multiShipmentQuotes, $origin, $serKey);
                            $allQuotes = $quotes['allQuotes'];
                            $multiShipmentQuotes = $quotes['multiShipmentQuotes']; 
                        }
                    }
                } else {
                    $service = reset($compiledQuotes);
                    foreach($service as $serKey => $ser){
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
        $this->upsSmallQuotesResults = new upsSmallQuotesResults();
        if ($residential['upsSmall'] == 'Y') {
            $this->isResi = true;
            $this->residentialDlvry = 1;
        } else {
            $this->isResi = false;
            $this->residentialDlvry = 0;
        }
        $this->alwaysResi = $this->residential['alwaysResi']['upsSmall'] ?? false;
        $access = $this->getAccessorialCodeSmall();

        $res = $this->upsSmallQuotesResults->compileQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $this->isResi, $access, $this->isMultiShipment, $this->items);
        if (!$this->isMultiShipment) {
            $this->isMultiShipment = $res['isMultiShipment'] ?? false;
        }

        return $res['resp'] ?? [];
    }
    public function compilePurolatorSmallQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential)
    {
        $this->purolatorSmallQuotesResults = new purolatorSmallQuotesResults();
        if ($residential['purolatorSmall'] == 'Y') {
            $this->isResi = true;
            $this->residentialDlvry = 1;
        } else {
            $this->isResi = false;
            $this->residentialDlvry = 0;
        }
        $this->alwaysResi = $this->residential['alwaysResi']['purolatorSmall'] ?? false;
        $access = $this->getAccessorialCodeSmall();

        $res = $this->purolatorSmallQuotesResults->compileQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $this->isResi, $access, $this->isMultiShipment, $this->items);
        if (!$this->isMultiShipment) {
            $this->isMultiShipment = $res['isMultiShipment'];
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
            $hatShipments[] = $estesLtl->HatQuoteCompile($quote,$this->quoteSettings);
            
            if (isset($quote['severity'])) {
                return $this->getInsPicAndLocDelQuotes($quote, $allOrigins);
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
            $originQuotes = [];
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

                                }

                            }
                        }
                        if(isset($data['ratpricing'])){
                            $data['totalNetCharge']['Amount'] = $data['ratpricing']['rattotalPrice'] ?? 0;
                        }
                        $access = $this->getAccessorialCode() . $resiPickup;
                        $price = $this->calculatePrice($data);

                        /*
                         * Date 01-07-22
                         * Adding Functionality of Delivery Estimate Options
                         * */
                        $date = $data['ratdelivery']['ratdate'] ?? null;
                        $days = $data['ratdelivery']['totalTransitTimeInDays'] ?? null;
                        $dateAndDays = ['deliveryDate' => $date, 'totalTransitTimeInDays' => $days];
                        $title = $this->getTitle($labelAs, false, false, $data['ratdelivery']['totalTransitTimeInDays'], [], $dateAndDays);
                        $arraySorting['simple'][$key] = $price;
                        $originQuotes[$key]['simple']['code'] = 'estesltl' . $data['ratquoteNumber'] . $access;
                        $originQuotes[$key]['simple']['rate'] = $price;
                        $originQuotes[$key]['simple']['title'] = $title;
                        if ($lgQuotes) {
                            $lgAccess = 'estesltl' . $this->getAccessorialCode(true) . $resiPickup;
                            $lgPrice = $this->calculatePrice($data, true);
                            $lgTitle = $this->getTitle($labelAs, true, false, $data['ratdelivery']['totalTransitTimeInDays'], [], $dateAndDays);
                            $arraySorting['liftgate'][$key] = $lgPrice;
                            $originQuotes[$key]['liftgate']['code'] = $data['ratquoteNumber'] . $lgAccess;
                            $originQuotes[$key]['liftgate']['rate'] = $lgPrice;
                            $originQuotes[$key]['liftgate']['title'] = $lgTitle;
                        }
                        // Get Notify Before Delivery Origin Quotes
                        if($notifyDelivery){
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes('notifydelivery', $labelAs, $originQuotes, $data, $key, $data['ratdelivery']['totalTransitTimeInDays'], 
                            $dateAndDays, false, 'estesltl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi);

                            $arraySorting['notifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }
                        if($notifyDelivery && $lgQuotes){
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes('lgnotifydelivery', $labelAs, $originQuotes, $data, $key, $data['ratdelivery']['totalTransitTimeInDays'], 
                            $dateAndDays, true, 'estesltl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi);

                            $arraySorting['lgnotifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }
                    }
                }
            }

            $compiledQuotes = $originQuotes;

            if ($compiledQuotes !== null && !empty($compiledQuotes)) {
                // Get Quotes Array
                if (count($compiledQuotes) > 1) {
                    foreach ($compiledQuotes as $k => $service) {
                        foreach($service as $serKey => $ser){
                            $quotes = Functions::getQuotesArray($service, $allQuotes, $multiShipmentQuotes, $origin, $serKey);
                            $allQuotes = $quotes['allQuotes'];
                            $multiShipmentQuotes = $quotes['multiShipmentQuotes']; 
                        }
                    }
                } else {
                    $service = reset($compiledQuotes);
                    foreach($service as $serKey => $ser){
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
            
            if (isset($hatShipments[0]['serviceDesc']) && !empty($hatShipments)) {
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
            return  $estesLtl->arrangeHATFreight($allQuotes, $hatShipments);
        }

        return $this->arrangeOwnFreight($allQuotes);
    }

    public function compileFedexSmallQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential, $destination)
    {
        $this->fedexSmallQuotesResults = new fedexSmallQuotesResults();
        if ($residential['fedexSmall'] == 'Y') {
            $this->isResi = true;
            $this->residentialDlvry = 1;
        } else {
            $this->isResi = false;
            $this->residentialDlvry = 0;
        }
        $this->alwaysResi = $this->residential['alwaysResi']['fedexSmall'] ?? false;
        $access = $this->getAccessorialCodeSmall();
        $res = $this->fedexSmallQuotesResults->compileQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $this->isResi, $access, $this->isMultiShipment, $destination, $this->items);
        if (!$this->isMultiShipment) {
            $this->isMultiShipment = $res['isMultiShipment'] ?? false;
        }
        return $res['resp'] ?? [];
    }

    public function compileGlobalTranzLtlQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential)
    {

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

                $laccess = (isset($this->quoteSettings['offer_limited_access_delivery']) && $this->quoteSettings['offer_limited_access_delivery']);
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
                        //$data['totalTransitTimeInDays'] = $data['LtlServiceDays'] ?? 0;
                        $access = $preCode . $this->GTZLtlQuotesResults->getAccessorialCode($isResi);
                        $price = $this->GTZLtlQuotesResults->calculatePrice($data, $this->quoteSettings, false, false, false, $this->originKey, $this->items, $this->allOrigins);

                        /*
                         * Date 01-07-22
                         * Adding Functionality of Delivery Estimate Options
                         * */
                        $date = $data['EstimatedDeliveryDate'] ?? null;
                        $days = $data['totalTransitTimeInDays'] ?? null;
                        $dateAndDays = ['deliveryDate' => $date, 'totalTransitTimeInDays' => $days];
                        $title = $this->getGTitle($data['serviceDesc'], false, false, false, false, $data['totalTransitTimeInDays'], $this->quoteSettings, false, $dateAndDays);
                        $titleQuickest = $this->getGTitle($data['serviceDesc'], false, false, false, false, $data['totalTransitTimeInDays'], $this->quoteSettings, true, $dateAndDays);
                        $arraySorting['simple'][$key] = $price;
                        $arraySorting['quickest']['simple'][$key] = $data['totalTransitTimeInDays'];
                        $originQuotes[$key]['simple']['code'] = $data['serviceType'] . $access;
                        $originQuotes[$key]['simple']['rate'] = $price;
                        $originQuotes[$key]['simple']['title'] = $title;
                        $originQuotes[$key]['simple']['titleQuickest'] = $titleQuickest;

                        if ($lgQuotes) {
                            $access = $preCode . $this->GTZLtlQuotesResults->getAccessorialCode($isResi, true);
                            $price = $this->GTZLtlQuotesResults->calculatePrice($data, $this->quoteSettings, true, false, false, $this->originKey, $this->items, $this->allOrigins);
                            $title = $this->getGTitle($data['serviceDesc'], true, false, false, false, $data['totalTransitTimeInDays'], $this->quoteSettings, false, $dateAndDays);
                            $titleQuickest = $this->getGTitle($data['serviceDesc'], true, false, false, false, $data['totalTransitTimeInDays'], $this->quoteSettings, true, $dateAndDays);
                            $arraySorting['liftgate'][$key] = $price;
                            $arraySorting['quickest']['liftgate'][$key] = $data['totalTransitTimeInDays'];
                            $originQuotes[$key]['liftgate']['code'] = $data['serviceType'] . $access;
                            $originQuotes[$key]['liftgate']['rate'] = $price;
                            $originQuotes[$key]['liftgate']['title'] = $title;
                            $originQuotes[$key]['liftgate']['titleQuickest'] = $titleQuickest;
                        }
                        // Get Notify Before Delivery Origin Quotes
                        if($notifyDelivery){
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes('notifydelivery', $data['serviceDesc'], $originQuotes, $data, $key, $data['totalTransitTimeInDays'], 
                            $dateAndDays, false, $preCode, $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi);

                            $arraySorting['notifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $arraySorting['quickest']['notifydelivery'][$key] = $data['totalTransitTimeInDays'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }
                        if($notifyDelivery && $lgQuotes){
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes('lgnotifydelivery', $data['serviceDesc'], $originQuotes, $data, $key, $data['totalTransitTimeInDays'], 
                            $dateAndDays, true, $preCode, $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi);

                            $arraySorting['lgnotifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $arraySorting['quickest']['lgnotifydelivery'][$key] = $data['totalTransitTimeInDays'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }
                    }
                }
            }
            if (!$this->isMultiShipment) {
                $compiledQuotes = $this->getGTZCompiledQuotes($originQuotes, $arraySorting, $lgQuotes, $notifyDelivery);
            } else {
                if (isset($this->quoteSettings['quickest_service']) && $this->quoteSettings['quickest_service'] == 1 && isset($this->quoteSettings['method']) && $this->quoteSettings['method'] == 0) {
                    $arraySorting = $arraySorting['quickest'] ?? $arraySorting;
                }
                $compiledQuotes = $this->getGtzMultiShipCompiledQuotes($originQuotes, $arraySorting, $lgQuotes);
            }
            if ($compiledQuotes !== null && !empty($compiledQuotes)) {
                if (count($compiledQuotes) > 1) {
                    foreach ($compiledQuotes as $k => $service) {
                        foreach($service as $serKey => $ser){
                            $quotes = Functions::getQuotesArray($service, $allQuotes, $multiShipmentQuotes, $origin, $serKey);
                            $allQuotes = $quotes['allQuotes'];
                            $multiShipmentQuotes = $quotes['multiShipmentQuotes']; 
                        }
                    }
                } else {
                    $service = reset($compiledQuotes);
                    foreach($service as $serKey => $ser){
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

        return $allQuotes;
    }

    public function getGtzMultiShipCompiledQuotes($services, $arraySorting, $lgQuotes)
    {
        if (empty($arraySorting) || empty($services)) {
            return [];
        }

        $this->quoteSettings['method'] = $this->quoteSettings['method'] ?? 1;
        if ($this->quoteSettings['method'] == 2 && $this->isMultiShipment == false) { //Cheapest method
            $options = (int)$this->quoteSettings['number_of_options'] ?? 1;
        } elseif ($this->quoteSettings['method'] == 3) { //Average rate
            $options = (int)$this->quoteSettings['number_of_options'];
        } else {
            $options = 1;
        }
        $sliced = $lgQuotes ?
            array_slice($arraySorting['liftgate'], 0, $options, true) :
            array_slice($arraySorting['simple'], 0, $options, true);
        if ($this->quoteSettings['method'] == 3) {
            return $this->averageRattingMethod($arraySorting, $options, $lgQuotes);
        }

        $resp = array_intersect_key($services, $sliced);
        return $resp;
    }

    public function compileCerasisLtlQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential)
    {
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
                        if ($lgQuotes) {
                            $access = $preCode . $this->getAccessorialCode(true);
                            $price = $this->calculatePrice($data, true);
                            $title = $this->getTitle($data['serviceDesc'], true, false, $data['transitTime'], [], $dateAndDays);
                            $arraySorting['liftgate'][$key] = $price;
                            $originQuotes[$key]['liftgate']['code'] = $data['serviceType'] . $access;
                            $originQuotes[$key]['liftgate']['rate'] = $price;
                            $originQuotes[$key]['liftgate']['title'] = $title;
                        }
                    }
                }
            }

            $compiledQuotes = $this->getCompiledQuotes($originQuotes, $arraySorting, $lgQuotes);

            if ($compiledQuotes !== null && !empty($compiledQuotes)) {
                if (count($compiledQuotes) > 1) {
                    foreach ($compiledQuotes as $k => $service) {
                        $allQuotes['simple'][] = $service['simple'];
                        $multiShipmentQuotes['simple'][$origin] = $service['simple'];
                        $lgQuotes ? $allQuotes['liftgate'][] = $service['liftgate'] : null;
                        $lgQuotes ? $multiShipmentQuotes['liftgate'][$origin] = $service['liftgate'] : null;
                    }
                } else {
                    $service = reset($compiledQuotes);
                    $allQuotes['simple'][] = $service['simple'] ?? '';
                    $multiShipmentQuotes['simple'][$origin] = $service['simple'] ?? '';
                    $lgQuotes ? $allQuotes['liftgate'][] = $service['liftgate'] : null;
                    $lgQuotes ? $multiShipmentQuotes['liftgate'][$origin] = $service['liftgate'] : null;
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
                    if (isset($data['serviceType']) && isset($data['serviceDesc']) && in_array($data['serviceType'] , $allConfigServices)) {
                        if ($isHatSrvc) {
                            $hatShipments[$key] = $data;
                             $hatArraySorting['simple'][$key] = $data['totalNetCharge']['Amount'];
                            continue;
                        }

                        $access = $this->getAccessorialCode();
                        $price = $this->calculatePrice($data);

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
                        if(isset($data['holdAtTerminalResponse']) && !empty($data['holdAtTerminalResponse'])){
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
                        if($notifyDelivery){
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes('notifydelivery', $data['serviceDesc'], $originQuotes, $data, $key, $data['transitTime'], 
                            $dateAndDays, false, 'fedexltl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi);

                            $arraySorting['notifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }
                        if($notifyDelivery && $lgQuotes){
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes('lgnotifydelivery', $data['serviceDesc'], $originQuotes, $data, $key, $data['transitTime'], 
                            $dateAndDays, true, 'fedexltl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi);

                            $arraySorting['lgnotifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }
                    }
                }
            }

            $compiledQuotes = $fedexLtl->getCompiledQuotes($originQuotes, $arraySorting, $lgQuotes, $this->isMultiShipment);
            $hatShipment = array_values($fedexLtl->getCompiledQuotes($hatShipments, $hatArraySorting, $lgQuotes, $this->isMultiShipment));
            if($this->isMultiShipment && !empty($hatShipment)){
                $HAT[] = $hatShipment[0];
            }else {
                $HAT = $hatShipment;
            }

            if ($compiledQuotes !== null && !empty($compiledQuotes)) {
                // Get Quotes Array
                if (count($compiledQuotes) > 1) {
                    foreach ($compiledQuotes as $k => $service) {
                        foreach($service as $serKey => $ser){
                            $quotes = Functions::getQuotesArray($service, $allQuotes, $multiShipmentQuotes, $origin, $serKey);
                            $allQuotes = $quotes['allQuotes'];
                            $multiShipmentQuotes = $quotes['multiShipmentQuotes']; 
                        }
                   }
                } else {
                    $service = reset($compiledQuotes);
                    foreach($service as $serKey => $ser){
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

            if (isset($quote['q'])) {
                if (isset($quote['hazardousStatus'])) {
                    $hazShipmentArr[$origin] = $quote['hazardousStatus'] == 'y' ? 'Y' : 'N';
                }
                foreach ($quote['q'] as $key => $data) {
                    $isHATQuote = isset($data['serviceType']) && strpos($data['serviceType'], 'HAT+') !== false;
                    if ($isHATQuote){
                        $hatShipments[] = $data;
                        continue;
                    }

                    $access = $this->getAccessorialCode();
                    $price = $this->calculatePrice($data);

                    /*
                     * Date 01-07-22
                     * Adding Functionality of Delivery Estimate Options
                     * */
                    $date = $data['deliveryDate'] ?? $data['deliveryTimestamp'] ?? null;
                    $days = $data['totalTransitTimeInDays'] ?? null;
                    $dateAndDays = ['deliveryDate' => $date, 'totalTransitTimeInDays' => $days];
                    $title = $this->getTitle($data['serviceDesc'], false, false, $data['totalTransitTimeInDays'], [], $dateAndDays);

                    $arraySorting['simple'][$key] = $price;
                    $originQuotes[$key]['simple']['code'] = 'xpoltl' . $access;
                    $originQuotes[$key]['simple']['rate'] = $price;
                    $originQuotes[$key]['simple']['title'] = $title;
                    if ($lgQuotes) {
                        $lgAccess = $this->getAccessorialCode(true);
                        $lgPrice = $this->calculatePrice($data, true);
                        $lgTitle = $this->getTitle($data['serviceDesc'], true, false, $data['totalTransitTimeInDays'], [], $dateAndDays);
                        $arraySorting['liftgate'][$key] = $lgPrice;
                        $originQuotes[$key]['liftgate']['code'] = 'xpoltl' . $lgAccess;
                        $originQuotes[$key]['liftgate']['rate'] = $lgPrice;
                        $originQuotes[$key]['liftgate']['title'] = $lgTitle;
                    }
                    // Get Notify Before Delivery Origin Quotes
                    if($notifyDelivery){
                        $compileNotifyDeliveryQuotes = Functions::getOriginQuotes('notifydelivery', $data['serviceDesc'], $originQuotes, $data, $key, $data['totalTransitTimeInDays'], 
                        $dateAndDays, false, 'xpoltl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi);

                        $arraySorting['notifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                        $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                    }
                    if($notifyDelivery && $lgQuotes){
                        $compileNotifyDeliveryQuotes = Functions::getOriginQuotes('lgnotifydelivery', $data['serviceDesc'], $originQuotes, $data, $key, $data['totalTransitTimeInDays'], 
                        $dateAndDays, true, 'xpoltl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi);

                        $arraySorting['lgnotifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                        $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                    }
                }
            }
            $compiledQuotes = $originQuotes;
            if ($compiledQuotes !== null && !empty($compiledQuotes)) {
                if (count($compiledQuotes) > 1) {
                    foreach ($compiledQuotes as $k => $service) {
                        foreach($service as $serKey => $ser){
                            $quotes = Functions::getQuotesArray($service, $allQuotes, $multiShipmentQuotes, $origin, $serKey);
                            $allQuotes = $quotes['allQuotes'];
                            $multiShipmentQuotes = $quotes['multiShipmentQuotes']; 
                        }
                    }
                } else {
                    $service = reset($compiledQuotes);
                    foreach($service as $serKey => $ser){
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
                    'checkoutQuotes' => $allQuotes,
                    'multiShipmentQuotes' => $multiShipmentQuotes,
                ];
            }

            return $resp;
        }

        if (!empty($hatShipments)) {
            return  $xpoLtl->arrangeHATFreight($allQuotes, $hatShipments);
        }

        return $this->arrangeOwnFreight($allQuotes);
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
                $insideDelivery = (isset($this->quoteSettings['offer_inside_delivery']) && $this->quoteSettings['offer_inside_delivery']) ||
                                  (isset($this->quoteSettings['always_inside_delivery']) && $this->quoteSettings['always_inside_delivery']);

                $notifyDelivery =
                    (isset($this->quoteSettings['always_quote_notify']) && $this->quoteSettings['always_quote_notify']) ||
                    (isset($this->quoteSettings['offer_notify_as_option']) && $this->quoteSettings['offer_notify_as_option']);
            }
            $originQuotes = [];
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
                        $HAT[] = $data;
                        continue;
                    }
                    $price = $this->calculatePrice($data);

                    $this->quoteSettings['label_as'] = (!empty($lableAs) ? $lableAs . ' ' : '') . $data['serviceDesc'];
                    $date = $data['deliveryDate'] ?? null;
                    $days = $data['totalTransitTimeInDays'] ?? null;
                    $dateAndDays = ['deliveryDate' => $date, 'totalTransitTimeInDays' => $days];
                    $title = $this->getTitle($data['serviceDesc'], false, false, $data['totalTransitTimeInDays'], [], $dateAndDays);

                    $access = $this->getAccessorialCode();
                    $arraySorting['simple'][$key] = $price;
                    $originQuotes[$key]['simple']['code'] = $preAccess . $access;
                    $originQuotes[$key]['simple']['rate'] = $price;
                    $originQuotes[$key]['simple']['title'] = $title;
                    if ($lgQuotes && !$isHat) {
                        $lgAccess = $this->getAccessorialCode(true);
                        $lgPrice = $this->calculatePrice($data, true);
                        $lgTitle = $this->getTitle($data['serviceDesc'], true, false, $data['totalTransitTimeInDays'], [], $dateAndDays);
                        $arraySorting['liftgate'][$key] = $lgPrice;
                        $originQuotes[$key]['liftgate']['code'] = $preAccess . $lgAccess;
                        $originQuotes[$key]['liftgate']['rate'] = $lgPrice;
                        $originQuotes[$key]['liftgate']['title'] = $lgTitle;
                    }
                    if ($insideDelivery && !$isHat) {
                        $access = $this->getAccessorialCode(false, true, false, false);
                        $price = $this->calculatePrice($data, false, false, false, true);
                        $title = $this->getTitle($data['serviceDesc'], false, false, $data['totalTransitTimeInDays'], [], $dateAndDays, true);
                        $arraySorting['insideDelivery'][$key] = $price;
                        $originQuotes[$key]['insideDelivery']['code'] = $data['serviceType'] . $access;
                        $originQuotes[$key]['insideDelivery']['rate'] = $price;
                        $originQuotes[$key]['insideDelivery']['title'] = $title;
                    }
                    if ($insideDelivery && $lgQuotes && !$isHat) {
                        $access = $this->getAccessorialCode(true, true, false, false);
                        $price = $this->calculatePrice($data, true, false, false, true);
                        $title = $this->getTitle($data['serviceDesc'], true, false, $data['totalTransitTimeInDays'], [], $dateAndDays, true);
                        $arraySorting['insideLiftGateDelivery'][$key] = $price;
                        $originQuotes[$key]['insideLiftGateDelivery']['code'] = $data['serviceType'] . $access;
                        $originQuotes[$key]['insideLiftGateDelivery']['rate'] = $price;
                        $originQuotes[$key]['insideLiftGateDelivery']['title'] = $title;
                    }
                    // Get Notify Before Delivery Origin Quotes
                    if($notifyDelivery && !$isHat){
                        $compileNotifyDeliveryQuotes = Functions::getOriginQuotes('notifydelivery', $data['serviceDesc'], $originQuotes, $data, $key, $data['totalTransitTimeInDays'], 
                        $dateAndDays, false, $preAccess, $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi);

                        $arraySorting['notifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                        $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                    }
                    if($notifyDelivery && $lgQuotes && !$isHat){
                        $compileNotifyDeliveryQuotes = Functions::getOriginQuotes('lgnotifydelivery', $data['serviceDesc'], $originQuotes, $data, $key, $data['totalTransitTimeInDays'], 
                        $dateAndDays, true, $preAccess, $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi);

                        $arraySorting['lgnotifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                        $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                    }
                    if($notifyDelivery && $insideDelivery && !$isHat){
                        $compileNotifyDeliveryQuotes = Functions::getOriginQuotes('insidenotifydelivery', $data['serviceDesc'], $originQuotes, $data, $key, $data['totalTransitTimeInDays'], 
                        $dateAndDays, false, $preAccess, $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi, $insideDelivery);

                        $arraySorting['insidenotifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                        $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                    }
                    if($notifyDelivery && $insideDelivery && $lgQuotes && !$isHat){
                        $compileNotifyDeliveryQuotes = Functions::getOriginQuotes('lginsidenotifydelivery', $data['serviceDesc'], $originQuotes, $data, $key, $data['totalTransitTimeInDays'], 
                        $dateAndDays, true, $preAccess, $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi, $insideDelivery);

                        $arraySorting['lginsidenotifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                        $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                    }
                }
            }

            $compiledQuotes = $originQuotes;
            if ($compiledQuotes !== null && !empty($compiledQuotes)) {
                // Get Quotes Array
                if (count($compiledQuotes) > 1) {
                    foreach ($compiledQuotes as $k => $service) {
                        foreach($service as $serKey => $ser){
                            $quotes = Functions::getQuotesArray($service, $allQuotes, $multiShipmentQuotes, $origin, $serKey);
                            $allQuotes = $quotes['allQuotes'];
                            $multiShipmentQuotes = $quotes['multiShipmentQuotes']; 
                        }
                        if ($this->isMultiShipment) {
                            break;
                        }
                    }
                } else {
                    $service = reset($compiledQuotes);
                    foreach($service as $serKey => $ser){
                        $quotes = Functions::getQuotesArray($service, $allQuotes, $multiShipmentQuotes, $origin, $serKey);
                        $allQuotes = $quotes['allQuotes'];
                        $multiShipmentQuotes = $quotes['multiShipmentQuotes']; 
                    }
                }
            }
            if($HAT !== null && !empty($HAT) && $this->isMultiShipment){
                $HATS[] = $HAT[0];
                unset($HAT); 
            }else {
                $HATS = $HAT;
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
            if (!empty($HATS)) {
                $allQuotes = $this->forceChangeTitle($allQuotes);
                $hatLabel = explode('|', $HATS[0]['serviceDesc']);
                unset($hatLabel[0]);
                $lableAs = 'Freight |' . implode('|', $hatLabel);
                $resp = [
                    'checkoutQuotes' => Functions::arrangeHATFreight($allQuotes, $HATS, $lableAs),
                    'multiShipmentQuotes' => Functions::arrangeHATMulti($multiShipmentQuotes, $HATS),
                ];
            } else {
                $allQuotes = $this->forceChangeTitle($allQuotes);
                $resp = [
                    'checkoutQuotes' => Functions::arrangeHATFreight($allQuotes, $HATS, Functions::$ltlMultiTitle),
                    'multiShipmentQuotes' => $multiShipmentQuotes,
                ];
            }
            return $resp;
        }

        if (!empty($HATS)) {
            return $rnlLtl->arrangeHATQuotes($allQuotes, $HATS);
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
                    if ($data['serviceType'] == "GND") {
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
                        if ($data['serviceType'] != "GND") {
                            continue;
                        }
                    }

                    $access = $this->getAccessorialCodeSmall();
                    // Adding Markup in services if enabled
                    $price = $this->wweSmallQuoteRes->getServiceRate($data['totalNetCharge']['Amount'], $data['serviceType'], $this->quoteSettings);
                    $quoteSettings = $this->quoteSettings;

                    $price = $this->wweSmallQuoteRes->addHandlingMarkupOfHazmat($price, $quoteSettings['handling_fee_markup'] ?? 0);
                    // Checking hazmat and adding hazmat amounts in services
                    if ($isHazmat) {
                        if ($this->isMultiShipment) {
                            if ($hazmatAllItems[$origin] == 'Y') {
                                $price = $this->wweSmallQuoteRes->addHazmatAmountsInServices($price, $data['serviceType'], $this->quoteSettings);
                            }
                        } else {
                            $price = $this->wweSmallQuoteRes->addHazmatAmountsInServices($price, $data['serviceType'], $this->quoteSettings);
                        }
                    }
                    $date = $data['deliveryTimestamp'] ?? null;
                    $days = $data['totalTransitTimeInDays'] ?? null;
                    $dateAndDays = ['deliveryDate' => $date, 'totalTransitTimeInDays' => $days];
                    $title = $this->wweSmallQuoteRes->getServiceTitle($data['serviceDesc'], $dateAndDays, $data['serviceType'], $this->quoteSettings, $this->isResi);
                    $productOriginMarkupFee = Functions::calProductOriginMarkupFee($data['totalNetCharge']['Amount'], $this->originKey, $this->items, $this->allOrigins);
                    $price = $price + $productOriginMarkupFee;
                    $price = (float)str_replace(',', '', $price);
                    $originQuotes[$shipmentCount]['shipment'][$key]['simple']['code'] = 'parcel_12wwe' . $data['serviceType'] . $access;
                    $originQuotes[$shipmentCount]['shipment'][$key]['simple']['rate'] = $price;
                    $originQuotes[$shipmentCount]['shipment'][$key]['simple']['title'] = $title;
                    $multiShipmentQuotes['simple'][$origin] = $originQuotes[$shipmentCount]['shipment'][$key]['simple'];
                }
            }
            $shipmentCount++;
        }
        // $multiShipmentQuotes
        // Check for mukti shipment finding lowest price in each shipment and adding them for multi shipment
        if ($this->isMultiShipment) {
            $originQuotesMulti = [];
            $multiShipPrice = 0;
            foreach ($originQuotes as $shipmentKey => $shipment) {
                $netChargeArray = array_column($shipment['shipment'], 'simple');
                $minValueFromNetChargeArr = min(array_column($netChargeArray, 'rate'));
                $multiShipPrice += str_replace(',', '', $minValueFromNetChargeArr);
                $originQuotesMulti[0]['code'] = $this->isResi || $this->alwaysResi ? 'Multi+R' : 'Multi';
                $originQuotesMulti[0]['rate'] = number_format($multiShipPrice, 2);
                $originQuotesMulti[0]['title'] = $this->isResi ? Functions::$smallMultiTitle . ' ' . Constant::RESI_LABEL : Functions::$smallMultiTitle;
            }
            $resp = [
                'checkoutQuotes' => $originQuotesMulti,
                'multiShipmentQuotes' => $multiShipmentQuotes,
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

        foreach ($shipments as $ship) {
            if (!isset($ship['severity'])) {
                $numberOfShipments++;
            }
        }
        if (!$this->isMultiShipment) {
            $this->isMultiShipment = is_countable($shipments) && $numberOfShipments > 1;
        }
        $lableAs = $this->quoteSettings['label_as'] ?? 'Freight';
        $key = 1;
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
            if (isset($quote['q'])) {
                if (isset($quote['hazardousStatus'])) {
                    $hazShipmentArr[$origin] = $quote['hazardousStatus'] == 'y' ? 'Y' : 'N';
                }
                $data = $quote['q'];

                $access = $this->getAccessorialCode();
                $price = $this->calculatePrice($data, false, false, true);

                /*
                 * Date 01-07-22
                 * Adding Functionality of Delivery Estimate Options
                 * */

                $date = $data['deliveryTimestamp'] ?? null;
                $days = $data['totalTransitTimeInDays'] ?? null;
                $dateAndDays = ['deliveryDate' => $date, 'totalTransitTimeInDays' => $days];
                $title = $this->getTitle($lableAs, false, false, $data['totalTransitTimeInDays'], [], $dateAndDays);
                $arraySorting['simple'][$key] = $price;
                $originQuotes[$key]['simple']['code'] = 'upsltl' . $access;
                $originQuotes[$key]['simple']['rate'] = $price;
                $originQuotes[$key]['simple']['title'] = $title;
                if ($lgQuotes) {
                    $lgAccess = $this->getAccessorialCode(true);
                    $lgPrice = $this->calculatePrice($data, true, false, true);
                    $lgTitle = $this->getTitle($lableAs, true, false, $data['totalTransitTimeInDays'], [], $dateAndDays);
                    $arraySorting['liftgate'][$key] = $lgPrice;
                    $originQuotes[$key]['liftgate']['code'] = 'upsltl' . $lgAccess;
                    $originQuotes[$key]['liftgate']['rate'] = $lgPrice;
                    $originQuotes[$key]['liftgate']['title'] = $lgTitle;
                }
                 // Get Notify Before Delivery Origin Quotes
                 if($notifyDelivery){
                    $compileNotifyDeliveryQuotes = Functions::getOriginQuotes('notifydelivery', $lableAs, $originQuotes, $data, $key, $data['totalTransitTimeInDays'], 
                    $dateAndDays, false, 'upsltl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi);

                    $arraySorting['notifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                    $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                }
                if($notifyDelivery && $lgQuotes){
                    $compileNotifyDeliveryQuotes = Functions::getOriginQuotes('lgnotifydelivery', $lableAs, $originQuotes, $data, $key, $data['totalTransitTimeInDays'], 
                    $dateAndDays, $lgQuotes, 'upsltl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi);

                    $arraySorting['lgnotifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                    $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                }
                $key++;
            }
            $compiledQuotes = $this->getCompiledQuotes($originQuotes, $arraySorting, $lgQuotes);

            if ($compiledQuotes !== null && !empty($compiledQuotes)) {
                if (count($compiledQuotes) > 1) {
                    foreach ($compiledQuotes as $k => $service) {
                        foreach($service as $serKey => $ser){
                            $quotes = Functions::getQuotesArray($service, $allQuotes, $multiShipmentQuotes, $origin, $serKey);
                            $allQuotes = $quotes['allQuotes'];
                            $multiShipmentQuotes = $quotes['multiShipmentQuotes']; 
                        }
                    }
                } else {
                    $service = reset($compiledQuotes);
                    foreach($service as $serKey => $ser){
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
                'checkoutQuotes' => $this->arrangeOwnFreight($allQuotes),
                'multiShipmentQuotes' => $multiShipmentQuotes,
            ];
            return $resp;
        }

        return $allQuotes;
    }

    private function compileUnishippersSmallQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential)
    {
        $this->unishippersSmallQuotesResults = new unishippersSmallQuotesResults();
        if ($residential['unishippersSmall'] == 'Y') {
            $this->isResi = true;
            $this->residentialDlvry = 1;
        } else {
            $this->isResi = false;
            $this->residentialDlvry = 0;
        }
        $this->alwaysResi = $this->residential['alwaysResi']['unishippersSmall'] ?? false;
        $access = $this->getAccessorialCodeSmall();
        $res = $this->unishippersSmallQuotesResults->compileQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $this->isResi, $access, $this->isMultiShipment, $this->items);

        if (!$this->isMultiShipment) {
            $this->isMultiShipment = $res['isMultiShipment'] ?? false;
        }

        return $res['resp'] ?? [];
    }

    private function compileDayRossLtlQuotes($shipments, $connectionSettings, $allOrigins, $hazmatAllItems, $residential)
    {
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

                $quotesArr[] = $quote['q'];
                foreach ($quotesArr as $key => $data) {
                    $srvcType = $data['serviceType'] ?? '';
                    if (isset($srvcType)) {
                        $price = $this->calculatePrice($data);

                        $this->quoteSettings['label_as'] = !blank($labelAs) ? $labelAs : 'Freight';

                        if ($this->isSameDayApi) {
                            $this->quoteSettings['label_as'] = '';
                            if(isset($data['serviceType']) && ($data['ServiceLevelCode'] == "H1" || $data['ServiceLevelCode'] == "H2")){
                                $access = $this->getAccessorialCode();
                            }else{
                                $access = '';
                            }
                        }else{
                            $access = $this->getAccessorialCode();
                        }

                        $days = $data['totalTransitTimeInDays'] ?? null;
                        $dateAndDays = $dayRossLtl->getShipmentDateAndDays($data);
                        $title = $this->getTitle($data['serviceDesc'], false, false, $days, [], $dateAndDays);

                        $arraySorting['simple'][$key] = $price;
                        $originQuotes[$key]['simple']['code'] = 'dayrossltl' . $access;
                        $originQuotes[$key]['simple']['rate'] = $price;
                        $originQuotes[$key]['simple']['title'] = $title;

                        if ($lgQuotes) {
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

                            if ($twoManQuotes && !$lgQuotes) {
                                $tmAccess = $this->getAccessorialCode(false, false, '', '', false, true, false);
                                $tmPrice = $this->calculatePrice($data, false, false, false, false, false, true, false);
                                $tmTitle = $this->getTitle($data['serviceDesc'], false, false, $days, [], $dateAndDays, false, false, false, false, $offerTwoManDelAsOpt, false);

                                $arraySorting['twoManDel'][$key] = $tmPrice;
                                $originQuotes[$key]['twoManDel']['code'] = 'dayrossltl' . $tmAccess;
                                $originQuotes[$key]['twoManDel']['rate'] = $tmPrice;
                                $originQuotes[$key]['twoManDel']['title'] = $tmTitle;
                            }

                            if ($appointmentQuotes && !$lgQuotes) {
                                $aptAccess = $this->getAccessorialCode(false, false, '', '', false, false, true);
                                $aptPrice = $this->calculatePrice($data, false, false, false, false, false, false, true);
                                $tmTitle = $this->getTitle($data['serviceDesc'], false, false, $days, [], $dateAndDays, false, false, false, false, false, $offerAppDelAsOpt);

                                $arraySorting['aptDel'][$key] = $aptPrice;
                                $originQuotes[$key]['aptDel']['code'] = 'dayrossltl' . $aptAccess;
                                $originQuotes[$key]['aptDel']['rate'] = $aptPrice;
                                $originQuotes[$key]['aptDel']['title'] = $tmTitle;
                            }

                            if ($twoManQuotes &&  $appointmentQuotes && !$lgQuotes) {
                                $aptAccess = $this->getAccessorialCode(false, false, '', '', false, true, true);
                                $aptPrice = $this->calculatePrice($data, false, false, false, false, false, true, true);
                                $tmTitle = $this->getTitle($data['serviceDesc'], false, false, $days, [], $dateAndDays, false, false, false, false, $offerTwoManDelAsOpt, $offerAppDelAsOpt);
                                
                                $arraySorting['twoManAptDel'][$key] = $aptPrice;
                                $originQuotes[$key]['twoManAptDel']['code'] = 'dayrossltl' . $aptAccess;
                                $originQuotes[$key]['twoManAptDel']['rate'] = $aptPrice;
                                $originQuotes[$key]['twoManAptDel']['title'] = $tmTitle;
                            }
                        }
                    }
                }
            }

            $compiledQuotes = $originQuotes;
            if ($compiledQuotes !== null && !empty($compiledQuotes)) {
                if (count($compiledQuotes) > 1) {
                    foreach ($compiledQuotes as $k => $service) {
                        $allQuotes['simple'][] = $service['simple'];
                        $multiShipmentQuotes['simple'][$origin] = $service['simple'];
                        $lgQuotes ? $allQuotes['liftgate'][] = $service['liftgate'] : null;
                        $lgQuotes ? $multiShipmentQuotes['liftgate'][$origin] = $service['liftgate'] : null;

                        $twoManQuotes ? $allQuotes['twoManDel'][] = $service['twoManDel'] : null;
                        $twoManQuotes ? $multiShipmentQuotes['twoManDel'][$origin] = $service['twoManDel'] : null;

                        $appointmentQuotes ? $allQuotes['aptDel'][] = $service['aptDel'] : null;
                        $appointmentQuotes ? $multiShipmentQuotes['aptDel'][$origin] = $service['aptDel'] : null;

                        $twoManQuotes && $appointmentQuotes ? $allQuotes['twoManAptDel'][] = $service['twoManAptDel'] : null;
                        $twoManQuotes && $appointmentQuotes ? $multiShipmentQuotes['twoManAptDel'][$origin] = $service['twoManAptDel'] : null;
                    }
                } else {
                    $service = reset($compiledQuotes);
                    $allQuotes['simple'][] = $service['simple'] ?? '';
                    $multiShipmentQuotes['simple'][$origin] = $service['simple'] ?? '';
                    $lgQuotes ? $allQuotes['liftgate'][] = $service['liftgate'] : null;
                    $lgQuotes ? $multiShipmentQuotes['liftgate'][$origin] = $service['liftgate'] : null;

                    $twoManQuotes ? $allQuotes['twoManDel'][] = $service['twoManDel'] : null;
                    $twoManQuotes ? $multiShipmentQuotes['twoManDel'][$origin] = $service['twoManDel'] : null;

                    $appointmentQuotes ? $allQuotes['aptDel'][] = $service['aptDel'] : null;
                    $appointmentQuotes ? $multiShipmentQuotes['aptDel'][$origin] = $service['aptDel'] : null;

                    ($twoManQuotes && $appointmentQuotes) ? $allQuotes['twoManAptDel'][] = $service['twoManAptDel'] : null;
                    ($twoManQuotes && $appointmentQuotes) ? $multiShipmentQuotes['twoManAptDel'][$origin] = $service['twoManAptDel'] : null;
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
        }

        /* Multishipment quotes with LGD  */
        if ((!empty($multiShipmentQuotes['simple']) && count($multiShipmentQuotes['simple']) > 1) || (!empty($multiShipmentQuotes['liftgate']) && count($multiShipmentQuotes['liftgate']) > 1)) {
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
            return  $dayRossLtl->arrangeHATFreight($allQuotes, $hatShipments);
        }

        return $this->arrangeOwnFreight($allQuotes);
    }

    private function compileYRCLtlQuotes($shipments, $connectionSettings, $allOrigins, $residential)
    {
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
                if(!$laccess){
                    $laccess = !($this->isResi || $this->alwaysResi) && ((isset($this->quoteSettings['offer_limited_access_delivery']) && $this->quoteSettings['offer_limited_access_delivery']) ||
                                (isset($this->quoteSettings['always_limited_access_delivery']) && $this->quoteSettings['always_limited_access_delivery']));
                }

                if(!$notifyDelivery){
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
                        if($notifyDelivery){
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes('notifydelivery', $data['serviceDesc'], $originQuotes, $data, $origin, $days, 
                            $dateAndDays, false, 'yrcltl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi);

                            $arraySorting['notifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }
                        if($notifyDelivery && $lgQuotes){
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes('lgnotifydelivery', $data['serviceDesc'], $originQuotes, $data, $origin, $days, 
                            $dateAndDays, true, 'yrcltl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi);

                            $arraySorting['lgnotifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }
                        if($notifyDelivery && $laccess){
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes('laccessnotifydelivery', $data['serviceDesc'], $originQuotes, $data, $origin, $days, 
                            $dateAndDays, false, 'yrcltl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi, false, $laccess);

                            $arraySorting['laccessnotifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }
                        if($notifyDelivery && $lgQuotes && $laccess){
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes('lglaccessnotifydelivery', $data['serviceDesc'], $originQuotes, $data, $origin, $days, 
                            $dateAndDays, true, 'yrcltl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi, false, $laccess);

                            $arraySorting['lglaccessnotifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }
                    }
                }
            }
            $compiledQuotes = $yrcLtl->getCompiledQuotes($originQuotes, $arraySorting, $this->isMultiShipment);

            if ($compiledQuotes !== null && !empty($compiledQuotes)) {
                if (count($compiledQuotes) > 1) {
                    foreach ($compiledQuotes as $k => $service) {
                        foreach($service as $serKey => $ser){
                            $quotes = Functions::getQuotesArray($service, $allQuotes, $multiShipmentQuotes, $origin, $serKey);
                            $allQuotes = $quotes['allQuotes'];
                            $multiShipmentQuotes = $quotes['multiShipmentQuotes']; 
                        }
                    }
                } else {
                    $service = reset($compiledQuotes);
                    foreach($service as $serKey => $ser){
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
            $TLquotes = $freightQuote->truckLoadQuotes($quote, $allConfigServices, $this->quoteSettings, $origin, $this->items, $allOrigins);
            $TLquotes = $this->getCompiledQuotes($TLquotes[0], $TLquotes[1], false);

            if (isset($quote['q'])) {
                if (isset($quote['hazardousStatus'])) {
                    $hazShipmentArr[$origin] = $quote['hazardousStatus'] == 'y' ? 'Y' : 'N';
                }

                foreach ($quote['q'] as $key => $data) {
                    if (isset($data['serviceType']) && in_array($data['serviceType'], $allConfigServices)) {
                        $access = $this->getAccessorialCode() . $resiPickup;
                        $charges = array(
                            'totalNetCharge' => array(
                                'Amount' => $data['totalNetCharge'],
                            ),
                            'surcharges' => $data['surcharges'],
                        );
                        $price = $this->calculatePrice($charges);

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
                            $lgPrice = $this->calculatePrice($charges, true);
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
                        $allQuotes['simple'][] = $service['simple'];
                        $multiShipmentQuotes['simple'][$origin] = $service['simple'];
                        $lgQuotes ? $allQuotes['liftgate'][] = $service['liftgate'] : null;
                        $lgQuotes ? $multiShipmentQuotes['liftgate'][$origin] = $service['liftgate'] : null;
                    }
                } else {
                    $service = reset($compiledQuotes);
                    $allQuotes['simple'][] = $service['simple'] ?? '';
                    $multiShipmentQuotes['simple'][$origin] = $service['simple'] ?? '';
                    $lgQuotes ? $allQuotes['liftgate'][] = $service['liftgate'] : null;
                    $lgQuotes ? $multiShipmentQuotes['liftgate'][$origin] = $service['liftgate'] : null;
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
        
        if(!(isset($this->quoteSettings['quoteltl_and_truckload']) && $this->quoteSettings['quoteltl_and_truckload']) && $this->isMultiShipment ){
            
            $ltlTruckloadQuotes = Functions::quotesLtlTruckLoad($allQuotes, $shipments);
            $allQuotes = $ltlTruckloadQuotes[0];
            $multiShipmentQuotes = $ltlTruckloadQuotes[1];

        }

        $allQuotes = $this->getFinalQuotesArray($allQuotes);
        if (!$this->isMultiShipment && isset($inStoreLdData) && !empty($inStoreLdData)) {
            $allQuotes = $this->inStoreLocalDeliveryQuotes($allQuotes, $inStoreLdData, $allOrigins);
        }

        if ((!empty($multiShipmentQuotes['simple']) && count($multiShipmentQuotes['simple']) > 1) || (!empty($multiShipmentQuotes['liftgate']) && count($multiShipmentQuotes['liftgate']) > 1) || (!empty($multiShipmentQuotes['Truckload']) && count($multiShipmentQuotes['Truckload']) > 1)) {
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
        $allQuotes = $odwArr = $multiShipmentQuotes = [];
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
            $isError = isset($quote['severity']);
            if ($isError) {
                return $this->getInsPicAndLocDelQuotes($quote, $allOrigins);
            }

            if ($count == 0) {
                $inStoreLdData = $quote['InstorPickupLocalDelivery'] ?? false;
                unset($quote['InstorPickupLocalDelivery']);
                $lgQuotes = $saiaLtl->isLGQuotes($this->quoteSettings, $this->isResi);

                $notifyDelivery =
                    (isset($this->quoteSettings['always_quote_notify']) && $this->quoteSettings['always_quote_notify']) ||
                    (isset($this->quoteSettings['offer_notify_as_option']) && $this->quoteSettings['offer_notify_as_option']);
            }

            $originQuotes = $arraySorting = [];

            if (isset($quote['q'])) {
                $quotesArr[] = $quote['q'];

                foreach ($quotesArr as $key => $data) {
                    $srvcType = $data['serviceType'] ?? '';

                    if (isset($srvcType)) {
                        $access = $this->getAccessorialCode();
                        $price = $this->calculatePrice($data);

                        $this->quoteSettings['label_as'] = $labelAs;

                        $days = $data['totalTransitTimeInDays'] ?? null;
                        $dateAndDays = $saiaLtl->getShipmentDateAndDays($data);
                        $title = $this->getTitle($data['serviceDesc'], false, false, $days, [], $dateAndDays);

                        $arraySorting['simple'][$origin] = $price;
                        $originQuotes[$origin]['simple']['code'] = 'saialtl' . $access;
                        $originQuotes[$origin]['simple']['rate'] = $price;
                        $originQuotes[$origin]['simple']['title'] = $title;

                        if ($lgQuotes) {
                            $lgAccess = $this->getAccessorialCode(true);
                            $lgPrice = $this->calculatePrice($data, true);
                            $lgTitle = $this->getTitle($data['serviceDesc'], true, false, $days, [], $dateAndDays);
                            $arraySorting['liftgate'][$origin] = $lgPrice;
                            $originQuotes[$origin]['liftgate']['code'] = 'saialtl' . $lgAccess;
                            $originQuotes[$origin]['liftgate']['rate'] = $lgPrice;
                            $originQuotes[$origin]['liftgate']['title'] = $lgTitle;
                        }
                        // Get Notify Before Delivery Origin Quotes
                        if($notifyDelivery){
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes('notifydelivery', $data['serviceDesc'], $originQuotes, $data, $origin, $days, 
                            $dateAndDays, false, 'saialtl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi);

                            $arraySorting['notifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }
                        if($notifyDelivery && $lgQuotes){
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes('lgnotifydelivery', $data['serviceDesc'], $originQuotes, $data, $origin, $days, 
                            $dateAndDays, true, 'saialtl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi);

                            $arraySorting['lgnotifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }
                    }
                }
            }

            $compiledQuotes = $originQuotes;
            if ($compiledQuotes !== null && !empty($compiledQuotes)) {
                // Get Quotes Array
                if (count($compiledQuotes) > 1) {
                    foreach ($compiledQuotes as $k => $service) {
                        foreach($service as $serKey => $ser){
                            $quotes = Functions::getQuotesArray($service, $allQuotes, $multiShipmentQuotes, $origin, $serKey);
                            $allQuotes = $quotes['allQuotes'];
                            $multiShipmentQuotes = $quotes['multiShipmentQuotes']; 
                        }
                    }
                } else {
                    $service = reset($compiledQuotes);
                    foreach($service as $serKey => $ser){
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
        }

        /* Multishipment quotes with LGD  */
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
        $hatShipments = [];

        foreach ($shipments as $origin => $quote) {
            $this->originKey = $origin;

            if ((isset($quote['severity']) || !isset($quote['q']) || (isset($quote['q']) && empty($quote['q'])))) {
                return $this->getInsPicAndLocDelQuotes($quote, $allOrigins);
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

            $originQuotes = [];
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
                    if ($isHATQuote){
                        $hatShipments[] = $data['holdAtTerminalResponse'];
                    }

                    $srvcType = $data['serviceType'] ?? '';
                    if (isset($srvcType)) {
                        $access = $this->getAccessorialCode();
                        $price = $this->calculatePrice($data);
                        
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
                        $originQuotes[$origin]['simple']['code'] = 'abfltl' . $access;
                        $originQuotes[$origin]['simple']['rate'] = $price;
                        $originQuotes[$origin]['simple']['title'] = $title;

                        if ($lgQuotes) {
                            $lgAccess = $this->getAccessorialCode(true);
                            $lgPrice = $this->calculatePrice($data, true);
                            $lgTitle = $this->getTitle($data['serviceDesc'], true, false, $days, [], $dateAndDays);
                            $arraySorting['liftgate'][$origin] = $lgPrice;
                            $originQuotes[$origin]['liftgate']['code'] = 'abfltl' . $lgAccess;
                            $originQuotes[$origin]['liftgate']['rate'] = $lgPrice;
                            $originQuotes[$origin]['liftgate']['title'] = $lgTitle;
                        }
                        // Get Notify Before Delivery Origin Quotes
                        if($notifyDelivery){
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes('notifydelivery', $data['serviceDesc'], $originQuotes, $data, $origin, $days, 
                            $dateAndDays, false, 'abfltl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi);

                            $arraySorting['notifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }
                        if($notifyDelivery && $lgQuotes){
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes('lgnotifydelivery', $data['serviceDesc'], $originQuotes, $data, $origin, $days, 
                            $dateAndDays, true, 'abfltl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi);

                            $arraySorting['lgnotifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }
                    }
                }
            }

            $compiledQuotes = $abfLtl->getCompiledQuotes($originQuotes, $arraySorting, $this->isMultiShipment);

            if ($compiledQuotes !== null && !empty($compiledQuotes)) {
                // Get Quotes Array
                if (count($compiledQuotes) > 1) {
                    foreach ($compiledQuotes as $k => $service) {
                        foreach($service as $serKey => $ser){
                            $quotes = Functions::getQuotesArray($service, $allQuotes, $multiShipmentQuotes, $origin, $serKey);
                            $allQuotes = $quotes['allQuotes'];
                            $multiShipmentQuotes = $quotes['multiShipmentQuotes']; 
                        }
                    }
                } else {
                    $service = reset($compiledQuotes);
                    foreach($service as $serKey => $ser){
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
        }

        /* Multishipment quotes with LGD  */
        if ((!empty($multiShipmentQuotes['simple']) && count($multiShipmentQuotes['simple']) > 1) || (!empty($multiShipmentQuotes['liftgate']) && count($multiShipmentQuotes['liftgate']) > 1)) {
            
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
                    'checkoutQuotes' => $allQuotes,
                    'multiShipmentQuotes' => $multiShipmentQuotes,
                ];
            }

            return $resp;
        }

        if (!empty($hatShipments)) {
            return  $abfLtl->arrangeHATFreight($allQuotes, $hatShipments);
        }

        $resp = $allQuotes;
        return $resp;
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
                $inStoreLdData = $SouthEastern->isSuppressedRatesShipment($shipments) ? $quote['InstorPickupLocalDelivery'] : $quote['q']['InstorPickupLocalDelivery'] ?? false;
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

            $originQuotes = [];
            $arraySorting = [];

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
                        $access = $this->getAccessorialCode();
                        $price = $this->calculatePrice($data);

                        $this->quoteSettings['label_as'] = !blank($labelAs) ? $labelAs : 'Freight';
                        $date = $quote['q']['deliveryDate'] ?? null;
                        $days = $quote['q']['totalTransitTimeInDays'] ?? null;
                        $dateAndDays = ['deliveryDate' => $date, 'totalTransitTimeInDays' => $days];
                        $title = $this->getTitle($data['serviceDesc'], false, false, $days, [], $dateAndDays);

                        $arraySorting['simple'][$origin] = $price;
                        $originQuotes[$origin]['simple']['code'] = 'SouthEastern' . $access;
                        $originQuotes[$origin]['simple']['rate'] = $price;
                        $originQuotes[$origin]['simple']['title'] = $title;

                        if ($lgQuotes) {
                            $lgAccess = $this->getAccessorialCode(true);
                            $lgPrice = $this->calculatePrice($data, true);
                            $lgTitle = $this->getTitle($data['serviceDesc'], true, false, $days, [], $dateAndDays);
                            $arraySorting['liftgate'][$origin] = $lgPrice;
                            $originQuotes[$origin]['liftgate']['code'] = 'SouthEastern' . $lgAccess;
                            $originQuotes[$origin]['liftgate']['rate'] = $lgPrice;
                            $originQuotes[$origin]['liftgate']['title'] = $lgTitle;
                        }
                        // Get Notify Before Delivery Origin Quotes
                        if($notifyDelivery){
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes('notifydelivery', $data['serviceDesc'], $originQuotes, $data, $origin, $days, 
                            $dateAndDays, false, 'SouthEastern', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi);

                            $arraySorting['notifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }
                        if($notifyDelivery && $lgQuotes){
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes('lgnotifydelivery', $data['serviceDesc'], $originQuotes, $data, $origin, $days, 
                            $dateAndDays, true, 'SouthEastern', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi);

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
                        foreach($service as $serKey => $ser){
                            $quotes = Functions::getQuotesArray($service, $allQuotes, $multiShipmentQuotes, $origin, $serKey);
                            $allQuotes = $quotes['allQuotes'];
                            $multiShipmentQuotes = $quotes['multiShipmentQuotes']; 
                        }
                    }
                } else {
                    $service = reset($compiledQuotes);
                    foreach($service as $serKey => $ser){
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

    private function compileUspsSmallQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential)
    {
        $uspsSmallQuotesResults = new uspsSmallQuotesResults();
        $this->isResi = false;
        $this->residentialDlvry = 0;
        $this->alwaysResi = false;

        $access = $this->getAccessorialCodeSmall();
        $res = $uspsSmallQuotesResults->compileQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $this->isResi, $access, $this->isMultiShipment, $this->items);

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

            $originQuotes = $arraySorting = [];

            if (isset($quote['q'])) {
                $items = $quote['q']['lineItems'] ?? [];
                foreach ($items as $key => $item) {
                    if($item['hazardous'] == 'Y'){
                        $hazShipmentArr[$origin] = 'Y';
                        break;
                    }
                        $hazShipmentArr[$origin] = 'N';
                }

                foreach ($quote['q'] as $key => $data) {
                    $srvcType = $data['CarrierSCAC'] ?? '';
                    
                    if (!empty($srvcType) && in_array($srvcType, $carrierServices)) {
                        $access = $this->getAccessorialCode();
                        $data['totalNetCharge']['Amount'] = $data['TotalCharge'] ?? 0;
                        $data['surcharges']['liftgateFee'] = $echoLtl->getLGFee($data['Accessorials'] ?? []) ?? 0;
                        $data['surcharges']['notifyBeforeDeliveryFee'] = $echoLtl->getNBDFee($data['Accessorials'] ?? []) ?? 0;
                        $price = $this->calculatePrice($data);

                        $days = $data['totalTransitTimeInDays'] ?? null;
                        $dateAndDays = $echoLtl->getShipmentDateAndDays($data);
                        $title = $this->getTitle($data['CarrierName'], false, false, $days, [], $dateAndDays);

                        $arraySorting['simple'][$key] = $price;
                        $originQuotes[$key]['simple']['code'] = 'echoltl' . $access . $srvcType;
                        $originQuotes[$key]['simple']['rate'] = $price;
                        $originQuotes[$key]['simple']['title'] = $title;

                        if ($lgQuotes) {
                            $lgAccess = $this->getAccessorialCode(true);
                            $lgPrice = $this->calculatePrice($data, true);
                            $lgTitle = $this->getTitle($data['CarrierName'], true, false, $days, [], $dateAndDays);
                            $arraySorting['liftgate'][$key] = $lgPrice;
                            $originQuotes[$key]['liftgate']['code'] = 'echoltl' . $lgAccess . $srvcType;
                            $originQuotes[$key]['liftgate']['rate'] = $lgPrice;
                            $originQuotes[$key]['liftgate']['title'] = $lgTitle;
                        }
                        // Get Notify Before Delivery Origin Quotes
                        if($notifyDelivery){
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes('notifydelivery', $data['CarrierName'], $originQuotes, $data, $key, $days, 
                            $dateAndDays, false, 'echoltl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi);

                            $arraySorting['notifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }
                        if($notifyDelivery && $lgQuotes){
                            $compileNotifyDeliveryQuotes = Functions::getOriginQuotes('lgnotifydelivery', $data['CarrierName'], $originQuotes, $data, $key, $days, 
                            $dateAndDays, true, 'echoltl', $this->originKey, $this->items, $this->allOrigins, $this->quoteSettings, $this->isResi, $this->alwaysResi);

                            $arraySorting['lgnotifydelivery'][$key] = $compileNotifyDeliveryQuotes['ndPrice'];
                            $originQuotes = $compileNotifyDeliveryQuotes['originQuotes'];
                        }
                    }
                }
            }

            $compiledQuotes = $this->getCompiledQuotes($originQuotes, $arraySorting, $lgQuotes);    
            if ($compiledQuotes !== null && !empty($compiledQuotes)) {
                if (count($compiledQuotes) > 1) {
                    foreach ($compiledQuotes as $k => $service) {
                        foreach($service as $serKey => $ser){
                            $quotes = Functions::getQuotesArray($service, $allQuotes, $multiShipmentQuotes, $origin, $serKey);
                            $allQuotes = $quotes['allQuotes'];
                            $multiShipmentQuotes = $quotes['multiShipmentQuotes']; 
                        }
                    }
                } else {
                    $service = reset($compiledQuotes);
                    foreach($service as $serKey => $ser){
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
        }

        /* Multishipment quotes with LGD  */
        if ((!empty($multiShipmentQuotes['simple']) && count($multiShipmentQuotes['simple']) > 1) || (!empty($multiShipmentQuotes['liftgate']) && count($multiShipmentQuotes['liftgate']) > 1)) {
            $allQuotes = $this->forceChangeTitle($allQuotes);
            $resp = [
                'checkoutQuotes' => $allQuotes,
                'multiShipmentQuotes' => $multiShipmentQuotes,
            ];

            return $resp;
        }

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
        
        if (!$this->isMultiShipment) {
            $this->isMultiShipment = $dayLightQuotes->isMultiShipment($shipments);
        }        
        $labelAs = $this->quoteSettings['label_as']  ?? '';
        $labelAs = !blank($labelAs) ? $labelAs : 'Freight';

        /* Quotes compilation */
        foreach ($shipments as $origin => $quote) {
            $this->originKey = $origin;
            if (isset($quote['severity'])) {
                return $this->getInsPicAndLocDelQuotes($quote, $allOrigins);
            }

            if ($count == 0) { 
                $inStoreLdData = $quote['InstorPickupLocalDelivery'] ?? false;
                $lgQuotes = $dayLightQuotes->isLGQuotes($this->quoteSettings, $this->isResi);
            }

            $originQuotes = $arraySorting = [];

            if (isset($quote['q'])) {
                $quotesArr[] = $quote['q'];

                foreach ($quotesArr as $key => $data) {                    
                    $access = $this->getAccessorialCode();
                    $price = $this->calculatePrice($data);

                    $this->quoteSettings['label_as'] = $labelAs ;

                    $dateAndDays = $dayLightQuotes->getShipmentDateAndDays($data);
                    $title = $this->getTitle($data['serviceDesc'], false, false, '', [], $dateAndDays);

                    $arraySorting['simple'][$origin] = $price;
                    $originQuotes[$origin]['simple']['code'] = 'daylightltl' . $access;
                    $originQuotes[$origin]['simple']['rate'] = $price;
                    $originQuotes[$origin]['simple']['title'] = $title;

                    if ($lgQuotes) {
                        $lgAccess = $this->getAccessorialCode(true);
                        $lgPrice = $this->calculatePrice($data, true);
                        $lgTitle = $this->getTitle($data['serviceDesc'], true, false, '', [], $dateAndDays);
                        $arraySorting['liftgate'][$origin] = $lgPrice;
                        $originQuotes[$origin]['liftgate']['code'] = 'daylightltl' . $lgAccess;
                        $originQuotes[$origin]['liftgate']['rate'] = $lgPrice;
                        $originQuotes[$origin]['liftgate']['title'] = $lgTitle;
                    }
                }
            }

            $compiledQuotes = $originQuotes;    
            if ($compiledQuotes !== null && !empty($compiledQuotes)) {
                if (count($compiledQuotes) > 1) {
                    foreach ($compiledQuotes as $k => $service) {
                        $allQuotes['simple'][] = $service['simple'];
                        $multiShipmentQuotes['simple'][$origin] = $service['simple'];
                        $lgQuotes ? $allQuotes['liftgate'][] = $service['liftgate'] : null;
                        $lgQuotes ? $multiShipmentQuotes['liftgate'][$origin] = $service['liftgate'] : null;
                    }
                } else {
                    $service = reset($compiledQuotes);
                    $allQuotes['simple'][] = $service['simple'] ?? '';
                    $multiShipmentQuotes['simple'][$origin] = $service['simple'] ?? '';
                    $lgQuotes ? $allQuotes['liftgate'][] = $service['liftgate'] : null;
                    $lgQuotes ? $multiShipmentQuotes['liftgate'][$origin] = $service['liftgate'] : null;
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
        }

        /* Multishipment quotes with LGD  */
        if ((!empty($multiShipmentQuotes['simple']) && count($multiShipmentQuotes['simple']) > 1) || (!empty($multiShipmentQuotes['liftgate']) && count($multiShipmentQuotes['liftgate']) > 1)) {
            $allQuotes = $this->forceChangeTitle($allQuotes);
            $resp = [
                'checkoutQuotes' => $allQuotes,
                'multiShipmentQuotes' => $multiShipmentQuotes,
            ];

            return $resp;
        }

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

            $originQuotes = [];
            $arraySorting = [];
            $TLquotes = $fqChrQuotes->truckLoadQuotes($quote, $this->quoteSettings, $origin, $this->items, $this->allOrigins);
            $TLquotes = $this->getCompiledQuotes($TLquotes[0], $TLquotes[1], false);

            if (isset($quote['q'])) {
                if (isset($quote['hazardousStatus'])) {
                    $hazShipmentArr[$origin] = $quote['hazardousStatus'] == 'y' ? 'Y' : 'N';
                }

                foreach ($quote['q'] as $key => $data) {
                    if (isset($data['serviceType']) && in_array($data['serviceType'], $allConfigServices)) {
                        $access = $this->getAccessorialCode();
                        $charges = array(
                            'totalNetCharge' => array(
                                'Amount' => $data['totalNetCharge'],
                            ),
                            'surcharges' => $data['surcharges'],
                        );

                        $price = $this->calculatePrice($charges);

                        $dateAndDays = $fqChrQuotes->getShipmentDateAndDays($data);
                        $title = $this->getTitle($data['serviceDesc'], false, false, $data['totalTransitTimeInDays'], [], $dateAndDays);
                        
                        $arraySorting['simple'][$key] = $price;
                        $originQuotes[$key]['simple']['code'] = 'fqchrltl' . $access;
                        $originQuotes[$key]['simple']['rate'] = $price;
                        $originQuotes[$key]['simple']['title'] = $title;

                        if ($lgQuotes) {
                            $lgAccess = 'fqchrltl' . $this->getAccessorialCode(true);
                            $lgPrice = $this->calculatePrice($charges, true);
                            $lgTitle = $this->getTitle($data['serviceDesc'], true, false, $data['totalTransitTimeInDays'], [], $dateAndDays);
                         
                            $arraySorting['liftgate'][$key] = $lgPrice;
                            $originQuotes[$key]['liftgate']['code'] = $lgAccess;
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
                        $allQuotes['simple'][] = $service['simple'];
                        $multiShipmentQuotes['simple'][$origin] = $service['simple'];
                        $lgQuotes ? $allQuotes['liftgate'][] = $service['liftgate'] : null;
                        $lgQuotes ? $multiShipmentQuotes['liftgate'][$origin] = $service['liftgate'] : null;
                    }
                } else {
                    $service = reset($compiledQuotes);
                    $allQuotes['simple'][] = $service['simple'] ?? '';
                    $multiShipmentQuotes['simple'][$origin] = $service['simple'] ?? '';
                    $lgQuotes ? $allQuotes['liftgate'][] = $service['liftgate'] : null;
                    $lgQuotes ? $multiShipmentQuotes['liftgate'][$origin] = $service['liftgate'] : null;
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

        if(!(isset($this->quoteSettings['quoteltl_and_truckload']) && $this->quoteSettings['quoteltl_and_truckload']) && $this->isMultiShipment ){
            
            $ltlTruckloadQuotes = Functions::quotesLtlTruckLoad($allQuotes, $shipments);
            $allQuotes = !empty($ltlTruckloadQuotes) ? $ltlTruckloadQuotes[0] : null;
            $multiShipmentQuotes = !empty($ltlTruckloadQuotes) ? $ltlTruckloadQuotes[1] : null;

        }

        $allQuotes = $this->getFinalQuotesArray($allQuotes);
        if (!$this->isMultiShipment && isset($inStoreLdData) && !empty($inStoreLdData)) {
            $allQuotes = $this->inStoreLocalDeliveryQuotes($allQuotes, $inStoreLdData, $allOrigins);
        }

        if ((!empty($multiShipmentQuotes['simple']) && count($multiShipmentQuotes['simple']) > 1) || (!empty($multiShipmentQuotes['liftgate']) && count($multiShipmentQuotes['liftgate']) > 1) || (!empty($multiShipmentQuotes['Truckload']) && count($multiShipmentQuotes['Truckload']) > 1)) {
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
    
    public function getInsPicAndLocDelQuotes($quote, $allOrigins): array
    {
        $inStoreLdData = $quote['InstorPickupLocalDelivery'] ?? $quote['q']['InstorPickupLocalDelivery'] ?? $quote['fedexServices']['InstorPickupLocalDelivery'] ?? [];
        if (!$this->isMultiShipment && !blank($inStoreLdData)) {
            return $this->inStoreLocalDeliveryQuotes([], $inStoreLdData, $allOrigins);
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
            $handlingFeeMarkup = (float)$this->quoteSettings['handling_free_markup'] ?? 0;
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
    public function getFinalQuotesArray($quotes)
    {
        if (empty($quotes)) {
            return [];
        }

        $lfg = (isset($this->quoteSettings['alwaysLiftGateDelivery']) && $this->quoteSettings['alwaysLiftGateDelivery'] == 1) || ($this->isResi && isset($this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->quoteSettings['autoDetectedResidentialAddressesLfg']);
        $TMD_or_APD = (isset($this->quoteSettings['always_two_man_delivery']) && $this->quoteSettings['always_two_man_delivery'] == 1) || (isset($this->quoteSettings['always_appointment_delivery']) && $this->quoteSettings['always_appointment_delivery'] == 1);
        $TMD_and_APD = (isset($this->quoteSettings['always_two_man_delivery']) && $this->quoteSettings['always_two_man_delivery'] == 1) && (isset($this->quoteSettings['always_appointment_delivery']) && $this->quoteSettings['always_appointment_delivery'] == 1);
        $alwaysNotifyDel = (isset($this->quoteSettings['always_quote_notify']) && $this->quoteSettings['always_quote_notify'] == 1);
        $alwaysInsideDel = (isset($this->quoteSettings['always_inside_delivery']) && $this->quoteSettings['always_inside_delivery'] == 1);
        $alwaysLimitedDel = (isset($this->quoteSettings['always_limited_access_delivery']) && $this->quoteSettings['always_limited_access_delivery'] == 1);

        if ($this->isMultiShipment == false) {
            if (
                isset($quotes['liftgate'])
                && (isset($this->quoteSettings['offerLiftGateDelivery'])
                    && $this->quoteSettings['offerLiftGateDelivery'] == 1)
                && (
                    (isset($this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->quoteSettings['autoDetectedResidentialAddressesLfg'] == 0) || $this->isResi == 0)
            ) {

                // Condition for always notify before delivery and inside delivery
                if($alwaysNotifyDel && $alwaysInsideDel){
                    return array_merge($quotes['insidenotifydelivery'] ?? [], $quotes['lginsidenotifydelivery'] ?? [], $quotes['limitedaccess'] ?? [], $quotes['limitedaccessLG'] ?? [], $quotes['Truckload'] ?? []);             
                }
                // Condition for always notify before delivery and limited access delivery
                if($alwaysNotifyDel && $alwaysLimitedDel){
                    return array_merge($quotes['laccessnotifydelivery'] ?? [], $quotes['lglaccessnotifydelivery'] ?? [], $quotes['Truckload'] ?? []);             
                }
                // Condition for always notify before delivery
                if($alwaysNotifyDel){
                    return array_merge($quotes['insidenotifydelivery'] ?? [], $quotes['lginsidenotifydelivery'] ?? [], $quotes['Truckload'] ?? [], $quotes['notifydelivery'] ?? [], $quotes['lgnotifydelivery'] ?? [], $quotes['laccessnotifydelivery'] ?? [], $quotes['lglaccessnotifydelivery'] ?? []);             
                } 
                // Condition for always limited access delivery
                if($alwaysLimitedDel){
                    return array_merge($quotes['insidenotifydelivery'] ?? [], $quotes['lginsidenotifydelivery'] ?? [], $quotes['Truckload'] ?? [], $quotes['limitedaccess'] ?? [], $quotes['limitedaccessLG'] ?? [], $quotes['laccessnotifydelivery'] ?? [], $quotes['lglaccessnotifydelivery'] ?? []);             
                }
                // Condition for always inside delivery
                if($alwaysInsideDel){
                    return array_merge($quotes['insideDelivery'] ?? [], $quotes['insidenotifydelivery'] ?? [], $quotes['lginsidenotifydelivery'] ?? [], $quotes['limitedaccess'] ?? [], $quotes['limitedaccessLG'] ?? [], $quotes['Truckload'] ?? [], $quotes['insideLiftGateDelivery'] ?? []);             
                }

                /**
                 * Condition for lift gate, inside and notify before delivery as an option
                 * */
                return array_merge($quotes['simple'] ?? [], $quotes['liftgate'] ?? [], $quotes['insideDelivery'] ?? [], $quotes['insideLiftGateDelivery'] ?? [], $quotes['limitedaccess'] ?? [], $quotes['limitedaccessLG'] ?? [], $quotes['Truckload'] ?? [], $quotes['notifydelivery'] ?? [], $quotes['lgnotifydelivery'] ?? [], $quotes['insidenotifydelivery'] ?? [], $quotes['lginsidenotifydelivery'] ?? [], $quotes['laccessnotifydelivery'] ?? [], $quotes['lglaccessnotifydelivery'] ?? []);
            } elseif ($lfg && $alwaysNotifyDel  && $alwaysInsideDel) {
                /**
                 * Condition for Always lift gate, notify before delivery and inside delivery (Single Shipment)
                 * */
                return array_merge($quotes['lginsidenotifydelivery'] ?? [], $quotes['limitedaccessLG'] ?? [], $quotes['Truckload'] ?? []) ?? $quotes['simple'];
            } elseif ($lfg && $alwaysInsideDel) {
                /**
                 * Condition for Always lift gate, inside delivery (Single Shipment)
                 * */
                return array_merge($quotes['lginsidenotifydelivery'] ?? [], $quotes['limitedaccessLG'] ?? [], $quotes['Truckload'] ?? [], $quotes['insideLiftGateDelivery'] ?? []) ?? $quotes['simple'];
            } elseif ($lfg && $alwaysLimitedDel && $alwaysNotifyDel) {
                /**
                 * Condition for Always lift gate, limited access and notify before delivery (Single Shipment)
                 * */
                return array_merge($quotes['lginsidenotifydelivery'] ?? [], $quotes['Truckload'] ?? [], $quotes['lglaccessnotifydelivery'] ?? []) ?? $quotes['simple'];
            } elseif ($lfg && $alwaysLimitedDel) {
                /**
                 * Condition for Always lift gate, limited access delivery (Single Shipment)
                 * */
                return array_merge($quotes['lginsidenotifydelivery'] ?? [], $quotes['Truckload'] ?? [], $quotes['limitedaccessLG'] ?? [], $quotes['lglaccessnotifydelivery'] ?? []) ?? $quotes['simple'];
            } elseif ($lfg && $alwaysNotifyDel) {
                /**
                 * Condition for Always lift gate, notify before delivery and lift gate for residential (Single Shipment)
                 * */
                return array_merge($quotes['lginsidenotifydelivery'] ?? [], $quotes['Truckload'] ?? [], $quotes['lgnotifydelivery'] ?? [], $quotes['lglaccessnotifydelivery'] ?? []) ?? $quotes['simple'];
            } elseif ($alwaysNotifyDel && $alwaysInsideDel) {
                /**
                 * Condition for Always inside and notify before delivery (Single Shipment)
                 * */
                return array_merge($quotes['insidenotifydelivery'] ?? [], $quotes['limitedaccessLG'] ?? [], $quotes['Truckload'] ?? []) ?? $quotes['simple'];
            } elseif ($alwaysNotifyDel) {
                /**
                 * Condition for Always notify before delivery and lift gate for residential (Single Shipment)
                 * */
                return array_merge($quotes['insidenotifydelivery'] ?? [], $quotes['Truckload'] ?? [], $quotes['notifydelivery'] ?? [], $quotes['laccessnotifydelivery'] ?? []) ?? $quotes['simple'];
            } elseif ($alwaysInsideDel) {
                /**
                 * Condition for Always inside before delivery (Single Shipment)
                 * */
                return array_merge($quotes['insideDelivery'] ?? [], $quotes['limitedaccessLG'] ?? [], $quotes['Truckload'] ?? [], $quotes['notifydelivery'] ?? []) ?? $quotes['simple'];
            } elseif ($lfg) {
                /**
                 * Condition for Always lift gate and lift gate for residential (Single Shipment)
                 * */
                return array_merge($quotes['liftgate'] ?? [], $quotes['insideLiftGateDelivery'] ?? [], $quotes['limitedaccessLG'] ?? [], $quotes['Truckload'] ?? [], $quotes['lgnotifydelivery'] ?? [], $quotes['lginsidenotifydelivery'] ?? [], $quotes['lglaccessnotifydelivery'] ?? []) ?? $quotes['simple'];
            } elseif ($TMD_and_APD) {
                /**
                 * Condition for Always two man and appointment delivery (Single Shipment)
                 * */
                return array_merge($quotes['twoManAptDel'] ?? []) ?? $quotes['simple'];
            } elseif ($TMD_or_APD) {
                /**
                 * Condition for Always two man or appointment delivery (Single Shipment)
                 * */
                return array_merge($quotes['twoManDel'] ?? [], $quotes['aptDel'] ?? []) ?? $quotes['simple'];
            } else {
                return array_merge($quotes['simple'] ?? [], $quotes['insideDelivery'] ?? [], $quotes['limitedaccess'] ?? [], $quotes['Truckload'] ?? [], $quotes['twoManDel'] ?? [], $quotes['aptDel'] ?? [], $quotes['twoManAptDel'] ?? [], $quotes['notifydelivery'] ?? [], $quotes['insidenotifydelivery'] ?? []);
            }
        } elseif ($lfg && $alwaysNotifyDel && $alwaysInsideDel) {
            /**
             * Condition for Always lift gate, notify before delivery and inside delivery (Multi Shipment)
             * */
            unset($quotes['simple'], $quotes['insideDelivery'], $quotes['lgnotifydelivery'], $quotes['insidenotifydelivery'], $quotes['insideLiftGateDelivery'], $quotes['limitedaccess'],$quotes['twoManDel'], $quotes['aptDel'], $quotes['notifydelivery'], $quotes['liftgate']);
        } elseif ($lfg && $alwaysNotifyDel && $alwaysLimitedDel) {
            /**
             * Condition for Always lift gate, notify before delivery and limited access delivery (Multi Shipment)
             * */
            unset($quotes['simple'], $quotes['insideDelivery'], $quotes['lgnotifydelivery'], $quotes['insidenotifydelivery'], $quotes['insideLiftGateDelivery'], $quotes['limitedaccess'], $quotes['limitedaccessLG'], $quotes['twoManDel'], $quotes['aptDel'], $quotes['notifydelivery'], $quotes['laccessnotifydelivery'], $quotes['liftgate']);
        } elseif ($lfg && $alwaysInsideDel) {
            /**
             * Condition for Always lift gate, inside delivery and lift gate for residential (Multi Shipment)
             * */
            unset($quotes['simple'], $quotes['insideDelivery'], $quotes['insidenotifydelivery'], $quotes['limitedaccess'],$quotes['twoManDel'], $quotes['aptDel'], $quotes['notifydelivery'], $quotes['lgnotifydelivery'], $quotes['liftgate']);
        } elseif ($lfg && $alwaysLimitedDel) {
            /**
             * Condition for Always lift gate, limited access delivery and lift gate (Multi Shipment)
             * */
            unset($quotes['simple'], $quotes['insideDelivery'], $quotes['insidenotifydelivery'], $quotes['limitedaccess'],$quotes['twoManDel'], $quotes['aptDel'], $quotes['notifydelivery'], $quotes['laccessnotifydelivery'], $quotes['lgnotifydelivery'], $quotes['liftgate']);
        } elseif ($alwaysInsideDel && $alwaysNotifyDel) {
            /**
             * Condition for Always inside, notify before delivery and (Multi Shipment)
             * */
            unset($quotes['simple'], $quotes['insideDelivery'], $quotes['lgnotifydelivery'], $quotes['insideLiftGateDelivery'], $quotes['limitedaccess'],$quotes['twoManDel'], $quotes['aptDel'], $quotes['notifydelivery'], $quotes['liftgate']);
        } elseif ($alwaysLimitedDel && $alwaysNotifyDel) {
            /**
             * Condition for Always limited access and notify before delivery and (Multi Shipment)
             * */
            unset($quotes['simple'], $quotes['insideDelivery'], $quotes['lgnotifydelivery'], $quotes['insideLiftGateDelivery'], $quotes['limitedaccess'], $quotes['limitedaccessLG'], $quotes['twoManDel'], $quotes['aptDel'], $quotes['notifydelivery'], $quotes['liftgate']);
        } elseif ($lfg && $alwaysNotifyDel) {
            /**
             * Condition for Always lift gate, notify before delivery and lift gate for residential (Multi Shipment)
             * */
            unset($quotes['simple'], $quotes['insideDelivery'], $quotes['insidenotifydelivery'], $quotes['insideLiftGateDelivery'], $quotes['limitedaccess'],$quotes['twoManDel'], $quotes['aptDel'], $quotes['notifydelivery'], $quotes['liftgate']);
        } elseif ($TMD_and_APD) {
            /**
             * Condition for Always two man and appointment delivery (Multi Shipment)
             * */
            unset($quotes['simple'], $quotes['insideDelivery'], $quotes['limitedaccess'],$quotes['twoManDel'], $quotes['aptDel']);
        } elseif ($alwaysInsideDel) {
            /**
             * Condition for Always inside delivery (Multi Shipment)
             * */
            unset($quotes['simple'], $quotes['liftgate'], $quotes['limitedaccess'],$quotes['twoManDel'], $quotes['aptDel'], $quotes['lgnotifydelivery'], $quotes['notifydelivery']);
        }elseif ($alwaysNotifyDel) {
            /**
             * Condition for Always notify before delivery (Multi Shipment)
             * */
            unset($quotes['simple'], $quotes['liftgate'], $quotes['insideLiftGateDelivery'], $quotes['insideDelivery'], $quotes['limitedaccess'],$quotes['twoManDel'], $quotes['aptDel']);
        }elseif ($alwaysLimitedDel) {
            /**
             * Condition for Always limited access delivery (Multi Shipment)
             * */
            unset($quotes['simple'], $quotes['notifydelivery'], $quotes['lgnotifydelivery'], $quotes['liftgate'], $quotes['insideLiftGateDelivery'], $quotes['insideDelivery'], $quotes['twoManDel'], $quotes['aptDel']);
        } elseif ($lfg || $TMD_or_APD) {
            /**
             * Condition for always ;8lift gate and lift gate for residential (Multi Shipment)
             * Condition for Always two man or appointment delivery (Multi Shipment)
             * */
            unset($quotes['simple'], $quotes['insideDelivery'], $quotes['insidenotifydelivery'], $quotes['limitedaccess'], $quotes['twoManAptDel'], $quotes['notifydelivery']);
        }
        return $this->organizeQuotesArray($quotes);
    }

    public function organizeQuotesArray($quotes)
    {
        $quotesArr = [];
        foreach ($quotes as $key => $value) {
            if ($this->isMultiShipment) {
                $rate = 0;
                $code = '';
                // check liftgate key with other features enable
                $isLiftGate = ( $key == 'liftgate' || $key == 'lgnotifydelivery' || $key == 'lginsidenotifydelivery' 
                            || $key == 'insideLiftGateDelivery' || $key == 'limitedaccessLG' || $key == 'lglaccessnotifydelivery') ? true : false;

                // check inside delivery key with other features enable
                $isInsideDelivery = ( $key == 'insideDelivery' || $key == 'insideLiftGateDelivery' 
                                || $key == 'insidenotifydelivery' || $key == 'lginsidenotifydelivery' ) ? true : false;

                // check limited access delivery key with other features enable
                $isLimitedAccess = ($key == 'limitedaccess' || $key == 'limitedaccessLG' || $key == 'laccessnotifydelivery' 
                                || $key == 'lglaccessnotifydelivery') ? true : false;

                $twoManDel = $key == 'twoManDel' ? true : false;
                $appDel = $key == 'aptDel' ? true : false;
                $twoManAptDel = $key == 'twoManAptDel' ? true : false;

                // check notify before delivery key with other features enable
                $isNotifydelivery = ( $key == 'notifydelivery' || $key == 'insidenotifydelivery' 
                                    || $key == 'lgnotifydelivery' || $key == 'lginsidenotifydelivery' 
                                    || $key == 'laccessnotifydelivery' || $key == 'lglaccessnotifydelivery') ? true : false;

                foreach ($value as $key2 => $data) {
                    $rate += $data['rate'];
                    $code = $data['code'];
                }
                $quotesArr[] = [
                    'code' => $code,
                    'rate' => $rate,
                    'title' => $this->getTitle(Functions::$ltlMultiTitle, $isLiftGate, true, '', [], [], $isInsideDelivery, $isLimitedAccess, false, $twoManDel, $appDel, $twoManAptDel, $isNotifydelivery, $this->isResi),
                ];
            } else {
                $quotesArr[] = reset($value);
            }
        }
        return $quotesArr;
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
    public function getAccessorialCode($lgOption = false, $insideDel = false, $resiPickup = '', $lgPickup = '', $laccess = false, $twoManDel = false, $appDel = false, $notifyDelivery = false, $isResi = false, $isAlwaysResidential = false)
    {
        $access = '';
        $isAlwaysResi = isset($this->isSameDayApi) && $this->isSameDayApi && $lgOption ? false : $this->alwaysResi;
        if ($this->residentialDlvry == '1' || $this->isResi || $isAlwaysResi || $isResi || $isAlwaysResidential) {
            $access .= '+R';
        }
        if (($lgOption || (isset($this->liftGate) && $this->liftGate == '1')) || (isset($this->RADforLiftgate) && $this->RADforLiftgate && $this->isResi)) {
            $access .= '+LG';
        }
        if($insideDel){
            $access .= '+ID';
        }
        if($laccess){
            $access .= '+LAD';
        }
        if($notifyDelivery){
            $access .= '+NBD';
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
    public function calculatePrice($data, $lgOption = false, $getCost = false, $isUpsLtl = false, $insideDel = false, $laccess = false, $twoManDel = false, $appDel = false, $notifyDelivery = false, $originKey = '', $items = [], $allOrigins = [], $quoteSettings = [])
    {
        $lgCost = $lgOption ? 0 : $this->getLiftGateCost($data, $getCost, $isUpsLtl);
        $IDCost = $insideDel ? 0 : $this->getInsideDeliveryCost($data); 
        $LADCost = $laccess ? 0 : $data['limitedAccessDeliveryFee'] ?? 0;
        $TMDCost = $twoManDel ? 0 : $data['surcharges']['twoManFee'] ?? 0;
        $APDCost = $appDel ? 0 : $data['surcharges']['appointmentFee'] ?? 0;
        $NBDCost = $notifyDelivery ? 0 : $this->getNotifyDeliveryCost($data, $isUpsLtl);
        $basePrice = str_replace(',', '', $data['totalNetCharge']['Amount']);
        $basePrice = (float)$basePrice;
        $basePrice = $basePrice - $lgCost - $LADCost - $IDCost - $TMDCost - $APDCost - $NBDCost;
        $productOriginMarkupFee = Functions::calProductOriginMarkupFee($basePrice, $this->originKey ?? $originKey, $this->items ?? $items, $this->allOrigins ?? $allOrigins);
        $basePrice = $this->calculateHandlingFee($basePrice, $quoteSettings);
        $basePrice = $basePrice + $productOriginMarkupFee;
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
        if (!(($this->isResi && isset($this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->quoteSettings['autoDetectedResidentialAddressesLfg']) ||
                (isset($this->quoteSettings['alwaysLiftGateDelivery']) && $this->quoteSettings['alwaysLiftGateDelivery'] == '1' && !(isset($this->quoteSettings['insideDelivery']) && $this->quoteSettings['insideDelivery']))) || $getCost) {
            if (isset($quotes['surcharges']) && isset($quotes['surcharges']['liftgateFee'])) {
                $lgCost = $quotes['surcharges']['liftgateFee'];
            }
            if ($isUpsLtl) {

                $surcharges = $quotes['surcharges'] ?? [];
                foreach ($surcharges as $surcharge) {
                    if (isset($surcharge['Type']['Code']) && $surcharge['Type']['Code'] === 'LIFTGATE') {
                        $lgCost = $surcharge['Factor']['Value'] ?? 0;
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
        if (!(isset($this->quoteSettings['always_quote_notify']) && $this->quoteSettings['always_quote_notify'])) {
            if (isset($quotes['surcharges']) && isset($quotes['surcharges']['notifyDeliveryFee'])) {
                $ndCost = (float)$quotes['surcharges']['notifyDeliveryFee'];
            }
            if (isset($quotes['surcharges']) && isset($quotes['surcharges']['notifyBeforeDeliveryFee'])) {
                $ndCost = (float)$quotes['surcharges']['notifyBeforeDeliveryFee'];
            }
        }

        if ($isUpsLtl) {

            $surcharges = $quotes['surcharges'] ?? [];
            foreach ($surcharges as $surcharge) {
                if (isset($surcharge['Type']['Code']) && $surcharge['Type']['Code'] === 'ADV_NOTF') {
                    $ndCost = $surcharge['Factor']['Value'] ?? 0;
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
    public function getTitle($serviceName, $lgOption = false, $from = false, $deliveryEstimate = '', $quoteSetting = [], $daysAndDate = [], $insideDel = false, $laccess = false, $laccessLG = false, $twoManDel = false, $appDel = false, $twoManAptDel = false, $notifyDelivery = false, $isResi = false)
    {
        // Here  Making service title
        if (!empty($quoteSetting)) {
            $this->quoteSettings = $quoteSetting;
        }
        $serviceTitle = $this->customLabel($serviceName);
        $deliveryEstimateLabel = $this->getDeliveryEstimates($daysAndDate);

        if ($this->isMultiShipment && $from == false) {
            return $serviceTitle . $deliveryEstimateLabel;
        }
        // Here  Making Delivery estimate title

        // Here  Making Access title
        $accessTitle = '';
        $isResi = $isResi ? $isResi : $this->isResi;

        // if ($lgOption === true || (isset($this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->quoteSettings['autoDetectedResidentialAddressesLfg'])) {
        //     if ($lgOption && $this->quoteSettings['alwaysLiftGateDelivery'] == '0') {
        //         $accessTitle = $this->isResi ? $this->resiLgLabel : $this->lgLabel;
        //     }
        //     if (isset($this->quoteSettings['alwaysLiftGateDelivery']) && $this->quoteSettings['alwaysLiftGateDelivery'] && $this->isResi) {
        //         $accessTitle = $this->resiLabel;
        //     }
        //     if (isset($this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->quoteSettings['autoDetectedResidentialAddressesLfg'] && $this->isResi) {
        //         $accessTitle = $this->resiLgLabel;
        //     }
        // } elseif ($this->isResi) {
        //     $accessTitle = $this->resiLabel;
        // } 
        // Make Inside Delivery Access Title
        // if(($lgOption && $insideDel) || $isInsideLiftGateDelivery){
        //     if(isset($this->quoteSettings['always_inside_delivery']) && $this->quoteSettings['always_inside_delivery'] == '0'){
        //         if(isset($this->quoteSettings['alwaysLiftGateDelivery']) && $this->quoteSettings['alwaysLiftGateDelivery'] == '0'){
        //             $accessTitle = $this->isResi ? Functions::$insideDelLiftGateResiLable : Functions::$insideDelLiftGateLable;
        //         } else{
        //             $accessTitle = $accessTitle ? Functions::$insideDelResiLable : Functions::$insideDelLable;
        //         }
                    
        //     } else{
        //         if(isset($this->quoteSettings['alwaysLiftGateDelivery']) && $this->quoteSettings['alwaysLiftGateDelivery'] == '0'){
        //             $accessTitle = $this->isResi ? Constant::RESI_LIFT_LABEL : Constant::LIFT_LABEL;
        //         }else{
        //             $accessTitle = $this->isResi ? Constant::RESI_LABEL : '';   
        //         }
        //     }
        // } else if ($insideDel) {
        //     if(isset($this->quoteSettings['always_inside_delivery']) && $this->quoteSettings['always_inside_delivery'] == '0'){
        //         $accessTitle = $accessTitle ? Functions::$insideDelResiLable : Functions::$insideDelLable;
        //     }else{
        //         $accessTitle = $this->isResi ? Constant::RESI_LABEL : '';
        //     }
        // }

        // if($laccess && $lgOption || $laccessLG){
        //     if ($this->quoteSettings['alwaysLiftGateDelivery'] == '1') {
        //         $accessTitle = $this->LADelLabel;
        //     } else {
        //         $accessTitle = $this->LimitedAccLGDelLabel;    
        //     }
        // } else if($laccess){
        //     $accessTitle = $this->LADelLabel;
        // }

        // if (($twoManDel && $appDel) || $twoManAptDel) {
        //     if ($this->quoteSettings['always_two_man_delivery'] == '1' && $this->quoteSettings['always_appointment_delivery'] == '1') {
        //         $accessTitle = $accessTitle;
        //     } else {
        //         $accessTitle = empty($accessTitle) ? Functions::$twoManAppDelLabel : $accessTitle . ' & two man & appointment delivery';
        //     }
        // } elseif($twoManDel) {
        //     if ($this->quoteSettings['always_two_man_delivery'] == '1') {
        //         $accessTitle = $accessTitle;
        //     } else {
        //         $accessTitle = empty($accessTitle) ? Functions::$twoManDeliveryLabel : $accessTitle . ' & two man delivery';
        //     }
        // } elseif($appDel) {
        //     if ($this->quoteSettings['always_appointment_delivery'] == '1') {
        //         $accessTitle = $accessTitle;
        //     } else {
        //         $accessTitle = empty($accessTitle) ? Functions::$appointmentDeliveryLabel : $accessTitle . ' & appointment delivery';
        //     }
        // }

        // Get Notify Before Delivery Access Title
        $accessTitle = Functions::getAccessTitle($this->quoteSettings, $isResi, $lgOption, $insideDel, $notifyDelivery, $laccess);

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
            $deliveryEstimates = !blank($date) ? " (Expected delivery by " . date('m-d-Y', strtotime($date)) . ")" : "";
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
                $accessTitle = $this->isResi ? Constant::RESI_LIFT_LABEL : Constant::LIFT_LABEL;
            }
            if (isset($this->quoteSettings['alwaysLiftGateDelivery']) && $this->quoteSettings['alwaysLiftGateDelivery'] && $this->isResi) {
                $accessTitle = Constant::RESI_LABEL; //$this->resiLabel;
            }
            if (isset($this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->quoteSettings['autoDetectedResidentialAddressesLfg'] && $this->isResi) {
                $accessTitle = Constant::RESI_LIFT_LABEL; //$this->resiLgLabel;
            }
        } elseif ($this->isResi) {
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
                'code' => $code, // or carrier name
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
                'code' => 'OWAR', // or carrier name
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
        $quickest = $quotes = [];
        if (isset($this->quoteSettings['quickest_service']) && $this->quoteSettings['quickest_service'] == 1 && isset($this->quoteSettings['method']) && $this->quoteSettings['method'] != 2 && !$this->isMultiShipment) {
            if (isset($arraySorting['quickest']['simple'])) {
                $minIndex = array_search(min($arraySorting['quickest']['simple']), $arraySorting['quickest']['simple']);
                $quickest = $services[$minIndex];
                unset($services);

                if (isset($quickest['simple']['title'])) {
                    $quickest['simple']['title'] = $quickest['simple']['titleQuickest'];
                }
                if (isset($quickest['liftgate']['title'])) {
                    $quickest['liftgate']['title'] = $quickest['liftgate']['titleQuickest'];
                }
                if (isset($quickest['notifydelivery']['title'])) {
                    $quickest['notifydelivery']['title'] = $quickest['notifydelivery']['titleQuickest'];
                }
                if (isset($quickest['lgnotifydelivery']['title'])) {
                    $quickest['lgnotifydelivery']['title'] = $quickest['lgnotifydelivery']['titleQuickest'];
                }
                $services[$minIndex] = $quickest;
                $quickest = $services;
            }
        }
        if (isset($this->quoteSettings['method']) && $this->quoteSettings['method'] != 0) {

            $quotes = $this->getGTZQuotes($servicesOriginal, $arraySorting, $lgQuotes, $notifyDelivery);
        }
        $quotes = array_merge($quotes, $quickest);
        foreach ($quotes as $key => $quote) {
            if (isset($quotes[$key]['simple']['titleQuickest'])) {
                unset($quotes[$key]['simple']['titleQuickest']);
            }
            if (isset($quotes[$key]['liftgate']['titleQuickest'])) {
                unset($quotes[$key]['liftgate']['titleQuickest']);
            }
            if (isset($quotes[$key]['notifydelivery']['titleQuickest'])) {
                unset($quotes[$key]['notifydelivery']['titleQuickest']);
            }
            if (isset($quotes[$key]['lgnotifydelivery']['titleQuickest'])) {
                unset($quotes[$key]['lgnotifydelivery']['titleQuickest']);
            }
        }

        return $quotes;
    }

    public function getGTZQuotes($services, $arraySorting, $lgQuotes, $notifyDelivery = false)
    {
        if (empty($arraySorting) || empty($services)) {
            return [];
        }
        asort($arraySorting['simple']);
        $this->quoteSettings['method'] = $this->quoteSettings['method'] ?? 1;
        if ($this->quoteSettings['method'] == 2 && $this->isMultiShipment == false) { //Cheapest method
            $options = (int)$this->quoteSettings['number_of_options'] ?? 1;
        } elseif ($this->quoteSettings['method'] == 3) { //Average rate
            $options = (int)$this->quoteSettings['number_of_options'];
        } else {
            $options = 1;
        }
        if ($lgQuotes && $notifyDelivery) {
            $sliced = array_slice($arraySorting['lgnotifydelivery'], 0, $options, true);
        } else if ($notifyDelivery) {
            $sliced = array_slice($arraySorting['notifydelivery'], 0, $options, true);
        } else if ($lgQuotes) {
            $sliced = array_slice($arraySorting['liftgate'], 0, $options, true);
        } else {
            $sliced = array_slice($arraySorting['simple'], 0, $options, true);
        }

        if ($this->quoteSettings['method'] == 3) {
            return $this->averageRattingMethod($arraySorting, $options, $lgQuotes);
        }
        if ($lgQuotes && $options === 1) {

            $slicedLg = array_slice($arraySorting['liftgate'], 0, $options, true);

            $resp = array_intersect_key($services, $sliced);
            $respLg = array_intersect_key($services, $slicedLg);

            $resp[array_key_first($resp)]['liftgate'] = $respLg[array_key_first($respLg)]['liftgate'];
        } else {
            $resp = array_intersect_key($services, $sliced);
        }

        return $resp;
    }

    public function getCompiledQuotes($services, $arraySorting, $lgQuotes, $resiPickup = '', $lgPickup = '', $insideDelivery = false, $notifyDelivery = false)
    {

        if (empty($arraySorting) || empty($services)) {
            return [];
        }
        asort($arraySorting['simple']);
        $this->quoteSettings['method'] = $this->quoteSettings['method'] ?? 1;
        if ($this->quoteSettings['method'] == 2 && $this->isMultiShipment == false) { //Cheapest method
            $options = (int)$this->quoteSettings['number_of_options'] ?? 1;
        } elseif ($this->quoteSettings['method'] == 3) { //Average rate
            $options = (int)$this->quoteSettings['number_of_options'];
        } else {
            $options = 1;
        }
        $sliced = array_slice($arraySorting['simple'], 0, $options, true);
        
        if ($this->quoteSettings['method'] == 3) {
            $services = array_values($services);
            if(isset($services[0]['Truckload']) && !empty($services[0]['Truckload'])){
                $AVR = $this->averageRattingMethod($arraySorting, $options, $lgQuotes);

                if(isset($this->isFQChr) && $this->isFQChr){
                    $title = $this->quoteSettings['truck_label_as'] ?? Functions::$simpleLTLTitle . ' - Truckload Service';
                }else {
                    $title = ($this->quoteSettings['label_as'] ?? Functions::$simpleLTLTitle) . ' - Truckload Service';
                }
                $averageRateService[0]['Truckload'] = [
                    'title' => $title,
                    'code' => $AVR[0]['simple']['code'] . '+TL',
                    'rate' => $AVR[0]['simple']['rate'],
                ];
                return $averageRateService;
            }

            return $this->averageRattingMethod($arraySorting, $options, $lgQuotes, $resiPickup, $lgPickup, $insideDelivery, $notifyDelivery);
        }

        $resp = array_intersect_key($services, $sliced);
        return $resp;
    }

    public function getCompiledQuotesTQL($services, $arraySorting, $lgQuotes, $notifyDelivery)
    {
        if (empty($arraySorting) || empty($services)) {
            return [];
        }
        asort($arraySorting['simple']);
        $this->quoteSettings['method'] = $this->quoteSettings['method'] ?? 1;
        if ($this->quoteSettings['method'] == 2 && $this->isMultiShipment == false) { //Cheapest method
            $options = (int)$this->quoteSettings['number_of_options'] ?? 1;
        } elseif ($this->quoteSettings['method'] == 3) { //Average rate
            $options = (int)$this->quoteSettings['number_of_options'];
        } else {
            $options = 1;
        }

        $sliced = array_slice($arraySorting['simple'], 0, $options, true);

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
    public function averageRattingMethod($ratesArray, $options, $lgQuotes, $resiPickup = '', $lgPickup = '', $insideDelivery = false, $notifyDelivery = false)
    {
        $averageRateService = [];
        $prefix = $this->isGTZCerasis ? 'AVG' : 'AVGwweltl';
        $prefix = isset($this->isFQ) && $this->isFQ ? 'AVGfqltl' : $prefix;
        $prefix = isset($this->isFQChr) && $this->isFQChr ? 'AVGfqchrltl' : $prefix;
        $prefix = isset($this->EchoLogistics) && $this->EchoLogistics ? 'AVGecholtl' : $prefix;
        $prefix = isset($this->TQL) && $this->TQL ? 'AVGTqlltl' : $prefix;
        $serviceName = $this->customLabel(Functions::$simpleLTLTitle);

        foreach($ratesArray as $key => $rates){
            $lgQuotes = $key == 'liftgate' || $key == 'insideLiftGateDelivery' || $key == 'lgnotifydelivery' || $key == 'lginsidenotifydelivery' ?? false;
            $insideDelivery = $key == 'insideDelivery' || $key == 'insideLiftGateDelivery' || $key == 'insidenotifydelivery' || $key == 'lginsidenotifydelivery' ?? false;
            $notifyDelivery = $key == 'notifydelivery' || $key == 'lgnotifydelivery' || $key == 'insidenotifydelivery' || $key == 'lginsidenotifydelivery' ?? false;
 
            if(!empty($rates)){
                asort($ratesArray[$key]);
                $sliced = array_slice($ratesArray[$key], 0, $options, true);
                $price = $this->getAveragePrice($sliced, $options);
                $averageRateService[0][$key] = [
                    'title' => $this->getTitle($serviceName, $lgQuotes, false, '', [], [], $insideDelivery, false, false, false, false, false, $notifyDelivery),
                    'code' => $prefix . $this->getAccessorialCode($lgQuotes, $insideDelivery, $resiPickup, $lgPickup, false, false, false, $notifyDelivery),
                    'rate' => $price,
                ];
            }
        }

        return $averageRateService;
    }

    public function averageRattingMethodTQL($ratesArray, $options, $lgQuotes, $notifyDelivery)
    {
        if (empty($ratesArray)) {
            return [];
        }
        foreach ($ratesArray as $key => $data) {
            if ($data['serviceLevel'] === 'Standard') {

                $ratesArray[$key]['carrier'] = $this->quoteSettings['standard'] ?? 'Freight';

            } elseif ($data['serviceLevel'] == 'Guaranteed 5 PM' || $data['serviceLevel'] == 'Guaranteed 12 PM') {

                $ratesArray[$key]['carrier'] = $this->quoteSettings['guaranteed'] ?? 'Freight';

            }
        }

        $simplePrice = $this->getAveragePriceTQL($ratesArray, $options);
        $prefix = $this->isGTZCerasis ? 'AVG' : 'AVGtqlltl';
        $prefix = isset($this->isFQ) && $this->isFQ ? 'AVGfqltl' : $prefix;
        $prefix = isset($this->TQL) && $this->TQL ? 'AVGTqlltl' : $prefix;
        $serviceName = $ratesArray[$key]['carrier'] ?? 'Freight';
        $averageRateService[0]['simple'] = [
            'title' => $this->getTitle($serviceName, false), //$serviceName,
            'code' => $prefix . $this->getAccessorialCode(),
            'rate' => $simplePrice,
        ];
        if ($lgQuotes) {
            asort($ratesArray);
            $lfgPrice = $this->getAveragePriceTQL($ratesArray, $options);
            $averageRateService[0]['liftgate'] = [
                'title' => $this->getTitle($serviceName, $lgQuotes),
                'code' => $prefix . $this->getAccessorialCode($lgQuotes),
                'rate' => $lfgPrice,
            ];
        }
        if ($notifyDelivery) {
            asort($ratesArray);
            $lfgPrice = $this->getAveragePriceTQL($ratesArray, $options);
            $averageRateService[0]['notifydelivery'] = [
                'title' => $this->getTitle($serviceName, false, false, '', [], [], false, false, false, false, false, false, $notifyDelivery),
                'code' => $prefix . $this->getAccessorialCode(false, false, '', '', false, false, false, $notifyDelivery),
                'rate' => $lfgPrice,
            ];
        }
        if ($notifyDelivery && $lgQuotes) {
            asort($ratesArray);
            $lfgPrice = $this->getAveragePriceTQL($ratesArray, $options);
            $averageRateService[0]['lgnotifydelivery'] = [
                'title' => $this->getTitle($serviceName, $lgQuotes, false, '', [], [], false, false, false, false, false, false, $notifyDelivery),
                'code' => $prefix . $this->getAccessorialCode($lgQuotes, false, '', '', false, false, false, $notifyDelivery),
                'rate' => $lfgPrice,
            ];
        }

        return $averageRateService;
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
    public function arrangeOwnFreight($finalQuotes)
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

    public function arrangeHATFreight($finalQuotes, $HAT, $lableAs = '')
    {
        if (empty($HAT)) {
            return $finalQuotes;
        }
        $amount = 0;
        foreach ($HAT as $data) {
            $amount += $data['totalNetCharge']['Amount'];
        }
        $hatQuotes[] = [
            'code' => $HAT[0]['serviceType'],
            'title' => $lableAs,
            'rate' => $amount,
        ];
        return array_merge($finalQuotes, $hatQuotes);
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
            'title' => 'Free',
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
