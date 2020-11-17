<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCarrierHasAddonsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('carrier_has__addons', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('addon_id');
            $table->foreign('addon_id')
              ->references('id')->on('addons');
            $table->unsignedBigInteger('carrier_tab_id');
            $table->foreign('carrier_tab_id')
              ->references('id')->on('carrier_tabs');
            $table->tinyInteger('is_enable');
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
        Schema::dropIfExists('carrier_has__addons');
        $table->dropForeign('addon_id','carrier_tab_id');

    }
}
