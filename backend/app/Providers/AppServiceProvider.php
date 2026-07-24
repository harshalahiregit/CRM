<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Vendor-neutral AI provider — resolved from config('ai.provider').
        $this->app->bind(
            \App\Contracts\AI\AIProviderInterface::class,
            fn () => \App\Services\AI\AIProviderFactory::make()
        );

        // Payroll attendance boundary — placeholder until SangoeTrack integration.
        // Swap this binding for a SangoeTrackAttendanceProvider to go live; payroll
        // logic depends only on the AttendanceProvider interface.
        $this->app->bind(
            \App\Contracts\Hr\AttendanceProvider::class,
            \App\Services\Hr\Attendance\PlaceholderAttendanceProvider::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
