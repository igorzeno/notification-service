<?php

use App\Enums\NotificationStatusEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_statuses', function (Blueprint $table) {
            $table->id();
            $table->uuid('notification_id');
            $table->foreignId('subscriber_id')->constrained()->onDelete('cascade');

            // Используем Enum для статусов
            $table->enum('status', NotificationStatusEnum::values())
                ->default(NotificationStatusEnum::QUEUED->value);

            $table->text('error_message')->nullable();
            $table->integer('retry_count')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['notification_id', 'subscriber_id'], 'unique_notification_subscriber');
            $table->index('subscriber_id');
            $table->index('status');
            $table->index('created_at');
            $table->index(['subscriber_id', 'created_at'], 'idx_subscriber_status_history');

            $table->foreign('notification_id')
                ->references('id')
                ->on('notifications')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_statuses');
    }
};
