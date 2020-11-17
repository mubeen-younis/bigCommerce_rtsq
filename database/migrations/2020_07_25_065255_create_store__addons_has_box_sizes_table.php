<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateStoreAddonsHasBoxSizesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('store__addons_has_box_sizes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_has_addon_id');
            $table->foreign('store_has_addon_id')
              ->references('id')->on('store_has__addons');
            $table->unsignedBigInteger('box_size_id');
            $table->foreign('box_size_id')
              ->references('id')->on('box_sizes');
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
        Schema::dropIfExists('store__addons_has_box_sizes');
        $table->dropForeign(['store_has_addon_id','box_size_id']);
    }
}
