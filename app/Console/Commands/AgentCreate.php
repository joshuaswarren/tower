<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AgentKind;
use App\Enums\TokenAbility;
use App\Models\Agent;
use App\Models\ApiToken;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * `tower:agent:create {name} --workspace= --kind= --abilities=`
 *
 * Mints a producer API token for a non-host (regular) agent. Creates the
 * workspace if it does not exist, registers the agent, and prints the
 * plaintext token EXACTLY ONCE. Only the SHA-256 hash is persisted.
 *
 * Token format: `twr_<base62 40>` (40 chars of [0-9A-Za-z]).
 */
class AgentCreate extends Command
{
    protected $signature = 'tower:agent:create
        {name : Human-friendly token label, e.g. "omp-lane-acme"}
        {--workspace= : Workspace name; created if missing (private visibility)}
        {--kind=omp : Agent kind (omp, openclaw, claude-code, codex, herdr, ci, demo, other)}
        {--abilities=ingest,attest : Comma-separated TokenAbility values}';

    protected $description = 'Mint a Tower API token for a producer agent; prints plaintext exactly once.';

    public function handle(): int
    {
        $name = trim((string) $this->argument('name'));
        if ($name === '') {
            $this->error('name is required');
            return self::INVALID;
        }

        $workspaceName = trim((string) $this->option('workspace'));
        if ($workspaceName === '') {
            $this->error('--workspace is required');
            return self::INVALID;
        }

        $kindRaw = strtolower(trim((string) $this->option('kind')));
        $kind = $this->parseKind($kindRaw);

        $abilities = $this->parseAbilities((string) $this->option('abilities'));
        if (empty($abilities)) {
            $this->error('At least one --abilities value is required.');
            return self::INVALID;
        }

        // Find or create the workspace (private by default — public is opt-in).
        $workspace = Workspace::query()->where('name', $workspaceName)->first();
        if ($workspace === null) {
            $workspace = Workspace::query()->create([
                'id' => (string) Str::ulid(),
                'host_id' => null,
                'name' => $workspaceName,
                'visibility' => Workspace::VISIBILITY_PRIVATE,
            ]);
        }

        // Find or create the agent.
        $agent = Agent::query()
            ->where('workspace_id', $workspace->id)
            ->where('name', $name)
            ->first();
        if ($agent === null) {
            $agent = Agent::query()->create([
                'id' => (string) Str::ulid(),
                'workspace_id' => $workspace->id,
                'host_id' => null,
                'name' => $name,
                'kind' => $kind,
                'status' => \App\Enums\AgentStatus::Idle,
                'meta' => [],
            ]);
        }

        // Mint the token. Plaintext is constructed once and never persisted.
        $plain = 'twr_'.Str::lower(Str::random(40));
        $token = ApiToken::query()->create([
            'id' => (string) Str::ulid(),
            'name' => $name,
            'token_prefix' => substr($plain, 0, 12),
            'token_hash' => hash('sha256', $plain),
            'owner_type' => Agent::class,
            'owner_id' => $agent->id,
            'abilities' => $abilities,
            'last_used_at' => null,
        ]);

        $this->line('Token created. The plaintext below is shown EXACTLY ONCE and is not stored.');
        $this->line('');
        $this->line('  '.$plain);
        $this->line('');
        $this->line('Token id: '.$token->id);
        $this->line('Agent:    '.$agent->name.' ('.$agent->id.')');
        $this->line('Workspace:'.$workspace->name.' ('.$workspace->id.')');
        $this->line('Abilities:'.implode(',', $abilities));

        return self::SUCCESS;
    }

    private function parseKind(string $raw): AgentKind
    {
        return match ($raw) {
            'omp' => AgentKind::Omp,
            'openclaw' => AgentKind::Openclaw,
            'claude-code' => AgentKind::ClaudeCode,
            'codex' => AgentKind::Codex,
            'herdr' => AgentKind::Herdr,
            'ci' => AgentKind::Ci,
            'demo' => AgentKind::Demo,
            default => AgentKind::Other,
        };
    }

    /**
     * @return list<string>
     */
    private function parseAbilities(string $raw): array
    {
        $parts = array_values(array_filter(array_map(
            fn (string $s) => trim($s),
            explode(',', $raw)
        )));
        $allowed = array_map(fn (TokenAbility $a) => $a->value, TokenAbility::cases());

        $out = [];
        foreach ($parts as $p) {
            if (in_array($p, $allowed, true)) {
                $out[] = $p;
            } else {
                $this->warn("Unknown ability [{$p}] — ignored.");
            }
        }
        return array_values(array_unique($out));
    }
}
