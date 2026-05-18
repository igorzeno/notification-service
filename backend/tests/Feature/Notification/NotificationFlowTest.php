<?php

namespace Tests\Feature\Notification;

use Tests\TestCase;
use App\Models\Subscriber;
use App\Models\Notification;
use App\Models\NotificationStatus;
use App\Enums\NotificationStatusEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use App\Jobs\SendNotificationJob;

class NotificationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected $subscriber;

    protected function setUp(): void
    {
        parent::setUp();

        Redis::flushall();

        // Создаём тестового подписчика
        $this->subscriber = Subscriber::create([
            'phone' => '+79161234567',
            'email' => 'test@example.com'
        ]);
    }

    /**
     * Тест 1: Счастливый путь — массовая рассылка
     */
    public function test_bulk_send_creates_notifications_and_dispatches_jobs()
    {
        Queue::fake();

        $payload = [
            'channel' => 'sms',
            'message' => 'Test message',
            'recipient_ids' => [$this->subscriber->id],
            'priority' => 'high',
            'idempotency_key' => 'test_001'
        ];

        $response = $this->postJson('/api/notifications/bulk', $payload);

        $response->assertStatus(202)
            ->assertJsonStructure([
                'notification_id',
                'queued_count',
                'status'
            ]);

        // Проверяем, что запись в БД создалась
        $this->assertDatabaseHas('notifications', [
            'idempotency_key' => 'test_001',
            'channel' => 'sms',
            'priority' => 'high'
        ]);

        $this->assertDatabaseHas('notification_statuses', [
            'subscriber_id' => $this->subscriber->id,
            'status' => NotificationStatusEnum::QUEUED->value
        ]);

        // Проверяем, что задача ушла в очередь
        Queue::assertPushed(SendNotificationJob::class);
    }

    /**
     * Тест 2: Дедубликация — повторный запрос с тем же ключом
     */
    public function test_idempotency_prevents_duplicate_requests()
    {
        $payload = [
            'channel' => 'sms',
            'message' => 'Test message',
            'recipient_ids' => [$this->subscriber->id],
            'priority' => 'high',
            'idempotency_key' => 'unique_key_123'
        ];

        // Первый запрос
        $firstResponse = $this->postJson('/api/notifications/bulk', $payload);
        $firstResponse->assertStatus(202);

        // Второй запрос с тем же ключом
        $secondResponse = $this->postJson('/api/notifications/bulk', $payload);
        $secondResponse->assertStatus(200); // already_processed

        // Должна быть только одна запись в БД
        $this->assertEquals(1, Notification::where('idempotency_key', 'unique_key_123')->count());
    }

    /**
     * Тест 3: Получение истории статусов подписчика
     */
    public function test_subscriber_history_returns_statuses()
    {
        // Создаём тестовую рассылку
        $notification = Notification::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'idempotency_key' => 'history_test',
            'channel' => 'sms',
            'message' => 'History test',
            'priority' => 'low'
        ]);

        NotificationStatus::create([
            'notification_id' => $notification->id,
            'subscriber_id' => $this->subscriber->id,
            'status' => NotificationStatusEnum::DELIVERED,
            'processed_at' => now()
        ]);

        $response = $this->getJson("/api/subscribers/{$this->subscriber->id}/notifications");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'subscriber_id',
                'subscriber' => ['phone', 'email'],
                'notifications' => [
                    '*' => ['notification_id', 'status', 'status_label']
                ],
                'pagination'
            ]);
    }

    /**
     * Тест 4: Приоритет — HIGH обрабатывается раньше LOW
     * (проверяем через очередь)
     */
    public function test_priority_high_before_low()
    {
        Queue::fake();

        // Отправляем LOW
        $this->postJson('/api/notifications/bulk', [
            'channel' => 'sms',
            'message' => 'Low priority',
            'recipient_ids' => [$this->subscriber->id],
            'priority' => 'low',
            'idempotency_key' => 'low_priority'
        ]);

        // Отправляем HIGH
        $this->postJson('/api/notifications/bulk', [
            'channel' => 'sms',
            'message' => 'High priority',
            'recipient_ids' => [$this->subscriber->id],
            'priority' => 'high',
            'idempotency_key' => 'high_priority'
        ]);

        // HIGH должна быть отправлена в очередь high
        Queue::assertPushed(SendNotificationJob::class, function ($job) {
            return $job->queue === 'high';
        });
    }

    /**
     * Тест 5: Невалидный номер -> статус dropped
     */
    public function test_invalid_phone_leads_to_dropped_status()
    {
        // Создаём подписчика с невалидным номером
        $invalidSubscriber = Subscriber::create([
            'phone' => '123',  // невалидный номер
            'email' => 'test@example.com'
        ]);

        $payload = [
            'channel' => 'sms',
            'message' => 'Test message',
            'recipient_ids' => [$invalidSubscriber->id],
            'priority' => 'high',
            'idempotency_key' => 'dropped_test_' . uniqid()
        ];

        $response = $this->postJson('/api/notifications/bulk', $payload);
        $response->assertStatus(202);

        $notificationId = $response->json('notification_id');

        // Создаём задачу и запускаем обработку
        $job = new SendNotificationJob($notificationId, $invalidSubscriber->id);

        // Создаём реальные шлюзы
        $smsGateway = new \App\Services\SmsGatewayMock();
        $emailGateway = new \App\Services\EmailGatewayMock();

        $job->handle($smsGateway, $emailGateway);

        // Проверяем, что статус стал dropped
        $this->assertDatabaseHas('notification_statuses', [
            'notification_id' => $notificationId,
            'subscriber_id' => $invalidSubscriber->id,
            'status' => NotificationStatusEnum::DROPPED->value
        ]);
    }

    /**
     * Тест 6: Статус меняется с QUEUED на DELIVERED после обработки
     */
    public function test_status_changes_from_queued_to_delivered()
    {
        $payload = [
            'channel' => 'sms',
            'message' => 'Test status flow',
            'recipient_ids' => [$this->subscriber->id],
            'priority' => 'high',
            'idempotency_key' => 'status_flow_test_' . uniqid()
        ];

        $response = $this->postJson('/api/notifications/bulk', $payload);
        $response->assertStatus(202);

        $notificationId = $response->json('notification_id');

        // Запускаем обработку
        $job = new SendNotificationJob($notificationId, $this->subscriber->id, 'high');
        $smsGateway = new \App\Services\SmsGatewayMock();
        $emailGateway = new \App\Services\EmailGatewayMock();
        $job->handle($smsGateway, $emailGateway);

        // Проверяем, что статус стал DELIVERED
        $this->assertDatabaseHas('notification_statuses', [
            'notification_id' => $notificationId,
            'subscriber_id' => $this->subscriber->id,
            'status' => NotificationStatusEnum::DELIVERED->value
        ]);
    }
}
