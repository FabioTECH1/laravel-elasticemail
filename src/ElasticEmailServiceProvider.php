<?php

namespace FabioTech\LaravelElasticEmail;

use FabioTech\LaravelElasticEmail\Transport\ElasticEmailTransportFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

final class ElasticEmailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/elasticemail.php', 'elasticemail');

        $this->app->singleton(ElasticEmailTransportFactory::class);
    }

    public function boot(Repository $config): void
    {
        $this->registerMailerConfiguration($config);

        Mail::extend('elasticemail', function (array $mailerConfig = []) use ($config) {
            $packageConfig = $config->get('elasticemail', []);

            return $this->app->make(ElasticEmailTransportFactory::class)->make(
                config: array_replace(is_array($packageConfig) ? $packageConfig : [], $mailerConfig),
                logger: $this->app->make(LoggerInterface::class),
            );
        });

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/elasticemail.php' => config_path('elasticemail.php'),
            ], 'elasticemail-config');
        }
    }

    private function registerMailerConfiguration(Repository $config): void
    {
        $packageConfig = $config->get('elasticemail', []);
        $mailerConfig = $config->get('mail.mailers.elasticemail', []);

        $mergedConfig = array_replace(
            ['transport' => 'elasticemail'],
            is_array($packageConfig) ? $packageConfig : [],
            is_array($mailerConfig) ? $mailerConfig : [],
        );
        $mergedConfig['transport'] = 'elasticemail';

        $config->set('mail.mailers.elasticemail', $mergedConfig);
    }
}
