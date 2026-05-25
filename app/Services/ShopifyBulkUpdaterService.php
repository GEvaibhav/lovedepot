<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopifyBulkUpdaterService {
    private const BASE_GOLD_KARAT = 24;
    private const TARGET_GOLD_KARAT = 9;
    private const GOLD_PRICE_GST = 1.03;
    private const MAKING_CHARGE_PERCENT = 0.5;
    private const MAKING_CHARGE_GST = 1.05;
    private const GST_PERCENT = 0.03;

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
                            numberDiamond: metafield(namespace: "custom", key: "number_diamond") {
                                value
                            }
                            diamondWeight: metafield(namespace: "custom", key: "diamond_weight") {
                                value
                            }
                            variantGoldWeight: metafield(namespace: "custom", key: "net_fine") {
                                value
                            }
                            product {
                                id
                                title
                                status
                                goldWeight: metafield(namespace: "custom", key: "net_fine") {
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
                    'number_diamond' => data_get($node, 'numberDiamond.value'),
                    'diamond_weight' => data_get($node, 'diamondWeight.value'),
                    'variantGoldWeight' => data_get($node, 'variantGoldWeight.value'),
                    'goldWeight' => data_get($node, 'product.goldWeight.value'),
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

            if ($weightInGrams <= 0) {
                $noWeight++;
                Log::channel('pricesync')->debug('Skipped variant without variant or product gold weight metafield', [
                    'product_title' => $variant['product_title'],
                    'variant_title' => $variant['variant_title'],
                ]);
                continue;
            }

            // $goldRate9Kt = $goldRate * (self::TARGET_GOLD_KARAT / self::BASE_GOLD_KARAT);
            $goldPrice = $weightInGrams * $goldRate;
            $goldPriceGST = $goldPrice * (self::GOLD_PRICE_GST);
            $makingCharge = $goldPrice * (self::MAKING_CHARGE_PERCENT);
            $makingChargeGST = $makingCharge * (self::MAKING_CHARGE_GST);
            // $subtotal = $goldPrice + $makingChargeGST;
            // $gst = $subtotal * (self::GST_PERCENT / 100);
            $finalPrice = $goldPriceGST + $makingChargeGST;

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
                    // 'gold_rate_9kt' => round($goldRate9Kt, 4),
                    'gold_price' => round($goldPrice, 2),
                    'gold_price_gst' => round($goldPriceGST, 2),
                    'making_charge' => round($makingCharge, 2),
                    'making_charge_gst' => round($makingChargeGST, 2),
                    'final_price' => $newPrice,
                ]);
                continue;
            }

            Log::channel('pricesync')->debug('Prepared variant price update', [
                'product_title' => $variant['product_title'],
                'variant_title' => $variant['variant_title'],
                'weight_in_grams' => round($weightInGrams, 4),
                'gold_rate_24kt' => round($goldRate, 4),
                // 'gold_rate_9kt' => round($goldRate9Kt, 4),
                'gold_price' => round($goldPrice, 2),
                'gold_price_gst' => round($goldPriceGST, 2),
                'making_charge' => round($makingCharge, 2),
                'making_charge_gst' => round($makingChargeGST, 2),
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
