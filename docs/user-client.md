# User MTProto Client Guide

The User MTProto client allows your Laravel application to connect to Telegram directly over MTProto 2.0.

---

## 1. Authentication & Session Management

### Step 1: Initialize User Login
```php
use MeRezaRezaei\Teleproto\Facades\Telegram;

// Initialize client with user's API credentials (or fallback to .env)
$user = Telegram::user(
    accountId: 123456789,
    apiId: 123456,
    apiHash: 'your_api_hash'
);

// Request SMS / Telegram code
$result = $user->call('auth.sendCode', [
    'phone_number' => '+1234567890',
    'api_id'       => 123456,
    'api_hash'     => 'your_api_hash',
    'settings'     => ['_' => 'codeSettings'],
]);
```

### Step 2: Submit Verification Code
```php
$signInResult = $user->call('auth.signIn', [
    'phone_number'    => '+1234567890',
    'phone_code_hash' => $result['phone_code_hash'],
    'phone_code'      => '12345',
]);
```

### Step 3: Handling 2FA Cloud Password (if required)
If Telegram responds with `SESSION_PASSWORD_NEEDED`:
```php
$passwordInfo = $user->call('account.getPassword');

// Compute 2FA SRP proof
$srpProof = $user->mtproto->compute2faProof($passwordInfo, 'user_2fa_password');

$authResult = $user->call('auth.checkPassword', [
    'password' => array_merge(['_' => 'inputCheckPasswordSRP'], $srpProof),
]);
```

### Step 4: Exporting the Session
Once authenticated, export the session to a string and save it in your database:
```php
$sessionString = $user->session->exportString();

// Save encrypted in your Laravel database:
$userModel->update([
    'telegram_session' => Crypt::encryptString($sessionString)
]);
```

---

## 2. Making Calls from Stored Session

```php
use MeRezaRezaei\Teleproto\Facades\Telegram;

// Load and initialize client from stored session string
$user = Telegram::fromSession(
    sessionString: Crypt::decryptString($userModel->telegram_session),
    apiId: $userModel->api_id,
    apiHash: $userModel->api_hash
);

// 1. Send Text Message
$user->sendMessage(peer: '@username', text: 'Hello from Laravel!');

// 2. Fetch User Profile
$profile = $user->call('users.getFullUser', [
    'id' => ['_' => 'inputUser', 'user_id' => 987654321, 'access_hash' => 0]
]);

// 3. Search Chat History
$messages = $user->call('messages.search', [
    'peer' => ['_' => 'inputPeerChannel', 'channel_id' => 123456, 'access_hash' => 0],
    'q'    => 'invoice',
    'filter' => ['_' => 'inputMessagesFilterEmpty'],
    'limit' => 50,
]);
```
