<?php

namespace App\Providers;

use App\Services\Customer\CustomerDirectoryService;
use App\Services\Helpdesk\Contracts\CustomerServiceContract;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Helpdesk (and other modules) resolve customer data through this
        // contract. Now backed by the real Customer module; MockCustomerService
        // is retained only as a reference/testing fallback.
        $this->app->bind(CustomerServiceContract::class, CustomerDirectoryService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
