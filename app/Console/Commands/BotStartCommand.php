<?php

namespace App\Console\Commands;

use App\Models\BotState;
use App\Services\Broker\OandaClient;
use Illuminate\Console\Command;

class BotStartCommand extends Command
{
    protected $signature = 'bot:start';

    protected $description = 'Trading Bot starten';

    public function handle(OandaClient $broker): int
    {
        $env = config('trading.oanda.environment');
        $accountId = config('trading.oanda.account_id');

        if (! config('trading.oanda.token') || ! $accountId) {
            $this->error('OANDA_API_TOKEN und OANDA_ACCOUNT_ID müssen in .env gesetzt sein!');

            return self::FAILURE;
        }

        // Kontoinformationen holen
        $account = $broker->getAccountInfo();

        if (isset($account['error'])) {
            $this->error('Verbindung zu OANDA fehlgeschlagen: '.($account['message'] ?? 'Unbekannter Fehler'));

            return self::FAILURE;
        }

        $balance = $account['balance'] ?? 'N/A';
        $currency = $account['currency'] ?? 'EUR';

        $this->newLine();
        $this->warn('╔══════════════════════════════════════════════════╗');
        $this->warn('║  ⚠  WARNUNG: Trading Bot mit Echtgeld-Zugang   ║');
        $this->warn('╚══════════════════════════════════════════════════╝');
        $this->newLine();

        $this->table(
            ['Parameter', 'Wert'],
            [
                ['Environment', strtoupper($env)],
                ['Account ID', $accountId],
                ['Balance', number_format((float) $balance, 2)." {$currency}"],
                ['Paare', implode(', ', config('trading.pairs'))],
                ['Max Risk/Trade', (config('trading.risk.max_per_trade') * 100).'%'],
                ['Max Drawdown', (config('trading.risk.max_drawdown') * 100).'%'],
            ],
        );

        $this->newLine();

        if ($env === 'live') {
            $this->error('⚠ ACHTUNG: LIVE-MODUS AKTIV — Echtes Geld wird riskiert!');
        }

        if ($this->input->isInteractive() && ! $this->confirm('Bot starten?', false)) {
            $this->info('Abgebrochen.');

            return self::SUCCESS;
        }

        // Bot-Status setzen
        BotState::setValue('is_running', 'true');
        BotState::setValue('is_paused', 'false');
        BotState::setValue('started_at', now()->toIso8601String());
        BotState::setValue('environment', $env);

        // Peak-Balance initialisieren
        $peakBalance = BotState::getPeakBalance();
        if ((float) $balance > $peakBalance) {
            BotState::setValue('peak_balance', (string) $balance);
        }

        // Wochen-Start-Balance
        if (! BotState::getValue('week_start_balance')) {
            BotState::setValue('week_start_balance', (string) $balance);
        }

        $this->newLine();
        $this->info('✓ Bot gestartet!');
        $this->info('  Dashboard: php artisan serve → http://localhost:8000');
        $this->info('  Logs: tail -f storage/logs/trading.log');
        $this->newLine();
        $this->comment('Starte in separaten Terminals:');
        $this->comment('  php artisan queue:work');
        $this->comment('  php artisan schedule:work');

        return self::SUCCESS;
    }
}
