<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Support;

use MeRezaRezaei\Teleproto\Support\EnvFile;
use PHPUnit\Framework\TestCase;

class EnvFileTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = tempnam(sys_get_temp_dir(), 'envtest');
        file_put_contents($this->tmp, "# comment\nA=1\nB=\"quoted value\"\n\nC=\"old\"\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->tmp);
    }

    public function testReadParsesValuesIncludingQuoted(): void
    {
        $this->assertSame('1', EnvFile::read($this->tmp)['A'] ?? '');
        $this->assertSame('quoted value', EnvFile::read($this->tmp)['B'] ?? '');
    }

    public function testUpsertReplacesExistingAndAddsNew(): void
    {
        EnvFile::upsert($this->tmp, 'C', 'new');
        EnvFile::upsert($this->tmp, 'D', 'added');
        $vars = EnvFile::read($this->tmp);
        $this->assertSame('new', $vars['C']);
        $this->assertSame('added', $vars['D']);
        $this->assertSame('1', $vars['A']); // untouched lines survive
    }

    public function testReadMissingFileReturnsEmpty(): void
    {
        $this->assertSame([], EnvFile::read('/nonexistent/env'));
    }
}
