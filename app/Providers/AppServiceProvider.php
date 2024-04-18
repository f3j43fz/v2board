<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use voku\helper\AntiXSS;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
        $this->app->singleton(AntiXSS::class, function ($app) {
            return new AntiXSS();
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->app['view']->addNamespace('theme', public_path() . '/theme');
    }
}
