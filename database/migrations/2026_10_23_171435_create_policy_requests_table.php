<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_requests', function (Blueprint $table) {
            $table->id();
            $table->string('client_request_id')->unique();
            $table->string('agent_id');
            $table->string('customer_name');
            $table->date('date_of_birth');
            $table->string('destination');
            $table->date('trip_start');
            $table->date('trip_end');
            $table->string('plan_code');
            $table->integer('sum_insured');
            $table->decimal('premium_amount', 12, 2)->nullable();
            $table->json('premium_snapshot')->nullable();
            $table->string('provider_reference')->nullable();
            $table->string('policy_number')->nullable()->unique();
            $table->string('state')->default('RECEIVED');
            $table->string('provider_status')->nullable();
            $table->json('last_response')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamps();

            $table->unique(['agent_id', 'client_request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_requests');
    }
};
