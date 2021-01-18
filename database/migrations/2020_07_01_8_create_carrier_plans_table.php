<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCarrierPlansTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('carrier_plans', function (Blueprint $table) {
            $table->id();
            $table->string('plan_type', 50);
            $table->unsignedBigInteger('installed_carrier_id');
            $table->foreign('installed_carrier_id')
            ->references('id')->on('installed_carriers')
            ->onDelete('cascade');
            $table->string('pakg_price', 10);
            $table->string('pakg_duration', 10);
            $table->string('pakg_group', 10);
            $table->string('pakg_level', 10);
            $table->string('expiry_date',100);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('carrier_plans');
        $table->dropForeign(['carrier_id']);
    }
}
