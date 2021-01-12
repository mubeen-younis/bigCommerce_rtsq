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

Route::get('/get_carriers',[CarrierController::class,'index']);
Route::get('/get_conn_settings',[ConnectionController::class,'index']);
Route::post('/submit_connection_settings',[ConnectionController::class,'store']);
Route::get('/get_qoute_settings',[QuoteSettingsController::class,'getSettings']);
Route::post('/submit_quote_settings',[QuoteSettingsController::class,'saveSettings']);
Route::get('/get_locations',[LocationsController::class,'index']);

/*------Services tab-------*/
Route::post('/submit_carriers',[AdditionalCarrierTabSettingController::class,'store']);
Route::get('/get_carrier_services',[AdditionalCarrierTabSettingController::class,'index']);

Route::get('/get_warehouse','LocationsController@warehouse');
Route::get('/get_dropships','LocationsController@dropships');
Route::post('/submit_location',[LocationsController::class,'store']);


Route::put('/location/update/{locations}','LocationsController@update');
Route::delete('warehouse/delete/{id}','LocationsController@delete_warehouse');
Route::delete('dropship/delete/{id}','LocationsController@delete_dropships');
Route::post('/save_csv','CsvController@store');
Route::post('/save_boxsize','BoxSizeController@store');
Route::get('/get_boxsize','BoxSizeController@index');
Route::delete('boxsize/delete/{id}','BoxSizeController@destroy');
Route::get('/getDetails/{zip_code}','AdressController@googleApiCurl');

//=============Carrier Route
Route::get('get_carrier_info', [CarrierController::class, 'getCarrierDetails']);
Route::get('getAllCarriers', [CarrierController::class, 'getAllCarriers']);


//========Product Routes
Route::get('/getProducts', [\App\Http\Controllers\ProductSettingController::class, 'getAllProducts']);

