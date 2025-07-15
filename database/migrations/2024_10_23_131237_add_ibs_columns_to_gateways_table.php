<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('gateways', function (Blueprint $table) {
            $table->string('token_ibs', 191)->charset('utf8mb4')->collation('utf8mb4_unicode_ci')->nullable();
            $table->string('secret_ibs', 191)->charset('utf8mb4')->collation('utf8mb4_unicode_ci')->nullable();
            $table->string('url_ibs', 191)->charset('utf8mb4')->collation('utf8mb4_unicode_ci')->nullable();
        });
    }

    public function down()
    {
        Schema::table('gateways', function (Blueprint $table) {
            $table->dropColumn(['token_ibs', 'secret_ibs', 'url_ibs']);
        });
    }

};
