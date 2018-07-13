<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateGolosBotsSettingsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('golos_bots_settings', function (Blueprint $table) {
            $table->increments('id');
            $table->string('chat_id');
            $table->string('bot_name')->default('tbotebot');
            $table->string('lang')->default('ru');
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
        Schema::dropIfExists('golos_bots_settings');
    }
}
