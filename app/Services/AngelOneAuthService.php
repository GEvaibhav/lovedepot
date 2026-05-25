<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use OTPHP\TOTP;

class AngelOneAuthService
{
    /**
     * Daily login and generate fresh tokens.
     */
    public function login(): ?array
    {
        try {

            /**
             * Generate TOTP dynamically
             * composer require spomky-labs/otphp
             */
            $totp = TOTP::create(
                config('shopify.gold_api.totp_secret')
            )->now();

            $response = Http::withHeaders([
                'Content-Type'      => 'application/json',
                'Accept'            => 'application/json',
                'X-UserType'        => 'USER',
                'X-SourceID'        => 'WEB',
                'X-ClientLocalIP'   => config('shopify.gold_api.local_ip'),
                'X-ClientPublicIP'  => config('shopify.gold_api.public_ip'),
                'X-MACAddress'      => config('shopify.gold_api.mac_address'),
                'X-PrivateKey'      => config('shopify.gold_api.private_key'),
            ])->post(
                'https://apiconnect.angelone.in/rest/auth/angelbroking/user/v1/loginByPassword',
                [
                    'clientcode' => config('shopify.gold_api.client_code'),
                    'password'   => config('shopify.gold_api.client_pin'),
                    'totp'       => $totp,
                    'state'      => 'ACTIVE',
                ]
            );

            if ($response->failed()) {

                Log::channel('pricesync')->error('Angel One login failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $payload = $response->json();

            $jwtToken     = data_get($payload, 'data.jwtToken');
            $refreshToken = data_get($payload, 'data.refreshToken');
            $feedToken    = data_get($payload, 'data.feedToken');

            if (!$jwtToken || !$refreshToken) {

                Log::channel('pricesync')->error('Invalid login response', [
                    'payload' => $payload,
                ]);

                return null;
            }

            /**
             * Store tokens in cache
             */
            Cache::put(
                'angel_one_access_token',
                $jwtToken,
                now()->endOfDay()
            );

            Cache::put(
                'angel_one_refresh_token',
                $refreshToken,
                now()->endOfDay()
            );

            Cache::put(
                'angel_one_feed_token',
                $feedToken,
                now()->endOfDay()
            );

            Log::channel('pricesync')->info('Angel One login successful');

            return [
                'access_token'  => $jwtToken,
                'refresh_token' => $refreshToken,
                'feed_token'    => $feedToken,
            ];

        } catch (\Exception $e) {

            Log::channel('pricesync')->error('Angel One login exception', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get valid access token
     */
    public function getAccessToken(): ?string
    {
        $token = Cache::get('angel_one_access_token');

        if ($token) {
            return $token;
        }

        $login = $this->login();

        return $login['access_token'] ?? null;
    }
}
