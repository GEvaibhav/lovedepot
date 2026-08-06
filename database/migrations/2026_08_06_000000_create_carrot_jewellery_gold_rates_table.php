<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('carrot_jewellery_gold_rates', function (Blueprint $table) {
            $table->id();
            $table->decimal('rate_per_gram', 12, 2);
            $table->string('source')->default('prices:sync');
            $table->timestamp('fetched_at')->index();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('carrot_jewellery_gold_rates');
    }
};
