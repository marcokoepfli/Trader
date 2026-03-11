<?php

use App\Jobs\AnalyzeMarketJob;
use App\Jobs\GenerateWeeklyReportJob;
use App\Jobs\ManageOpenTradesJob;
use App\Models\BotState;
use App\Services\NewsFilter;
use Illuminate\Support\Facades\Schedule;

// Marktanalyse alle 5 Minuten (nur wenn Bot läuft)
Schedule::job(new AnalyzeMarketJob)->everyFiveMinutes()->when(fn () => BotState::isRunning());

// Offene Trades jede Minute managen
Schedule::job(new ManageOpenTradesJob)->everyMinute()->when(fn () => BotState::isRunning());

// Wöchentlicher Report (Sonntag 20:00)
Schedule::job(new GenerateWeeklyReportJob)->weeklyOn(0, '20:00');

// Täglicher Report
Schedule::command('bot:report')->dailyAt('23:55');

// News-Daten stündlich aktualisieren
Schedule::call(fn () => app(NewsFilter::class)->fetchExternalNews())->hourly();
