<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Notification extends Model
{
    use HasFactory;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'idempotency_key',
        'channel',
        'message',
        'priority'
    ];

    // Автоматическая генерация UUID
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    // Связь со статусами
    public function statuses()
    {
        return $this->hasMany(NotificationStatus::class);
    }

    // Получить всех получателей рассылки
    public function subscribers()
    {
        return $this->belongsToMany(
            Subscriber::class,
            'notification_statuses'
        )->withPivot('status', 'error_message', 'retry_count', 'processed_at');
    }

    // Подсчёт статистики
    public function getStatsAttribute()
    {
        return [
            'total' => $this->statuses()->count(),
            'queued' => $this->statuses()->where('status', 'queued')->count(),
            'sent' => $this->statuses()->where('status', 'sent')->count(),
            'delivered' => $this->statuses()->where('status', 'delivered')->count(),
            'dropped' => $this->statuses()->where('status', 'dropped')->count(),
        ];
    }
}
