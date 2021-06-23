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
use App\Http\Middleware\EnsureTokenIsValid;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;


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
Route::post('webhooks', [MainController::class, 'addAndUpdateProductFromWebHook']);
Route::post('order/webhooks', [OrderController::class, 'orderFromWebhook']);
// Ws Route For Adding Plan
Route::post('/save_plan_detail', [CarrierPlanController::class, 'addPlanFromWs']);
Route::middleware([EnsureTokenIsValid::class])->group(function () {
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
    Route::post('/rad/changeDefaultAddress', [RADController::class, 'setDefaultAddress']);
    Route::get('/rad/getAddonAdressSettings', [RADController::class, 'getDefaultAddress']);

    /* SBS routes */
    Route::get('/sbs/get_plans', [SBSController::class, 'getPlans']);
    Route::post('/sbsb/change_plan', [RADController::class, 'changePlan']);
    Route::post('/sbs/change_status', [RADController::class, 'changeStatus']);

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
});

Route::get('/get_carriers', [CarrierController::class, 'index']);
Route::get('/get_conn_settings', [ConnectionController::class, 'index']);
Route::post('/submit_connection_settings', [ConnectionController::class, 'store']);
Route::get('/get_qoute_settings/{carrierId}', [QuoteSettingsController::class, 'getSettings']);
Route::post('/submit_quote_settings', [QuoteSettingsController::class, 'saveSettings']);

/*------Services tab-------*/
Route::get('/get_carrier_services', [AdditionalCarrierTabSettingController::class, 'index']);

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
