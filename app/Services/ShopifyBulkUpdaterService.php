<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopifyBulkUpdaterService {
    private const MAKING_CHARGE_PERCENT = 1.45;
    private const CHARGE_GST = 1.03;

    private string $endpoint;
    private array $headers;
    private bool $verifySsl;

    public function __construct() {
        $domain = config('shopify.store_domain');
        $version = config('shopify.api_version');
        $this->verifySsl = (bool) config('shopify.verify_ssl', !app()->environment('local'));

        $this->endpoint = "https://{$domain}/admin/api/{$version}/graphql.json";
        $this->headers = [
            'X-Shopify-Access-Token' => config('shopify.access_token'),
            'Content-Type' => 'application/json',
        ];
    }

    private function shopifyRequest(): PendingRequest {
        return Http::withHeaders($this->headers)->withOptions([
            'verify' => $this->verifySsl,
        ]);
    }

    public function fetchAllVariants(): array {
        $variants = [];
        $cursor = null;
        $page = 1;

        do {
            $afterClause = $cursor ? ', after: "' . $cursor . '"' : '';

            $query = <<<GQL
            {
                productVariants(first: 250{$afterClause}) {
                    edges {
                        cursor
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
                            product {
                                id
                                title
                                status
                                goldWeight: metafield(namespace: "custom", key: "net_fine") {
                                    value
                                }
                                makingCharge: metafield(namespace: "custom", key: "making") {
                                    value
                                }
                            }
                        }
                    }
                    pageInfo {
                        hasNextPage
                    }
                }
            }
            GQL;

            $response = $this->shopifyRequest()
                ->post($this->endpoint, ['query' => $query]);

            if ($response->failed()) {
                Log::channel('pricesync')->error('Failed to fetch variants', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'endpoint' => $this->endpoint,
                    'ssl_verification' => $this->verifySsl,
                ]);
                break;
            }

            $errors = $response->json('errors');
            if (!empty($errors)) {
                Log::channel('pricesync')->error('Shopify GraphQL returned errors while fetching variants', [
                    'errors' => $errors,
                    'endpoint' => $this->endpoint,
                    'store_domain' => config('shopify.store_domain'),
                ]);
                break;
            }

            $data = $response->json('data.productVariants');
            if ($data === null) {
                Log::channel('pricesync')->error('Shopify productVariants payload missing', [
                    'response' => $response->json(),
                    'endpoint' => $this->endpoint,
                    'store_domain' => config('shopify.store_domain'),
                ]);
                break;
            }

            $edges = $data['edges'] ?? [];

            if ($page === 1 && empty($edges)) {
                Log::channel('pricesync')->warning('Shopify returned zero variants on first page', [
                    'store_domain' => config('shopify.store_domain'),
                    'endpoint' => $this->endpoint,
                    'response' => $response->json(),
                ]);
            }

            foreach ($edges as $edge) {
                $node = $edge['node'];
                $productStatus = data_get($node, 'product.status');

                $cursor = $edge['cursor'];

                if ($productStatus !== 'ACTIVE') {
                    continue;
                }

                $variants[] = [
                    'gid' => $node['id'],
                    'product_gid' => $node['product']['id'] ?? null,
                    'product_title' => $node['product']['title'] ?? '',
                    'variant_title' => $node['title'] ?? '',
                    'current_price' => $node['price'],
                    'variantGoldWeight' => data_get($node, 'variantGoldWeight.value'),
                    'variantMakingCharge' => data_get($node, 'variantMakingCharge.value'),
                    'goldWeight' => data_get($node, 'product.goldWeight.value'),
                    'makingCharge' => data_get($node, 'product.makingCharge.value'),
                ];
            }

            $hasNext = $data['pageInfo']['hasNextPage'] ?? false;
            $page++;
        } while ($hasNext);

        Log::channel('pricesync')->info('Fetched Shopify variants', [
            'count' => count($variants),
            'store_domain' => config('shopify.store_domain'),
            'endpoint' => $this->endpoint,
        ]);

        return $variants;
    }

    public function fetchAllProductSnapshots(): array {
        $products = [];
        $cursor = null;
        $page = 1;

        do {
            $afterClause = $cursor ? ', after: "' . $cursor . '"' : '';

            $query = <<<GQL
            {
                products(first: 250{$afterClause}) {
                    edges {
                        cursor
                        node {
                            id
                            title
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
                    pageInfo {
                        hasNextPage
                    }
                }
            }
            GQL;

            $response = $this->shopifyRequest()
                ->post($this->endpoint, ['query' => $query]);

            if ($response->failed()) {
                Log::channel('pricesync')->error('Failed to fetch products for carrot jewellery snapshot', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'endpoint' => $this->endpoint,
                    'ssl_verification' => $this->verifySsl,
                ]);

                throw new \RuntimeException('Shopify product fetch failed with HTTP status ' . $response->status() . '.');
            }

            $errors = $response->json('errors');
            if (!empty($errors)) {
                Log::channel('pricesync')->error('Shopify GraphQL returned errors while fetching product snapshots', [
                    'errors' => $errors,
                    'endpoint' => $this->endpoint,
                    'store_domain' => config('shopify.store_domain'),
                ]);

                throw new \RuntimeException('Shopify GraphQL error: ' . json_encode($errors));
            }

            $data = $response->json('data.products');
            if ($data === null) {
                Log::channel('pricesync')->error('Shopify products payload missing', [
                    'response' => $response->json(),
                    'endpoint' => $this->endpoint,
                    'store_domain' => config('shopify.store_domain'),
                ]);

                throw new \RuntimeException('Shopify products payload missing.');
            }

            $edges = $data['edges'] ?? [];

            if ($page === 1 && empty($edges)) {
                Log::channel('pricesync')->warning('Shopify returned zero products on first page', [
                    'store_domain' => config('shopify.store_domain'),
                    'endpoint' => $this->endpoint,
                    'response' => $response->json(),
                ]);
            }

            foreach ($edges as $edge) {
                $node = $edge['node'] ?? [];
                $cursor = $edge['cursor'];
                $variants = [];

                foreach (data_get($node, 'variants.edges', []) as $variantEdge) {
                    $variant = $variantEdge['node'] ?? [];

                    $variants[] = [
                        'gid' => $variant['id'] ?? null,
                        'title' => $variant['title'] ?? '',
                        'goldWeight' => data_get($variant, 'variantGoldWeight.value'),
                        'makingCharge' => data_get($variant, 'variantMakingCharge.value'),
                    ];
                }

                $products[] = [
                    'product_gid' => $node['id'] ?? null,
                    'product_title' => $node['title'] ?? '',
                    'goldWeight' => data_get($node, 'goldWeight.value'),
                    'makingCharge' => data_get($node, 'makingCharge.value'),
                    'variants' => $variants,
                ];
            }

            $hasNext = $data['pageInfo']['hasNextPage'] ?? false;
            $page++;
        } while ($hasNext);

        Log::channel('pricesync')->info('Fetched Shopify product snapshots', [
            'count' => count($products),
            'store_domain' => config('shopify.store_domain'),
            'endpoint' => $this->endpoint,
        ]);

        return array_values(array_filter($products, fn ($product) => !empty($product['product_gid'])));
    }

    public function buildPriceUpdates(array $variants, float $goldRate): array {
        $skipped = 0;
        $noWeight = 0;
        $groupedUpdates = [];

        foreach ($variants as $variant) {
            $weightInGrams = (float) (
                $variant['variantGoldWeight']
                ?? $variant['goldWeight']
                ?? 0
            );

            // $makingPercent = (float) (
            //     $variant['variantMakingCharge']
            //     ?? $variant['makingCharge']
            //     ?? 45
            // );

            $makingPercent = (float) (
                 $variant['makingCharge']
                ?? 45
            );

            if ($weightInGrams <= 0) {
                $noWeight++;
                Log::channel('pricesync')->debug('Skipped variant without variant or product gold weight metafield', [
                    'product_title' => $variant['product_title'],
                    'variant_title' => $variant['variant_title'],
                ]);
                continue;
            }

            $goldPrice = $weightInGrams * $goldRate;
            $makingCharge = $goldPrice * (1 + $makingPercent / 100);
            $finalPrice = $makingCharge * (self::CHARGE_GST);

            $newPrice = number_format($finalPrice, 2, '.', '');
            $currentPrice = number_format((float) $variant['current_price'], 2, '.', '');

            if ($currentPrice === $newPrice) {
                $skipped++;
                continue;
            }

            if (empty($variant['product_gid'])) {
                Log::channel('pricesync')->warning('Skipped variant without product id', [
                    'variant_id' => $variant['gid'],
                    'product_title' => $variant['product_title'],
                    'variant_title' => $variant['variant_title'],
                    'weight_in_grams' => round($weightInGrams, 4),
                    'gold_rate_24kt' => round($goldRate, 4),
                    'gold_price' => round($goldPrice, 2),
                    'making_charge' => round($makingCharge, 2),
                    'final_price' => $newPrice,
                ]);
                continue;
            }

            Log::channel('pricesync')->debug('Prepared variant price update', [
                'product_title' => $variant['product_title'],
                'variant_title' => $variant['variant_title'],
                'weight_in_grams' => round($weightInGrams, 4),
                'gold_rate_24kt' => round($goldRate, 4),
                'gold_price' => round($goldPrice, 2),
                'making_charge' => round($makingCharge, 2),
                'final_price' => $newPrice,
            ]);

            $groupedUpdates[$variant['product_gid']][] = [
                'id' => $variant['gid'],
                'price' => $newPrice,
            ];
        }

        Log::channel('pricesync')->info('Price updates prepared', [
            'updates' => array_sum(array_map('count', $groupedUpdates)),
            'products' => count($groupedUpdates),
            'skipped' => $skipped,
            'no_weight' => $noWeight,
        ]);

        return $groupedUpdates;
    }

    public function updatePrices(array $groupedUpdates): array {
        $mutation = <<<'GQL'
        mutation UpdateProductVariants($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
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

        $summary = [
            'updated' => 0,
            'failed' => 0,
            'products' => count($groupedUpdates),
            'errors' => [],
        ];

        foreach ($groupedUpdates as $productId => $variants) {
            $response = $this->shopifyRequest()->post($this->endpoint, [
                'query' => $mutation,
                'variables' => [
                    'productId' => $productId,
                    'variants' => $variants,
                ],
            ]);

            if ($response->failed()) {
                $summary['failed'] += count($variants);
                $summary['errors'][] = [
                    'product_id' => $productId,
                    'type' => 'http',
                    'status' => $response->status(),
                    'body' => $response->body(),
                ];
                continue;
            }

            $errors = $response->json('errors');
            if (!empty($errors)) {
                $summary['failed'] += count($variants);
                $summary['errors'][] = [
                    'product_id' => $productId,
                    'type' => 'graphql',
                    'errors' => $errors,
                ];
                continue;
            }

            $result = $response->json('data.productVariantsBulkUpdate');
            if ($result === null) {
                $summary['failed'] += count($variants);
                $summary['errors'][] = [
                    'product_id' => $productId,
                    'type' => 'missing_payload',
                    'response' => $response->json(),
                ];
                continue;
            }

            if (!empty($result['userErrors'])) {
                $summary['failed'] += count($variants);
                $summary['errors'][] = [
                    'product_id' => $productId,
                    'type' => 'user_errors',
                    'errors' => $result['userErrors'],
                ];
                continue;
            }

            $updatedCount = count($result['productVariants'] ?? []);
            $summary['updated'] += $updatedCount;

            Log::channel('pricesync')->info('Updated product variants', [
                'product_id' => $productId,
                'updated_count' => $updatedCount,
            ]);
        }

        Log::channel('pricesync')->info('Direct variant update finished', $summary);

        return $summary;
    }
}
