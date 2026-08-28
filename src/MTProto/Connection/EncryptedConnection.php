<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\MTProto\Connection;

use MeRezaRezaei\Teleproto\Exceptions\TelegramException;
use MeRezaRezaei\Teleproto\MTProto\Crypto\PacketCodec;
use MeRezaRezaei\Teleproto\MTProto\SessionData;
use MeRezaRezaei\Teleproto\MTProto\TL\TLDecoder;
use MeRezaRezaei\Teleproto\MTProto\TL\TLEncoder;
use MeRezaRezaei\Teleproto\MTProto\TL\TLRegistry;
use MeRezaRezaei\Teleproto\MTProto\TL\TLSerializer;
use MeRezaRezaei\Teleproto\MTProto\Transport\FrameCodec;
use MeRezaRezaei\Teleproto\MTProto\Transport\StreamSocket;
use RuntimeException;

/**
 * Encrypted MTProto 2.0 RPC connection (single in-flight query; sufficient for CLI/short jobs).
 *
 * First call is wrapped in invokeWithLayer(layer, initConnection(..., query)); later calls
 * send the bare constructor. Handles bad_server_salt (resend once with the fresh salt),
 * gzip_packed results, rpc_error -> TelegramException, and skips transient push messages.
 */
class EncryptedConnection
{
    public const LAYER = 227;

    /**
     * Transient push messages skipped while waiting for the rpc_result. Both
     * constructors are registered in TLRegistry, so their ids are looked up
     * there (msgs_ack msg_ids:Vector long = MsgsAck -> 0x62d6b459,
     * new_session_created first_msg_id:long unique_id:long server_salt:long
     * = NewSession -> 0x9ec20908) instead of being duplicated here.
     */
    private const MAX_TRANSIENT_MESSAGES = 3;

    /** @var resource|null */
    protected $socket;
    protected int $serverSalt = 0;
    protected int $sessionId;
    protected bool $inited = false;
    protected int $lastMessageId = 0;

    /** @param resource|null $socket */
    final public function __construct(protected SessionData $session, $socket = null, protected int $apiId = 0)
    {
        $this->socket = $socket;
        $this->sessionId = (int)unpack('P', random_bytes(8))[1];
    }

    public static function connect(SessionData $session, string $host, int $port = 443, float $timeout = 10.0): static
    {
        $socket = StreamSocket::createConnection($host, $port, timeout: $timeout);
        FrameCodec::writeInit($socket);
        return new static($session, $socket);
    }

    /**
     * invokeWithLayer cannot go through TLEncoder (generic {X:Type} field), so it is
     * framed manually: id || layer:int || initConnection object.
     */
    public static function buildFirstQueryBody(int $layer, array $initConnectionArgs): string
    {
        $init = TLEncoder::encodeObject('initConnection', $initConnectionArgs);
        return TLSerializer::packInt(TLRegistry::id('invokeWithLayer'))
            . TLSerializer::packInt($layer)
            . $init;
    }

    /**
     * rpc_result's `result` arrives already decoded (Object field). If it is a
     * gzip_packed wrapper, inflate and decode the inner object; otherwise return as-is.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public static function unwrapResultIfGzipped(array $result): array
    {
        if (($result['_'] ?? '') !== 'gzip_packed') {
            return $result;
        }
        $inflated = gzdecode($result['packed_data']);
        if ($inflated === false) {
            throw new RuntimeException('EncryptedConnection: gzdecode failed');
        }
        $offset = 0;
        return TLDecoder::decodeObject($inflated, $offset);
    }

    /**
     * @return array<string, mixed> decoded result of the RPC call
     * @throws TelegramException on rpc_error
     * @throws RuntimeException on transport/protocol failures
     */
    public function call(string $constructor, array $args = []): array
    {
        if (!is_resource($this->socket)) {
            throw new RuntimeException('EncryptedConnection: not connected');
        }

        $body = $this->inited
            ? TLEncoder::encodeObject($constructor, $args)
            : self::buildFirstQueryBody(self::LAYER, [
                'api_id' => $this->apiId,
                'device_model' => 'Teleproto',
                'system_version' => PHP_OS . ' PHP ' . PHP_VERSION,
                'app_version' => '1.0.0',
                'system_lang_code' => 'en',
                'lang_pack' => '',
                'lang_code' => 'en',
                'query' => array_merge(['_' => $constructor], $args),
            ]);

        $maxAttempts = 2; // original + one bad_server_salt resend
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $this->inited = true;
            $packet = PacketCodec::encryptPacket(
                payload: $body,
                authKey: $this->session->authKey,
                sessionId: $this->sessionId,
                serverSalt: $this->serverSalt,
                seqNo: 0, // multi-in-flight seq_no tracking is out of scope for this task
                serverTimeDelta: $this->session->serverTimeDelta,
                messageId: $this->nextMessageId()
            );
            FrameCodec::sendMessage($this->socket, $packet);

            $result = $this->receiveDecodedResponse();

            if (($result['_'] ?? '') === 'bad_server_salt') {
                $this->serverSalt = (int)$result['new_server_salt'];
                continue; // resend with the fresh salt
            }

            if (($result['_'] ?? '') !== 'rpc_result') {
                throw new RuntimeException(sprintf(
                    "EncryptedConnection: unexpected response '%s'",
                    $result['_'] ?? '(no constructor)'
                ));
            }

            $inner = self::unwrapResultIfGzipped((array)$result['result']);
            if (($inner['_'] ?? '') === 'rpc_error') {
                throw new TelegramException(
                    'MTProto: ' . $inner['error_message'],
                    (int)$inner['error_code']
                );
            }
            return $inner;
        }
        throw new RuntimeException('EncryptedConnection: exhausted retries after bad_server_salt');
    }

    /**
     * Transient push-message constructor ids (msgs_ack / new_session_created),
     * sourced from TLRegistry so the ids exist in exactly one place.
     *
     * @return list<int>
     */
    protected static function transientConstructorIds(): array
    {
        return [TLRegistry::id('msgs_ack'), TLRegistry::id('new_session_created')];
    }

    /**
     * Reads encrypted frames until a non-transient message arrives; skips at most
     * MAX_TRANSIENT_MESSAGES consecutive msgs_ack / new_session_created pushes.
     *
     * @return array<string, mixed>
     */
    protected function receiveDecodedResponse(): array
    {
        $transientIds = self::transientConstructorIds();
        for ($transients = 0; $transients <= self::MAX_TRANSIENT_MESSAGES; $transients++) {
            $frame = FrameCodec::receiveMessage($this->socket);
            $msg = PacketCodec::decryptPacket($frame, $this->session->authKey);
            $payload = $msg['payload'];

            $id = strlen($payload) >= 4 ? unpack('V', substr($payload, 0, 4))[1] : 0;
            if (in_array($id, $transientIds, true)) {
                continue; // transient push, keep reading
            }

            $offset = 0;
            return TLDecoder::decodeObject($payload, $offset);
        }
        throw new RuntimeException('EncryptedConnection: too many consecutive transient messages');
    }

    /**
     * Encrypted client content message id: ≡ 1 (mod 4), strictly increasing.
     * (PacketCodec::generateMessageId yields ≡ 0 mod 4 ids for plain messages — not usable here.)
     */
    protected function nextMessageId(): int
    {
        $candidate = (((int)((microtime(true) + $this->session->serverTimeDelta) * 2**32)) & ~3) | 1;
        if ($candidate <= $this->lastMessageId) {
            $candidate = $this->lastMessageId + 4; // keeps ≡ 1 (mod 4) while strictly increasing
        }
        return $this->lastMessageId = $candidate;
    }

    public function lastSessionData(): SessionData
    {
        return $this->session;
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
    }
}
