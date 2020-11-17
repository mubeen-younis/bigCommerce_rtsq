<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCarrierTabsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('carrier_tabs', function (Blueprint $table) {
            $table->id();
            $table->char('name', 100);
            $table->char('slug', 100);
            $table->unsignedBigInteger('carrier_id');
            $table->foreign('carrier_id')
            ->references('id')->on('carriers')
            ->onDelete('cascade');
            $table->json('page_data');
            $table->tinyInteger('sort');          
            $table->boolean('default_page')->default(false);
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
        Schema::dropIfExists('carrier_tabs');
        $table->dropForeign(['carrier_id']);
    }
}
