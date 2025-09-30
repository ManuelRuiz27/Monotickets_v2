<?php

namespace App\Providers;

use App\Services\Qr\QrCodeProvider;
use App\Services\Qr\SecureQrCodeProvider;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class QrServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SecureQrCodeProvider::class, function ($app): SecureQrCodeProvider {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            $secret = (string) $config->get('qr.secret', '');

            if ($secret === '' && $config->get('app.env') === 'testing') {
                $secret = 'testing-secret';
            }

            if ($secret === '') {
                throw new RuntimeException('QR secret is not configured.');
            }

            return new SecureQrCodeProvider($secret);
        });

        $this->app->bind(QrCodeProvider::class, SecureQrCodeProvider::class);
    }
}
