<?php
// database/migrations/2024_01_01_000000_create_properties_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePropertiesTable extends Migration
{
    public function up()
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->enum('type', ['rent', 'buy']);
            $table->decimal('price', 10, 2);
            $table->string('address');
            $table->string('district');
            $table->integer('floor');
            $table->integer('rooms');
            $table->boolean('has_lift')->default(false);
            $table->boolean('has_balcony')->default(false);
            $table->integer('bathroom');
            $table->string('type_home');
            $table->text('nearbu')->nullable();
            $table->string('date_use')->nullable();
            $table->decimal('apartment_area', 8, 2)->nullable();
            $table->text('description')->nullable();
            $table->json('images')->nullable();
            $table->json('assets_array')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('properties');
    }
}