<?php

namespace App\CustomClasses;

use App\Constants\Constant;
use App\CustomClasses\WWESMALL\WweSmallQuoteResults;
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

    public function __construct()
    {

        $this->wweSmallQuoteRes = new WweSmallQuoteResults();
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
        // dd($allOrigins);
        if (empty($quotesArray)) {
            return [];
        }
        /*    if (count($allOrigins) > 1) {
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
                $quotesArray[] = [
                    'code' => 'INSP',
                    'rate' => 0,
                    'transitTime' => '',
                    'title' => $warehouseData['inStoreTitle'] ?? '',
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
        $planNumber = $this->planInfo()['planNumber'];
        $warehouses = $this->fetchWarehouseSecData('warehouse');
        if ($planNumber < '2' && count($warehouses)) {
            $this->canAddWh = 0;
        }
        return $this->canAddWh;
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
        $this->resiLabel = ' (R)';
        $this->lgLabel = ' (L)';
        $this->resiLgLabel = ' (R | L)';
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

    public function isRADEnabledandActive(){
        $quoteSettings = $this->quoteSettings;
        $installed_addon = (array) DB::table('installed_carriers')->where('installed_carriers.id', $quoteSettings['carrierId'])
            ->Join('installed_addons', 'installed_addons.store_id', '=', 'installed_carriers.store_id')         ->Join('stores', 'stores.id', '=', 'installed_carriers.store_id')->select('installed_addons.is_enabled', 'installed_addons.is_suspend', 'installed_addons.store_id', 'stores.name')->first();
        if(empty($installed_addon)){
            return [];
        }
        $RADController = new RADController();
        $request = new \Illuminate\Http\Request();
        $request->store_id = $installed_addon['store_id'];
        $request->store_name = $installed_addon['name'];
        $RADplan = $RADController->getPlans($request)->original['data']['current_plan'];

        $now = Carbon::createFromFormat('Y-d-m H:i:s', now());
        if(isset($RADplan->status->subscriptionInfo->expiryTime)){
            $expiry = Carbon::createFromFormat('Y-d-m H:i:s', $RADplan->status->subscriptionInfo->expiryTime);

            $isRadNotActive = $RADplan->severity !== 'SUCCESS' || $now->gt($expiry) || $RADplan->status->subscriptionInfo->subscriptionStatus != 1;
        }else{
            $isRadNotActive = true;
        }

        if($isRadNotActive){
            return [];
        }else{
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
    public function newGetQuotesResults($quotes, $connectionSettings, $allOrigins, $isHazmat, $hazmatAllItems, $residential)
    {
        $this->residential = $residential;
        /*if($residential == 'Y'){
            $this->isResi = true;
            $this->residentialDlvry = 1;
        }else{
            $this->isResi = false;
            $this->residentialDlvry = 0;
        }*/
        if ($quotes == null) {
            return [];
        }
        $quotesRes = [];
        foreach ($quotes as $key => $shipment) {
            switch ($key) {
                case "wweLTL":
                    $resp = $this->compileWweLtlQuotes($shipment, $connectionSettings, $allOrigins);
                    if (!empty($resp)) {
                        $quotesRes = array_merge($quotesRes, $resp);
                    }
                    break;
                case "wweSmall":
                    $quotesRes = array_merge($quotesRes, $this->compileWweSmallQuotes($shipment, $connectionSettings, $allOrigins, $isHazmat, $hazmatAllItems));
                    break;
            }
        }
        // Removing duplicate respone of quotes
        $quotesRes = array_map("unserialize", array_unique(array_map("serialize", $quotesRes)));
        return $quotesRes;

    }

    public function compileWweLtlQuotes($shipments, $connectionSettings, $allOrigins)
    {
        if($this->residential['wweLtl'] == 'Y'){
            $this->isResi = true;
            $this->residentialDlvry = 1;
        }else{
            $this->isResi = false;
            $this->residentialDlvry = 0;
        }
        $this->quoteSettings = $connectionSettings['ltl-quotes']['quote_settings'];
        $allConfigServices = $connectionSettings['ltl-quotes']['carrier_services'] ?? [];
        $this->quoteSettingsData();
        $allQuotes = $odwArr = $hazShipmentArr = [];
        $count = 0;
        $lgQuotes = false;
        $this->isMultiShipment = false;
        $this->isMultiShipment = is_countable($shipments) && count($shipments) > 1;

        foreach ($shipments as $origin => $quote) {
            if (isset($quote['severity'])) {
                if (isset($quote['dismissedProduct'])) {
                    continue;
                }
                return [];
            }


            if ($count == 0) { //To be checked only once
                $isRad = $quote['autoResidentialsStatus'] ?? '';
                //$resi = $this->isResi ? $this->resiLabel : '';
                //dd($isRad, $resi);
                //$this->getAutoResidentialTitle($isRad);
                $inStoreLdData = $quote['InstorPickupLocalDelivery'] ?? false;
                unset($quote['InstorPickupLocalDelivery']);
                $lgQuotes = $this->quoteSettings['alwaysLiftGateDelivery'] || $this->quoteSettings['offerLiftGateDelivery'] || ($this->quoteSettings['alwaysResidentialDelivery'] && $this->quoteSettings['autoDetectedResidentialAddressesLfg']);
            }

            $originQuotes = [];
            $arraySorting = [];
            if (isset($quote['q'])) {
                if (isset($quote['hazardousStatus'])) {
                    $hazShipmentArr[$origin] = $quote['hazardousStatus'] == 'y' ? 'Y' : 'N';
                }
                foreach ($quote['q'] as $key => $data) {
                    if (isset($data['serviceType']) && in_array($data['serviceType'], $allConfigServices) && isset($data['GuaranteedDaysToDelivery']) && $data['GuaranteedDaysToDelivery'] != 'Y' ) {
                        $access = $this->getAccessorialCode();
                        $price = $this->calculatePrice($data);
                        $title = $this->getTitle($data['serviceDesc'], false, false, $data['transitTime']);
                        $arraySorting['simple'][$key] = $price;
                        $originQuotes[$key]['simple']['code'] = $data['serviceType'] . $access;
                        $originQuotes[$key]['simple']['rate'] = $price;
                        $originQuotes[$key]['simple']['title'] = $title;
                        if ($lgQuotes) {
                            $lgAccess = $this->getAccessorialCode(true);
                            $lgPrice = $this->calculatePrice($data, true);
                            $lgTitle = $this->getTitle($data['serviceDesc'], true, false, $data['transitTime']);
                            $arraySorting['liftgate'][$key] = $lgPrice;
                            $originQuotes[$key]['liftgate']['code'] = $data['serviceType'] . $lgAccess;
                            $originQuotes[$key]['liftgate']['rate'] = $lgPrice;
                            $originQuotes[$key]['liftgate']['title'] = $lgTitle;
                        }
                    }
                }
            }
            //Todo: function naming according to the functionality
            $compiledQuotes = $this->getCompiledQuotes($originQuotes, $arraySorting, $lgQuotes);
//echo "<pre>"; print_r($compiledQuotes); exit;
            if ($compiledQuotes !== null) {
                if (count($compiledQuotes) > 1) {
                    foreach ($compiledQuotes as $k => $service) {
                        $allQuotes['simple'][] = $service['simple'];
                        $lgQuotes ? $allQuotes['liftgate'][] = $service['liftgate'] : null;
                        //$allQuotes['liftgate'][] = $service['simple'];
                    }
                } else {
                    $service = reset($compiledQuotes);
                    $allQuotes['simple'][] = $service['simple'] ?? '';
                    $lgQuotes ? $allQuotes['liftgate'][] = $service['liftgate'] : null;
                    //$allQuotes['liftgate'][] = $service['simple'];
                }
            }

            if ($this->isMultiShipment) {
                $odwArr[$origin]['quotes'] = $compiledQuotes;
            }
            $count++;
        }
        //s$this->setOrderDetailWidgetData($odwArr, $hazShipmentArr);
        $allQuotes = $this->getFinalQuotesArray($allQuotes);
        if (!$this->isMultiShipment && isset($inStoreLdData) && !empty($inStoreLdData)) {
            $allQuotes = $this->inStoreLocalDeliveryQuotes($allQuotes, $inStoreLdData, $allOrigins);
        }
        return $this->arrangeOwnFreight($allQuotes);
    }

    public function compileWweSmallQuotes($shipments, $connectionSettings, $allOrigins, $isHazmat, $hazmatAllItems)
    {
        if($this->residential['wweSmall'] == 'Y'){
            $this->isResi = true;
            $this->residentialDlvry = 1;
        }else{
            $this->isResi = false;
            $this->residentialDlvry = 0;
        }
        $this->quoteSettings = [];
        $isHazmat = $isHazmat == "Y" ? true : false;
        $this->quoteSettings = $connectionSettings['small-package']['quote_settings'];
        $allConfigServices = $connectionSettings['small-package']['quote_settings']['carrier_services'] ?? [];
        // Removing Markup indexes from services
        $allConfigServices = $this->wweSmallQuoteRes->filterWweSmallServicesFromMarkup($allConfigServices);
        $enabledServices = $this->wweSmallQuoteRes->getEnabledServicesCodes($allConfigServices);
        if (empty($enabledServices)) {
            return [];
        }
        // dd($allConfigServices, $enabledServices, $shipments,$this->quoteSettings);

        $this->isMultiShipment = false;
        $this->isMultiShipment = count($shipments) > 1;
        $originQuotes = [];
        $shipmentCount = 0;
        $count = 0;

        foreach ($shipments as $origin => $quote) {
            if (isset($quote['severity'])) {
                if (isset($quote['dismissedProduct'])) {
                    continue;
                }

                return [];
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
                        if ($this->quoteSettings['number_of_transit_days'] != null && $this->quoteSettings['ground_metric'] != null) {
                            $islimited = $this->wweSmallQuoteRes->checkGroundTransit($data, $this->quoteSettings);
                            if ($islimited) {
                                continue;
                            }
                        }
                    }
                    //  CHecks FOr Only quote ground service if hazardous
                    if ($isHazmat && $this->quoteSettings['ground_service_for_hazardous_material']) {
                        if ($data['serviceType'] != "GND") {
                            continue;
                        }
                    }

                    $access = '';
                    // Adding Markup in services if enabled
                    $price = $this->wweSmallQuoteRes->getServiceRate($data['totalNetCharge']['Amount'], $data['serviceType'], $this->quoteSettings);
                    // Checking hazmat and adding hazmat amounts in services
                    if ($isHazmat) {
                        if($this->isMultiShipment){
                            if ($hazmatAllItems[$origin] == 'Y'){
                                $price = $this->wweSmallQuoteRes->addHazmatAmountsInServices($price, $data['serviceType'], $this->quoteSettings);
                            }
                        }else{
                            $price = $this->wweSmallQuoteRes->addHazmatAmountsInServices($price, $data['serviceType'], $this->quoteSettings);
                        }
                    }
                    $quoteSettings = $this->quoteSettings;
                    $price = $this->wweSmallQuoteRes->addHandlingMarkupOfHazmat($price, $quoteSettings['handling_fee_markup']);

                    $title = $this->wweSmallQuoteRes->getServiceTitle($data['serviceDesc'], $data['transitTime'], $data['serviceType'], $this->quoteSettings, $this->isResi);
                    $originQuotes[$shipmentCount]['shipment'][$key]['simple']['code'] = 'parcel_12'.$data['serviceType'] . $access;
                    $originQuotes[$shipmentCount]['shipment'][$key]['simple']['rate'] = $price;
                    $originQuotes[$shipmentCount]['shipment'][$key]['simple']['title'] = $title;
                }
            }
            $shipmentCount++;
        }
        // Check for mukti shipment finding lowest price in each shipment and adding them for multi shipment

        if ($this->isMultiShipment) {
            $originQuotesMulti = [];
            $multiShipPrice = 0;
            foreach ($originQuotes as $shipmentKey => $shipment) {
                $netChargeArray = array_column($shipment['shipment'], 'simple');
                $minValueFromNetChargeArr = min(array_column($netChargeArray, 'rate'));
                $multiShipPrice += $minValueFromNetChargeArr;
                $originQuotesMulti[0]['code'] = 'Multi';
                $originQuotesMulti[0]['rate'] = number_format($multiShipPrice, 2);
                $originQuotesMulti[0]['title'] = $this->isResi ? 'Shipping ( R ) ' : 'Shipping';

            }
            return $originQuotesMulti;
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

        return [];
    }


    /*public function getQuotesResults($quotes, $quoteSettings, $allOrigins)
    {
        if ($quotes == null) {
            return [];
        }
        $quotes = reset($quotes);
        $this->quoteSettings = $quoteSettings['WweLtl'];
        $allConfigServices = $quoteSettings['WweLtl']['carrier_services'] ?? [];
        $this->quoteSettingsData();

        $allQuotes = $odwArr = $hazShipmentArr = [];
        $count = 0;
        $lgQuotes = false;
        $this->isMultiShipment = count($quotes) > 1;
        foreach ($quotes as $origin => $quote) {
            if (isset($quote->severity)) {
                return [];
            }

            if ($count == 0) { //To be checked only once
                $isRad = $quote->autoResidentialsStatus ?? '';
                $this->getAutoResidentialTitle($isRad);
                $inStoreLdData = $quote->InstorPickupLocalDelivery ?? false;
                unset($quote->InstorPickupLocalDelivery);
                $lgQuotes = $this->quoteSettings['alwaysLiftGateDelivery'] || $this->quoteSettings['offerLiftGateDelivery'] || ($this->quoteSettings['alwaysResidentialDelivery'] && $this->quoteSettings['autoDetectedResidentialAddressesLfg']);
            }

            $originQuotes = [];
            $arraySorting = [];
            if (isset($quote['q'])) {
                if (isset($quote->hazardousStatus)) {
                    $hazShipmentArr[$origin] = $quote->hazardousStatus == 'y' ? 'Y' : 'N';
                }
                foreach ($quote['q'] as $key => $data) {
                    if (isset($data['serviceType']) && in_array($data['serviceType'], $allConfigServices)) {
                        $access = $this->getAccessorialCode();
                        $price = $this->calculatePrice($data);
                        $title = $this->getTitle($data['serviceDesc'], false, false, $data['transitTime']);
                        $arraySorting['simple'][$key] = $price;
                        $originQuotes[$key]['simple']['code'] = $data['serviceType'] . $access;
                        $originQuotes[$key]['simple']['rate'] = $price;
                        $originQuotes[$key]['simple']['title'] = $title;
                        if ($lgQuotes) {
                            $lgAccess = $this->getAccessorialCode(true);
                            $lgPrice = $this->calculatePrice($data, true);
                            $lgTitle = $this->getTitle($data['serviceDesc'], true, false, $data['transitTime']);
                            $arraySorting['liftgate'][$key] = $lgPrice;
                            $originQuotes[$key]['liftgate']['code'] = $data['serviceType'] . $lgAccess;
                            $originQuotes[$key]['liftgate']['rate'] = $lgPrice;
                            $originQuotes[$key]['liftgate']['title'] = $lgTitle;
                        }
                    }
                }
            }
            //Todo: function naming according to the functionality
            $compiledQuotes = $this->getCompiledQuotes($originQuotes, $arraySorting, $lgQuotes);
            if ($compiledQuotes !== null) {
                if (count($compiledQuotes) > 1) {
                    foreach ($compiledQuotes as $k => $service) {
                        $allQuotes['simple'][] = $service['simple'];
                        $lgQuotes ? $allQuotes['liftgate'][] = $service['liftgate'] : null;
                    }
                } else {
                    $service = reset($compiledQuotes);
                    $allQuotes['simple'][] = $service['simple'] ?? '';
                    $lgQuotes ? $allQuotes['liftgate'][] = $service['liftgate'] : null;
                }
            }
            if ($this->isMultiShipment) {
                $odwArr[$origin]['quotes'] = $compiledQuotes;
            }
            $count++;
        }
        //s$this->setOrderDetailWidgetData($odwArr, $hazShipmentArr);
        $allQuotes = $this->getFinalQuotesArray($allQuotes);
        if (!$this->isMultiShipment && isset($inStoreLdData) && !empty($inStoreLdData)) {
            $allQuotes = $this->inStoreLocalDeliveryQuotes($allQuotes, $inStoreLdData, $allOrigins);
        }
        return $this->arrangeOwnFreight($allQuotes);
    }*/

    /**
     * Calculate Handling Fee
     * @param $cost
     * @return float
     */
    public function calculateHandlingFee($cost)
    {
        $handlingFeeMarkup = (float)$this->quoteSettings['handling_free_markup'] ?? 0;
        $symbolicHandlingFee = strpos($this->quoteSettings['handling_free_markup'], '%') ? '%' : '';

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
        //dd($quotes);
        if (empty($quotes)) {
            return [];
        }
        $lfg = $this->quoteSettings['alwaysLiftGateDelivery'] == 1 || ($this->isResi && $this->quoteSettings['autoDetectedResidentialAddressesLfg']);
        //echo "<pre>"; print_r($quotes); print_r($this->quoteSettings); exit;
        if ($this->isMultiShipment == false) {
            if (isset($quotes['liftgate']) && $this->quoteSettings['offerLiftGateDelivery'] == 1 && ($this->quoteSettings['autoDetectedResidentialAddressesLfg'] == 0 || $this->isResi == 0)) {
                /**
                 * Condition for lift gate as an option
                 * */
                return array_merge($quotes['simple'], $quotes['liftgate']);
            } elseif ($lfg) {
                /**
                 * Condition for Always lift gate and lift gate for residential (Single Shipment)
                 * */
                return $quotes['simple'];
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
        $access = '';
        if ($this->residentialDlvry == '1' || $this->isResi) {
            $access .= '+R';
        }
        if (($lgOption || $this->liftGate == '1') || ($this->RADforLiftgate && $this->isResi)) {
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
    public function calculatePrice($data, $lgOption = false, $getCost = false)
    {
        $lgCost = $lgOption ? 0 : $this->getLiftGateCost($data, $getCost);
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
    public function getLiftGateCost($quotes, $getCost = false)
    {
        $lgCost = 0;
        if (!(($this->isResi && $this->quoteSettings['autoDetectedResidentialAddressesLfg']) || $this->quoteSettings['alwaysLiftGateDelivery'] == '1') || $getCost) {
            if (isset($quotes['surcharges']) && isset($quotes['surcharges']['liftgateFee'])) {
                $lgCost = $quotes['surcharges']['liftgateFee'];
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
    public function getTitle($serviceName, $lgOption = false, $from = false, $deliveryEstimate = '')
    {
        // Here  Making service title
        $serviceTitle = $this->customLabel($serviceName);
        if ($this->isMultiShipment && $from == false) {
            return $serviceTitle;
        }
        // Here  Making Delivery estimate title
        $deliveryEstimateLabel = (!empty($deliveryEstimate) && $this->quoteSettings['showDeliveryEstimate']) ? ' (Estimated transit time of ' . $deliveryEstimate . ' business days)' : '';
        // Here  Making Access title
        $accessTitle = '';

        if ($lgOption === true || $this->quoteSettings['autoDetectedResidentialAddressesLfg']) {
            if ($lgOption && $this->quoteSettings['alwaysLiftGateDelivery'] == '0') {
                $accessTitle = $this->isResi ? $this->resiLgLabel : $this->lgLabel;
            }
            if ($this->quoteSettings['alwaysLiftGateDelivery'] && $this->isResi) {
                $accessTitle = $this->resiLabel;
            }
            if ($this->quoteSettings['autoDetectedResidentialAddressesLfg'] && $this->isResi) {
                $accessTitle = $this->resiLgLabel;
            }
        } elseif ($this->isResi) {
            $accessTitle = $this->resiLabel;
        }
        return $serviceTitle  . $deliveryEstimateLabel . $accessTitle;
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
    public function getCompiledQuotes($services, $arraySorting, $lgQuotes)
    {
        if (empty($arraySorting) || empty($services)) {
            return [];
        }
        asort($arraySorting['simple']);
        $options = ($this->quoteSettings['method'] > 1 && $this->isMultiShipment == false) ? (int)$this->quoteSettings['number_of_options'] : 1;
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
            'title' => $serviceName,
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

    public function customLabel($serviceName)
    {
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
}
