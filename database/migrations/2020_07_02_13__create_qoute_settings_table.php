<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateQouteSettingsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('qoute_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('installed_carrier_id');
            $table->foreign('installed_carrier_id')
              ->references('id')->on('installed_carriers');
            $table->json('value');
            $table->dateTime('created_at'); 
            $table->dateTime('updated_at');         
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('qoute_settings');
        $table->dropForeign(['installed_carrier_id']);
    }
}
