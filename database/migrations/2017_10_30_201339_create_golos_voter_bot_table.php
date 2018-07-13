<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateGolosVoterBotTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('golos_voter_bots', function (Blueprint $table) {
            $table->increments('id');
            $table->text('author')->nullable();
            $table->text('link')->nullable();
            $table->longText('data')->nullable();
            $table->text('status')->nullable();
            $table->text('inline_message_id')->nullable();
            $table->text('result_id')->nullable();
            $table->text('chat_id')->nullable();
            $table->text('message_id')->nullable();
            $table->text('from_user')->nullable();
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
        Schema::dropIfExists('golos_voter_bot');
    }
}
