# Telegram Passport Integration Guide

Telegram Passport is an end-to-end encrypted identity authorization system allowing services to securely receive user credentials (Passports, Driver's Licenses, ID Cards, Utility Bills, and Personal Details).

---

## 1. Setup RSA Keys & @BotFather

Generate an RSA 2048-bit keypair:
```bash
openssl genrsa -out storage/app/keys/passport_private.pem 2048
openssl rsa -in storage/app/keys/passport_private.pem -pubout -out storage/app/keys/passport_public.pem
```

Register `passport_public.pem` in [@BotFather](https://t.me/botfather) (`/mybots` ➔ Select Bot ➔ Bot Settings ➔ Telegram Passport).

---

## 2. Decrypting Passport Credentials in Laravel Webhook

When a user submits passport data, decrypt it in your webhook controller:

```php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use MeRezaRezaei\Teleproto\Passport\PassportDecryptor;

class TelegramWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $update = $request->all();
        $message = $update['message'] ?? [];

        if (isset($message['passport_data'])) {
            $credentials = $message['passport_data']['credentials'];
            $privateKey = file_get_contents(storage_path('app/keys/passport_private.pem'));

            $decrypted = PassportDecryptor::decryptCredentials(
                encryptedData:   $credentials['data'],
                encryptedSecret: $credentials['secret'],
                privateKeyPem:   $privateKey,
                hash:            $credentials['hash']
            );

            // Access verified identity data
            $personal = $decrypted['personal_details'] ?? [];
            $firstName = $personal['first_name'];
            $lastName  = $personal['last_name'];
            $birthDate = $personal['birth_date'];

            return response()->json(['status' => 'verified', 'user' => $personal]);
        }
    }
}
```
