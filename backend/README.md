# Notification Service

Микросервис для массовой рассылки SMS и Email-уведомлений с поддержкой приоритетов, асинхронной обработкой через RabbitMQ и дедубликацией запросов.

---

## Технологический стек

- **PHP 8.4** + **Laravel 12**
- **PostgreSQL** — основная база данных
- **RabbitMQ** — брокер сообщений (очереди high/low)
- **Redis** — кэш и дедубликация
- **Docker** + **Docker Compose** — контейнеризация
- **Supervisor** — управление воркерами
- **PHPUnit** — интеграционные тесты

---

## Функциональные возможности

- ✅ Массовая рассылка SMS/Email
- ✅ Приоритеты: HIGH (транзакционные) > LOW (маркетинговые)
- ✅ Асинхронная обработка через очереди
- ✅ Статусы доставки: `queued` → `sent` → `delivered` / `dropped`
- ✅ Дедубликация (Idempotency) через Redis + PostgreSQL
- ✅ Retry-механизмы (3 попытки с задержкой 5, 15, 30 сек)
- ✅ Health-check сервисов
- ✅ Интеграционные тесты

---
### 1. Клонировать репозиторий

```bash
git clone https://github.com/your-username/notification-service.git
cd notification-service

2. Создать .env файл

```bash
cp backend/.env.example backend/.env

3. Запустить контейнеры
bash

docker compose up -d --build
docker compose exec app php artisan key:generate

4. Выполнить миграции и сидеры
bash

docker compose exec app php artisan migrate:fresh --seed

5. Проверить статус воркеров
bash

docker compose exec app supervisorctl status

Все процессы должны быть RUNNING.
API Endpoints
1. Массовая рассылка
http

POST /api/notifications/bulk
Content-Type: application/json

Тело запроса:
json

{
  "channel": "sms",
  "message": "Hello world!",
  "recipient_ids": [1, 2, 3],
  "priority": "high",
  "idempotency_key": "unique_key_123"
}

Поля:

    channel — sms или email

    message — текст сообщения (max 1000 символов)

    recipient_ids — массив ID подписчиков

    priority — high (транзакционные) или low (маркетинг)

    idempotency_key — уникальный ключ для дедубликации

Успешный ответ (202 Accepted):
json

{
  "notification_id": "550e8400-e29b-41d4-a716-446655440000",
  "queued_count": 3,
  "status": "processing"
}

2. История статусов подписчика
http

GET /api/subscribers/{id}/notifications

Пример ответа:
json

{
  "subscriber_id": 1,
  "subscriber": {
    "phone": "+79161234567",
    "email": "user@example.com"
  },
  "notifications": [
    {
      "notification_id": "uuid-123",
      "channel": "sms",
      "message": "Hello!",
      "priority": "high",
      "status": "delivered",
      "status_label": "Доставлено",
      "processed_at": "2026-05-17T17:04:00.000000Z"
    }
  ],
  "pagination": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 20,
    "total": 1
  }
}


## 🔍 Проверка работы сервиса
1. Отправить HIGH приоритет (SMS)
bash

curl -X POST http://localhost:8000/api/notifications/bulk \
  -H "Content-Type: application/json" \
  -d '{
    "channel": "sms",
    "message": "HIGH priority message!",
    "recipient_ids": [1,2,3],
    "priority": "high",
    "idempotency_key": "test_high_'$(date +%s)'"
  }'

Ожидаемый ответ:
json

{
  "notification_id": "uuid-xxx",
  "queued_count": 3,
  "status": "processing"
}

2. Отправить LOW приоритет (Email)
bash

curl -X POST http://localhost:8000/api/notifications/bulk \
  -H "Content-Type: application/json" \
  -d '{
    "channel": "email",
    "message": "LOW priority newsletter",
    "recipient_ids": [1,2,3],
    "priority": "low",
    "idempotency_key": "test_low_'$(date +%s)'"
  }'

3. Проверить статусы подписчика
bash

curl http://localhost:8000/api/subscribers/1/notifications

Ожидаемый ответ: массив уведомлений со статусами queued → sent → delivered
4. Проверить приоритеты (очереди)
bash

# Логи high очереди
docker compose exec app tail -20 /var/www/backend/storage/logs/worker-high.log

# Логи low очереди
docker compose exec app tail -20 /var/www/backend/storage/logs/worker-low.log

Ожидаем: HIGH обработались раньше LOW
5. Проверить дедубликацию
bash

# Первый запрос
curl -X POST http://localhost:8000/api/notifications/bulk \
  -H "Content-Type: application/json" \
  -d '{
    "channel": "sms",
    "message": "Test dedup",
    "recipient_ids": [1],
    "priority": "low",
    "idempotency_key": "same_key_123"
  }'

# Второй запрос (тот же ключ)
curl -X POST http://localhost:8000/api/notifications/bulk \
  -H "Content-Type: application/json" \
  -d '{
    "channel": "sms",
    "message": "Test dedup",
    "recipient_ids": [1],
    "priority": "low",
    "idempotency_key": "same_key_123"
  }'

Ожидаем:

    Первый → 202 Accepted

    Второй → 200 already_processed


## Postman коллекция

Для тестирования API импортируйте коллекцию в Postman:

[Скачать Postman коллекцию](./postman/NotificationService.postman_collection.json)

### Endpoints в коллекции:
- `POST /api/notifications/bulk` — массовая рассылка
- `GET /api/subscribers/{id}/notifications` — история статусов


Тестирование
Запуск интеграционных тестов
bash

docker compose exec app php vendor/bin/phpunit

Результат:
OK (6 tests, 26 assertions)

Полезные команды
Просмотр логов
bash

# Логи воркеров
docker compose exec app tail -f /var/www/backend/storage/logs/worker-high.log
docker compose exec app tail -f /var/www/backend/storage/logs/worker-low.log

# Логи Laravel
docker compose exec app tail -f /var/www/backend/storage/logs/laravel.log

Управление воркерами
bash

docker compose exec app supervisorctl status
docker compose exec app supervisorctl restart all

Очистка кэша
bash

docker compose exec app php artisan optimize:clear

Архитектура
Клиент → API → Redis (дедубликация) → БД (статус queued) → RabbitMQ (очередь)
                                                                      ↓
                                                              Воркеры (Supervisor)
                                                                      ↓
                                                              Мок-шлюзы (SMS/Email)
                                                                      ↓
                                                              БД (статус delivered)
                                                                      ↓
Клиент ← API ← БД (статусы для проверки)

Структура проекта

notification-service/
├── backend/                 # Laravel проект
│   ├── app/                 # Основной код
│   ├── database/            # Миграции и сидеры
│   └── tests/               # Тесты
├── docker/                  # Dockerfile и конфиги
├── postman/                 # Postman коллекция
├── docker-compose.yml
└── README.md

## ✅ Соответствие требованиям ТЗ

| Требование | Реализовано | Проверка |
|------------|-------------|----------|
| Массовая рассылка | ✅ | `POST /api/notifications/bulk` |
| Приоритет HIGH > LOW | ✅ | 4 high воркера, 1 low воркер |
| Статус "в очереди" | ✅ | `queued` при создании |
| Статус "отправлено" | ✅ | `sent` перед вызовом шлюза |
| Статус "доставлено" | ✅ | `delivered` после успеха |
| Статус "отброшено" | ✅ | `dropped` при ошибке |
| Персистентность | ✅ | RabbitMQ durable |
| Retry-механизмы | ✅ | `$tries=3`, `$backoff` |
| Дедубликация | ✅ | Redis SETNX + БД |
| Интеграционные тесты | ✅ | 6 tests, 26 assertions |
| Docker-образ | ✅ | `docker/app/Dockerfile` |
| `docker-compose up` | ✅ | Все сервисы поднимаются |
| Мок-шлюзы | ✅ | `SmsGatewayMock`, `EmailGatewayMock` |
| **Postman коллекция** | ✅ | `./postman/Notification Service API.postman_collection.json` |

## 📮 Postman коллекция

Файл для импорта в Postman:  
[`./postman/Notification Service API.postman_collection.json`](./postman/Notification%20Service%20API.postman_collection.json)

### Endpoints в коллекции:
- `POST /api/notifications/bulk` — массовая рассылка
- `GET /api/subscribers/{id}/notifications` — история статусов


## Лицензия

MIT

