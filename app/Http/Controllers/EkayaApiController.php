<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EkayaApiController extends Controller
{
    public function getPincodeData(Request $request)
    {
        try {
            $pincode = $request->query('pincode');

            if (!$pincode) {
                return response()->json(['error' => 'Pincode parameter is required'], 400);
            }

            $pincodeData  = DB::table('pincode_service_list')
                ->where('Pincode', $pincode)
                ->select('State', 'TAT_Delivery_Time_Line')
                ->first();

            if (!$pincodeData) {
                return response()->json(['error' => 'No data found for the provided pincode'], 404);
            }

            return response()->json($pincodeData);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Something went wrong'], 500);
        }
    }
}
