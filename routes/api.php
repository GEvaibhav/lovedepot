<?php

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

// Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//     return $request->user();
// });

//Love Depot Webhook routes
Route::any('lovedepot/order_create', 'ShopifyWebhookController@handleOrderCreate');
Route::any('cronjob', 'ShopifyWebhookController@fetchWareIqOrders');

//Innovist Webhook routes
// Route::any('innovist/order_update', 'InnovistWebhookController@handleOrderUpdateWebhook');
// Route::any('innovist/order_fullfill', 'InnovistWebhookController@handleOrderFullfillWebhook');
Route::any('innovist/create_event', 'InnovistWebhookController@handleCreateEvent'); //use MoEngage plateform
Route::any('innovist/fullfill_create', 'InnovistWebhookController@handleOrderFullfillCreate');
Route::any('innovist/fullfill_update', 'InnovistWebhookController@handleOrderFullfillUpdate');

//Ekaya API
Route::get('ekaya/getPincodeData', 'EkayaApiController@getPincodeData');

//EasyEcom Webhook API (to cleavertap connect)
Route::any('easyecom/create_order', 'EasyEcomWebhookController@handleOrderCreateWebhook');
Route::any('easyecom/order_track', 'EasyEcomWebhookController@handleOrderTrackWebhook');
