<?php

namespace App\Enums;

enum NotificationStatusEnum: string
{
    case QUEUED = 'queued';
    case SENT = 'sent';
    case DELIVERED = 'delivered';
    case DROPPED = 'dropped';

    public function label(): string
    {
        return match($this) {
            self::QUEUED => 'В очереди',
            self::SENT => 'Отправлено',
            self::DELIVERED => 'Доставлено',
            self::DROPPED => 'Отброшено',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
