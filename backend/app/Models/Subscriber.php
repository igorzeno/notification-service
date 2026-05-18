<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subscriber extends Model
{
    use HasFactory;

    protected $fillable = ['phone', 'email'];

    // Связь со статусами
    public function notificationStatuses()
    {
        return $this->hasMany(NotificationStatus::class);
    }

    // Получить все уведомления подписчика
    public function notifications()
    {
        return $this->belongsToMany(
            Notification::class,
            'notification_statuses'
        )->withPivot('status', 'error_message', 'retry_count', 'processed_at');
    }
}
