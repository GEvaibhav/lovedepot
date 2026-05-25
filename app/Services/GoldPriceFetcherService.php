<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoldPriceFetcherService
{
    protected AngelOneAuthService $authService;

    public function __construct(AngelOneAuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Fetch current gold price per gram from Angel One API.
     */
    public function fetchRatePerGram(): ?float
    {
        try {

            /**
             * Get valid access token
             */
            $accessToken = $this->authService->getAccessToken();

            if (!$accessToken) {

                Log::channel('pricesync')->error(
                    'Unable to get Angel One access token'
                );

                return null;
            }

            /**
             * Fetch LTP Data
             */
            $response = Http::withHeaders([
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'X-MACAddress'  => config('shopify.gold_api.mac_address'),
                'X-PrivateKey'  => config('shopify.gold_api.private_key'),
                'X-UserType'    => 'USER',
                'X-SourceID'    => 'WEB',
                'Authorization' => 'Bearer ' . $accessToken,
            ])->timeout(30)->post(
                config('shopify.gold_api.url'),
                [
                    'exchange'      => config('shopify.gold_api.exchange', 'MCX'),
                    'tradingsymbol' => config('shopify.gold_api.trading_symbol'),
                    'symboltoken'   => config('shopify.gold_api.symbol_token'),
                ]
            );

            /**
             * Retry once if token expired
             */
            if (
                $response->status() === 401 ||
                str_contains($response->body(), 'AG8001')
            ) {

                Log::channel('pricesync')->warning(
                    'Angel One token expired. Re-login initiated.'
                );

                $login = $this->authService->login();

                if (!$login || empty($login['access_token'])) {

                    Log::channel('pricesync')->error(
                        'Angel One re-login failed'
                    );

                    return null;
                }

                /**
                 * Retry API with new token
                 */
                $response = Http::withHeaders([
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                    'X-MACAddress'  => config('shopify.gold_api.mac_address'),
                    'X-PrivateKey'  => config('shopify.gold_api.private_key'),
                    'X-UserType'    => 'USER',
                    'X-SourceID'    => 'WEB',
                    'Authorization' => 'Bearer ' . $login['access_token'],
                ])->timeout(30)->post(
                    config('shopify.gold_api.url'),
                    [
                        'exchange'      => config('shopify.gold_api.exchange', 'MCX'),
                        'tradingsymbol' => config('shopify.gold_api.trading_symbol'),
                        'symboltoken'   => config('shopify.gold_api.symbol_token'),
                    ]
                );
            }

            if ($response->failed()) {

                Log::channel('pricesync')->error(
                    'Angel One gold API failed',
                    [
                        'status' => $response->status(),
                        'body'   => $response->body(),
                    ]
                );

                return null;
            }

            $payload = $response->json();

            /**
             * Expected response:
             *
             * {
             *   "status": true,
             *   "message": "SUCCESS",
             *   "data": {
             *      "ltp": 98765
             *   }
             * }
             */

            $ltp = data_get($payload, 'data.ltp');

            if (!is_numeric($ltp) || $ltp <= 0) {

                Log::channel('pricesync')->error(
                    'Invalid gold LTP received',
                    [
                        'payload' => $payload,
                    ]
                );

                return null;
            }

            /**
             * MCX GOLD FUT usually represents 10 grams
             * Verify contract specification once.
             * 1.01 (1+gst rate/100) is added as a markup to cover taxes and fees, adjust as needed.
             */
            $pricePerGram = (float) $ltp * 1.01 / 10;

            Log::channel('pricesync')->info(
                'Gold rate fetched successfully',
                [
                    'ltp'             => (float) $ltp,
                    'price_per_gram'  => round($pricePerGram, 2),
                    'exchange'        => config('shopify.gold_api.exchange'),
                    'trading_symbol'  => config('shopify.gold_api.trading_symbol'),
                    'symbol_token'    => config('shopify.gold_api.symbol_token'),
                ]
            );

            return round($pricePerGram, 2);

        } catch (\Exception $e) {

            Log::channel('pricesync')->error(
                'Gold price API exception',
                [
                    'message' => $e->getMessage(),
                ]
            );

            return null;
        }
    }
}
