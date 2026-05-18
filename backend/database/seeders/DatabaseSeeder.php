<?php

namespace Database\Seeders;

use App\Models\Subscriber;
use App\Models\Notification;
use App\Models\NotificationStatus;
use App\Enums\NotificationStatusEnum;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Создаём 10 тестовых подписчиков с реальными номерами и email
        $subscribers = [];
        for ($i = 1; $i <= 10; $i++) {
            $subscribers[] = Subscriber::create([
                'phone' => '+7916' . str_pad($i, 7, '0', STR_PAD_LEFT),
                'email' => "user{$i}@example.com",
            ]);
        }

        // Создаём одну рассылку
        $notification = Notification::create([
            'idempotency_key' => 'test_seed_001',
            'channel' => 'sms',
            'message' => 'Test message from seeder',
            'priority' => 'low',
        ]);

        // Для каждого подписчика создаём статус
        foreach ($subscribers as $subscriber) {
            NotificationStatus::create([
                'notification_id' => $notification->id,
                'subscriber_id' => $subscriber->id,
                'status' => NotificationStatusEnum::DELIVERED,
                'processed_at' => now(),
            ]);
        }
    }
}
