<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoldPriceFetcherService {
    protected AngelOneAuthService $authService;

    public function __construct(AngelOneAuthService $authService) {
        $this->authService = $authService;
    }

    /**
     * Fetch current gold price per gram from Angel One API.
     */
    public function fetchRatePerGram(): ?float {
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
                'X-PrivateKey'  => config('shopify.gold_api.private_key'),
                'X-UserType'    => 'USER',
                'X-SourceID'    => 'WEB',
                'Authorization' => 'Bearer ' . $accessToken,
            ])->timeout(30)->post(
                config('shopify.gold_api.url'),
                [
                    'mode' => 'LTP',
                    'exchangeTokens' => [
                        config('shopify.gold_api.exchange', 'MCX') => [
                            (string) config('shopify.gold_api.symbol_token'),
                        ],
                    ],
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
                    'X-PrivateKey'  => config('shopify.gold_api.private_key'),
                    'X-UserType'    => 'USER',
                    'X-SourceID'    => 'WEB',
                    'Authorization' => 'Bearer ' . $login['access_token'],
                ])->timeout(30)->post(
                    config('shopify.gold_api.url'),
                    [
                        'mode' => 'LTP',
                        'exchangeTokens' => [
                            config('shopify.gold_api.exchange', 'MCX') => [
                                (string) config('shopify.gold_api.symbol_token'),
                            ],
                        ],
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
             *    {
             *        "status":true,
             *        "message":"SUCCESS",
             *        "errorcode":"",
             *        "data":{
             *            "fetched":[{
             *                "exchange":"MCX",
             *                "tradingSymbol":"GOLD05JUN26FUT",
             *                "symbolToken":"459277",
             *                "ltp":155627.0
             *            }],
             *            "unfetched":[]
             *        }
             *   }
             */

            if (
                data_get($payload, 'status') !== true ||
                data_get($payload, 'message') !== 'SUCCESS'
            ) {

                Log::channel('pricesync')->error(
                    'Gold API returned unsuccessful response',
                    [
                        'payload' => $payload,
                    ]
                );

                return null;
            }

            $expectedExchange = config('shopify.gold_api.exchange', 'MCX');
            $expectedTradingSymbol = config('shopify.gold_api.trading_symbol');
            $expectedSymbolToken = config('shopify.gold_api.symbol_token');
            $quote = null;

            foreach (data_get($payload, 'data.fetched', []) as $fetchedQuote) {
                if (
                    data_get($fetchedQuote, 'exchange') === $expectedExchange &&
                    (string) data_get($fetchedQuote, 'symbolToken') === (string) $expectedSymbolToken
                ) {
                    $quote = $fetchedQuote;
                    break;
                }
            }

            if (
                !$quote ||
                data_get($quote, 'tradingSymbol') !== $expectedTradingSymbol
            ) {

                Log::channel('pricesync')->error(
                    'Gold API returned unexpected instrument',
                    [
                        'expected' => [
                            'exchange' => $expectedExchange,
                            'trading_symbol' => $expectedTradingSymbol,
                            'symbol_token' => $expectedSymbolToken,
                        ],
                        'actual' => [
                            'exchange' => data_get($quote, 'exchange'),
                            'trading_symbol' => data_get($quote, 'tradingSymbol'),
                            'symbol_token' => data_get($quote, 'symbolToken'),
                        ],
                        'payload' => $payload,
                    ]
                );

                return null;
            }

            $ltp = data_get($quote, 'ltp');

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
             * 1.5 (1+gst rate/100) is added as a markup to cover taxes and fees, adjust as needed.
             */
            $pricePerGram = (float) $ltp * 1.018 / 10;

            Log::channel('pricesync')->info(
                'Gold rate fetched successfully',
                [
                    'GOLD MCX'             => (float) $ltp,
                    'GOLD RTGS'             => round($pricePerGram, 2),
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
