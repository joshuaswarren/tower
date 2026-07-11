<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\HostKind;
use App\Enums\TokenAbility;
use App\Models\ApiToken;
use App\Models\Host;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HostCreateCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_host_create_registers_host_and_mints_token(): void
    {
        $this->artisan('tower:host:create', [
            'name' => 'claude-a',
            '--kind' => 'herdr',
            '--connect-hint' => 'ssh claude-a',
        ])->assertExitCode(0);

        $host = Host::query()->where('name', 'claude-a')->firstOrFail();
        $this->assertSame(HostKind::Herdr, $host->kind);
        $this->assertSame('ssh claude-a', $host->connect_hint);

        $token = ApiToken::query()->where('owner_type', Host::class)->where('owner_id', $host->id)->firstOrFail();
        $this->assertSame([TokenAbility::IngestHerdr->value], $token->abilities);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token->token_hash);
    }
}
