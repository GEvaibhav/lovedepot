<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('carrot_jewellery_products', function (Blueprint $table) {
            if (!Schema::hasColumn('carrot_jewellery_products', 'diamond_weight')) {
                $table->string('diamond_weight')->nullable()->after('making_charge');
            }

            if (!Schema::hasColumn('carrot_jewellery_products', 'diamond_count')) {
                $table->string('diamond_count')->nullable()->after('diamond_weight');
            }
        });
    }

    public function down()
    {
        Schema::table('carrot_jewellery_products', function (Blueprint $table) {
            if (Schema::hasColumn('carrot_jewellery_products', 'diamond_count')) {
                $table->dropColumn('diamond_count');
            }

            if (Schema::hasColumn('carrot_jewellery_products', 'diamond_weight')) {
                $table->dropColumn('diamond_weight');
            }
        });
    }
};
