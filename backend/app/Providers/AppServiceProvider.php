<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\FxService;
use App\Services\PriceService;
use App\Services\Quote\KisDomesticQuoteProvider;
use App\Services\Quote\KisOverseasQuoteProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(KisDomesticQuoteProvider::class);
        $this->app->singleton(KisOverseasQuoteProvider::class);

        $this->app->singleton(PriceService::class, function ($app) {
            return new PriceService(
                $app->make(KisDomesticQuoteProvider::class),
                $app->make(KisOverseasQuoteProvider::class)
            );
        });

        $this->app->singleton(FxService::class);
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        //
    }
}
