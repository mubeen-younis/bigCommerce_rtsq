<?php

use App\Http\Controllers\AdditionalCarrierTabSettingController;
use App\Http\Controllers\CarrierController;
use App\Http\Controllers\ConnectionController;
use App\Http\Controllers\GetRatesController;
use App\Http\Controllers\LocationsController;
use App\Http\Controllers\QuoteSettingsController;
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
Route::post('webhooks', [\App\Http\Controllers\MainController::class, 'addAndUpdateProductFromWebHook']);
// Ws Route For Adding Plan
Route::post('/save_plan_detail', [\App\Http\Controllers\CarrierPlanController::class, 'addPlanFromWs']);
Route::middleware([\App\Http\Middleware\EnsureTokenIsValid::class])->group(function () {
    //========Product Routes
    Route::get('/getProducts', [\App\Http\Controllers\ProductSettingController::class, 'getAllProducts']);
    Route::get('/import_products', [\App\Http\Controllers\ProductSettingController::class, 'importProducts']);
    Route::get('/get_products', [\App\Http\Controllers\ProductSettingController::class, 'getStoreProductsFromDb']);
    Route::get('/get_product', [\App\Http\Controllers\ProductSettingController::class, 'getSingleProductDetail']);
    Route::get('/edit_product', [\App\Http\Controllers\ProductSettingController::class, 'editProduct']);
    Route::post('/update_product', [\App\Http\Controllers\ProductSettingController::class, 'updateProductDetail']);

    //=======Carriers
    Route::get('/get_add_tab_sett', [\App\Http\Controllers\AdditionalCarrierTabSettingController::class, 'getAddTabSett']);
    Route::get('/get_add_tab_sett_store', [\App\Http\Controllers\AdditionalCarrierTabSettingController::class, 'getAddTabSettByStoreID']);
    Route::get('/get_inst_car', [\App\Http\Controllers\InstalledCarrierController::class, 'getInstalledCarriers']);
    Route::post('/inst_carrier', [\App\Http\Controllers\InstalledCarrierController::class, 'installCarrier']);
    Route::post('/update_carrier', [\App\Http\Controllers\InstalledCarrierController::class, 'updateCarrier']);
    Route::get('/get_plans_det', [\App\Http\Controllers\CarrierPlanController::class, 'getPlansDetail']);
    Route::get('/get_sin_car_plan', [\App\Http\Controllers\CarrierPlanController::class, 'getSingleCarrierPlan']);

    //=========Locations
    Route::put('/location/update/{locations}', 'LocationsController@update');
    Route::delete('warehouse/delete/{id}', 'LocationsController@delete_warehouse');
    Route::delete('dropship/delete/{id}', 'LocationsController@delete_dropships');
    Route::get('/get_loc_from_zip/{zip_code}', [\App\Http\Controllers\LocationsController::class, 'getLocationFromZip']);
    Route::post('/save_location', [\App\Http\Controllers\LocationsController::class, 'store']);
    Route::get('/get_location', [\App\Http\Controllers\LocationsController::class, 'getSingleLocation']);
    Route::get('/get_locations', [\App\Http\Controllers\LocationsController::class, 'getLocations']);
    Route::post('/delete_location', [\App\Http\Controllers\LocationsController::class, 'deleteLocation']);
    //============= Register WebHook
    // Route::get('register_webhook/{type}', [\App\Http\Controllers\MainController::class, 'registerWebHook']);

    //=========Addons

    Route::get('/get_installed_addons', [\App\Http\Controllers\AddonsController::class, 'getAddons']);
    
});

Route::get('/get_carriers', [CarrierController::class, 'index']);
Route::get('/get_conn_settings', [ConnectionController::class, 'index']);
Route::post('/submit_connection_settings', [ConnectionController::class, 'store']);
Route::get('/get_qoute_settings', [QuoteSettingsController::class, 'getSettings']);
Route::post('/submit_quote_settings', [QuoteSettingsController::class, 'saveSettings']);

/*------Services tab-------*/
Route::post('/submit_carriers', [AdditionalCarrierTabSettingController::class, 'store']);
Route::get('/get_carrier_services', [AdditionalCarrierTabSettingController::class, 'index']);

Route::get('/get_warehouse', 'LocationsController@warehouse');
Route::get('/get_dropships', 'LocationsController@dropships');
Route::post('/submit_location', [LocationsController::class, 'store']);

Route::post('/save_csv', 'CsvController@store');
Route::post('/save_boxsize', 'BoxSizeController@store');
Route::get('/get_boxsize', 'BoxSizeController@index');
Route::delete('boxsize/delete/{id}', 'BoxSizeController@destroy');
Route::get('/getDetails/{zip_code}', 'AdressController@googleApiCurl');

Route::get('get_carrier_info', [CarrierController::class, 'getCarrierDetails']);
Route::get('getAllCarriers', [CarrierController::class, 'getAllCarriers']);

Route::get('getNearestWareHouse', [GetRatesController::class, 'getNearestWarehouseTest']);

Route::post('rate', [GetRatesController::class, 'returnRates']);

Route::get('/getInstalledCarriers', [CarrierController::class, 'getInstalledCarriers']);
Route::post('/changeCarrierStatus', [CarrierController::class, 'changeCarrierStatus']);
