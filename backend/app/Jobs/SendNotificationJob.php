<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Models\Subscriber;
use App\Models\NotificationStatus;
use App\Enums\NotificationStatusEnum;
use App\Services\SmsGatewayMock;
use App\Services\EmailGatewayMock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public $tries = 3;
    public $backoff = [5, 15, 30];

    protected $notificationId;
    protected $subscriberId;
    public $queue;

    public function __construct($notificationId, $subscriberId, $queue = 'low')
    {
        $this->notificationId = $notificationId;
        $this->subscriberId = $subscriberId;
        $this->queue = $queue;
    }

    public function handle(
        SmsGatewayMock $smsGateway,
        EmailGatewayMock $emailGateway
    ) {
        Log::info("Processing job for notification {$this->notificationId}, subscriber {$this->subscriberId} on {$this->queue} queue");

        // Получаем данные из БД
        $notification = Notification::find($this->notificationId);
        $subscriber = Subscriber::find($this->subscriberId);

        if (!$notification || !$subscriber) {
            Log::error("Notification or Subscriber not found");
            return;
        }

        $status = NotificationStatus::where('notification_id', $this->notificationId)
            ->where('subscriber_id', $this->subscriberId)
            ->first();

        if (!$status) {
            Log::error("Status not found");
            return;
        }

        // Обновляем статус на "отправляется"
        $status->update([
            'status' => NotificationStatusEnum::SENT,
            'processed_at' => now(),
        ]);

        // Отправка через мок-шлюз
        try {
            if ($notification->channel === 'sms') {
                $result = $smsGateway->send($subscriber->phone, $notification->message);
            } else {
                $result = $emailGateway->send($subscriber->email, $notification->message);
            }

            if ($result['success']) {
                $status->update([
                    'status' => NotificationStatusEnum::DELIVERED,
                ]);
                Log::info("✅ Message sent successfully to subscriber {$this->subscriberId}");
            } else {
                $status->update([
                    'status' => NotificationStatusEnum::DROPPED,
                    'error_message' => $result['error'] ?? 'Unknown error'
                ]);
                Log::warning("❌ Failed to send message: {$result['error']}");
            }

        } catch (\Exception $e) {
            // Временная ошибка — увеличиваем счётчик и делаем retry
            $status->increment('retry_count');
            Log::warning("Temporary error, attempt {$status->retry_count}: " . $e->getMessage());
            throw $e;  // Повторная попытка
        }
    }

    public function failed(\Throwable $e)
    {
        Log::error("Job failed after 3 attempts", [
            'notification_id' => $this->notificationId,
            'subscriber_id' => $this->subscriberId,
            'error' => $e->getMessage()
        ]);

        NotificationStatus::where('notification_id', $this->notificationId)
            ->where('subscriber_id', $this->subscriberId)
            ->update([
                'status' => NotificationStatusEnum::DROPPED,
                'error_message' => $e->getMessage()
            ]);
    }
}
