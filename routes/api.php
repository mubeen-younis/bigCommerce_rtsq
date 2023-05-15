<?php

use App\Http\Controllers\AdditionalCarrierTabSettingController;
use App\Http\Controllers\AddonsController;
use App\Http\Controllers\CarrierController;
use App\Http\Controllers\CarrierPlanController;
use App\Http\Controllers\ConnectionController;
use \App\Http\Controllers\Subscriptions;

//use App\Http\Controllers\CsvController;
use App\Http\Controllers\ExportImportProducts;
use App\Http\Controllers\GetRatesController;
use App\Http\Controllers\InstalledCarrierController;
use App\Http\Controllers\LocationsController;
use App\Http\Controllers\MainController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductSettingController;
use App\Http\Controllers\QuoteSettingsController;
use App\Http\Controllers\RADController;
use App\Http\Controllers\SBSController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\Subscription\SubscriptionController;
use App\Http\Controllers\Subscription\PackageSubscriptionController;
use App\Http\Middleware\EnsureTokenIsValid;
use App\Models\CarrierServices;
use App\Models\Locations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BoxSizeController;
use App\Http\Controllers\FDOController;

use App\Http\Controllers\DBSC\ShippingClassController;
use App\Http\Controllers\DBSC\ShippingProfileController;
use App\Http\Controllers\DBSC\ShippingOriginController;
use App\Http\Controllers\DBSC\ShippingZoneController;
use App\Http\Controllers\DBSC\ShippingRatesController;
use App\Http\Controllers\DBSC\OtherSettingsController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
 */

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});
Route::middleware([ \App\Http\Middleware\EnsureStoreisActive::class])->group(function () {
    Route::post('webhooks', [MainController::class, 'addAndUpdateProductFromWebHook']);
    Route::post('order/webhooks', [OrderController::class, 'orderFromWebhook']);
    Route::post('sku/webhooks', [ProductSettingController::class, 'skuFromWebhook']);
});
Route::get('/add_to_test_stores', [MainController::class, 'addTestStore']);


// FDO ROUTES
Route::middleware([\App\Http\Middleware\FDOValidity::class])->group(function () {
    Route::get('/order/{orderId}.json', [\App\Http\Controllers\FDOOrderController::class, 'getOrderDetails']);
    Route::get('/product/{variantID}.json', [\App\Http\Controllers\FDOProductController::class, 'getVariantDetail']);
    Route::get('/locations.json', [\App\Http\Controllers\FDOLocationsController::class, 'getLocations']);
    Route::get('/get_boxes', 'App\Http\Controllers\BoxSizeController@index');

});
Route::post('update_coupon_details_fdo_av', [FDOController::class, 'updateCouponDetailsFromFDOAV']);
Route::post('connection_update_from_va', [\App\Http\Controllers\AddressValidationController::class, 'connectionUpdateFromVa']);
Route::post('connection_update_from_fdo', [\App\Http\Controllers\FDOController::class, 'connectionUpdateFromFdo']);


/////


// Ws Route For Adding Plan
Route::post('/save_plan_detail', [CarrierPlanController::class, 'addPlanFromWs']);
Route::middleware([EnsureTokenIsValid::class])->group(function () {
    //======Webhook Manually
    Route::get('/reg_webhooks_man', [\App\Http\Controllers\WebHooksController::class, 'registerStoreWebhooksManually']);
    //========Product Routes
    Route::get('/getProducts', [ProductSettingController::class, 'getAllProducts']);
    Route::get('/import_products', [ProductSettingController::class, 'importProducts']);
    Route::get('/get_products', [ProductSettingController::class, 'getStoreProductsFromDb']);
    Route::get('/get_product', [ProductSettingController::class, 'getSingleProductDetail']);
    Route::get('/edit_product', [ProductSettingController::class, 'editProduct']);
    Route::post('/update_product', [ProductSettingController::class, 'updateProductDetail']);

    //=======Carriers
    Route::get('/get_add_tab_sett', [AdditionalCarrierTabSettingController::class, 'getAddTabSett']);
    Route::get('/get_add_tab_sett_store/{carrierId}', [AdditionalCarrierTabSettingController::class, 'getAddTabSettByCarrierID']);
    Route::get('/get_inst_car', [InstalledCarrierController::class, 'getInstalledCarriers']);
    Route::post('/inst_carrier', [InstalledCarrierController::class, 'installCarrier']);
    Route::post('/update_carrier', [InstalledCarrierController::class, 'updateCarrier']);
    Route::get('/get_plans_det', [CarrierPlanController::class, 'getPlansDetail']);
    Route::get('/get_sin_car_plan', [CarrierPlanController::class, 'getSingleCarrierPlan']);
    Route::post('/installCarrier', [CarrierController::class, 'installCarrier']);

    //=========Locations
    Route::put('/location/update/{locations}', 'LocationsController@update');
    Route::delete('warehouse/delete/{id}', 'LocationsController@delete_warehouse');
    Route::delete('dropship/delete/{id}', 'LocationsController@delete_dropships');
    Route::get('/get_loc_from_zip/{zip_code}', [LocationsController::class, 'getLocationFromZip']);
    Route::post('/save_location', [LocationsController::class, 'store']);
    Route::get('/get_location', [LocationsController::class, 'getSingleLocation']);
    Route::get('/get_locations', [LocationsController::class, 'getLocations']);
    Route::post('/delete_location', [LocationsController::class, 'deleteLocation']);
    //============= Register WebHook
    // Route::get('register_webhook/{type}', [\App\Http\Controllers\MainController::class, 'registerWebHook']);

    /*RAD routes*/
    Route::get('/rad/get_plans', [RADController::class, 'getPlans']);
    Route::post('/rad/change_plan', [RADController::class, 'changePlan']);
    Route::post('/rad/change_status', [RADController::class, 'changeStatus']);
    //Route::post('/rad/changeDefaultAddress', [RADController::class, 'setDefaultAddress']);
    Route::get('/rad/getAddonAdressSettings', [RADController::class, 'getDefaultAddress']);
    Route::post('/saveResidentialSettings', [RADController::class, 'saveSettings']);
    Route::get('/getResidentialSettings', [RADController::class, 'getSettings']);

    /* SBS routes */
    Route::get('/sbs/get_plans', [SBSController::class, 'getPlans']);
    Route::post('/sbsb/change_plan', [RADController::class, 'changePlan']);
    Route::post('/sbs/change_status', [RADController::class, 'changeStatus']);

    //=========Shipping Groups
    Route::get('/get_shipping_groups', [\App\Http\Controllers\ShippingGroupController::class, 'getShippingGroups']);
    Route::post('/save_shipping_group', [\App\Http\Controllers\ShippingGroupController::class, 'saveShippingGroup']);
    Route::post('/delete_shipping_group', [\App\Http\Controllers\ShippingGroupController::class, 'deleteShippingGroup']);
    Route::get('/get_shipping_group_detail', [\App\Http\Controllers\ShippingGroupController::class, 'getShippingGroupDetail']);

    //=========Addons
    Route::get('/getAllAddons', [AddonsController::class, 'index']);
    Route::get('/get_installed_addons', [AddonsController::class, 'getAddons']);
    Route::get('/getRecommendedAddons', [AddonsController::class, 'getRecommendedAddons']);
    Route::post('/changeAddonStatus', [AddonsController::class, 'changeAddonStatus']);
    Route::post('/changeAddonSuspendStatus', [AddonsController::class, 'changeAddonSuspendStatus']);
    Route::post('/installAddon', [AddonsController::class, 'installAddon']);

    //====Plans Info
    Route::get('/get_plans_info', [\App\Http\Controllers\PlansController::class, 'getPlansInfo']);

    Route::get('/getInstalledCarriers', [CarrierController::class, 'getInstalledCarriers']);
    Route::get('/getRecommendedCarriers', [CarrierController::class, 'getRecommendedCarriers']);
    Route::post('/changeCarrierStatus', [CarrierController::class, 'changeCarrierStatus']);
    Route::get('/getInstalledCarrierPlanInfo', [CarrierController::class, 'getInstalledCarrierPlanInfo']);

    Route::post('/submit_carriers', [AdditionalCarrierTabSettingController::class, 'store']);

    Route::get('/get_boxsize', 'App\Http\Controllers\BoxSizeController@index');
    Route::post('/save_boxsize', 'App\Http\Controllers\BoxSizeController@store');
    Route::post('/update_boxsize', 'App\Http\Controllers\BoxSizeController@update');
    Route::delete('boxsize/delete/{id}', 'App\Http\Controllers\BoxSizeController@destroy');


    Route::post('/submit_connection_settings', [ConnectionController::class, 'store']);

    // Orders
    Route::get('/get_orders', [OrderController::class, 'index']);
    Route::get('/get_order_widget', [OrderController::class, 'getOrderWidget']);
    Route::post('/update_order', [OrderController::class, 'update']);

    //import export csv
    Route::post('/exportProductsTemplate', [ExportImportProducts::class, 'exportProductsTemplate']);
    Route::get('/getRowHeaderImportedFile', [ExportImportProducts::class, 'getRowHeaderImportedFile']);
    Route::post('/importProducts', [ExportImportProducts::class, 'importProductsCsv']);

    //subscription
    Route::post('/create_subscription', [Subscriptions::class, 'createSubscription']);


    //stores
    Route::get('/store', [StoreController::class, 'index']);
    Route::get('/get_fdo_info', [FDOController::class, 'getFdoCompanyInfo']);
    Route::get('/get_fdo_coupon_info', [FDOController::class, 'getFDOCouponInfo']);
    Route::get('/get_fdo_coupon_carrier_info', [FDOController::class, 'getFDOCouponCarrierInfo']);
    Route::post('/apply_promo_code', [FDOController::class, 'applyPromoCode']);
    Route::post('/update_fdo_connection', [FDOController::class, 'update']);

    // Address Validation
    Route::get('/get_av_info', [\App\Http\Controllers\AddressValidationController::class, 'getAvCompanyInfo']);
    Route::post('/update_va_connection', [\App\Http\Controllers\AddressValidationController::class, 'updateVA']);

    //Start: Subscription Module Routes are given below
    Route::post('/subscribe-plan', [SubscriptionController::class, 'subscribeToPlan']);
    Route::post('/cancel-subscription', [SubscriptionController::class, 'cancelSubscriptionPlan']);
    Route::get('/get-subscription-details', [SubscriptionController::class, 'getSubscriptionDetail']);
    Route::post('/change-payment-method', [SubscriptionController::class, 'changePaymentMethod']);
    //END: Subscription Routes
    //Start: SBS Routes
    Route::get('/get-all-pacakges', [PackageSubscriptionController::class, 'getAllPackagesList']);
    Route::post('/subscribe-package', [PackageSubscriptionController::class, 'subscribeToPackage']);
    Route::get('/consume-hits', [PackageSubscriptionController::class, 'consumeHits']);
    Route::get('/get-addon-details', [PackageSubscriptionController::class, 'getAddonPackageDetails']);
    Route::post('/suspend-use-addon', [PackageSubscriptionController::class, 'suspendAddonUse']);
    Route::post('/bins-package-mode', [PackageSubscriptionController::class, 'binsPackagingMode']);
    //END: SBS Routes


    Route::post('/syncGTZCerasisProviders', [AdditionalCarrierTabSettingController::class, 'syncGTZCerasisProviders']);

    Route::post('/get_carrier_services', [AdditionalCarrierTabSettingController::class, 'index']);
    Route::post('/has_insurance', [AdditionalCarrierTabSettingController::class, 'hasInsurance']);


    //Multiple packages
    Route::get('/getmultiplepackages', [BoxSizeController::class, 'getMultiplePackagingBoxes']);
    Route::post('/addmultiplepackages', [BoxSizeController::class, 'addMultiplePackagingBox']);
    Route::post('/updatemultiplepackages', [BoxSizeController::class, 'updateMultiplePackagingBox']);
    Route::post('/deletemultiplepackages', [BoxSizeController::class, 'deleteMultiplePackagingBox']);

    // DBSC carrier
    // Shipping class Route
    Route::post('/add_shipping_class',[ShippingClassController::class,'store']);
    Route::get('/get_shipping_classes',[ShippingClassController::class,'show']);
    Route::post('/update_shipping_class',[ShippingClassController::class,'update']);
    Route::post('/delete_shipping_class',[ShippingClassController::class,'destroy']);

    // Shipping Profile Route
    Route::post('/add_dbsc_profile',[ShippingProfileController::class,'store']);
    Route::get('/edit_dbsc_profile',[ShippingProfileController::class,'edit']);
    Route::get('/get_dbsc_profiles',[ShippingProfileController::class,'show']);
    Route::post('/update_dbsc_profile',[ShippingProfileController::class,'update']);
    Route::post('/delete_dbsc_profile',[ShippingProfileController::class,'destroy']);

    // Shipping Origin  Route
    Route::post('/add_dbsc_origin',[ShippingOriginController::class,'store']);
    Route::get('/edit_dbsc_origin',[ShippingOriginController::class,'edit']);
    Route::get('/get_dbsc_origins',[ShippingOriginController::class,'show']);
    Route::post('/update_dbsc_origin',[ShippingOriginController::class,'update']);
    Route::post('/delete_dbsc_origin',[ShippingOriginController::class,'destroy']);

    // Shipping Zone Route
    Route::get('/get_zones_bc',[ShippingZoneController::class,'getZonesOfStore']);
    Route::post('/add_dbsc_zone',[ShippingZoneController::class,'store']);
    Route::get('/edit_dbsc_zone',[ShippingZoneController::class,'edit']);
    Route::get('/get_dbsc_zones',[ShippingZoneController::class,'show']);
    Route::post('/update_dbsc_zone',[ShippingZoneController::class,'update']);
    Route::post('/delete_dbsc_zone',[ShippingZoneController::class,'destroy']);

    // Shipping Rates Route
    Route::post('/add_dbsc_rates',[ShippingRatesController::class,'store']);
    Route::get('/edit_dbsc_rates',[ShippingRatesController::class,'edit']);
    Route::get('/get_dbsc_rates',[ShippingRatesController::class,'show']);
    Route::post('/update_dbsc_rates',[ShippingRatesController::class,'update']);
    Route::post('/delete_dbsc_rates',[ShippingRatesController::class,'destroy']);

    // Dbsc Other Settings
    Route::get('/get_dbsc_other_settings', [OtherSettingsController::class, 'index']);
    Route::post('/save_dbsc_other_settings', [OtherSettingsController::class, 'store']);

    Route::get('/get_threshold_settings', [QuoteSettingsController::class, 'getThresholdSettings']);
    Route::post('/submit_threshold_settings', [QuoteSettingsController::class, 'saveThresholdSettings']);

    Route::get('/get_carrs_conn_settings', [ConnectionController::class, 'getConnSettings']);
});
//Webhook
Route::post('/bc-subscription-update', [SubscriptionController::class, 'paymentByStripeWebHook']);
//Route::post('/bc-payment-succeeded', [SubscriptionController::class, 'invoicePaymentSucceeded']);
//Route::post('/update-subscription', [SubscriptionController::class, 'updateSubscriptionFromStripe']);

Route::get('/get_carriers', [CarrierController::class, 'index']);
Route::get('/get_conn_settings', [ConnectionController::class, 'index']);
Route::get('/get_qoute_settings/{carrierId}', [QuoteSettingsController::class, 'getSettings']);
Route::post('/submit_quote_settings', [QuoteSettingsController::class, 'saveSettings']);

/*------Services tab-------*/


Route::get('/get_warehouse', 'LocationsController@warehouse');
Route::get('/get_dropships', 'LocationsController@dropships');
Route::post('/submit_location', [LocationsController::class, 'store']);

Route::post('/save_csv', 'CsvController@store');

Route::get('/getDetails/{zip_code}', 'AdressController@googleApiCurl');

Route::get('get_carrier_info', [CarrierController::class, 'getCarrierDetails']);
Route::get('getAllCarriers', [CarrierController::class, 'index']);

Route::get('getNearestWareHouse', [GetRatesController::class, 'getNearestWarehouseTest']);

Route::post('rate', [GetRatesController::class, 'returnRates']);
Route::get('downloadcsv/{hash}', [ExportImportProducts::class, 'downloadCsv'])->name('downloadcsv');
Route::post('/uploadcsv', [ExportImportProducts::class, 'uploadCsv'])->name('uploadcsv');

Route::get('splitCSVinChunks', [ExportImportProducts::class, 'splitCSVinChunks']);


//plans
Route::get('/get_plans', [\App\Http\Controllers\PlansController::class, 'getPlans']);

Route::get('/test_bin', [App\CustomClasses\Bin3D\Bin3D::class, 'getBinResponse']);

// app logs
Route::get('/api_logs', [App\Http\Controllers\LogToDbController::class, 'index']);
Route::get('/truncate_logs', [App\Http\Controllers\LogToDbController::class, 'truncateLogs']);


