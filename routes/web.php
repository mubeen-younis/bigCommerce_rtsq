<?php

use App\Http\Controllers\MainController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

/*Route::get('/', function () {
    return view('welcome');
});*/

Route::post('webhooks', [MainController::class, 'addAndUpdateProductFromWebHook']);
Route::get('uninstall1', function () {
    $arr = ['R', 'L','N', 'A'];
    $string = '';
    if (count($arr) == 4) {
        $string .= '(' . $arr[0] . ')    ';
        $string .= ' (' . $arr[0] . '| ' . $arr[1] . ')';
        $string .= ' (' . $arr[0] . '| ' . $arr[2] . ')';
        $string .= ' (' . $arr[0] . '| ' . $arr[3] . ')';
        $string .= ' (' . $arr[0] . '| ' . $arr[1] . '| ' . $arr[2] . ')';
        $string .= ' (' . $arr[0] . '| ' . $arr[1] . '| ' . $arr[3] . ')';
        $string .= ' (' . $arr[0] . '| ' . $arr[2] . '| ' . $arr[3] . ')';
        $string .= ' (' . $arr[0] . '| ' . $arr[1] . '| ' . $arr[2] . '| ' . $arr[3] . ')';
    } elseif (count($arr) == 3) {
// DOnt know how many combinations u have to make
    } elseif (count($arr) == 2) {
        $string .= $arr[0];
        $string .= ' (' . $arr[0] . '| ' . $arr[1] . ')';
    } elseif (count($arr) == 1) {
        $string .= $arr[0];
    } else {
        $string = '';
    }
    echo $string;
});
Route::group(['prefix' => 'auth'], function () {
    Route::get('install', [MainController::class, 'install']);

    Route::get('load', [MainController::class, 'load']);

    Route::get('uninstall', function () {
        echo 'uninstall';
        return app()->version();
    });

    Route::get('remove-user', function () {
        echo 'remove-user';
        return app()->version();
    });

});

Route::get('get-dom', [MainController::class, 'getDom']);

Route::get('getQoutes', [MainController::class, 'getQoutes']);

//    Route::any('/rate', 'MainController@rate');

Route::any('/bc-api/{endpoint}', [MainController::class, 'proxyBigCommerceAPIRequest'])
    ->where('endpoint', 'v2\/.*|v3\/.*');

Route::get('/getProducts', [MainController::class, 'getProducts']);
