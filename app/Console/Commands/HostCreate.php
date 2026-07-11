<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\HostKind;
use App\Models\ApiToken;
use App\Models\Host;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * `tower:host:create {name} --kind= --connect-hint=`
 *
 * Registers a host (bridge target) and mints a host-owned API token with
 * the `ingest:herdr` ability. Plaintext is printed EXACTLY ONCE.
 */
class HostCreate extends Command
{
    protected $signature = 'tower:host:create
        {name : Host label (e.g. claude-a)}
        {--kind=herdr : Host kind (herdr, server, ci, cloud, other)}
        {--connect-hint= : Copy-paste attach text for the board}';

    protected $description = 'Register a Tower host and mint a host-owned API token (printed once).';

    public function handle(): int
    {
        $name = trim((string) $this->argument('name'));
        if ($name === '') {
            $this->error('name is required');
            return self::INVALID;
        }

        $kindRaw = strtolower(trim((string) $this->option('kind')));
        $kind = $this->parseKind($kindRaw);
        $connectHint = $this->option('connect-hint');
        $connectHint = is_string($connectHint) ? trim($connectHint) : '';
        if ($connectHint === '') {
            $connectHint = null;
        }

        $host = Host::query()->where('name', $name)->first();
        if ($host === null) {
            $host = Host::query()->create([
                'id' => (string) Str::ulid(),
                'name' => $name,
                'kind' => $kind,
                'connect_hint' => $connectHint,
                'last_seen_at' => null,
            ]);
        } else {
            $host->forceFill([
                'kind' => $kind,
                'connect_hint' => $connectHint,
            ])->save();
        }

        $plain = 'twr_'.Str::lower(Str::random(40));
        $token = ApiToken::query()->create([
            'id' => (string) Str::ulid(),
            'name' => $name.' token',
            'token_prefix' => substr($plain, 0, 12),
            'token_hash' => hash('sha256', $plain),
            'owner_type' => Host::class,
            'owner_id' => $host->id,
            'abilities' => [\App\Enums\TokenAbility::IngestHerdr->value],
            'last_used_at' => null,
        ]);

        $this->line('Host token created. The plaintext below is shown EXACTLY ONCE and is not stored.');
        $this->line('');
        $this->line('  '.$plain);
        $this->line('');
        $this->line('Token id: '.$token->id);
        $this->line('Host:     '.$host->name.' ('.$host->id.')');
        $this->line('Ability:  '.implode(',', (array) $token->abilities));

        return self::SUCCESS;
    }

    private function parseKind(string $raw): HostKind
    {
        return match ($raw) {
            'herdr' => HostKind::Herdr,
            'server' => HostKind::Server,
            'ci' => HostKind::Ci,
            'cloud' => HostKind::Cloud,
            default => HostKind::Other,
        };
    }
}
