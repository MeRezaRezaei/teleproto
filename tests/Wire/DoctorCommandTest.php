<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Wire;

use Illuminate\Console\OutputStyle;
use MeRezaRezaei\Teleproto\Console\DoctorCommand;
use MeRezaRezaei\Teleproto\MTProto\Client;
use MeRezaRezaei\Teleproto\MTProto\SessionData;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class DoctorCommandTest extends TestCase
{
    public function testProbeConnectivityFailsCleanlyOnDeadHostWithoutApp(): void
    {
        $session = new SessionData(dcId: 2, authKey: '');
        $client = (new Client(apiId: 1, apiHash: 'h', session: $session))->live();

        $cmd = new DoctorCommand();
        // probeConnectivity() only uses line()/error(), which write to $this->output;
        // a bare command never ran through run(), so inject a no-op OutputStyle.
        $cmd->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput()));

        $exit = $cmd->probeConnectivity($client, host: '127.0.0.1', port: 1);

        $this->assertSame(1, $exit);
    }
}
