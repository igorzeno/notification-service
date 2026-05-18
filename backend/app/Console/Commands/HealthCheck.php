<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class HealthCheck extends Command
{
    protected $signature = 'health:check';
    protected $description = 'Check health of all services';

    public function handle()
    {
        $allHealthy = true;

        // 1. PostgreSQL
        try {
            DB::connection()->getPdo();
            $this->info('✅ PostgreSQL: OK');
        } catch (\Exception $e) {
            $this->error('❌ PostgreSQL: ' . $e->getMessage());
            $allHealthy = false;
        }

        // 2. Redis
        try {
            Redis::ping();
            $this->info('✅ Redis: OK');
        } catch (\Exception $e) {
            $this->error('❌ Redis: ' . $e->getMessage());
            $allHealthy = false;
        }

        // 3. RabbitMQ (через Laravel)
        try {
            $queueConnection = config('queue.default');
            $this->info("✅ Queue connection: {$queueConnection}");

            // Проверяем, что соединение с RabbitMQ работает
            $queueSize = \Illuminate\Support\Facades\Queue::size('high');
            $this->info("✅ RabbitMQ: OK (high queue size: {$queueSize})");
        } catch (\Exception $e) {
            $this->error('❌ RabbitMQ: ' . $e->getMessage());
            $allHealthy = false;
        }

        // 4. Supervisor процессы (воркеры)
        $requiredWorkers = [
            'queue:work.*high' => 2,
            'queue:work.*low' => 3,
        ];

        foreach ($requiredWorkers as $pattern => $expected) {
            $count = (int) exec("ps aux | grep '{$pattern}' | grep -v grep | wc -l");
            if ($count >= $expected) {
                $this->info("✅ Worker {$pattern}: {$count}/{$expected}");
            } else {
                $this->error("❌ Worker {$pattern}: {$count}/{$expected}");
                $allHealthy = false;
            }
        }

        // 5. PHP-FPM
        $phpFpmCount = (int) exec("ps aux | grep 'php-fpm: pool' | grep -v grep | wc -l");
        if ($phpFpmCount >= 1) {
            $this->info("✅ PHP-FPM: {$phpFpmCount} processes");
        } else {
            $this->error("❌ PHP-FPM: no processes");
            $allHealthy = false;
        }

        return $allHealthy ? 0 : 1;
    }
}
