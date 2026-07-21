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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
