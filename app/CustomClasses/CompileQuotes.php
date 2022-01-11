<?php

namespace App\CustomClasses;

use App\Constants\Constant;
use App\CustomClasses\UpsSmall\QuotesResults as upsSmallQuotesResults;
use App\CustomClasses\Fedex\ltl\QuotesResults as fedexLtlQuotesResults;
use App\CustomClasses\Fedex\small\QuotesResults as fedexSmallQuotesResults;
use App\CustomClasses\XPO\ltl\QuotesResults as xpoLtlQuotesResults;
use App\CustomClasses\GTZ\ltl\QuotesResults as globalTranzQuotesResults;
use App\CustomClasses\RL\ltl\QuotesResults as rnlLtlQuotesResults;
use App\CustomClasses\WWESMALL\WweSmallQuoteResults;
use App\CustomClasses\Shipping;
use App\Models\Locations;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\RADController;
use Carbon\Carbon;

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
            return json_decode($location->additionals, true);
            /*     $whFactory = $this->warehouseFactory->create();
                 $dsCollection = $whFactory->getCollection()
                     ->addFilter('location', ['eq' => $location])
                     ->addFilter('warehouse_id', ['eq' => $warehouseId]);
                 return $this->purifyCollectionData($dsCollection);*/
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
        /*if (empty($quotesArray)) {
            return [];
        }
            if (count($allOrigins) > 1) {
                return $quotesArray;
            }*/
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
            /* dd(2,$inStoreLd);*/
            if (isset($inStoreLd['inStorePickup']['status']) && $inStoreLd['inStorePickup']['status'] == 1) {
                $title = $warehouseData['inStoreTitle'] ?? '';

                if (isset($inStoreLd['totalDistance']) && $inStoreLd['totalDistance'] > 0) {
                    $title .= " | " . $inStoreLd['totalDistance'] . " away ";
                }
                $title .= " | " . $this->getShortStreetAddress($array['address']) . " " . $array['senderCity'] . ", " . $array['senderState'] . ", " . $array['senderZip'];

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
        $whCollection = $this->fetchWarehouseWithID($data['location'], $data['locationId']);
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
        // dd($isRadEnabled, $resi);
        if (!empty($isRadEnabled) && $isRadEnabled['is_enabled'] && $isRadEnabled['is_suspend'] !== 1) {
            $isRadSuspend = $isRadEnabled['is_suspend'] == 0 ? 'no' : '';//$this->getConfigData("resaddressdetection/suspend/value");
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
    public function newGetQuotesResults($quotes, $connectionSettings, $allOrigins, $isHazmat, $smalLtlHazmat, $hazmatAllItems, $residential, $freeRNL = false, $destination)
    {
        $this->residential = $residential;
        if ($quotes == null) {
            return [];
        }
        $quotesRes = [];
        $quotesTemp = [];
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
            }
        }

        // Removing duplicate respone of quotes
        $quotesRes = $this->handleMultiCarrResp($quotesTemp);
        $quotesRes = array_map("unserialize", array_unique(array_map("serialize", $quotesRes)));

        return $quotesRes;

    }

    private function handleMultiCarrResp($quotes)
    {
        $newQuotes = [];
        $quotes = array_filter($quotes);
        $ownArrangement = [];
        $shipping = new Shipping();
        /* $hasLtlQuotes = false;
         foreach ($quotes as $car => $quote) {
             if ($shipping->isLtlCarrier($car)) {
                 $hasLtlQuotes = true;
                 break;
             }
         }*/
        if ($this->isMultiShipment) {
            $newQuotes['checkoutQuotes'] = $newQuotes['multiShipmentQuotes'] = [];
            foreach ($quotes as $car => $quote) {
                if (isset($quote['checkoutQuotes'])) {
                    foreach ($quote['checkoutQuotes'] as $key => $quot) {
                        /*$position = !empty($newQuotes) ? array_search($quot['title'], array_column($newQuotes['checkoutQuotes'], 'title')) : false;*/
                        if ($quot['code'] !== 'own_arrangement') {
                            /**
                             * following code taking the cheapest rate for same title but now we have to show quotes
                             * on checkout page with duplicate titles(display name)
                             */
                            /*if ($position !== false) {
                               if ($quot['rate'] < $newQuotes['checkoutQuotes'][$position]['rate']) {
                                   $newQuotes['checkoutQuotes'][$position] = $quot;
                                   $newQuotes['multiShipmentQuotes'][$position] = $quote['multiShipmentQuotes'];
                                }
                            } else {
                                array_push($newQuotes['checkoutQuotes'], $quot);
                                array_push($newQuotes['multiShipmentQuotes'], $quote['multiShipmentQuotes']);
                            }*/

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
        } else {
            foreach ($quotes as $car => $quote) {
                /*$allow = false;
                if($hasLtlQuotes){
                    if(!$shipping->isSmallCarrier($car)){
                        $allow = true;
                    }
                }else{
                    $allow = true;
                }*/
                //if ($allow) {
                foreach ($quote as $key => $quot) {
                    /*$position = array_search($quot['title'], array_column($newQuotes, 'title'));
                    if ($position !== false) {
                        if ($quot['rate'] < $newQuotes[$position]['rate']) {
                            $newQuotes[$position] = $quot;
                        }
                    } else {
                        $newQuotes[] = $quot;
                    }*/
                    $newQuotes[] = $quot;
                }
                //}
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

            if (isset($quote['severity'])) {
                continue;
            }

            if ($count == 0) { //To be checked only once
                $isRad = $quote['autoResidentialsStatus'] ?? '';
                $inStoreLdData = $quote['InstorPickupLocalDelivery'] ?? false;
                unset($quote['InstorPickupLocalDelivery']);
                $lgQuotes =
                    (isset($this->quoteSettings['alwaysLiftGateDelivery']) && $this->quoteSettings['alwaysLiftGateDelivery']) ||
                    (isset($this->quoteSettings['offerLiftGateDelivery']) && $this->quoteSettings['offerLiftGateDelivery']);
                if (!$lgQuotes) {
                    $lgQuotes = ((isset($this->quoteSettings['autoDetectedResidentialAddresses']) && $this->quoteSettings['autoDetectedResidentialAddresses']) &&
                            (isset($this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->quoteSettings['autoDetectedResidentialAddressesLfg'])) && $this->isResi;
                }
                $resiPickup = isset($this->quoteSettings['residentialPickup']) && $this->quoteSettings['residentialPickup'] ? '+pu' : '';
            }
            $originQuotes = [];
            $arraySorting = [];
            if (isset($quote['q'])) {
                if (isset($quote['hazardousStatus'])) {
                    $hazShipmentArr[$origin] = $quote['hazardousStatus'] == 'y' ? 'Y' : 'N';
                }
                foreach ($quote['q'] as $key => $data) {
                    if (isset($data['serviceType']) && in_array($data['serviceType'], $allConfigServices) && isset($data['GuaranteedDaysToDelivery']) && $data['GuaranteedDaysToDelivery'] != 'Y') {
                        $access = $this->getAccessorialCode() . $resiPickup;
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
                        if ($lgQuotes) {
                            $lgAccess = 'wweltl' . $this->getAccessorialCode(true) . $resiPickup;
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
                'checkoutQuotes' => $this->arrangeOwnFreight($allQuotes),
                'multiShipmentQuotes' => $multiShipmentQuotes
            ];
            return $resp;
        }
        return $this->arrangeOwnFreight($allQuotes);
    }

    public function forceChangeTitle($allQuotes)
    {
        if (!empty($allQuotes)) {
            foreach ($allQuotes as $key => $quote) {
                $title = explode('(', $quote['title'])[0];
                $title = explode('w/', $title);
                $title[0] = 'Freight';
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
        $res = $this->upsSmallQuotesResults->compileQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $this->isResi, $access, $this->isMultiShipment);

        if (!$this->isMultiShipment) {
            $this->isMultiShipment = $res['isMultiShipment'];
        }
        return $res['resp'];
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
        $res = $this->fedexSmallQuotesResults->compileQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $this->isResi, $access, $this->isMultiShipment, $destination);
        if (!$this->isMultiShipment) {
            $this->isMultiShipment = $res['isMultiShipment'] ?? false;
        }
        return $res['resp'];
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

            if (isset($quote['severity'])) {
                continue;
            }

            if ($count == 0) { //To be checked only once
                $isRad = $quote['autoResidentialsStatus'] ?? '';
                $inStoreLdData = $quote['InstorPickupLocalDelivery'] ?? false;
                unset($quote['InstorPickupLocalDelivery']);

                $lgQuotes =
                    (isset($this->quoteSettings['alwaysLiftGateDelivery']) && $this->quoteSettings['alwaysLiftGateDelivery']) ||
                    (isset($this->quoteSettings['offerLiftGateDelivery']) && $this->quoteSettings['offerLiftGateDelivery']);
                if (!$lgQuotes) {
                    $lgQuotes = ((isset($this->quoteSettings['autoDetectedResidentialAddresses']) && $this->quoteSettings['autoDetectedResidentialAddresses']) &&
                            (isset($this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->quoteSettings['autoDetectedResidentialAddressesLfg'])) && $this->isResi;

                }

                $notify = (isset($this->quoteSettings['always_quote_notify']) && $this->quoteSettings['always_quote_notify']) ||
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
                        $price = $this->GTZLtlQuotesResults->calculatePrice($data, $this->quoteSettings);
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
                            $price = $this->GTZLtlQuotesResults->calculatePrice($data, $this->quoteSettings, true);
                            $title = $this->getGTitle($data['serviceDesc'], true, false, false, false, $data['totalTransitTimeInDays'], $this->quoteSettings, false, $dateAndDays);
                            $titleQuickest = $this->getGTitle($data['serviceDesc'], true, false, false, false, $data['totalTransitTimeInDays'], $this->quoteSettings, true, $dateAndDays);
                            $arraySorting['liftgate'][$key] = $price;
                            $arraySorting['quickest']['liftgate'][$key] = $data['totalTransitTimeInDays'];
                            $originQuotes[$key]['liftgate']['code'] = $data['serviceType'] . $access;
                            $originQuotes[$key]['liftgate']['rate'] = $price;
                            $originQuotes[$key]['liftgate']['title'] = $title;
                            $originQuotes[$key]['liftgate']['titleQuickest'] = $titleQuickest;
                        }
                        /*if ($notify) {
                            $access = $preCode.$this->GTZLtlQuotesResults->getAccessorialCode($isResi,false, true);
                            $price = $this->GTZLtlQuotesResults->calculatePrice($data, $this->quoteSettings,  false, true);
                            $title = $this->getGTitle($data['serviceDesc'], false, true, false, false, $data['totalTransitTimeInDays'], $this->quoteSettings);
                            $arraySorting['notify'][$key] = $price;
                            $originQuotes[$key]['notify']['code'] = $data['serviceType'] . $access;
                            $originQuotes[$key]['notify']['rate'] = $price;
                            $originQuotes[$key]['notify']['title'] = $title;
                        }
                        if($laccess){
                            $access = $preCode.$this->GTZLtlQuotesResults->getAccessorialCode($isResi,false, false, true);
                            $price = $this->GTZLtlQuotesResults->calculatePrice($data, $this->quoteSettings, false, false, true);
                            $title = $this->getGTitle($data['serviceDesc'], false, false, true, true, $data['totalTransitTimeInDays'], $this->quoteSettings);
                            $arraySorting['lacsess'][$key] = $price;
                            $originQuotes[$key]['lacsess']['code'] = $data['serviceType'] . $access;
                            $originQuotes[$key]['lacsess']['rate'] = $price;
                            $originQuotes[$key]['lacsess']['title'] = $title;
                        }
                        if($lgQuotes && $notify){
                            $access = $preCode.$this->GTZLtlQuotesResults->getAccessorialCode($isResi,true, true, false);
                            $price = $this->GTZLtlQuotesResults->calculatePrice($data, $this->quoteSettings, true, true, false);
                            $title = $this->getGTitle($data['serviceDesc'], true, true, false, true, $data['totalTransitTimeInDays'], $this->quoteSettings);
                            $arraySorting['notify_liftgate'][$key] = $price;
                            $originQuotes[$key]['notify_liftgate']['code'] = $data['serviceType'] . $access;
                            $originQuotes[$key]['notify_liftgate']['rate'] = $price;
                            $originQuotes[$key]['notify_liftgate']['title'] = $title;
                        }*/
                    }
                }
            }
            if (!$this->isMultiShipment) {
                $compiledQuotes = $this->getGTZCompiledQuotes($originQuotes, $arraySorting, $lgQuotes);
            } else {
                if (isset($this->quoteSettings['quickest_service']) && $this->quoteSettings['quickest_service'] == 1 && isset($this->quoteSettings['method']) && $this->quoteSettings['method'] != 2) {
                    $arraySorting = $arraySorting['quickest'] ?? $arraySorting;
                }
                $compiledQuotes = $this->getCompiledQuotes($originQuotes, $arraySorting, $lgQuotes);
            }
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
                'multiShipmentQuotes' => $multiShipmentQuotes
            ];
            return $resp;
        }

        return $allQuotes;
    }


    public function compileCerasisLtlQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential)
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
        }
        if (!$this->isMultiShipment) {
            $this->isMultiShipment = is_countable($shipments) && $numberOfShipments > 1;
        }
        foreach ($shipments as $origin => $quote) {

            if (isset($quote['severity'])) {
                continue;
            }

            if ($count == 0) { //To be checked only once
                $isRad = $quote['autoResidentialsStatus'] ?? '';
                $inStoreLdData = $quote['InstorPickupLocalDelivery'] ?? false;
                unset($quote['InstorPickupLocalDelivery']);

                $lgQuotes =
                    (isset($this->quoteSettings['alwaysLiftGateDelivery']) && $this->quoteSettings['alwaysLiftGateDelivery']) ||
                    (isset($this->quoteSettings['offerLiftGateDelivery']) && $this->quoteSettings['offerLiftGateDelivery']);
                if (!$lgQuotes) {
                    $lgQuotes = ((isset($this->quoteSettings['autoDetectedResidentialAddresses']) && $this->quoteSettings['autoDetectedResidentialAddresses']) &&
                            (isset($this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->quoteSettings['autoDetectedResidentialAddressesLfg'])) && $this->isResi;

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
            $preCode = 'cerasisltl';
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
                        $dateAndDays = ['deliveryDate' => $date, 'totalTransitTimeInDays' => $days];
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
                'multiShipmentQuotes' => $multiShipmentQuotes
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
        $shipments = $fedexLtl->formateQuoteBeforeCompile($shipments);
        $this->quoteSettings = $connectionSettings['fedex-ltl']['quote_settings'] ?? [];
        $allConfigServices = [];
        if (isset($this->quoteSettings['fedex_freight_economy']) && $this->quoteSettings['fedex_freight_economy']) {
            array_push($allConfigServices, 'FEDEX_FREIGHT_ECONOMY');
        }
        if (isset($this->quoteSettings['fedex_freight_priority']) && $this->quoteSettings['fedex_freight_priority']) {
            array_push($allConfigServices, 'FEDEX_FREIGHT_PRIORITY');
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
            $this->isMultiShipment = is_countable($shipments) && $numberOfShipments > 1;;
        }
        $lableAs = $this->quoteSettings['label_as'] ?? '';
        foreach ($shipments as $origin => $quote) {

            if (isset($quote['severity'])) {
                continue;
            }

            if ($count == 0) { //To be checked only once
                $isRad = $quote['autoResidentialsStatus'] ?? '';
                $inStoreLdData = $quote['InstorPickupLocalDelivery'] ?? false;
                unset($quote['InstorPickupLocalDelivery']);
                $lgQuotes =
                    (isset($this->quoteSettings['alwaysLiftGateDelivery']) && $this->quoteSettings['alwaysLiftGateDelivery']) ||
                    (isset($this->quoteSettings['offerLiftGateDelivery']) && $this->quoteSettings['offerLiftGateDelivery']);
                if (!$lgQuotes) {
                    $lgQuotes = ((isset($this->quoteSettings['autoDetectedResidentialAddresses']) && $this->quoteSettings['autoDetectedResidentialAddresses']) &&
                            (isset($this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->quoteSettings['autoDetectedResidentialAddressesLfg'])) && $this->isResi;
                }
            }
            $originQuotes = [];
            $arraySorting = [];

            if (isset($quote['q'])) {
                if (isset($quote['hazardousStatus'])) {
                    $hazShipmentArr[$origin] = $quote['hazardousStatus'] == 'y' ? 'Y' : 'N';
                }
                foreach ($quote['q'] as $key => $data) {
                    if (isset($data['serviceType']) && in_array($data['serviceType'], $allConfigServices)) {
                        $access = $this->getAccessorialCode();
                        $price = $this->calculatePrice($data);
                        if (isset($this->quoteSettings['label_as']) && isset($data['serviceType'])) {
                            $EcoPrio = $data['serviceType'] === 'FEDEX_FREIGHT_ECONOMY' ? ' Economy' : ' Priority';
                            $this->quoteSettings['label_as'] = $lableAs . $EcoPrio;
                        }
                        /*
                         * Date 01-07-22
                         * Adding Functionality of Delivery Estimate Options
                         * */
                        $date = $data['deliveryTimestamp'] ?? null;
                        $days = $data['totalTransitTimeInDays'] ?? null;
                        $dateAndDays = ['deliveryDate' => $date, 'totalTransitTimeInDays' => $days];
                        $title = $this->getTitle($data['serviceDesc'], false, false, $data['transitTime'], [], $dateAndDays);

                        $arraySorting['simple'][$key] = $price;
                        $originQuotes[$key]['simple']['code'] = 'fedexltl' . $access;
                        $originQuotes[$key]['simple']['rate'] = $price;
                        $originQuotes[$key]['simple']['title'] = $title;
                        if ($lgQuotes) {
                            $lgAccess = $this->getAccessorialCode(true);
                            $lgPrice = $this->calculatePrice($data, true);
                            $lgTitle = $this->getTitle($data['serviceDesc'], true, false, $data['transitTime']);
                            $arraySorting['liftgate'][$key] = $lgPrice;
                            $originQuotes[$key]['liftgate']['code'] = 'fedexltl' . $lgAccess;
                            $originQuotes[$key]['liftgate']['rate'] = $lgPrice;
                            $originQuotes[$key]['liftgate']['title'] = $lgTitle;
                        }
                    }
                }
            }
            $compiledQuotes = $fedexLtl->getCompiledQuotes($originQuotes, $arraySorting, $lgQuotes, $this->isMultiShipment);

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
                'checkoutQuotes' => $this->arrangeOwnFreight($allQuotes),
                'multiShipmentQuotes' => $multiShipmentQuotes
            ];
            return $resp;
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
        //print_r($shipments); exit;
        $shipments = $xpoLtl->formateQuoteBeforeCompile($shipments);
//print_r($shipments); exit;
        $this->quoteSettings = $connectionSettings['xpo-ltl']['quote_settings'] ?? [];

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
            $this->isMultiShipment = is_countable($shipments) && $numberOfShipments > 1;;
        }
        $lableAs = $this->quoteSettings['label_as'] ?? '';
        foreach ($shipments as $origin => $quote) {

            if (isset($quote['severity'])) {
                continue;
            }

            if ($count == 0) { //To be checked only once
                $isRad = $quote['autoResidentialsStatus'] ?? '';
                $inStoreLdData = $quote['InstorPickupLocalDelivery'] ?? false;
                unset($quote['InstorPickupLocalDelivery']);
                $lgQuotes =
                    (isset($this->quoteSettings['alwaysLiftGateDelivery']) && $this->quoteSettings['alwaysLiftGateDelivery']) ||
                    (isset($this->quoteSettings['offerLiftGateDelivery']) && $this->quoteSettings['offerLiftGateDelivery']);
                if (!$lgQuotes) {
                    $lgQuotes = ((isset($this->quoteSettings['autoDetectedResidentialAddresses']) && $this->quoteSettings['autoDetectedResidentialAddresses']) &&
                            (isset($this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->quoteSettings['autoDetectedResidentialAddressesLfg'])) && $this->isResi;
                }
            }
            $originQuotes = [];
            $arraySorting = [];

            if (isset($quote['q'])) {
                if (isset($quote['hazardousStatus'])) {
                    $hazShipmentArr[$origin] = $quote['hazardousStatus'] == 'y' ? 'Y' : 'N';
                }
                foreach ($quote['q'] as $key => $data) {
                    $access = $this->getAccessorialCode();
                    $price = $this->calculatePrice($data);
                    /*
                                       * Date 01-07-22
                                       * Adding Functionality of Delivery Estimate Options
                                       * */
                    $date = $data['deliveryDate'] ?? null;
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
                        $lgTitle = $this->getTitle($data['serviceDesc'], true, false, $data['totalTransitTimeInDays']);
                        $arraySorting['liftgate'][$key] = $lgPrice;
                        $originQuotes[$key]['liftgate']['code'] = 'xpoltl' . $lgAccess;
                        $originQuotes[$key]['liftgate']['rate'] = $lgPrice;
                        $originQuotes[$key]['liftgate']['title'] = $lgTitle;
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

    function compileRNLLtlQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential, $freeRNL)
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
//print_r($shipments); exit;
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
            $this->isMultiShipment = is_countable($shipments) && $numberOfShipments > 1;;
        }
        $lableAs = $this->quoteSettings['label_as'] ?? '';
        $preAccess = 'rnlltl';
        $HAT = [];
        foreach ($shipments as $origin => $quote) {
            if (isset($quote['severity'])) {
                continue;
            }
            if ($count == 0) { //To be checked only once
                $isRad = $quote['autoResidentialsStatus'] ?? '';
                $inStoreLdData = $quote['InstorPickupLocalDelivery'] ?? false;
                unset($quote['InstorPickupLocalDelivery']);
                $lgQuotes =
                    (isset($this->quoteSettings['alwaysLiftGateDelivery']) && $this->quoteSettings['alwaysLiftGateDelivery']) ||
                    (isset($this->quoteSettings['offerLiftGateDelivery']) && $this->quoteSettings['offerLiftGateDelivery']);
                if (!$lgQuotes) {
                    $lgQuotes = ((isset($this->quoteSettings['autoDetectedResidentialAddresses']) && $this->quoteSettings['autoDetectedResidentialAddresses']) &&
                            (isset($this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->quoteSettings['autoDetectedResidentialAddressesLfg'])) && $this->isResi;
                }
                $ID = (isset($this->quoteSettings['offer_inside_delivery']) && $this->quoteSettings['offer_inside_delivery']);

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
                    $this->quoteSettings['label_as'] = $lableAs . ' ' . $data['serviceDesc'];
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
                        $lgTitle = $this->getTitle($data['serviceDesc'], true, false, $data['totalTransitTimeInDays']);
                        $arraySorting['liftgate'][$key] = $lgPrice;
                        $originQuotes[$key]['liftgate']['code'] = $preAccess . $lgAccess;
                        $originQuotes[$key]['liftgate']['rate'] = $lgPrice;
                        $originQuotes[$key]['liftgate']['title'] = $lgTitle;
                    }
                    /*if ($ID && !$isHat) {
                        $access = $preCode.$this->GTZLtlQuotesResults->getAccessorialCode($isResi,false, true);
                        $price = $this->GTZLtlQuotesResults->calculatePrice($data, $this->quoteSettings,  false, true);
                        $title = $this->getGTitle($data['serviceDesc'], false, true, false, false, $data['totalTransitTimeInDays'], $this->quoteSettings);
                        $arraySorting['notify'][$key] = $price;
                        $originQuotes[$key]['notify']['code'] = $data['serviceType'] . $access;
                        $originQuotes[$key]['notify']['rate'] = $price;
                        $originQuotes[$key]['notify']['title'] = $title;
                    }*/
                }
            }
            //dd($HAT);
            $compiledQuotes = $originQuotes;
            if ($compiledQuotes !== null && !empty($compiledQuotes)) {
                if (count($compiledQuotes) > 1) {
                    foreach ($compiledQuotes as $k => $service) {
                        $allQuotes['simple'][] = $service['simple'];
                        $multiShipmentQuotes['simple'][$origin] = $service['simple'];
                        $lgQuotes && isset($service['liftgate']) ? $allQuotes['liftgate'][] = $service['liftgate'] : null;
                        $lgQuotes && isset($service['liftgate']) ? $multiShipmentQuotes['liftgate'][$origin] = $service['liftgate'] : null;
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
            if (!empty($HAT)) {
                $allQuotes = $this->forceChangeTitle($allQuotes);
                $hatLabel = explode('|', $HAT[0]['serviceDesc']);
                unset($hatLabel[0]);
                $lableAs = 'Freight |' . implode('|', $hatLabel);
                $resp = [
                    'checkoutQuotes' => $this->arrangeHATFreight($allQuotes, $HAT, $lableAs),
                    'multiShipmentQuotes' => $this->arrangeHATMulti($multiShipmentQuotes, $HAT)
                ];
            } else {
                $allQuotes = $this->forceChangeTitle($allQuotes);
                $resp = [
                    'checkoutQuotes' => $this->arrangeHATFreight($allQuotes, $HAT, 'Freight'),
                    'multiShipmentQuotes' => $multiShipmentQuotes
                ];
            }
            return $resp;
        }
        if (!empty($HAT)) {
            $lableAs .= ' ' . $HAT[0]['serviceDesc'];
            return $this->arrangeHATFreight($allQuotes, $HAT, $lableAs);
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
            if (!isset($ship['severity']) && !in_array($key, ['air', 'ground'])) {
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

            if (isset($quote['severity'])) {
                continue;
            }
            if ($count == 0) { //To be checked only once
                // $this->getAutoResidentialTitle('');
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


                    $title = $this->wweSmallQuoteRes->getServiceTitle($data['serviceDesc'], $data['deliveryTimestamp'], $data['serviceType'], $this->quoteSettings, $this->isResi);

                    $price = (float)str_replace(',', '', $price);
                    $originQuotes[$shipmentCount]['shipment'][$key]['simple']['code'] = 'parcel_12wwe' . $data['serviceType'] . $access;
                    $originQuotes[$shipmentCount]['shipment'][$key]['simple']['rate'] = $price;
                    $originQuotes[$shipmentCount]['shipment'][$key]['simple']['title'] = $title;
                    $multiShipmentQuotes['simple'][$origin] = $originQuotes[$shipmentCount]['shipment'][$key]['simple'];
                }
            }
            $shipmentCount++;
        }
        //  dd($originQuotes,'dds',$this->isMultiShipment);
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
                $originQuotesMulti[0]['title'] = $this->isResi ? 'Shipping' . Constant::RESI_LABEL : 'Shipping';
            }
            $resp = [
                'checkoutQuotes' => $originQuotesMulti,
                'multiShipmentQuotes' => $multiShipmentQuotes,
            ];
            return $resp;
        }
        // Doing For SIngle Shipment
        //dd($originQuotes);
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

            if (isset($quote['severity'])) {
                continue;
            }

            if ($count == 0) { //To be checked only once
                $isRad = $quote['autoResidentialsStatus'] ?? '';
                $inStoreLdData = $quote['InstorPickupLocalDelivery'] ?? false;
                unset($quote['InstorPickupLocalDelivery']);
                $lgQuotes =
                    (isset($this->quoteSettings['alwaysLiftGateDelivery']) && $this->quoteSettings['alwaysLiftGateDelivery']) ||
                    (isset($this->quoteSettings['offerLiftGateDelivery']) && $this->quoteSettings['offerLiftGateDelivery']);
                if (!$lgQuotes) {
                    $lgQuotes = ((isset($this->quoteSettings['autoDetectedResidentialAddresses']) && $this->quoteSettings['autoDetectedResidentialAddresses']) &&
                            (isset($this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->quoteSettings['autoDetectedResidentialAddressesLfg'])) && $this->isResi;
                }
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
                    $lgPrice = $this->calculatePrice($data, false);
                    $lgTitle = $this->getTitle($lableAs, true, false, $data['totalTransitTimeInDays'], [], $dateAndDays);
                    $arraySorting['liftgate'][$key] = $lgPrice;
                    $originQuotes[$key]['liftgate']['code'] = 'upsltl' . $lgAccess;
                    $originQuotes[$key]['liftgate']['rate'] = $lgPrice;
                    $originQuotes[$key]['liftgate']['title'] = $lgTitle;
                }
                $key++;
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
            //dd($allQuotes);
            $resp = [
                'checkoutQuotes' => $this->arrangeOwnFreight($allQuotes),
                'multiShipmentQuotes' => $multiShipmentQuotes
            ];
            return $resp;
        }

        return $allQuotes;
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
        if ($this->isMultiShipment == false) {
            if (
                isset($quotes['liftgate'])
                && (isset($this->quoteSettings['offerLiftGateDelivery'])
                    && $this->quoteSettings['offerLiftGateDelivery'] == 1)
                && (
                    (isset($this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->quoteSettings['autoDetectedResidentialAddressesLfg'] == 0) || $this->isResi == 0)
            ) {
                /**
                 * Condition for lift gate as an option
                 * */
                return array_merge($quotes['simple'], $quotes['liftgate']);
            } elseif ($lfg) {
                /**
                 * Condition for Always lift gate and lift gate for residential (Single Shipment)
                 * */
                return $quotes['liftgate'] ?? $quotes['simple'];
            } else {
                return $quotes['simple'];
            }
        } elseif ($lfg) {
            /**
             * Condition for always lift gate and lift gate for residential (Multi Shipment)
             * */
            unset($quotes['simple']);
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
                $isLiftGate = $key == 'liftgate' ? true : false;
                foreach ($value as $key2 => $data) {
                    $rate += $data['rate'];
                    $code = $data['code'];
                }
                $quotesArr[] = [
                    'code' => $code,
                    'rate' => $rate,
                    'title' => $this->getTitle('Freight', $isLiftGate, true)
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
    public function getAccessorialCode($lgOption = false)
    {
        //dd($this->residentialDlvry);
        $access = '';
        if ($this->residentialDlvry == '1' || $this->isResi || $this->alwaysResi) {
            $access .= '+R';
        }
        if (($lgOption || (isset($this->liftGate) && $this->liftGate == '1')) || (isset($this->RADforLiftgate) && $this->RADforLiftgate && $this->isResi)) {
            $access .= '+LG';
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
    public function calculatePrice($data, $lgOption = false, $getCost = false, $isUpsLtl = false)
    {
        $lgCost = $lgOption ? 0 : $this->getLiftGateCost($data, $getCost, $isUpsLtl);
        $basePrice = (float)$data['totalNetCharge']['Amount'];
        $basePrice = $basePrice - $lgCost;
        $basePrice = $this->calculateHandlingFee($basePrice);
        return $basePrice;
    }

    /**
     * @param $quotes
     * @param bool $getCost
     * @return float
     */
    public function getLiftGateCost($quotes, $getCost = false, $isUpsLtl = false)
    {
        $lgCost = 0;
        if (!(($this->isResi && isset($this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->quoteSettings['autoDetectedResidentialAddressesLfg']) ||
                (isset($this->quoteSettings['alwaysLiftGateDelivery']) && $this->quoteSettings['alwaysLiftGateDelivery'] == '1')) || $getCost) {
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
    public function getTitle($serviceName, $lgOption = false, $from = false, $deliveryEstimate = '', $quoteSetting = [], $daysAndDate = [])
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

        if ($lgOption === true || (isset($this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->quoteSettings['autoDetectedResidentialAddressesLfg'])) {
            if ($lgOption && $this->quoteSettings['alwaysLiftGateDelivery'] == '0') {
                $accessTitle = $this->isResi ? $this->resiLgLabel : $this->lgLabel;
            }
            if (isset($this->quoteSettings['alwaysLiftGateDelivery']) && $this->quoteSettings['alwaysLiftGateDelivery'] && $this->isResi) {
                $accessTitle = $this->resiLabel;
            }
            if (isset($this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->quoteSettings['autoDetectedResidentialAddressesLfg'] && $this->isResi) {
                $accessTitle = $this->resiLgLabel;
            }
        } elseif ($this->isResi) {
            $accessTitle = $this->resiLabel;
        }
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
            $deliveryEstimates = !blank($days) ? " (Estimated number of days until delivery is " . $days . ")" : "";
        } elseif (isset($this->quoteSettings['delivery_estimate_options']) && $this->quoteSettings['delivery_estimate_options'] == 3) {
            $deliveryEstimates = !blank($date) ? " (Estimated delivery date is " . date('m-d-Y \b\y h:i A', strtotime($date)) . ")" : "";
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
                $accessTitle = Constant::RESI_LABEL;//$this->resiLabel;
            }
            if (isset($this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->quoteSettings['autoDetectedResidentialAddressesLfg'] && $this->isResi) {
                $accessTitle = Constant::RESI_LIFT_LABEL;//$this->resiLgLabel;
            }
        } elseif ($this->isResi) {
            $accessTitle = Constant::RESI_LABEL;//$this->resiLabel;
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
                            'hazShipment' => $hazShipment];

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
                'code' => $code,// or carrier name
                'title' => $title . $appendLabel,
                'rate' => $grandTotal
            ];
        }

        return $allowed;
    }

    public function checkOwnArrangement($finalArr)
    {
        if (isset($this->ownArangement) && $this->ownArangement == 1) {
            $title = (isset($this->ownArangementText) && trim($this->ownArangementText) != '') ? $this->ownArangementText :
                "I'll Arrange My Own Freight";
            $finalArr[] = ['code' => 'OWAR',// or carrier name
                'title' => $title,
                'rate' => 0
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
                'storeType' => $storeType
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
            'msg' => ($msg != null) ? $msg : $defaultError
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
    public function getGTZCompiledQuotes($services, $arraySorting, $lgQuotes)
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
                $services[$minIndex] = $quickest;
                $quickest = $services;
            }
        }
        if (isset($this->quoteSettings['method']) && $this->quoteSettings['method'] != 0) {
            $quotes = $this->getGTZQuotes($servicesOriginal, $arraySorting, $lgQuotes);
        }
        $quotes = array_merge($quotes, $quickest);
        foreach ($quotes as $key => $quote) {
            if (isset($quotes[$key]['simple']['titleQuickest'])) {
                unset($quotes[$key]['simple']['titleQuickest']);
            }
            if (isset($quotes[$key]['liftgate']['titleQuickest'])) {
                unset($quotes[$key]['liftgate']['titleQuickest']);
            }
        }
        return $quotes;
    }

    public function getGTZQuotes($services, $arraySorting, $lgQuotes)
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

    public function getCompiledQuotes($services, $arraySorting, $lgQuotes)
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
            return $this->averageRattingMethod($arraySorting, $options, $lgQuotes);
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
    public function averageRattingMethod($ratesArray, $options, $lgQuotes)
    {
        $sliced = array_slice($ratesArray['simple'], 0, $options, true);
        $simplePrice = $this->getAveragePrice($sliced, $options);

        $serviceName = $this->customLabel('Freight');
        $averageRateService[0]['simple'] = [
            'title' => $this->getTitle($serviceName, false),//$serviceName,
            'code' => 'AVG' . $this->getAccessorialCode(),
            'rate' => $simplePrice,
        ];
        if ($lgQuotes) {
            asort($ratesArray['liftgate']);
            $sliced = array_slice($ratesArray['liftgate'], 0, $options, true);
            $lfgPrice = $this->getAveragePrice($sliced, $options);
            $averageRateService[0]['liftgate'] = [
                'title' => $this->getTitle($serviceName, $lgQuotes),
                'code' => 'AVG' . $this->getAccessorialCode($lgQuotes),
                'rate' => $lfgPrice,
            ];
        }
        return $averageRateService;
    }

    public function getAveragePrice($arraySorting, $options)
    {
        $numOfIndexes = count($arraySorting);
        $divider = ($numOfIndexes == $options) ? $options : $numOfIndexes;
        return array_sum($arraySorting) / $divider;
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
            'rate' => 0
        ];
        return array_merge($finalQuotes, $ownArrangement);
    }

    function arrangeHATFreight($finalQuotes, $HAT, $lableAs = '')
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
            'rate' => $amount
        ];
        return array_merge($finalQuotes, $hatQuotes);
    }

    function arrangeHATMulti($mulishipment, $HAT)
    {
        $quotes = $mulishipment['simple'] ?? $mulishipment['liftgate'] ?? [];
        $count = 0;
        foreach ($quotes as $shipmentId => $quote) {
            $newQuote = [
                'code' => $HAT[$count]['serviceType'] ?? '',
                'rate' => $HAT[$count]['totalNetCharge']['Amount'] ?? '',
                'title' => $HAT[$count]['serviceDesc'] ?? ''
            ];
            $mulishipment['hat'][$shipmentId] = $newQuote;
        }
        return $mulishipment;
    }

    function arrangeFreeRNL($finalQuotes)
    {
        $hatQuotes[] = [
            'code' => 'freernlltl',
            'title' => 'Free',
            'rate' => 0
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
            'enableCuttOff'
        ];
        $advance = [];
        switch ($currentPlan) {
            case 2:
            case 3:
                break;

            default:
                $restriction = [
//                    'advance' => $advance,
                    'standard' => $standard
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
