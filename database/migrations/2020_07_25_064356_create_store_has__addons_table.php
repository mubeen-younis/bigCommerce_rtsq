<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateStoreHasAddonsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('store_has__addons', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('carrier_has_addon_id');
            $table->foreign('carrier_has_addon_id')
              ->references('id')->on('carrier_has__addons');
            $table->unsignedBigInteger('addon_has_plan_id');
            $table->foreign('addon_has_plan_id')
              ->references('id')->on('addon_has__plans');
            $table->unsignedBigInteger('store_id');
            $table->foreign('store_id')
              ->references('id')->on('stores');
            $table->json('value_settings');
            $table->dateTime('created_at');         
            $table->dateTime('updated_at');
            $table->dateTime('expiry_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('store_has__addons');
        $table->dropForeign(['carrier_has_addon_id','addon_has_plan_id','store_id']);
    }
}
