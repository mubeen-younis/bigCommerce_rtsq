<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBoxSizesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('box_sizes', function (Blueprint $table) {
            $table->id();
            // $table->char('nickname', 100)->unique();
            // $table->float('innner_length');
            // $table->float('inner_width');
            // $table->float('inner_height');
            // $table->float('outer_length');
            // $table->float('outer_width');
            // $table->float('outer_height');
            // $table->float('box_weight');
            // $table->float('box_fee');
            // $table->boolean('is_available')->default(false);
            // $table->boolean('is_fedex_box')->default(false);
            
            $table->char('nickname', 100)->unique();
            $table->float('length');
            $table->float('width');
            $table->float('height');
            $table->boolean('check_enable');
            $table->float('box_fee');
            $table->float('max_weight');
            $table->float('box_weight');       
            $table->boolean('is_available')->default(false);
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
        Schema::dropIfExists('box_sizes');
    }
}
