<?php

namespace App\Providers;

use App\Events\DrawdownAlert;
use App\Events\TradeClosed;
use App\Events\TradeOpened;
use App\Listeners\LogTradeActivity;
use App\Listeners\SendDrawdownNotification;
use App\Listeners\UpdateLearningEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
        $this->configureDefaults();

        Event::listen(TradeOpened::class, LogTradeActivity::class);
        Event::listen(TradeClosed::class, [LogTradeActivity::class, UpdateLearningEngine::class]);
        Event::listen(DrawdownAlert::class, SendDrawdownNotification::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
