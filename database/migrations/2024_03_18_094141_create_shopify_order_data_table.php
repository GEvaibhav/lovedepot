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
        if (Schema::hasTable('shopify_order_data')) {
            return;
        }

        Schema::create('shopify_order_data', function (Blueprint $table) {
            $table->id();
            $table->string('order_id');
            $table->string('order_name');
            $table->dateTime('order_date');
            $table->boolean('status')->default(false);
            $table->boolean('delete_order_Shopify')->default(false);
            $table->boolean('delete_order_wareiq')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('shopify_order_data');
    }
};
