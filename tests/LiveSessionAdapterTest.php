<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests;

use PHPUnit\Framework\TestCase;
use MeRezaRezaei\Teleproto\MTProto\Client;
use MeRezaRezaei\Teleproto\MTProto\SessionData;

class LiveSessionAdapterTest extends TestCase
{
    public function testSessionExportAndImportString(): void
    {
        $session = new SessionData(
            dcId: 2,
            authKey: random_bytes(256),
            serverTimeDelta: -15,
            seqNo: 5,
            userId: 987654321
        );

        $exportedString = $session->exportString();
        $this->assertIsString($exportedString);

        $imported = SessionData::importString($exportedString);

        $this->assertEquals(2, $imported->dcId);
        $this->assertEquals($session->authKey, $imported->authKey);
        $this->assertEquals(-15, $imported->serverTimeDelta);
        $this->assertEquals(987654321, $imported->userId);
    }

    public function testSessionAdapterLoadsFromDatabaseRecord(): void
    {
        $accountRecord = [
            'id' => 123456789,
            'api_id' => 11111,
            'api_hash' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
            'dc_id' => 2,
            'auth_key' => base64_encode(str_repeat("\x42", 256)),
            'server_time_delta' => -10,
            'seq_no' => 4,
        ];

        $session = SessionData::fromArray($accountRecord);

        $client = new Client(
            apiId: $accountRecord['api_id'],
            apiHash: $accountRecord['api_hash'],
            session: $session
        );

        $this->assertEquals(2, $client->getSession()->dcId);
        $this->assertEquals(str_repeat("\x42", 256), $client->getSession()->authKey);
        $this->assertEquals(-10, $client->getSession()->serverTimeDelta);

        // Perform RPC call with session
        $response = $client->call('help.getConfig');
        $this->assertEquals('rpc_result', $response['_']);
        $this->assertEquals('help.getConfig', $response['method']);
        $this->assertEquals(2, $response['dc_id']);
    }

    public function testDirectTcpSocketToTelegramDc(): void
    {
        $dcIp = Client::DC_IPS[2]; // 149.154.167.51
        $port = Client::DEFAULT_PORT; // 443

        $socket = @fsockopen($dcIp, $port, $errno, $errstr, 3.0);
        if ($socket) {
            $this->assertIsResource($socket);
            fclose($socket);
        } else {
            $this->markTestSkipped("Direct internet TCP access to Telegram DC ({$dcIp}:{$port}) unavailable in test environment.");
        }
    }
}
