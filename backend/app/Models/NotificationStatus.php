<?php

namespace App\Models;

use App\Enums\NotificationStatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationStatus extends Model
{
    use HasFactory;

    protected $fillable = [
        'notification_id',
        'subscriber_id',
        'status',
        'error_message',
        'retry_count',
        'processed_at'
    ];

    protected $casts = [
        'processed_at' => 'datetime',
        'retry_count' => 'integer',
        'status' => NotificationStatusEnum::class,  // 👈 каст в Enum
    ];

    // Связи
    public function notification()
    {
        return $this->belongsTo(Notification::class);
    }

    public function subscriber()
    {
        return $this->belongsTo(Subscriber::class);
    }

    // Проверки статусов
    public function isQueued(): bool
    {
        return $this->status === NotificationStatusEnum::QUEUED;
    }

    public function isSent(): bool
    {
        return $this->status === NotificationStatusEnum::SENT;
    }

    public function isDelivered(): bool
    {
        return $this->status === NotificationStatusEnum::DELIVERED;
    }

    public function isDropped(): bool
    {
        return $this->status === NotificationStatusEnum::DROPPED;
    }

    // Обновление статусов
    public function markAsSent(): void
    {
        $this->update([
            'status' => NotificationStatusEnum::SENT,
            'processed_at' => now()
        ]);
    }

    public function markAsDelivered(): void
    {
        $this->update(['status' => NotificationStatusEnum::DELIVERED]);
    }

    public function markAsDropped(?string $errorMessage = null): void
    {
        $this->update([
            'status' => NotificationStatusEnum::DROPPED,
            'error_message' => $errorMessage
        ]);
    }

    public function incrementRetryCount(): void
    {
        $this->increment('retry_count');
    }
}
