<?php

namespace App\Console\Commands;

use App\Services\GoldPriceFetcherService;
use App\Services\ShopifyBulkUpdaterService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncPricesCommand extends Command
{
    protected $signature = 'prices:sync';
    protected $description = 'Fetch gold rate and update active Shopify variant prices by weight';

    public function handle(
        GoldPriceFetcherService $fetcher,
        ShopifyBulkUpdaterService $updater
    ): int {
        $start = now();
        Log::channel('pricesync')->info('=== Gold price sync started ===');

        $goldRate = $fetcher->fetchRatePerGram();

        if ($goldRate === null) {
            $this->error('Failed to fetch gold rate. Aborting.');
            return self::FAILURE;
        }

        $this->info('Gold RTGS rate: Rs ' . $goldRate . '/gram');

        $variants = $updater->fetchAllVariants();
        if (empty($variants)) {
            $this->error('No variants found in Shopify. Aborting.');
            return self::FAILURE;
        }

        $this->info('Found ' . count($variants) . ' variants');

        $groupedUpdates = $updater->buildPriceUpdates($variants, $goldRate);
        if (empty($groupedUpdates)) {
            $this->info('All prices are already up to date.');
            Log::channel('pricesync')->info('No price changes needed. Done.');
            return self::SUCCESS;
        }

        $result = $updater->updatePrices($groupedUpdates);

        $duration = now()->diffInSeconds($start);
        Log::channel('pricesync')->info("=== Sync finished in {$duration}s ===", $result);

        if (($result['failed'] ?? 0) === 0) {
            $this->info('Sync completed. Variants updated: ' . ($result['updated'] ?? 0));
            return self::SUCCESS;
        }

        $this->error(
            'Sync finished with errors. Variants updated: '
            . ($result['updated'] ?? 0)
            . ', failed: '
            . ($result['failed'] ?? 0)
        );

        return self::FAILURE;
    }
}
