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
            $table->char('name', 100);
            $table->unsignedBigInteger('carrier_id');
            $table->foreign('carrier_id')
            ->references('id')->on('carriers')
            ->onDelete('cascade');
            $table->char('slug', 100);
            $table->integer('price');
            $table->integer('duration_period');		
            $table->json('page_data');
            $table->tinyInteger('Status');
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
        Schema::dropIfExists('carrier_plans');
        $table->dropForeign(['carrier_id']);
    }
}
