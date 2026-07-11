<?php

declare(strict_types=1);

namespace Tests\Unit\Actions;

use App\Actions\Drift\DetectDrift;
use App\Enums\DriftSeverity;
use App\Enums\EventType;
use App\Enums\RunState;
use App\Models\Agent;
use App\Models\Allowlist;
use App\Models\DriftFlag;
use App\Models\Event;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DetectDriftTest extends TestCase
{
    use RefreshDatabase;

    public function test_manifest_bash_edit_with_curl_invocation_creates_one_warn(): void
    {
        $agent = Agent::factory()->create();
        Allowlist::factory()->forAgent($agent)->version(1)->create([
            'manifest' => ['tools' => ['bash', 'edit'], 'scopes' => [], 'deny' => []],
        ]);

        $event = Event::factory()->forAgent($agent)->create([
            'type' => EventType::ToolInvoked,
            'payload' => ['tool' => 'curl'],
        ]);

        $flags = (new DetectDrift())->execute([$event->id]);

        $this->assertCount(1, $flags);
        $this->assertSame(DriftSeverity::Warn, $flags[0]->severity);
        $this->assertSame('curl', $flags[0]->tool);
    }

    public function test_repeat_invocation_in_same_run_increments_count_not_count(): void
    {
        $agent = Agent::factory()->create();
        Allowlist::factory()->forAgent($agent)->version(1)->create([
            'manifest' => ['tools' => ['bash', 'edit'], 'scopes' => [], 'deny' => []],
        ]);
        $run = Run::factory()->forAgent($agent)->running()->create();

        $e1 = Event::factory()->forRun($run)->create([
            'type' => EventType::ToolInvoked,
            'payload' => ['tool' => 'curl'],
        ]);
        $e2 = Event::factory()->forRun($run)->create([
            'type' => EventType::ToolInvoked,
            'payload' => ['tool' => 'curl'],
        ]);

        (new DetectDrift())->execute([$e1->id, $e2->id]);

        $flag = DriftFlag::query()->where('agent_id', $agent->id)->where('tool', 'curl')->firstOrFail();
        $this->assertSame(2, $flag->detail['count']);
        $this->assertSame(1, DriftFlag::query()->where('agent_id', $agent->id)->count());
    }

    public function test_deny_match_is_violation(): void
    {
        $agent = Agent::factory()->create();
        Allowlist::factory()->forAgent($agent)->version(1)->create([
            'manifest' => [
                'tools' => ['bash'],
                'scopes' => [],
                'deny' => ['prod:*'],
            ],
        ]);

        $event = Event::factory()->forAgent($agent)->create([
            'type' => EventType::ToolInvoked,
            'payload' => ['tool' => 'prod:db'],
        ]);

        $flags = (new DetectDrift())->execute([$event->id]);

        $this->assertCount(1, $flags);
        $this->assertSame(DriftSeverity::Violation, $flags[0]->severity);
    }

    public function test_no_manifest_means_no_flags(): void
    {
        $agent = Agent::factory()->create();
        // No Allowlist.

        $event = Event::factory()->forAgent($agent)->create([
            'type' => EventType::ToolInvoked,
            'payload' => ['tool' => 'curl'],
        ]);

        $flags = (new DetectDrift())->execute([$event->id]);

        $this->assertSame([], $flags);
        $this->assertSame(0, DriftFlag::query()->count());
    }

    public function test_repeat_invocation_across_batches_accumulates_count(): void
    {
        $agent = Agent::factory()->create();
        Allowlist::factory()->forAgent($agent)->version(1)->create([
            'manifest' => ['tools' => ['bash', 'edit'], 'scopes' => [], 'deny' => []],
        ]);
        $run = Run::factory()->forAgent($agent)->running()->create();

        $e1 = Event::factory()->forRun($run)->create([
            'type' => EventType::ToolInvoked,
            'payload' => ['tool' => 'curl'],
        ]);
        (new DetectDrift())->execute([$e1->id]);

        $e2 = Event::factory()->forRun($run)->create([
            'type' => EventType::ToolInvoked,
            'payload' => ['tool' => 'curl'],
        ]);
        (new DetectDrift())->execute([$e2->id]);

        $flag = DriftFlag::query()->where('agent_id', $agent->id)->where('tool', 'curl')->firstOrFail();
        $this->assertSame(2, $flag->detail['count']);
        $this->assertSame((int) $e2->id, (int) $flag->detail['last_event_id']);
        $this->assertSame(1, DriftFlag::query()->where('agent_id', $agent->id)->count());
    }

    public function test_drift_disabled_is_no_op(): void
    {
        config()->set('tower.drift.enabled', false);
        $agent = Agent::factory()->create();
        Allowlist::factory()->forAgent($agent)->version(1)->create([
            'manifest' => ['tools' => ['bash'], 'scopes' => [], 'deny' => []],
        ]);
        $event = Event::factory()->forAgent($agent)->create([
            'type' => EventType::ToolInvoked,
            'payload' => ['tool' => 'curl'],
        ]);

        $flags = (new DetectDrift())->execute([$event->id]);

        $this->assertSame([], $flags);
        $this->assertSame(0, DriftFlag::query()->count());
    }
}
