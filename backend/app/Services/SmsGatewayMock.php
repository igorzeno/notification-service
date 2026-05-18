<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class SmsGatewayMock
{
    /**
     * Отправка SMS через мок-шлюз
     *
     * @param string $phone Номер телефона
     * @param string $message Текст сообщения
     * @return array
     * @throws \Exception При таймауте
     */
    public function send($phone, $message)
    {
        Log::info("SmsGatewayMock: попытка отправки SMS на номер {$phone}");

        // Валидация номера (простая проверка)
        if (!$this->isValidPhone($phone)) {
            Log::warning("SmsGatewayMock: неверный номер {$phone}");
            return [
                'success' => false,
                'error' => 'Invalid phone number format'
            ];
        }

        // Имитация задержки сети
        $delay = rand(100, 500);
        usleep($delay * 1000);

        // Симуляция различных сценариев
        $rand = rand(1, 100);

        // 5% - таймаут (будет retry)
        if ($rand <= 5) {
            Log::warning("SmsGatewayMock: таймаут при отправке на {$phone}");
            throw new \Exception('Provider timeout: connection refused');
        }

        // 5% - ошибка валидации
        if ($rand <= 10) {
            Log::warning("SmsGatewayMock: ошибка валидации для {$phone}");
            return [
                'success' => false,
                'error' => 'Phone number is blocked or invalid'
            ];
        }

        // 90% - успех
        Log::info("SmsGatewayMock: SMS успешно отправлена на {$phone}");

        return [
            'success' => true,
            'provider_id' => 'mock_sms_' . uniqid(),
            'sent_at' => now()->toIso8601String()
        ];
    }

    /**
     * Проверка валидности номера телефона
     */
    private function isValidPhone($phone)
    {
        // Простая проверка: номер должен начинаться с +7, 8 или 7 и содержать 10-11 цифр
        $cleaned = preg_replace('/[^0-9+]/', '', $phone);
        return preg_match('/^(\+7|8|7)[0-9]{10}$/', $cleaned);
    }
}
