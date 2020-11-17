<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAddonHasPlansTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('addon_has__plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('addon_id');
            $table->foreign('addon_id')
              ->references('id')->on('addons');
            $table->char('slug',100);
            $table->integer('price');
            $table->bigInteger('stripe_plan_id');
            $table->integer('duration_period');
            $table->tinyInteger('status');
            $table->integer('sort');
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
        Schema::dropIfExists('addon_has__plans');
        $table->dropForeign(['addon_id']);
    }
}
