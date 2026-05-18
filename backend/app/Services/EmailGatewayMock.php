<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class EmailGatewayMock
{
    /**
     * Отправка Email через мок-шлюз
     *
     * @param string $email Email получателя
     * @param string $message Текст сообщения
     * @return array
     * @throws \Exception При таймауте
     */
    public function send($email, $message)
    {
        Log::info("EmailGatewayMock: попытка отправки на {$email}");

        // Валидация email
        if (!$this->isValidEmail($email)) {
            Log::warning("EmailGatewayMock: неверный email {$email}");
            return [
                'success' => false,
                'error' => 'Invalid email address format'
            ];
        }

        // Имитация задержки сети
        $delay = rand(100, 500);
        usleep($delay * 1000);

        // Симуляция различных сценариев
        $rand = rand(1, 100);

        // 5% - таймаут (будет retry)
        if ($rand <= 5) {
            Log::warning("EmailGatewayMock: таймаут при отправке на {$email}");
            throw new \Exception('Provider timeout: SMTP connection refused');
        }

        // 5% - ошибка валидации
        if ($rand <= 10) {
            Log::warning("EmailGatewayMock: ошибка валидации для {$email}");
            return [
                'success' => false,
                'error' => 'Email address does not exist or mailbox is full'
            ];
        }

        // 90% - успех
        Log::info("EmailGatewayMock: Email успешно отправлен на {$email}");

        return [
            'success' => true,
            'provider_id' => 'mock_email_' . uniqid(),
            'sent_at' => now()->toIso8601String()
        ];
    }

    /**
     * Проверка валидности email
     */
    private function isValidEmail($email)
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
