<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProductCreateService
{
    private const CHARGE_GST = 1.03;
    private string $endpoint;
    private array $headers;
    private bool $verifySsl;

    public function __construct()
    {
        $domain = config('shopify.store_domain');
        $version = config('shopify.api_version');
        $this->verifySsl = (bool) config('shopify.verify_ssl', !app()->environment('local'));

        $this->endpoint = "https://{$domain}/admin/api/{$version}/graphql.json";
        $this->headers = [
            'X-Shopify-Access-Token' => config('shopify.access_token'),
            'Content-Type' => 'application/json',
        ];
    }

    public function handleProductCreate(
        array $payload,
        CarrotJewelleryProductSnapshotService $snapshotService
    ): array {
        try {
            $productId = $this->extractProductId($payload);

            if ($productId === null) {
                Log::channel('pricesync')->warning('Product create webhook skipped: product id missing', [
                    'payload' => $payload,
                ]);

                return [
                    'status' => 'skipped',
                    'message' => 'Product id not found in webhook payload.',
                ];
            }

            Log::channel('pricesync')->info('Product create webhook received', [
                'product_id' => $productId,
                'payload' => $payload,
            ]);

            $product = $this->fetchProduct($productId);

            if ($product === null) {
                Log::channel('pricesync')->warning('Product not found or inactive in Shopify', [
                    'product_id' => $productId,
                ]);

                return [
                    'status' => 'skipped',
                    'message' => 'Product not found or inactive in Shopify.',
                    'product_id' => $productId,
                ];
            }

            $snapshotService->upsertProductSnapshot($product);

            Log::channel('pricesync')->info('Product created and stored in database', [
                'product_id' => $productId,
                'product_title' => $product['product_title'],
            ]);

            return [
                'status' => 'success',
                'message' => 'Product snapshot stored successfully.',
                'product_id' => $productId,
                'product_title' => $product['product_title'],
            ];
        } catch (\Throwable $e) {
            Log::channel('pricesync')->error('Product create webhook failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $payload,
            ]);

            return [
                'status' => 'error',
                'message' => 'Product creation failed.',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function fetchProduct(string $productId): ?array
    {
        $query = <<<'GQL'
        query ProductForCreate($id: ID!) {
            product(id: $id) {
                id
                title
                status
                goldWeight: metafield(namespace: "custom", key: "net_fine") {
                    value
                }
                makingCharge: metafield(namespace: "custom", key: "making") {
                    value
                }
                variants(first: 250) {
                    edges {
                        node {
                            id
                            title
                            price
                            variantGoldWeight: metafield(namespace: "custom", key: "net_fine") {
                                value
                            }
                            variantMakingCharge: metafield(namespace: "custom", key: "making") {
                                value
                            }
                        }
                    }
                }
            }
        }
        GQL;

        $response = Http::withHeaders($this->headers)->withOptions([
            'verify' => $this->verifySsl,
        ])->post($this->endpoint, [
            'query' => $query,
            'variables' => [
                'id' => $productId,
            ],
        ]);

        if ($response->failed()) {
            Log::channel('pricesync')->error('Failed to fetch product for create', [
                'product_id' => $productId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $errors = $response->json('errors');
        if (!empty($errors)) {
            Log::channel('pricesync')->error('Shopify GraphQL errors while fetching product for create', [
                'product_id' => $productId,
                'errors' => $errors,
            ]);

            return null;
        }

        $product = $response->json('data.product');
        if ($product === null || data_get($product, 'status') !== 'ACTIVE') {
            Log::channel('pricesync')->info('Product not found or inactive for create', [
                'product_id' => $productId,
                'status' => data_get($product, 'status'),
            ]);

            return null;
        }

        $variants = [];
        foreach (data_get($product, 'variants.edges', []) as $edge) {
            $node = $edge['node'] ?? [];

            $variants[] = [
                'gid' => $node['id'] ?? null,
                'product_gid' => $product['id'] ?? $productId,
                'product_title' => $product['title'] ?? '',
                'variant_title' => $node['title'] ?? '',
                'current_price' => $node['price'] ?? 0,
                'variantGoldWeight' => data_get($node, 'variantGoldWeight.value'),
                'variantMakingCharge' => data_get($node, 'variantMakingCharge.value'),
                'goldWeight' => data_get($product, 'goldWeight.value'),
                'makingCharge' => data_get($product, 'makingCharge.value'),
            ];
        }

        return [
            'product_gid' => $product['id'] ?? $productId,
            'product_title' => $product['title'] ?? '',
            'goldWeight' => data_get($product, 'goldWeight.value'),
            'makingCharge' => data_get($product, 'makingCharge.value'),
            'variants' => array_values(array_filter($variants, fn($variant) => !empty($variant['gid']))),
        ];
    }

    private function extractProductId(array $payload): ?string
    {
        $adminGraphqlId = data_get($payload, 'admin_graphql_api_id');
        if (is_string($adminGraphqlId) && str_contains($adminGraphqlId, 'Product/')) {
            return $adminGraphqlId;
        }

        $productId = data_get($payload, 'id');
        if (!empty($productId)) {
            return $this->normalizeProductGid((string) $productId);
        }

        return null;
    }

    private function normalizeProductGid(string $productId): string
    {
        if (str_starts_with($productId, 'gid://shopify/Product/')) {
            return $productId;
        }

        return 'gid://shopify/Product/' . preg_replace('/\D/', '', $productId);
    }
}
