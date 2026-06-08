<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('carrot_jewellery_products', function (Blueprint $table) {
            $table->id();
            $table->string('shopify_product_id')->unique();
            $table->string('shopify_product_numeric_id')->nullable()->index();
            $table->string('title')->nullable();
            $table->string('gold_weight')->nullable();
            $table->string('making_charge')->nullable();
            $table->json('variants')->nullable();
            $table->string('metafield_hash', 64)->index();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('carrot_jewellery_products');
    }
};
