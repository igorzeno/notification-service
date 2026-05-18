<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\Subscriber;
use App\Models\NotificationStatus;
use App\Enums\NotificationStatusEnum;
use App\Jobs\SendNotificationJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NotificationController extends Controller
{
    /**
     * POST /api/notifications/bulk
     *
     * Массовая рассылка уведомлений
     */
    public function bulkSend(Request $request)
    {
        // 1. Валидация
        $validated = $request->validate([
            'channel' => 'required|in:sms,email',
            'message' => 'required|string|max:1000',
            'recipient_ids' => 'required|array|min:1|max:10000',
            'recipient_ids.*' => 'exists:subscribers,id',
            'priority' => 'required|in:high,low',
            'idempotency_key' => 'required|string|max:255'
        ]);

        $idempotencyKey = $validated['idempotency_key'];

        // 2. Дедубликация: проверяем в БД (главный источник истины)
        $existingNotification = Notification::where('idempotency_key', $idempotencyKey)->first();

        if ($existingNotification) {
            return response()->json([
                'notification_id' => $existingNotification->id,
                'status' => 'already_processed',
                'message' => 'This request has already been processed'
            ], 200);
        }

        // 3. Дедубликация: блокировка в Redis (защита от параллельных запросов)
        $lockKey = "idempotent:{$idempotencyKey}";
        $lock = Redis::setnx($lockKey, 'processing');

        if (!$lock) {
            // Ждём 1 секунду и проверяем БД ещё раз
            sleep(1);
            $existingNotification = Notification::where('idempotency_key', $idempotencyKey)->first();
            if ($existingNotification) {
                return response()->json([
                    'notification_id' => $existingNotification->id,
                    'status' => 'already_processed'
                ], 200);
            }
            return response()->json(['error' => 'Duplicate request'], 409);
        }

        // Устанавливаем TTL 24 часа
        Redis::expire($lockKey, 86400);

        try {
            DB::beginTransaction();

            // 4. Создаём уведомление
            $notification = Notification::create([
                'id' => (string) Str::uuid(),
                'idempotency_key' => $idempotencyKey,
                'channel' => $validated['channel'],
                'message' => $validated['message'],
                'priority' => $validated['priority']
            ]);

            $queue = $validated['priority'] === 'high' ? 'high' : 'low';

            // 5. Для каждого получателя создаём статус и отправляем задачу
            foreach ($validated['recipient_ids'] as $recipientId) {
                NotificationStatus::create([
                    'notification_id' => $notification->id,
                    'subscriber_id' => $recipientId,
                    'status' => NotificationStatusEnum::QUEUED
                ]);

                // Рабочий способ отправить задачу в очередь с приоритетом
                dispatch(new SendNotificationJob($notification->id, $recipientId, $queue));
            }

            DB::commit();

            return response()->json([
                'notification_id' => $notification->id,
                'queued_count' => count($validated['recipient_ids']),
                'status' => 'processing'
            ], 202);

        } catch (\Exception $e) {
            DB::rollBack();
            Redis::del($lockKey);

            return response()->json([
                'error' => 'Failed to process request',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/subscribers/{id}/notifications
     *
     * История статусов подписчика
     */
    public function subscriberHistory($subscriberId)
    {
        // Проверяем, существует ли подписчик
        $subscriber = Subscriber::find($subscriberId);

        if (!$subscriber) {
            return response()->json(['error' => 'Subscriber not found'], 404);
        }

        $statuses = NotificationStatus::with('notification')
            ->where('subscriber_id', $subscriberId)
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'subscriber_id' => $subscriberId,
            'subscriber' => [
                'phone' => $subscriber->phone,
                'email' => $subscriber->email,
            ],
            'notifications' => $statuses->map(function ($status) {
                return [
                    'notification_id' => $status->notification_id,
                    'channel' => $status->notification->channel ?? null,
                    'message' => $status->notification->message ?? null,
                    'priority' => $status->notification->priority ?? null,
                    'status' => $status->status->value,
                    'status_label' => $status->status->label(),
                    'error_message' => $status->error_message,
                    'retry_count' => $status->retry_count,
                    'processed_at' => $status->processed_at,
                    'created_at' => $status->created_at,
                ];
            }),
            'pagination' => [
                'current_page' => $statuses->currentPage(),
                'last_page' => $statuses->lastPage(),
                'per_page' => $statuses->perPage(),
                'total' => $statuses->total(),
            ]
        ]);
    }
}
