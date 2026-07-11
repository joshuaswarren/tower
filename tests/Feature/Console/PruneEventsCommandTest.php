<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class PruneEventsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_events_older_than_cutoff_are_deleted_boundary_row_kept(): void
    {
        $agent = \App\Models\Agent::factory()->create();

        $old = Event::factory()->forAgent($agent)->create([
            'received_at' => Carbon::now()->subDays(40),
            'occurred_at' => Carbon::now()->subDays(40),
        ]);
        $boundary = Event::factory()->forAgent($agent)->create([
            'received_at' => Carbon::now()->subDays(29),
            'occurred_at' => Carbon::now()->subDays(29),
        ]);
        $recent = Event::factory()->forAgent($agent)->create([
            'received_at' => Carbon::now()->subDays(5),
            'occurred_at' => Carbon::now()->subDays(5),
        ]);

        $this->artisan('tower:prune-events')->assertExitCode(0);

        $this->assertNull(Event::query()->find($old->id), 'old event must be pruned');
        $this->assertNotNull(Event::query()->find($boundary->id), 'boundary event at 29d must be kept (cutoff is 30d)');
        $this->assertNotNull(Event::query()->find($recent->id), 'recent event must be kept');
    }
}
