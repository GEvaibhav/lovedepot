<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (Schema::hasColumn('carrot_jewellery_products', '9k_gold_weight')) {
            return;
        }

        Schema::table('carrot_jewellery_products', function (Blueprint $table) {
            $table->string('9k_gold_weight')->nullable()->after('gold_weight');
        });
    }

    public function down()
    {
        if (!Schema::hasColumn('carrot_jewellery_products', '9k_gold_weight')) {
            return;
        }

        Schema::table('carrot_jewellery_products', function (Blueprint $table) {
            $table->dropColumn('9k_gold_weight');
        });
    }
};
