<?php

declare(strict_types=1);

use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

// `fleet.board` is the security-relevant private gate: only a persisted user
// may subscribe; `public.board` is open. Guests are denied at the HTTP
// boundary by the `auth` middleware on /broadcasting/auth (driver-independent),
// and the channel closure denies any non-persisted user.

function channelCallback(string $channel): Closure
{
    $broadcaster = app(\Illuminate\Contracts\Broadcasting\Factory::class)->connection();
    $reflection = new ReflectionObject($broadcaster);
    $prop = $reflection->getProperty('channels');
    $prop->setAccessible(true);
    $channels = $prop->getValue($broadcaster);

    expect($channels)->toHaveKey($channel);

    return $channels[$channel];
}

it('authorizes a persisted user on fleet.board (closure)', function (): void {
    expect(channelCallback('fleet.board')(User::factory()->create()))->toBeTrue();
});

it('denies a non-persisted user on fleet.board (closure)', function (): void {
    expect(channelCallback('fleet.board')(new User()))->toBeFalse();
});

it('leaves public.board open (closure)', function (): void {
    expect(channelCallback('public.board')())->toBeTrue();
});

it('denies a guest at /broadcasting/auth regardless of driver', function (): void {
    post('/broadcasting/auth', [
        'channel_name' => 'private-fleet.board',
        'socket_id' => '123.456',
    ])->assertRedirect(route('login'));
});

it('authorizes an authenticated user at /broadcasting/auth', function (): void {
    actingAs(User::factory()->create())
        ->post('/broadcasting/auth', [
            'channel_name' => 'private-fleet.board',
            'socket_id' => '123.456',
        ])->assertOk();
});
