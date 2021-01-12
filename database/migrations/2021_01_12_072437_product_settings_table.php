<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ProductSettingsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('product_settings', function (Blueprint $table) {
            $table->id();
            $table->char('name', 100);
            $table->char('variant_id', 50)->nullable();
            $table->bigInteger('source_product_id');
            $table->string('image_src', 300)->nullable();
            $table->string('product_type', 25)->nullable();
            $table->string('sku', 100)->nullable();
            $table->float('weight', 6, 2)->nullable();
            $table->float('length', 6, 2)->nullable();
            $table->float('width', 6, 2)->nullable();
            $table->float('height', 6, 2)->nullable();
            $table->float('price', 10, 2)->nullable();
            $table->unsignedBigInteger('store_id');
            $table->foreign('store_id')
                ->references('id')->on('stores')
                ->onDelete('cascade');
            $table->json('settings');
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
        Schema::dropIfExists('product_settings');
    }
}
