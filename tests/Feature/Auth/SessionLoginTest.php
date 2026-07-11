<?php

declare(strict_types=1);

use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\post;

it('renders the login form on GET /login', function (): void {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Sign in')
        ->assertSee('name="email"', false)
        ->assertSee('name="password"', false)
        ->assertSee('action="'.route('login.store').'"', false);
});

it('logs the user in and redirects to /board on valid credentials', function (): void {
    $user = User::factory()->create([
        'password' => 'password',
    ]);

    $response = post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('board'));
    $this->assertAuthenticatedAs($user);
});

it('rejects invalid credentials', function (): void {
    $user = User::factory()->create([
        'password' => 'password',
    ]);

    $response = post(route('login.store'), [
        'email' => $user->email,
        'password' => 'not-the-password',
    ]);

    $response->assertSessionHasErrors('email');
    assertGuest();
});

it('does not offer a registration route', function (): void {
    // Registration is intentionally absent. Asserting the route
    // collection is the cleanest way to prove it.
    $registered = collect(\Illuminate\Support\Facades\Route::getRoutes())
        ->map(fn (\Illuminate\Routing\Route $r) => $r->getName())
        ->filter()
        ->values()
        ->all();

    expect($registered)
        ->not->toContain('register')
        ->not->toContain('password.request')
        ->not->toContain('password.email');

    // And a guessed registration URL does not 200.
    foreach (['/register', '/forgot-password', '/reset-password'] as $path) {
        $this->get($path)
            ->assertNotFound();
    }
});

it('logs the user out and redirects to / on POST /logout', function (): void {
    $user = User::factory()->create();
    actingAs($user);

    $this->assertAuthenticatedAs($user);

    $this->post(route('logout'))->assertRedirect('/');
    assertGuest();
});
