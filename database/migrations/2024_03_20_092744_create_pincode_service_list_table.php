<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('pincode_service_list', function (Blueprint $table) {
            $table->id();
            $table->string('Pincode');
            $table->string('Area');
            $table->string('Sc');
            $table->string('Blue_Dart_Office');
            $table->string('Region');
            $table->string('Zone');
            $table->string('State');
            $table->string('Product');
            $table->string('Sub_Product');
            $table->string('TAT_Delivery_Time_Line');
            $table->string('Max_Value');
            $table->string('Air_Prepaid_Service');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('pincode_service_list');
    }
};
