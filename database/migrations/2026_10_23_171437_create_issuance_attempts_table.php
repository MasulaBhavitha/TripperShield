<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issuance_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_request_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('scenario');
            $table->string('provider_reference')->nullable();
            $table->string('status');
            $table->json('response_payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issuance_attempts');
    }
};
