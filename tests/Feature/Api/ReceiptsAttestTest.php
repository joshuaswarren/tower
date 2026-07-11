<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Actions\Receipts\CreateReceiptStub;
use App\Enums\EventType;
use App\Enums\ReceiptStatus;
use App\Enums\RunState;
use App\Enums\TokenAbility;
use App\Events\Board\ReceiptUpdated;
use App\Jobs\ProcessIngestedBatch;
use App\Models\Agent;
use App\Models\ApiToken;
use App\Models\Event;
use App\Models\Receipt;
use App\Models\Run;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReceiptsAttestTest extends TestCase
{
    use RefreshDatabase;

    private function makeToken(array $abilities): array
    {
        $workspace = Workspace::factory()->create();
        $agent = Agent::factory()->inWorkspace($workspace)->create();
        $plain = 'twr_'.Str::lower(Str::random(40));
        $token = ApiToken::factory()->forAgent($agent)
            ->withAbilities($abilities)
            ->create([
                'token_prefix' => substr($plain, 0, 12),
                'token_hash' => hash('sha256', $plain),
            ]);
        return [$token, $plain, $agent];
    }

    private function makeRunWithDoneEvent(int $durationMs = 12_345): array
    {
        [, $plain, $agent] = $this->makeToken([TokenAbility::Ingest, TokenAbility::Attest]);
        // Create a run that is already done with the given duration.
        $run = Run::factory()->forAgent($agent)->done($durationMs)->create();
        Event::factory()->forRun($run)->runStateChanged('running', 'done')->create([
            'type' => EventType::RunStateChanged,
            'to_state' => 'done',
        ]);
        return [$run, $plain, $agent];
    }

    public function test_done_transition_creates_exactly_one_unattested_stub_with_correct_duration(): void
    {
        Cache::flush();
        Queue::fake();
        [$run, $plain, $agent] = $this->makeRunWithDoneEvent(42_000);

        $result = (new CreateReceiptStub())->execute($run);

        $this->assertTrue($result['created']);
        $this->assertSame(ReceiptStatus::Unattested, $result['receipt']->status);
        $this->assertSame(42_000, $result['receipt']->stub['duration_ms']);
        $this->assertSame($agent->name, $result['receipt']->stub['agent']);
        $this->assertSame(1, Receipt::query()->where('run_id', $run->id)->count());
    }

    public function test_second_call_to_create_stub_is_idempotent(): void
    {
        Cache::flush();
        [$run] = $this->makeRunWithDoneEvent();

        $r1 = (new CreateReceiptStub())->execute($run);
        $r2 = (new CreateReceiptStub())->execute($run);

        $this->assertTrue($r1['created']);
        $this->assertFalse($r2['created']);
        $this->assertSame($r1['receipt']->id, $r2['receipt']->id);
        $this->assertSame(1, Receipt::query()->where('run_id', $run->id)->count());
    }

    public function test_attest_with_ingest_only_token_returns_403(): void
    {
        Cache::flush();
        [$run, , ] = $this->makeRunWithDoneEvent();
        $receipt = (new CreateReceiptStub())->execute($run)['receipt'];

        // Mint a separate token that has only `ingest`.
        [, $plainNoAttest] = $this->makeToken([TokenAbility::Ingest]);

        $this->postJson(
            "/api/v1/receipts/{$receipt->id}/attest",
            ['url' => 'https://example.com/pr/1'],
            ['Authorization' => 'Bearer '.$plainNoAttest]
        )->assertStatus(403);

        $receipt->refresh();
        $this->assertSame(ReceiptStatus::Unattested, $receipt->status);
    }

    public function test_attest_with_url_succeeds_and_second_attest_returns_409(): void
    {
        Cache::flush();
        [$run, $plain] = $this->makeRunWithDoneEvent();
        $receipt = (new CreateReceiptStub())->execute($run)['receipt'];

        $this->postJson(
            "/api/v1/receipts/{$receipt->id}/attest",
            ['url' => 'https://example.com/pr/1', 'kind' => 'link'],
            ['Authorization' => 'Bearer '.$plain]
        )->assertOk();

        $receipt->refresh();
        $this->assertSame(ReceiptStatus::Attested, $receipt->status);
        $this->assertSame('https://example.com/pr/1', $receipt->url);
        $this->assertSame(ApiToken::class, $receipt->attested_by_type);

        // Second attest is rejected.
        $this->postJson(
            "/api/v1/receipts/{$receipt->id}/attest",
            ['url' => 'https://example.com/pr/2'],
            ['Authorization' => 'Bearer '.$plain]
        )->assertStatus(409);
    }

    public function test_attest_with_summary_only_also_succeeds(): void
    {
        Cache::flush();
        [$run, $plain] = $this->makeRunWithDoneEvent();
        $receipt = (new CreateReceiptStub())->execute($run)['receipt'];

        $this->postJson(
            "/api/v1/receipts/{$receipt->id}/attest",
            ['summary' => 'Inline summary attestation.'],
            ['Authorization' => 'Bearer '.$plain]
        )->assertOk();

        $receipt->refresh();
        $this->assertSame(ReceiptStatus::Attested, $receipt->status);
        $this->assertSame('Inline summary attestation.', $receipt->summary);
    }

    public function test_attest_with_neither_url_nor_summary_returns_422(): void
    {
        Cache::flush();
        [$run, $plain] = $this->makeRunWithDoneEvent();
        $receipt = (new CreateReceiptStub())->execute($run)['receipt'];

        $this->postJson(
            "/api/v1/receipts/{$receipt->id}/attest",
            [],
            ['Authorization' => 'Bearer '.$plain]
        )->assertStatus(422);
    }
}
