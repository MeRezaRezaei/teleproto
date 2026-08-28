<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\MTProto\TL;

use InvalidArgumentException;

/**
 * Constructor-name ↔ CRC32-id registry built from canonical TL schema lines.
 * IDs are computed at runtime from the canonical string (single spaces, exact field order).
 *
 * Canonicalization notes (verified against core.telegram.org/mtproto/auth_key,
 * /mtproto/samples-auth_key and /mtproto/service_messages):
 *  - MTProto handshake lines use `string` (not `bytes`) for pq/p/q/dh_prime/g_a/g_b/encrypted_*.
 *  - resPQ's fingerprint list is `Vector long` (no angle brackets) in the canonical string.
 *  - Generic type declarations `{X:Type}` appear brace-less (`X:Type`) in the CRC string.
 *  - rpc_error yields RpcError; the dh_gen_* constructors answer Set_client_DH_params_answer.
 */
class TLRegistry
{
    public const VECTOR = 0x1cb5c415;

    /** @var array<string, int> name => id */
    protected static array $ids = [];

    /** @var array<int, string> id => name */
    protected static array $names = [];

    /** @var array<string, string> name => canonical line */
    protected static array $signatures = [];

    protected static bool $booted = false;

    public const SCHEMA = [
        'req_pq_multi nonce:int128 = ResPQ',
        'resPQ nonce:int128 server_nonce:int128 pq:string server_public_key_fingerprints:Vector long = ResPQ',
        'p_q_inner_data pq:string p:string q:string nonce:int128 server_nonce:int128 new_nonce:int256 = P_Q_inner_data',
        'p_q_inner_data_dc pq:string p:string q:string nonce:int128 server_nonce:int128 new_nonce:int256 dc:int = P_Q_inner_data',
        'p_q_inner_data_temp_dc pq:string p:string q:string nonce:int128 server_nonce:int128 new_nonce:int256 dc:int expires_in:int = P_Q_inner_data',
        'req_DH_params nonce:int128 server_nonce:int128 p:string q:string public_key_fingerprint:long encrypted_data:string = Server_DH_Params',
        'server_DH_params_ok nonce:int128 server_nonce:int128 encrypted_answer:string = Server_DH_Params',
        'server_DH_params_fail nonce:int128 server_nonce:int128 new_nonce_hash:int128 = Server_DH_Params',
        'server_DH_inner_data nonce:int128 server_nonce:int128 g:int dh_prime:string g_a:string server_time:int = Server_DH_inner_data',
        'client_DH_inner_data nonce:int128 server_nonce:int128 retry_id:long g_b:string = Client_DH_Inner_Data',
        'set_client_DH_params nonce:int128 server_nonce:int128 encrypted_data:string = Set_client_DH_params_answer',
        'dh_gen_ok nonce:int128 server_nonce:int128 new_nonce_hash1:int128 = Set_client_DH_params_answer',
        'dh_gen_retry nonce:int128 server_nonce:int128 new_nonce_hash2:int128 = Set_client_DH_params_answer',
        'dh_gen_fail nonce:int128 server_nonce:int128 new_nonce_hash3:int128 = Set_client_DH_params_answer',
        'invokeWithLayer X:Type layer:int query:!X = X',
        'initConnection X:Type flags:# api_id:int device_model:string system_version:string app_version:string system_lang_code:string lang_pack:string lang_code:string proxy:flags.0?InputClientProxy params:flags.1?JSONValue query:!X = X',
        'help.getNearestDc = NearestDc',
        'nearestDc country:string this_dc:int nearest_dc:int = NearestDc',
        'help.getConfig = Config',
        'auth.importBotAuthorization flags:int api_id:int api_hash:string bot_auth_token:string = auth.Authorization',
        'rpc_result req_msg_id:long result:Object = RpcResult',
        'rpc_error error_code:int error_message:string = RpcError',
        'bad_server_salt bad_msg_id:long bad_msg_seqno:int error_code:int new_server_salt:long = BadMsgNotification',
        'bad_msg_notification bad_msg_id:long bad_msg_seqno:int error_code:int = BadMsgNotification',
        'gzip_packed packed_data:string = Object',
        'msgs_ack msg_ids:Vector long = MsgsAck',
        'new_session_created first_msg_id:long unique_id:long server_salt:long = NewSession',
    ];

    protected static function boot(): void
    {
        if (static::$booted) {
            return;
        }
        static::$booted = true; // set before seeding: register() re-enters boot()
        foreach (self::SCHEMA as $line) {
            self::register($line);
        }
    }

    public static function register(string $canonicalLine): void
    {
        self::boot(); // seeding must not depend on call order: register-before-lookup still seeds SCHEMA
        if (!preg_match('/^([A-Za-z0-9_.]+)#([0-9a-fA-F]{1,8})\b/', $canonicalLine, $m)) {
            // Line without explicit id: compute from the full canonical string.
            $name = trim(explode(' ', $canonicalLine)[0]);
            $id = self::crc32Canonical($canonicalLine);
        } else {
            $name = $m[1];
            $id = (int)hexdec(str_pad($m[2], 8, '0', STR_PAD_LEFT));
        }
        static::$ids[$name] = $id;
        static::$names[$id] = $name;
        static::$signatures[$name] = $canonicalLine;
    }

    public static function id(string $constructorName): int
    {
        self::boot();
        if (!isset(static::$ids[$constructorName])) {
            throw new InvalidArgumentException("TLRegistry: unknown constructor '{$constructorName}'");
        }
        return static::$ids[$constructorName];
    }

    public static function signature(string $constructorName): string
    {
        self::boot();
        if (!isset(static::$signatures[$constructorName])) {
            throw new InvalidArgumentException("TLRegistry: unknown constructor '{$constructorName}'");
        }
        return static::$signatures[$constructorName];
    }

    public static function nameOf(int $id): ?string
    {
        self::boot();
        return static::$names[$id] ?? null;
    }

    /**
     * crc32 of the canonical string, interpreted as little-endian 32-bit.
     * Reference canonicalization is "single spaces, nothing trimmed";
     * callers must pass already-canonical lines.
     */
    public static function crc32Canonical(string $line): int
    {
        return (int)sprintf('%u', crc32($line));
    }
}
