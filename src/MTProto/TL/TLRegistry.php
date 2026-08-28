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

    /** @var array<string, ParsedSignature> name => parse-once cached struct */
    protected static array $parsed = [];

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
        // --- InputPeer and InputUser constructors ---
        'inputPeerEmpty#7f3b18ea = InputPeer',
        'inputPeerSelf#7da07ec9 = InputPeer',
        'inputPeerChat#35a95cb9 chat_id:long = InputPeer',
        'inputPeerUser#dde8a54c user_id:long access_hash:long = InputPeer',
        'inputPeerChannel#27bcbbfc channel_id:long access_hash:long = InputPeer',
        'inputUserEmpty#b98886cf = InputUser',
        'inputUserSelf#f7c1b13f = InputUser',
        'inputUser#f21158c6 user_id:long access_hash:long = InputUser',
        'inputUserFromMessage#1da448e2 peer:InputPeer msg_id:int user_id:long = InputUser',
        'userProfilePhoto#82d1f706 flags:# has_video:flags.0?true personal:flags.2?true photo_id:long stripped_thumb:flags.1?bytes dc_id:int = UserProfilePhoto',
        'userStatusEmpty#9d05049 = UserStatus',
        'userStatusOnline#edb93949 expires:int = UserStatus',
        'userStatusOffline#8c703f was_online:int = UserStatus',
        'userStatusRecently#7b197dc8 flags:# by_me:flags.0?true = UserStatus',
        'userStatusLastWeek#541a1d1a flags:# by_me:flags.0?true = UserStatus',
        'userStatusLastMonth#65899777 flags:# by_me:flags.0?true = UserStatus',
        'auth.sentCode#5e002502 flags:# type:auth.SentCodeType phone_code_hash:string next_type:flags.1?auth.CodeType timeout:flags.2?int = auth.SentCode',
        'auth.sentCodeTypeApp#3dbb5986 length:int = auth.SentCodeType',
        'auth.sentCodeTypeSms#c000bba2 length:int = auth.SentCodeType',
        'auth.sentCodeTypeCall#5353e5a7 length:int = auth.SentCodeType',
        'auth.sentCodeTypeFlashCall#ab03c6d9 pattern:string = auth.SentCodeType',
        'auth.sentCodeTypeMissedCall#82006484 prefix:string length:int = auth.SentCodeType',
        'auth.sentCodeTypeFragmentSms#d9565c39 url:string length:int = auth.SentCodeType',
        'auth.codeTypeSms#72a3158c = auth.CodeType',
        'auth.codeTypeCall#741cd3e3 = auth.CodeType',
        'auth.codeTypeFlashCall#226ccefb = auth.CodeType',
        'auth.codeTypeMissedCall#d61ad6ee = auth.CodeType',
        'auth.codeTypeFragmentSms#6ed998c = auth.CodeType',
        'auth.authorization#2ea2c0d4 flags:# setup_password_required:flags.1?true otherwise_relogin_days:flags.1?int tmp_sessions:flags.0?int future_auth_token:flags.2?bytes user:User = auth.Authorization',
        'auth.authorizationSignUpRequired#44747e9a flags:# terms_of_service:flags.0?help.TermsOfService = auth.Authorization',
        'account.password#957b50fb flags:# has_recovery:flags.0?true has_secure_values:flags.1?true has_password:flags.2?true current_algo:flags.2?PasswordKdfAlgo srp_B:flags.2?bytes srp_id:flags.2?long hint:flags.3?string email_unconfirmed_pattern:flags.4?string new_algo:PasswordKdfAlgo new_secure_algo:SecurePasswordKdfAlgo secure_random:bytes pending_reset_date:flags.5?int login_email_pattern:flags.6?string = account.Password',
        'passwordKdfAlgoUnknown#d45ab096 = PasswordKdfAlgo',
        'passwordKdfAlgoSHA256SHA256PBKDF2HMACSHA512iter100000SHA256ModPow#3a912d4a salt1:bytes salt2:bytes g:int p:bytes = PasswordKdfAlgo',
        'securePasswordKdfAlgoUnknown#4a8537 = SecurePasswordKdfAlgo',
        'securePasswordKdfAlgoPBKDF2HMACSHA512iter100000#bbf2dda0 salt:bytes = SecurePasswordKdfAlgo',
        'securePasswordKdfAlgoSHA512#86471d92 salt:bytes = SecurePasswordKdfAlgo',
        'inputCheckPasswordSRP#d27ff082 srp_id:long A:bytes M1:bytes = InputCheckPasswordSRP',
        'inputCheckPasswordEmpty#acd9bf6d = InputCheckPasswordSRP',
        'codeSettings#ad253d78 flags:# allow_flashcall:flags.0?true current_number:flags.1?true allow_app_hash:flags.4?true allow_missed_call:flags.5?true allow_firebase:flags.7?true unknown_number:flags.9?true logout_tokens:flags.6?Vector<bytes> token:flags.8?string app_sandbox:flags.8?Bool = CodeSettings',
        'boolTrue#997275b4 = Bool',
        'boolFalse#bc799737 = Bool',
        'auth.sendCode#a677244f phone_number:string api_id:int api_hash:string settings:CodeSettings = auth.SentCode',
        'auth.signIn#8d52a951 flags:# phone_number:string phone_code_hash:string phone_code:flags.0?string email_verification:flags.1?EmailVerification = auth.Authorization',
        'auth.checkPassword#d18b4d16 password:InputCheckPasswordSRP = auth.Authorization',
        'account.getPassword#548a30f5 = account.Password',
        'auth.resendCode#cae47523 flags:# phone_number:string phone_code_hash:string reason:flags.0?string = auth.SentCode',
        'auth.cancelCode#1f04049f phone_number:string phone_code_hash:string = Bool',
        'auth.loginToken#629f1980 expires:int token:bytes = auth.LoginToken',
        'auth.loginTokenMigrateTo#68e9916 dc_id:int token:bytes = auth.LoginToken',
        'auth.loginTokenSuccess#390d5c5e authorization:auth.Authorization = auth.LoginToken',
        'auth.exportLoginToken#b7e085fe api_id:int api_hash:string except_ids:Vector<long> = auth.LoginToken',
        'auth.importLoginToken#95ac5ce4 token:bytes = auth.LoginToken',
        'users.getUsers#d91a548 id:Vector<InputUser> = Vector<User>',
        'users.getFullUser#b60f5918 id:InputUser = users.UserFull',
        'user#31774388 flags:# self:flags.10?true contact:flags.11?true mutual_contact:flags.12?true deleted:flags.13?true bot:flags.14?true bot_chat_history:flags.15?true bot_nochats:flags.16?true verified:flags.17?true restricted:flags.18?true min:flags.20?true bot_inline_geo:flags.21?true support:flags.23?true scam:flags.24?true apply_min_photo:flags.25?true fake:flags.26?true bot_attach_menu:flags.27?true premium:flags.28?true attach_menu_enabled:flags.29?true flags2:# bot_can_edit:flags2.1?true close_friend:flags2.2?true stories_hidden:flags2.3?true stories_unavailable:flags2.4?true contact_require_premium:flags2.10?true bot_business:flags2.11?true bot_has_main_app:flags2.13?true bot_forum_view:flags2.16?true bot_forum_can_manage_topics:flags2.17?true bot_can_manage_bots:flags2.18?true bot_guestchat:flags2.19?true bot_guard:flags2.20?true id:long access_hash:flags.0?long first_name:flags.1?string last_name:flags.2?string username:flags.3?string phone:flags.4?string photo:flags.5?UserProfilePhoto status:flags.6?UserStatus bot_info_version:flags.14?int restriction_reason:flags.18?Vector<RestrictionReason> bot_inline_placeholder:flags.19?string lang_code:flags.22?string emoji_status:flags.30?EmojiStatus usernames:flags2.0?Vector<Username> stories_max_id:flags2.5?RecentStory color:flags2.8?PeerColor profile_color:flags2.9?PeerColor bot_active_users:flags2.12?int bot_verification_icon:flags2.14?long send_paid_messages_stars:flags2.15?long = User',
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
        // Full constructor closure for the documented scope methods (layer 227)
        foreach (\MeRezaRezaei\Teleproto\MTProto\TL\Schema\UserScopeSchema::LINES as $line) {
            self::register($line);
        }
    }

    public static function register(string $canonicalLine): void
    {
        self::boot(); // seeding must not depend on call order: register-before-lookup still seeds SCHEMA
        // Generic wrapper lines (`invokeWithLayer`, `initConnection`) carry brace-less
        // `{X:Type}` declarations and `!X` result tokens that are outside the tokenizer
        // grammar; they get a hand-built degraded struct instead (see parseGenericWrapper).
        $parsed = str_contains($canonicalLine, 'X:Type')
            ? self::parseGenericWrapper($canonicalLine)
            : self::parseStrictly($canonicalLine);
        $name = $parsed->name;
        $id = $parsed->hasExplicitId ? $parsed->id : self::crc32Canonical($canonicalLine);
        static::$parsed[$name] = $parsed;
        static::$ids[$name] = $id;
        static::$names[$id] = $name;
        static::$signatures[$name] = $canonicalLine;
    }

    private static function parseStrictly(string $canonicalLine): ParsedSignature
    {
        try {
            return TLSignatureParser::parse($canonicalLine);
        } catch (InvalidArgumentException $e) {
            // A malformed schema line (e.g. a generated UserScopeSchema line) must
            // fail loudly at boot, naming the offending line.
            throw new InvalidArgumentException(
                "TLRegistry: malformed schema line '{$canonicalLine}': {$e->getMessage()}",
                0,
                $e,
            );
        }
    }

    /**
     * Degraded parse for the two generic wrapper lines whose `!X`/`X:Type`
     * tokens the tokenizer rejects. Field walk is byte-identical to what the
     * regex-era fieldsOf produced for them: `X:Type` declarations are skipped,
     * `!X` stays a plain (nested-object) type, conditionals decompose to
     * flagWord/bit. String functions only — no regex.
     *
     * @return ParsedSignature
     */
    protected static function parseGenericWrapper(string $canonicalLine): ParsedSignature
    {
        $line = trim($canonicalLine);
        $name = explode(' ', $line, 2)[0];
        $id = 0;
        $hasId = false;
        $hash = strpos($name, '#');
        if ($hash !== false) {
            $id = (int) hexdec(substr($name, $hash + 1));
            $name = substr($name, 0, $hash);
            $hasId = true;
        }
        $equals = (int) strpos($line, '=');
        $returnType = trim(substr($line, $equals + 1));
        $body = trim(substr($line, strlen(explode(' ', $line, 2)[0]), $equals - strlen(explode(' ', $line, 2)[0])));

        /** @var list<array{name: string, type: string, flagWord: string|null, bit: int|null}> $fields */
        $fields = [];
        if ($body !== '') {
            foreach (explode(' ', $body) as $token) {
                [$fieldName, $type] = explode(':', $token, 2);
                if ($type === 'Type') {
                    continue; // generic declaration (canonical brace-less `{X:Type}`), not a wire field
                }
                $flagWord = null;
                $bit = null;
                $question = strpos($type, '?');
                if ($question !== false) {
                    [$conditional, $type] = explode('?', $type, 2);
                    [$flagWord, $bitDigits] = explode('.', $conditional, 2);
                    $bit = (int) $bitDigits;
                }
                $fields[] = ['name' => $fieldName, 'type' => $type, 'flagWord' => $flagWord, 'bit' => $bit];
            }
        }

        return new ParsedSignature($name, $id, $hasId, $fields, $returnType);
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

    /**
     * Parse-once cached struct for a registered constructor. Repeated calls
     * return the same immutable ParsedSignature instance.
     */
    public static function signatureOf(string $name): ParsedSignature
    {
        self::boot();
        return static::$parsed[$name] ?? throw new InvalidArgumentException("TLRegistry: unknown constructor '{$name}'");
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
