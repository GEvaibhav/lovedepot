<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('carrot_jewellery_products', function (Blueprint $table) {
            if (!Schema::hasColumn('carrot_jewellery_products', 'diamond_rate')) {
                $table->string('diamond_rate')->nullable()->after('diamond_count');
            }
        });
    }

    public function down()
    {
        Schema::table('carrot_jewellery_products', function (Blueprint $table) {
            if (Schema::hasColumn('carrot_jewellery_products', 'diamond_rate')) {
                $table->dropColumn('diamond_rate');
            }
        });
    }
};
