<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateInstalledCarriersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('installed_carriers', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('store_id')->unique();;
            $table->bigInteger('carrier_plan_id')->unique();;
            //    $table->bigInteger('install_carrier_id')->unique();
            // $table->bigInteger('installed_app_id');
            $table->boolean('is_enabled')->default(false);
            $table->dateTime('installed_at'); 
            $table->dateTime('plan_updated_at');    
            $table->dateTime('plan_expiry_at'); 
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('installed_carriers');
    }
}
