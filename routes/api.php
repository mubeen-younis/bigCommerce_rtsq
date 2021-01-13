<?php

use App\Http\Controllers\AdditionalCarrierTabSettingController;
use App\Http\Controllers\CarrierController;
use App\Http\Controllers\CarrierTabController;
use App\Http\Controllers\ConnectionController;
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
Route::middleware([\App\Http\Middleware\EnsureTokenIsValid::class])->group(function () {
    //========Product Routes
    Route::get('/getProducts', [\App\Http\Controllers\ProductSettingController::class, 'getAllProducts']);
    Route::get('/import_products', [\App\Http\Controllers\ProductSettingController::class, 'importProducts']);
    Route::get('/get_products', [\App\Http\Controllers\ProductSettingController::class, 'getStoreProductsFromDb']);
    Route::get('/get_product', [\App\Http\Controllers\ProductSettingController::class, 'getSingleProductDetail']);

    //=========Locations
    Route::put('/location/update/{locations}', 'LocationsController@update');
    Route::delete('warehouse/delete/{id}', 'LocationsController@delete_warehouse');
    Route::delete('dropship/delete/{id}', 'LocationsController@delete_dropships');
    Route::get('/get_loc_from_zip', [\App\Http\Controllers\LocationsController::class, 'getLocationFromZip']);
    Route::post('/save_location', [\App\Http\Controllers\LocationsController::class, 'store']);
});

Route::get('/get_carriers', [CarrierController::class, 'index']);
Route::get('/get_conn_settings', [ConnectionController::class, 'index']);
Route::post('/submit_connection_settings', [ConnectionController::class, 'store']);
Route::get('/get_qoute_settings', [QuoteSettingsController::class, 'getSettings']);
Route::post('/submit_quote_settings', [QuoteSettingsController::class, 'saveSettings']);
Route::get('/get_locations', [LocationsController::class, 'index']);

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





