<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GuestApiAuthenticationTest extends TestCase
{
    public static function guestRequests(): array
    {
        return [
            'member JSON' => ['/api/me', 'application/json'],
            'member HTML' => ['/api/me', 'text/html'],
            'member no Accept' => ['/api/me', null],
            'admin JSON' => ['/api/admin/me', 'application/json'],
            'admin HTML' => ['/api/admin/me', 'text/html'],
            'admin no Accept' => ['/api/admin/me', null],
        ];
    }

    #[DataProvider('guestRequests')]
    public function test_guests_receive_json_401_regardless_of_accept(string $path, ?string $accept): void
    {
        $this->get($path, $accept === null ? [] : ['Accept' => $accept])
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_non_api_guest_redirect_still_uses_the_named_login_route(): void
    {
        Route::get('/__guest_login_probe', fn () => 'login')->name('login');
        Route::get('/__guest_protected_probe', fn () => 'protected')->middleware('auth:web');
        Route::getRoutes()->refreshNameLookups();

        $this->get('/__guest_protected_probe', ['Accept' => 'text/html'])
            ->assertRedirect('/__guest_login_probe');
    }
}
