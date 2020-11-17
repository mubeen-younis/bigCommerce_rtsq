<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAdditionalCarrierTabSettingsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('additional_carrier_tab_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('carrier_id');
            $table->foreign('carrier_id')
              ->references('id')->on('carriers')
              ->onDelete('cascade');
            $table->unsignedBigInteger('store_id');
            $table->foreign('store_id')
              ->references('id')->on('stores')
              ->onDelete('cascade');
            $table->json('value');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('additional_carrier_tab_settings');
        $table->dropForeign('carriers_carrier_id_foreign','stores_store_id_foreign');
    }
}
