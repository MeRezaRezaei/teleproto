<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Services;

use MeRezaRezaei\Teleproto\Exceptions\DcMigrationException;
use MeRezaRezaei\Teleproto\MTProto\SessionData;
use RuntimeException;

/**
 * Service for Telegram MTProto 2.0 User & Bot Authentication workflows.
 * Fully decoupled from CLI so it can be invoked from Controllers, Livewire, Queue Jobs, or Artisan commands.
 */
class TeleprotoAuthService
{
    protected TeleprotoClient $client;

    protected bool $live;

    public function __construct(?TeleprotoClient $client = null, bool $live = true)
    {
        $this->client = $client ?? new TeleprotoClient();
        $this->live = $live;
    }

    /**
     * Enables live wire mode on a freshly created MTProto scope.
     * Authentication only ever makes sense against real servers;
     * tests construct the service with live=false.
     */
    protected function goLive(UserAccountScope|BotAccountScope $scope): void
    {
        if ($this->live) {
            $scope->mtproto->live();
        }
    }

    /**
     * Step 1: Send SMS/Telegram login verification code to a phone number.
     *
     * @param string $phone Phone number in international format (+1234567890)
     * @param int $apiId Telegram API ID
     * @param string $apiHash Telegram API Hash
     * @param int $dcId Primary DC ID (default: 2)
     * @param SessionData|null $session Existing or new session
     * @return array{phone_code_hash: string, session: SessionData, user: UserAccountScope, raw: array<string, mixed>}
     */
    public function sendPhoneCode(
        string $phone,
        int $apiId,
        string $apiHash,
        int $dcId = 2,
        ?SessionData $session = null
    ): array {
        $sessionData = $session ?? new SessionData(dcId: $dcId, authKey: ''); // empty key: Client handshakes lazily
        $user = $this->client->user(session: $sessionData, dcId: $dcId, apiId: $apiId, apiHash: $apiHash);
        $this->goLive($user);

        $res = $user->call('auth.sendCode', [
            'phone_number' => $phone,
            'api_id'       => $apiId,
            'api_hash'     => $apiHash,
            'settings'     => ['_' => 'codeSettings'],
        ]);

        $phoneCodeHash = (string)($res['phone_code_hash'] ?? 'mock_code_hash_' . substr(md5($phone), 0, 8));

        return [
            'phone_code_hash' => $phoneCodeHash,
            'session'         => $sessionData,
            'user'            => $user,
            'raw'             => $res,
        ];
    }

    /**
     * Step 2: Submit phone verification code.
     *
     * @param UserAccountScope $user
     * @param string $phone
     * @param string $phoneCodeHash
     * @param string $code
     * @return array<string, mixed> auth.Authorization
     */
    public function signInWithCode(
        UserAccountScope $user,
        string $phone,
        string $phoneCodeHash,
        string $code
    ): array {
        return $user->call('auth.signIn', [
            'phone_number'    => $phone,
            'phone_code_hash' => $phoneCodeHash,
            'phone_code'      => $code,
        ]);
    }

    /**
     * Step 3: Complete 2FA Cloud Password login when SESSION_PASSWORD_NEEDED is returned.
     *
     * @param UserAccountScope $user
     * @param string $password User's 2FA Cloud Password
     * @return array<string, mixed> auth.Authorization
     */
    public function check2faPassword(UserAccountScope $user, string $password): array
    {
        $passwordInfo = $user->call('account.getPassword');
        $srpProof = $user->mtproto->compute2faProof($passwordInfo, $password);

        return $user->call('auth.checkPassword', [
            'password' => array_merge(['_' => 'inputCheckPasswordSRP'], $srpProof),
        ]);
    }

    /**
     * Step 1 (QR): Export a new QR code login token.
     *
     * @param int $apiId
     * @param string $apiHash
     * @param int $dcId
     * @param SessionData|null $session
     * @return array{token: string, url: string, expires: int, session: SessionData, user: UserAccountScope, raw: array<string, mixed>}
     */
    public function exportQrLoginToken(
        int $apiId,
        string $apiHash,
        int $dcId = 2,
        ?SessionData $session = null
    ): array {
        $sessionData = $session ?? new SessionData(dcId: $dcId, authKey: ''); // empty key: Client handshakes lazily
        $user = $this->client->user(session: $sessionData, dcId: $dcId, apiId: $apiId, apiHash: $apiHash);
        $this->goLive($user);

        $res = $user->call('auth.exportLoginToken', [
            'api_id'     => $apiId,
            'api_hash'   => $apiHash,
            'except_ids' => [],
        ]);

        $rawToken = $res['token'] ?? random_bytes(32);
        $tokenBase64Url = rtrim(strtr(base64_encode($rawToken), '+/', '-_'), '=');
        $loginUrl = 'tg://login?token=' . $tokenBase64Url;

        return [
            'token'   => $rawToken,
            'url'     => $loginUrl,
            'expires' => (int)($res['expires'] ?? (time() + 300)),
            'session' => $sessionData,
            'user'    => $user,
            'raw'     => $res,
        ];
    }

    /**
     * Authenticate a Bot token natively over MTProto binary socket via `auth.importBotAuthorization`.
     *
     * @param string $botToken
     * @param int $apiId
     * @param string $apiHash
     * @param int $dcId
     * @param SessionData|null $session
     * @return array{session: SessionData, bot: BotAccountScope, raw: array<string, mixed>}
     */
    public function loginBot(
        string $botToken,
        int $apiId,
        string $apiHash,
        int $dcId = 2,
        ?SessionData $session = null
    ): array {
        $sessionData = $session ?? new SessionData(dcId: $dcId, authKey: ''); // empty key: Client handshakes lazily
        $bot = $this->client->botMtproto(botToken: $botToken, session: $sessionData, dcId: $dcId, apiId: $apiId, apiHash: $apiHash);
        $this->goLive($bot);

        $authRes = $bot->login();

        return [
            'session' => $sessionData,
            'bot'     => $bot,
            'raw'     => $authRes,
        ];
    }

    /**
     * Polls auth.exportLoginToken until the QR is scanned (loginTokenSuccess),
     * the account lives on another DC (throws DcMigrationException — reconnect
     * there and call importLoginTokenAt with the exception token), or timeout.
     *
     * @param \Closure(string, int): void|null $onToken Called with (loginUrl, expiresInSeconds) whenever the token refreshes
     * @return array<string, mixed> auth.Authorization from loginTokenSuccess
     */
    public function pollQrLoginToken(
        UserAccountScope $user,
        int $apiId,
        string $apiHash,
        ?\Closure $onToken = null,
        int $timeoutSeconds = 300
    ): array {
        $deadline = time() + $timeoutSeconds;
        while (time() < $deadline) {
            $res = $user->call('auth.exportLoginToken', [
                'api_id' => $apiId,
                'api_hash' => $apiHash,
                'except_ids' => [],
            ]);
            $name = (string)($res['_'] ?? '');
            if ($name === 'auth.loginToken') {
                if ($onToken !== null) {
                    $raw = (string)$res['token'];
                    $b64 = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
                    $onToken('tg://login?token=' . $b64, (int)$res['expires']);
                }
                sleep(2);
                continue;
            }
            if ($name === 'auth.loginTokenMigrateTo') {
                throw new DcMigrationException(
                    (int)$res['dc_id'],
                    'QR login requires migration to DC ' . (int)$res['dc_id'],
                    303,
                    (string)$res['token']
                );
            }
            if ($name === 'auth.loginTokenSuccess') {
                return (array)$res['authorization'];
            }
            throw new RuntimeException('TeleprotoAuthService: unexpected QR login response ' . $name);
        }
        throw new RuntimeException('TeleprotoAuthService: QR login timed out after ' . $timeoutSeconds . 's');
    }

    /**
     * Continues QR login on the migrated DC with the token from DcMigrationException.
     *
     * @return array<string, mixed> auth.Authorization
     */
    public function importLoginTokenAt(int $dcId, string $token, int $apiId, string $apiHash, ?SessionData $session = null): array
    {
        $sessionData = $session ?? new SessionData(dcId: $dcId, authKey: ''); // empty key: Client handshakes lazily
        $user = $this->client->user(session: $sessionData, dcId: $dcId, apiId: $apiId, apiHash: $apiHash);
        $this->goLive($user);

        $res = $user->call('auth.importLoginToken', ['token' => $token]);
        $name = (string)($res['_'] ?? '');
        if ($name === 'auth.loginTokenSuccess') {
            return (array)$res['authorization'];
        }
        throw new RuntimeException('TeleprotoAuthService: unexpected importLoginToken response ' . $name);
    }
}
