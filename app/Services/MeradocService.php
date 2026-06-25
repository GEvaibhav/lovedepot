<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;

class MeradocService {
    protected string $baseUrl;
    protected string $clientId;
    protected string $authorizationKey;

    protected string $shopifyShop;
    protected string $shopifyAccessToken;

    protected Client $client;

    public function __construct() {
        $this->client = new Client([
            'timeout' => 60,
        ]);

        $this->baseUrl = config('services.meradoc.base_url');
        $this->clientId = config('services.meradoc.client_id');
        $this->authorizationKey = config('services.meradoc.authorization_key');

        $this->shopifyShop = config('services.meradoc.manup_store_domain');
        $this->shopifyAccessToken = config('services.meradoc.manup_store_token');
    }

    /**
     * Get token from cache or generate a new one
     */
    public function getToken(): string {
        $token = Cache::get('meradoc_access_token');

        if ($token) {
            return $token;
        }

        return $this->generateToken();
    }

    /**
     * Generate new token and save in cache
     */
    public function generateToken(): string {
        Log::channel('manup')->info('Generating Meradoc Token');

        $response = $this->client->post(
            $this->baseUrl . '/user/api/v1/admin/generateToken',
            [
                'headers' => [
                    'clientId' => $this->clientId,
                    'authorizationKey' => $this->authorizationKey,
                ],
            ]
        );

        $result = json_decode($response->getBody()->getContents(), true);

        // Log::channel('manup')->info(
        //     'Meradoc Generate Token Response',
        //     $result
        // );

        /**
         * Adjust according to actual response
         */
        if (
            !isset($result['status']) ||
            $result['status'] != 200 ||
            empty($result['data']['token'])
        ) {
            throw new \Exception('Unable to generate Meradoc token');
        }

        $token = $result['data']['token'];

        $cacheExpiry = $this->getCacheExpiryFromJwt($token);

        Cache::put(
            'meradoc_access_token',
            $token,
            $cacheExpiry
        );

        return $token;
    }

    /**
     * Extract expiry from JWT
     */
    private function getCacheExpiryFromJwt(string $jwt) {
        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            return now()->addMinutes(55);
        }

        $payload = json_decode(
            base64_decode(
                strtr($parts[1], '-_', '+/')
            ),
            true
        );

        $exp = $payload['exp'] ?? null;

        if (!$exp) {
            return now()->addMinutes(55);
        }

        return now()
            ->setTimestamp($exp)
            ->subMinutes(5);
    }

    /**
     * Validate/Re-Validate Prescription
     */
    public function validateOrder(array $payload) {
        try {

            return $this->sendValidateOrderRequest(
                $this->getToken(),
                $payload
            );
        } catch (ClientException $e) {

            if ($e->getResponse()->getStatusCode() === 401) {

                Log::channel('manup')->warning(
                    'Meradoc token expired. Regenerating token.'
                );

                Cache::forget('meradoc_access_token');

                $newToken = $this->generateToken();

                return $this->sendValidateOrderRequest(
                    $newToken,
                    $payload
                );
            }

            throw $e;
        }
    }

    /**
     * Actual API Request
     */
    private function sendValidateOrderRequest(
        string $token,
        array $payload
    ) {
        Log::channel('manup')->info(
            'Meradoc Request Payload',
            $payload
        );

        $response = $this->client->post(
            $this->baseUrl . '/prescription/api/v1/prescription/re-validate',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                ],
                'json' => $payload,
            ]
        );

        $result = json_decode(
            $response->getBody()->getContents(),
            true
        );

        // Log::channel('manup')->info(
        //     'Meradoc Response',
        //     $result
        // );

        return $result;
    }

    /**
     * Download prescription image from Meradoc
     */
    public function getPrescriptionImage(string $imageUrl) {
        try {

            return $this->downloadPrescriptionImage(
                $this->getToken(),
                $imageUrl
            );
        } catch (ClientException $e) {

            if ($e->getResponse()->getStatusCode() === 401) {

                Log::channel('manup')->warning(
                    'Meradoc token expired while fetching prescription image. Regenerating token.'
                );

                Cache::forget('meradoc_access_token');

                $newToken = $this->generateToken();

                return $this->downloadPrescriptionImage(
                    $newToken,
                    $imageUrl
                );
            }

            throw $e;
        }
    }

    /**
     * Actual image download request
     */
    private function downloadPrescriptionImage(
        string $token,
        string $imageUrl
    ) {
        $response = $this->client->get(
            $imageUrl,
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                ],
            ]
        );

        $contentType = $response->getHeaderLine('Content-Type');

        $extension = match ($contentType) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            default      => 'jpg',
        };

        return [
            'content'      => $response->getBody()->getContents(),
            'content_type' => $contentType,
            'filename'     => 'prescription_' . time() . '.' . $extension,
        ];
    }

    public function uploadImageToShopify(array $imageData): ?string {
        $graphqlUrl = "https://{$this->shopifyShop}/admin/api/2025-10/graphql.json";

        $mutation = <<<'GRAPHQL'
                    mutation stagedUploadsCreate($input: [StagedUploadInput!]!) {
                    stagedUploadsCreate(input: $input) {
                        stagedTargets {
                        url
                        resourceUrl
                        parameters {
                            name
                            value
                        }
                        }
                        userErrors {
                        field
                        message
                        }
                    }
                    }
                    GRAPHQL;

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $this->shopifyAccessToken,
        ])->post($graphqlUrl, [
            'query' => $mutation,
            'variables' => [
                'input' => [[
                    'filename' => $imageData['filename'],
                    'mimeType' => $imageData['content_type'],
                    'resource' => 'FILE',
                    'fileSize' => strval(strlen($imageData['content'])),
                ]]
            ]
        ]);

        Log::channel('manup')->info(
            'Shopify Staged Upload Response',
            $response->json()
        );

        $target = data_get(
            $response->json(),
            'data.stagedUploadsCreate.stagedTargets.0'
        );

        if (!$target) {
            throw new \Exception('Failed to create staged upload');
        }

        $multipart = [];

        foreach ($target['parameters'] as $parameter) {
            $multipart[] = [
                'name' => $parameter['name'],
                'contents' => $parameter['value'],
            ];
        }

        $multipart[] = [
            'name' => 'file',
            'contents' => $imageData['content'],
            'filename' => $imageData['filename'],
        ];

        Http::asMultipart()->send(
            'POST',
            $target['url'],
            [
                'multipart' => $multipart,
            ]
        );

        return $this->createShopifyFile(
            $target['resourceUrl']
        );
    }

    private function createShopifyFile(
        string $resourceUrl
    ): ?string {

        $graphqlUrl = "https://{$this->shopifyShop}/admin/api/2025-10/graphql.json";
        $mutation = <<<'GRAPHQL'
                    mutation fileCreate($files: [FileCreateInput!]!) {
                    fileCreate(files: $files) {
                        files {
                        id
                        fileStatus

                        ... on MediaImage {
                            image {
                            url
                            }
                        }
                        }

                        userErrors {
                        field
                        message
                        }
                    }
                    }
                    GRAPHQL;

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $this->shopifyAccessToken,
        ])->post($graphqlUrl, [
            'query' => $mutation,
            'variables' => [
                'files' => [[
                    'originalSource' => $resourceUrl,
                    'contentType' => 'IMAGE'
                ]]
            ]
        ]);

        Log::channel('manup')->info(
            'Shopify File Create Response',
            $response->json()
        );

        return data_get(
            $response->json(),
            'data.fileCreate.files.0.image.url'
        );
    }

    public function updateOrderPrescriptionMetafield(
        int $orderId,
        string $imageUrl
    ): array {

        $graphqlUrl = "https://{$this->shopifyShop}/admin/api/2025-10/graphql.json";

        $mutation = <<<'GRAPHQL'
                    mutation metafieldsSet($metafields: [MetafieldsSetInput!]!) {
                    metafieldsSet(metafields: $metafields) {
                        metafields {
                            id
                            key
                            value
                        }
                        userErrors {
                            field
                            message
                        }
                    }
                    }
                    GRAPHQL;

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $this->shopifyAccessToken,
        ])->post($graphqlUrl, [
            'query' => $mutation,
            'variables' => [
                'metafields' => [[
                    'ownerId' => "gid://shopify/Order/{$orderId}",
                    'namespace' => 'custom',
                    'key' => 'prescription_image',
                    'type' => 'single_line_text_field',
                    'value' => $imageUrl,
                ]]
            ]
        ]);

        $result = $response->json();

        if (
            empty(data_get($result, 'data.metafieldsSet.userErrors'))
        ) {
            Log::channel('manup')->info(
                'Shopify Order Metafield Updated Successfully',
                [
                    'order_id' => $orderId,
                    'image_url' => $imageUrl,
                    'metafield' => 'custom.prescription_image',
                ]
            );
        } else {
            Log::channel('manup')->error(
                'Shopify Order Metafield Update Failed',
                [
                    'order_id' => $orderId,
                    'errors' => data_get(
                        $result,
                        'data.metafieldsSet.userErrors'
                    ),
                ]
            );
        }

        return $result;
    }

    public function updateOrderTag(
        int $orderId,
        string $tag
    ): array {

        $graphqlUrl = "https://{$this->shopifyShop}/admin/api/2025-10/graphql.json";

        $mutation = <<<'GRAPHQL'
                    mutation orderUpdate($input: OrderInput!) {
                    orderUpdate(input: $input) {
                        order {
                        id
                        tags
                        }
                        userErrors {
                        field
                        message
                        }
                    }
                    }
                    GRAPHQL;

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $this->shopifyAccessToken,
        ])->post($graphqlUrl, [
            'query' => $mutation,
            'variables' => [
                'input' => [
                    'id' => "gid://shopify/Order/{$orderId}",
                    'tags' => [$tag]
                ]
            ]
        ]);

        $result = $response->json();

        if (
            empty(data_get($result, 'data.orderUpdate.userErrors'))
        ) {
            Log::channel('manup')->info(
                'Shopify Order Tag Updated Successfully',
                [
                    'order_id' => $orderId,
                    'tag' => $tag,
                ]
            );
        } else {
            Log::channel('manup')->error(
                'Shopify Order Tag Update Failed',
                [
                    'order_id' => $orderId,
                    'errors' => data_get(
                        $result,
                        'data.orderUpdate.userErrors'
                    ),
                ]
            );
        }

        return $result;
    }

    public function savePrescriptionImageLocally(array $imageData): ?string {
        $directory = public_path('uploads/manup-prescriptions');

        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        $fileName = $imageData['filename'];

        $fullPath = $directory . '/' . $fileName;

        file_put_contents(
            $fullPath,
            $imageData['content']
        );

        return config('app.url')
            . '/public/uploads/manup-prescriptions/'
            . $fileName;
    }
}
