<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateStoresTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->char('name', 100);
            $table->text('url', 500);
            $table->char('hash', 100);
            $table->char('access_token', 255);
            $table->char('token', 255);
            $table->boolean('is_webhook_created')->default(false);
            $table->char('owner_email', 100);
            $table->char('owner_id', 50);
            // $table->bigInteger('owner_id')->unique();
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
        Schema::dropIfExists('stores');
    }
}
