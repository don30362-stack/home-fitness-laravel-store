<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $tooManyAttempts = fn (Request $request, array $headers) => response()->json([
            'message' => '嘗試次數過多，請稍後再試。',
        ], 429, $headers);

        foreach (['member-login', 'admin-login'] as $name) {
            RateLimiter::for($name, function (Request $request) use ($tooManyAttempts) {
                $email = $request->input('email');
                // Invalid payloads still have a bounded bucket; validation handles their shape.
                $identifier = is_string($email) ? strtolower(trim($email)) : '';

                return Limit::perMinute(5)
                    ->by(hash('sha256', $identifier.'|'.$request->ip()))
                    ->response($tooManyAttempts);
            });
        }

        RateLimiter::for('member-register', fn (Request $request) => Limit::perMinute(3)
            ->by($request->ip())->response($tooManyAttempts));
        RateLimiter::for('admin-demo', fn (Request $request) => Limit::perMinute(5)
            ->by($request->ip())->response($tooManyAttempts));
    }
}
