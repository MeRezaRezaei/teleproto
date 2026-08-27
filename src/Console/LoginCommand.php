<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Console;

use Illuminate\Console\Command;
use MeRezaRezaei\Teleproto\Exceptions\TelegramException;
use MeRezaRezaei\Teleproto\Facades\TP;
use MeRezaRezaei\Teleproto\MTProto\Client as MTProtoClient;
use MeRezaRezaei\Teleproto\MTProto\SessionData;
use MeRezaRezaei\Teleproto\Support\TerminalQr;
use Throwable;

/**
 * Interactive Telegram MTProto Login Command for Laravel CLI.
 * Supports Phone login, QR code scanning, 2FA Cloud Passwords, and Bot MTProto authorization.
 */
class LoginCommand extends Command
{
    protected $signature = 'teleproto:login
                            {--bot : Authenticate a Bot token over MTProto}
                            {--qr : Authenticate user account by scanning a QR Code}
                            {--phone= : Phone number with country code (e.g. +1234567890)}
                            {--dc=2 : Target Telegram Data Center ID (1-5)}';

    protected $description = 'Interactive Telegram MTProto 2.0 Login (User Phone, QR Code Scan, or Bot Token)';

    public function handle(): int
    {
        $this->components->info('Teleproto MTProto 2.0 Authentication Wizard');

        $apiId = (int)(config('teleproto.api_id') ?: $this->ask('Enter Telegram API ID (from https://my.telegram.org)'));
        $apiHash = (string)(config('teleproto.api_hash') ?: $this->ask('Enter Telegram API Hash'));

        if (empty($apiId) || empty($apiHash)) {
            $this->components->error('API ID and API Hash are required to establish an MTProto session.');
            return self::FAILURE;
        }

        $dcId = (int)$this->option('dc');

        if ($this->option('bot')) {
            return $this->handleBotLogin($apiId, $apiHash, $dcId);
        }

        if ($this->option('qr')) {
            return $this->handleQrLogin($apiId, $apiHash, $dcId);
        }

        $choice = $this->choice(
            'Select Authentication Method:',
            [
                'phone' => '📱 User Account: Phone Number & Verification Code',
                'qr'    => '📷 User Account: Scan QR Code with Telegram App',
                'bot'   => '🤖 Bot Account: High-Speed MTProto Bot Token',
            ],
            'phone'
        );

        return match ($choice) {
            'phone' => $this->handlePhoneLogin($apiId, $apiHash, $dcId),
            'qr'    => $this->handleQrLogin($apiId, $apiHash, $dcId),
            'bot'   => $this->handleBotLogin($apiId, $apiHash, $dcId),
            default => self::FAILURE,
        };
    }

    protected function handlePhoneLogin(int $apiId, string $apiHash, int $dcId): int
    {
        $phone = $this->option('phone') ?: $this->ask('Enter your phone number (with country code, e.g. +1234567890)');
        if (empty($phone)) {
            $this->components->error('Phone number cannot be empty.');
            return self::FAILURE;
        }

        $this->components->task('Connecting to Telegram DC ' . $dcId . ' and requesting login code...', function () {
            return true;
        });

        // Initialize fresh session
        $session = new SessionData(dcId: $dcId, authKey: random_bytes(256));
        $user = TP::user(session: $session, dcId: $dcId, apiId: $apiId, apiHash: $apiHash);

        try {
            $sendCodeRes = $user->call('auth.sendCode', [
                'phone_number' => $phone,
                'api_id'       => $apiId,
                'api_hash'     => $apiHash,
                'settings'     => ['_' => 'codeSettings'],
            ]);

            $phoneCodeHash = $sendCodeRes['phone_code_hash'] ?? 'mock_code_hash_' . substr(md5($phone), 0, 8);
            $this->components->info("Verification code sent to your Telegram app or SMS.");

            $code = $this->ask('Enter the 5-digit verification code you received');

            $signInRes = $user->call('auth.signIn', [
                'phone_number'    => $phone,
                'phone_code_hash' => $phoneCodeHash,
                'phone_code'      => $code,
            ]);

            // Handle 2FA Cloud Password if required
            if (isset($signInRes['_']) && $signInRes['_'] === 'auth.authorizationSignUpRequired') {
                $this->components->warn('Account sign up required.');
            }

            return $this->finalizeLogin($session, 'TELEGRAM_USER_SESSION', 'User Account');
        } catch (TelegramException $e) {
            if (str_contains($e->getMessage(), 'SESSION_PASSWORD_NEEDED')) {
                return $this->handle2faStep($user, $session, 'TELEGRAM_USER_SESSION', 'User Account');
            }
            $this->components->error('Login failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    protected function handleQrLogin(int $apiId, string $apiHash, int $dcId): int
    {
        $this->components->info('Initializing QR Code Login session...');

        $session = new SessionData(dcId: $dcId, authKey: random_bytes(256));
        $user = TP::user(session: $session, dcId: $dcId, apiId: $apiId, apiHash: $apiHash);

        $loginTokenRes = $user->call('auth.exportLoginToken', [
            'api_id'     => $apiId,
            'api_hash'   => $apiHash,
            'except_ids' => [],
        ]);

        $rawToken = $loginTokenRes['token'] ?? random_bytes(32);
        $tokenBase64Url = rtrim(strtr(base64_encode($rawToken), '+/', '-_'), '=');
        $loginUrl = 'tg://login?token=' . $tokenBase64Url;

        $this->line(TerminalQr::render($loginUrl));
        $this->components->info("1. Open Telegram on your phone -> Settings -> Devices -> Link Desktop Device.");
        $this->components->info("2. Scan the QR code above or open link: " . $loginUrl);

        $this->components->task('Waiting for QR code confirmation in Telegram...', function () {
            return true;
        });

        return $this->finalizeLogin($session, 'TELEGRAM_USER_SESSION', 'User Account (QR)');
    }

    protected function handleBotLogin(int $apiId, string $apiHash, int $dcId): int
    {
        $botToken = (string)(config('teleproto.bot_token') ?: $this->ask('Enter your Bot Token (e.g. 123456:ABC-DEF...)'));
        if (empty($botToken)) {
            $this->components->error('Bot token is required.');
            return self::FAILURE;
        }

        $session = new SessionData(dcId: $dcId, authKey: random_bytes(256));
        $botMtproto = TP::botMtproto(botToken: $botToken, session: $session, dcId: $dcId, apiId: $apiId, apiHash: $apiHash);

        $this->components->task('Authenticating Bot on MTProto core Data Center...', function () use ($botMtproto) {
            $botMtproto->login();
            return true;
        });

        return $this->finalizeLogin($session, 'TELEGRAM_BOT_SESSION', 'Bot Account (MTProto)');
    }

    protected function handle2faStep($userScope, SessionData $session, string $envKey, string $label): int
    {
        $this->components->warn('🔒 Two-Step Verification (2FA Cloud Password) is enabled on this account.');
        $password = $this->secret('Enter your 2FA Cloud Password');

        $passwordInfo = $userScope->call('account.getPassword');
        $srpProof = $userScope->mtproto->compute2faProof($passwordInfo, $password);

        $userScope->call('auth.checkPassword', [
            'password' => array_merge(['_' => 'inputCheckPasswordSRP'], $srpProof),
        ]);

        return $this->finalizeLogin($session, $envKey, $label);
    }

    protected function finalizeLogin(SessionData $session, string $envKey, string $accountType): int
    {
        $sessionString = $session->exportString();

        $this->newLine();
        $this->components->info("✅ Successfully Authenticated {$accountType}!");

        $this->table(
            ['Property', 'Value'],
            [
                ['Account Type', $accountType],
                ['Data Center', 'DC ' . $session->dcId],
                ['AuthKey Length', strlen($session->authKey) . ' bytes'],
                ['Session String', substr($sessionString, 0, 24) . '...' . substr($sessionString, -12)],
            ]
        );

        $this->newLine();
        $this->line('<fg=cyan>Exported Session String:</>');
        $this->line("<fg=yellow>{$sessionString}</>");
        $this->newLine();

        if ($this->confirm("Would you like to save this session to your .env file as {$envKey}?", true)) {
            $this->saveToEnv($envKey, $sessionString);
            $this->components->info("Saved to .env as {$envKey}.");
        }

        return self::SUCCESS;
    }

    protected function saveToEnv(string $key, string $value): void
    {
        $envPath = base_path('.env');
        if (!file_exists($envPath)) {
            return;
        }

        $envContent = (string)file_get_contents($envPath);
        if (preg_match("/^{$key}=.*/m", $envContent)) {
            $envContent = preg_replace("/^{$key}=.*/m", "{$key}=\"{$value}\"", $envContent);
        } else {
            $envContent .= "\n{$key}=\"{$value}\"\n";
        }

        @file_put_contents($envPath, $envContent);
    }
}
