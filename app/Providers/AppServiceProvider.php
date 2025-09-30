<?php

namespace App\Providers;

use App\Support\TenantContext;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class, static fn (): TenantContext => new TenantContext());
    }

    public function boot(): void
    {
        Schema::defaultStringLength(191);

        $this->assertCriticalEnvironmentVariables();
    }

    private function assertCriticalEnvironmentVariables(): void
    {
        /** @var ConfigRepository $config */
        $config = $this->app->make(ConfigRepository::class);

        if ($config->get('app.env') === 'testing') {
            return;
        }

        $required = [
            'APP_KEY',
            'QUEUE_CONNECTION',
            'QR_SECRET',
            'FINGERPRINT_ENCRYPTION_KEY',
        ];

        $databaseKeys = [
            'DB_CONNECTION',
            'DB_HOST',
            'DB_PORT',
            'DB_DATABASE',
            'DB_USERNAME',
            'DB_PASSWORD',
        ];

        $required = array_merge($required, $databaseKeys);

        $allowEmpty = ['DB_PASSWORD'];

        $missing = [];

        foreach ($required as $key) {
            $value = env($key);

            if ($value === null) {
                $missing[] = $key;
                continue;
            }

            if (is_string($value) && Str::of($value)->trim()->isEmpty() && ! in_array($key, $allowEmpty, true)) {
                $missing[] = $key;
            }
        }

        if ($config->get('database.default') === 'sqlite') {
            $missing = array_values(array_diff($missing, ['DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD']));
        }

        if ($missing !== []) {
            throw new RuntimeException(sprintf(
                'Missing required environment variable(s): %s',
                implode(', ', $missing)
            ));
        }
    }
}
