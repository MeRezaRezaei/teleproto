<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use MeRezaRezaei\Teleproto\Http\Middleware\VerifyMiniAppInitData;
use MeRezaRezaei\Teleproto\Services\TeleprotoAuthService;
use MeRezaRezaei\Teleproto\Services\TeleprotoClient;

class TeleprotoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/teleproto.php', 'teleproto');

        $this->app->singleton(TeleprotoClient::class, function ($app) {
            $config = $app['config']['teleproto'] ?? $app['config']['telegram'] ?? [];
            return new TeleprotoClient(
                defaultApiId: (int)($config['api_id'] ?? 0),
                defaultApiHash: (string)($config['api_hash'] ?? ''),
                defaultBotToken: $config['bot_token'] ?? $config['default_bot_token'] ?? null,
                defaultProxyConfig: $config['proxy'] ?? null,
                defaultUserSession: $config['user_session'] ?? null,
                defaultBotSession: $config['bot_session'] ?? null,
                defaultDcId: (int)($config['dc_id'] ?? 2)
            );
        });

        $this->app->singleton(TeleprotoAuthService::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/teleproto.php' => config_path('teleproto.php'),
            ], 'teleproto-config');

            $this->commands([
                \MeRezaRezaei\Teleproto\Console\LoginCommand::class,
                \MeRezaRezaei\Teleproto\Console\PollCommand::class,
            ]);
        }

        if (isset($this->app['router'])) {
            /** @var Router $router */
            $router = $this->app['router'];
            $router->aliasMiddleware('tg.miniapp', VerifyMiniAppInitData::class);

            // Register Route Macro for simple Webhook endpoint declaration
            $router->macro('telegramWebhook', function (string $uri = 'telegram/webhook') use ($router) {
                return $router->post($uri, \MeRezaRezaei\Teleproto\Http\Controllers\TelegramWebhookController::class);
            });
        }
    }
}
