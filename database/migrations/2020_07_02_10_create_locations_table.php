<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLocationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->char('nickname', 100)->nullable();
            $table->unsignedBigInteger('store_id');
             $table->foreign('store_id')
              ->references('id')->on('stores');
            $table->tinyInteger('type');  
            $table->char('city', 50);
            $table->char('state', 50); 
            $table->char('zip_code', 50);
            $table->char('country', 20);
            // $table->json('additional_params'); 
            $table->dateTime('created_at'); 
            $table->dateTime('updated_at');
            // $table->dateTime('deleted_at')->default(null);     
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('locations');
        $table->dropForeign(['store_id']);
    }
}
