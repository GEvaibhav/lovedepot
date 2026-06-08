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
Route::any('cronjob', 'ShopifyWebhookController@handleCronJob');

//Innovist Webhook routes
// Route::any('innovist/order_update', 'InnovistWebhookController@handleOrderUpdateWebhook');
// Route::any('innovist/order_fullfill', 'InnovistWebhookController@handleOrderFullfillWebhook');
Route::any('innovist/create_event', 'InnovistWebhookController@handleCreateEvent'); //use MoEngage plateform
Route::any('innovist/fullfill_create', 'InnovistWebhookController@handleOrderFullfillCreate');
Route::any('innovist/fullfill_update', 'InnovistWebhookController@handleOrderFullfillUpdate');

//Ekaya API
Route::get('ekaya/getPincodeData', 'EkayaApiController@getPincodeData');

//Typsy API
Route::any('typsy/setQuizData', 'TypsyApiController@handleQuizCreate');
Route::any('typsy/sendOTP', 'TypsyApiController@sendOTP');
Route::any('typsy/verifyOTP', 'TypsyApiController@verifyOTP');

//EasyEcom Webhook API (to cleavertap connect)
Route::any('easyecom/create_order', 'EasyEcomWebhookController@handleOrderCreateWebhook');
Route::any('easyecom/order_track', 'EasyEcomWebhookController@handleOrderTrackWebhook');

//HazarBazar
Route::any('hazarbazar/price_update', 'HazarbazarApiController@handleCronJob');
Route::any('hazarbazar/carrot_jewellery_products_sync', 'ShopifyProductPriceWebhookController@syncCarrotJewelleryProducts');
Route::any('hazarbazar/product_create', 'ShopifyProductPriceWebhookController@handleProductCreate');
Route::any('hazarbazar/product_metafield_update', 'ShopifyProductPriceWebhookController@handleProductMetafieldUpdate');
