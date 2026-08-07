<?php

namespace App\Services;

use App\Models\CarrotJewelleryGoldRate;
use Illuminate\Support\Facades\Log;

class GoldRateService
{
    public function recordRate(float $ratePerGram, string $source = 'prices:sync'): CarrotJewelleryGoldRate
    {
        $goldRate = CarrotJewelleryGoldRate::create([
            'rate_per_gram' => $ratePerGram,
            'source' => $source,
            'fetched_at' => now(),
        ]);

        Log::channel('pricesync')->info('Gold rate saved to database', [
            'gold_rate_id' => $goldRate->id,
            'rate_per_gram' => $goldRate->rate_per_gram,
            'source' => $goldRate->source,
            'fetched_at' => optional($goldRate->fetched_at)->toDateTimeString(),
        ]);

        return $goldRate;
    }

    public function latestRatePerGram(): ?float
    {
        $goldRate = CarrotJewelleryGoldRate::query()
            ->latest('fetched_at')
            ->latest('id')
            ->first();

        if ($goldRate === null) {
            Log::channel('pricesync')->warning('No saved gold rate found in database');

            return null;
        }

        return (float) $goldRate->rate_per_gram;
    }
}
