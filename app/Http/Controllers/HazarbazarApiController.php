<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class HazarbazarApiController extends Controller
{
    public function handleCronJob(Request $request)
    {
        try {
            Log::channel('pricesync')->info('=== API Cron Triggered ===');

            // Call Artisan command
            Artisan::call('prices:sync');

            // Get output (optional)
            $output = Artisan::output();

            return response()->json([
                'status'  => 'success',
                'message' => 'Cron executed successfully',
                'output'  => $output
            ]);
        } catch (\Exception $e) {
            Log::channel('pricesync')->error('Cron failed', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
