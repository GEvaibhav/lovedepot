<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopifySingleProductPriceUpdateService
{
    private const CHARGE_GST = 1.03;
    private const RECENT_UPDATE_CACHE_SECONDS = 60;

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

    public function updateProductPriceFromWebhook(array $payload, GoldPriceFetcherService $goldPriceFetcher): array
    {
        $productId = $this->extractProductGid($payload);

        if ($productId === null) {
            Log::channel('pricesync')->warning('Single product price update skipped: product id missing', [
                'payload' => $payload,
            ]);

            return [
                'status' => 'skipped',
                'message' => 'Product id not found in webhook payload.',
                'updated' => 0,
                'failed' => 0,
            ];
        }

        if ($this->wasRecentlyUpdatedByThisService($productId)) {
            Log::channel('pricesync')->info('Single product price update skipped: recent self-triggered webhook', [
                'product_id' => $productId,
            ]);

            return [
                'status' => 'skipped',
                'message' => 'Recent price update webhook ignored.',
                'product_id' => $productId,
                'updated' => 0,
                'failed' => 0,
            ];
        }

        $goldRate = $goldPriceFetcher->fetchRatePerGram();

        if ($goldRate === null) {
            return [
                'status' => 'error',
                'message' => 'Failed to fetch gold rate.',
                'product_id' => $productId,
                'updated' => 0,
                'failed' => 0,
            ];
        }

        return $this->updateProductPrice($productId, $goldRate);
    }

    public function updateProductPrice(string $productId, float $goldRate): array
    {
        $productId = $this->normalizeProductGid($productId);
        $variants = $this->fetchProductVariants($productId);

        if (empty($variants)) {
            return [
                'status' => 'skipped',
                'message' => 'No active product variants found.',
                'product_id' => $productId,
                'updated' => 0,
                'failed' => 0,
            ];
        }

        $updates = $this->buildPriceUpdates($variants, $goldRate);

        if (empty($updates)) {
            return [
                'status' => 'success',
                'message' => 'Product prices are already up to date.',
                'product_id' => $productId,
                'updated' => 0,
                'failed' => 0,
            ];
        }

        return $this->updateVariants($productId, $updates);
    }

    private function shopifyRequest(): PendingRequest
    {
        return Http::withHeaders($this->headers)->withOptions([
            'verify' => $this->verifySsl,
        ]);
    }

    private function fetchProductVariants(string $productId): array
    {
        $query = <<<'GQL'
        query ProductForSinglePriceUpdate($id: ID!) {
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
                        }
                    }
                }
            }
        }
        GQL;

        $response = $this->shopifyRequest()->post($this->endpoint, [
            'query' => $query,
            'variables' => [
                'id' => $productId,
            ],
        ]);

        if ($response->failed()) {
            Log::channel('pricesync')->error('Failed to fetch single product variants', [
                'product_id' => $productId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [];
        }

        $errors = $response->json('errors');
        if (!empty($errors)) {
            Log::channel('pricesync')->error('Shopify GraphQL errors while fetching single product', [
                'product_id' => $productId,
                'errors' => $errors,
            ]);

            return [];
        }

        $product = $response->json('data.product');
        if ($product === null || data_get($product, 'status') !== 'ACTIVE') {
            Log::channel('pricesync')->info('Single product price update skipped: product missing or inactive', [
                'product_id' => $productId,
                'status' => data_get($product, 'status'),
            ]);

            return [];
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
                'goldWeight' => data_get($product, 'goldWeight.value'),
                'makingCharge' => data_get($product, 'makingCharge.value'),
            ];
        }

        return array_filter($variants, fn ($variant) => !empty($variant['gid']));
    }

    private function buildPriceUpdates(array $variants, float $goldRate): array
    {
        $updates = [];

        foreach ($variants as $variant) {
            $weightInGrams = (float) (
                $variant['variantGoldWeight']
                ?? $variant['goldWeight']
                ?? 0
            );

            if ($weightInGrams <= 0) {
                Log::channel('pricesync')->debug('Skipped single product variant without weight metafield', [
                    'product_title' => $variant['product_title'],
                    'variant_title' => $variant['variant_title'],
                ]);
                continue;
            }

            $makingPercent = (float) ($variant['makingCharge'] ?? 45);
            $goldPrice = $weightInGrams * $goldRate;
            $makingCharge = $goldPrice * (1 + $makingPercent / 100);
            $newPrice = number_format($makingCharge * self::CHARGE_GST, 2, '.', '');
            $currentPrice = number_format((float) $variant['current_price'], 2, '.', '');

            if ($currentPrice === $newPrice) {
                continue;
            }

            $updates[] = [
                'id' => $variant['gid'],
                'price' => $newPrice,
            ];
        }

        return $updates;
    }

    private function updateVariants(string $productId, array $variants): array
    {
        $mutation = <<<'GQL'
        mutation UpdateSingleProductVariants($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
            productVariantsBulkUpdate(productId: $productId, variants: $variants) {
                product {
                    id
                }
                productVariants {
                    id
                    price
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GQL;

        $response = $this->shopifyRequest()->post($this->endpoint, [
            'query' => $mutation,
            'variables' => [
                'productId' => $productId,
                'variants' => $variants,
            ],
        ]);

        if ($response->failed()) {
            Log::channel('pricesync')->error('Failed to update single product variants', [
                'product_id' => $productId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [
                'status' => 'error',
                'message' => 'Shopify variant update request failed.',
                'product_id' => $productId,
                'updated' => 0,
                'failed' => count($variants),
            ];
        }

        $errors = $response->json('errors');
        $userErrors = $response->json('data.productVariantsBulkUpdate.userErrors', []);

        if (!empty($errors) || !empty($userErrors)) {
            Log::channel('pricesync')->error('Single product variant update returned errors', [
                'product_id' => $productId,
                'errors' => $errors,
                'user_errors' => $userErrors,
            ]);

            return [
                'status' => 'error',
                'message' => 'Shopify returned errors while updating product variants.',
                'product_id' => $productId,
                'updated' => 0,
                'failed' => count($variants),
                'errors' => $errors,
                'user_errors' => $userErrors,
            ];
        }

        $updated = count($response->json('data.productVariantsBulkUpdate.productVariants', []));
        $this->markRecentlyUpdatedByThisService($productId);

        Log::channel('pricesync')->info('Single product price update finished', [
            'product_id' => $productId,
            'updated' => $updated,
        ]);

        return [
            'status' => 'success',
            'message' => 'Product variants updated.',
            'product_id' => $productId,
            'updated' => $updated,
            'failed' => 0,
        ];
    }

    private function extractProductGid(array $payload): ?string
    {
        $adminGraphqlId = data_get($payload, 'admin_graphql_api_id');
        if (is_string($adminGraphqlId) && str_contains($adminGraphqlId, 'Product/')) {
            return $adminGraphqlId;
        }

        $ownerResource = data_get($payload, 'owner_resource');
        $ownerId = data_get($payload, 'owner_id');
        if ($ownerResource === 'product' && !empty($ownerId)) {
            return $this->normalizeProductGid((string) $ownerId);
        }

        $productId = data_get($payload, 'product_id');
        if (!empty($productId)) {
            return $this->normalizeProductGid((string) $productId);
        }

        if (array_key_exists('status', $payload) || array_key_exists('title', $payload)) {
            $productId = data_get($payload, 'id');

            if (!empty($productId)) {
                return $this->normalizeProductGid((string) $productId);
            }
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

    private function wasRecentlyUpdatedByThisService(string $productId): bool
    {
        return Cache::has($this->recentUpdateCacheKey($productId));
    }

    private function markRecentlyUpdatedByThisService(string $productId): void
    {
        Cache::put(
            $this->recentUpdateCacheKey($productId),
            true,
            now()->addSeconds(self::RECENT_UPDATE_CACHE_SECONDS)
        );
    }

    private function recentUpdateCacheKey(string $productId): string
    {
        return 'shopify-single-product-price-updated:' . md5($productId);
    }
}
