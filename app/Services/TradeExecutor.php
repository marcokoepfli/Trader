<?php

namespace App\Services;

use App\DTOs\SignalDTO;
use App\Events\TradeOpened;
use App\Models\BotState;
use App\Models\Signal;
use App\Models\Trade;
use App\Services\Broker\OandaClient;
use Illuminate\Support\Facades\Log;

class TradeExecutor
{
    public function __construct(
        private OandaClient $broker,
        private RiskManager $riskManager,
    ) {}

    /**
     * Signal ausführen und Trade eröffnen
     */
    public function execute(SignalDTO $signal, string $pair, array $indicators, string $session, string $marketCondition, float $confluenceScore): ?Trade
    {
        $log = Log::channel('trading');

        // Kontoinformationen holen
        $account = $this->broker->getAccountInfo();
        $balance = (float) ($account['balance'] ?? 0);

        if ($balance <= 0) {
            $log->error('[TRADE] Kein Kontostand verfügbar');

            return null;
        }

        // Positionsgrösse berechnen
        $units = $this->riskManager->calculatePositionSize($balance, $signal->entryPrice, $signal->stopLoss);
        if ($units <= 0) {
            $log->warning('[TRADE] Positionsgrösse = 0 — Trade übersprungen');

            return null;
        }

        // Bei SELL: negative Units
        $orderUnits = $signal->direction === 'SELL' ? -$units : $units;

        // Order bei OANDA platzieren
        $response = $this->broker->placeMarketOrder($pair, $orderUnits, $signal->stopLoss, $signal->takeProfit);

        if (isset($response['error']) || ! isset($response['orderFillTransaction'])) {
            $log->error('[TRADE] Order fehlgeschlagen', ['response' => $response]);

            // Signal als nicht ausgeführt speichern
            Signal::query()->create([
                'pair' => $pair,
                'strategy' => $signal->strategy,
                'direction' => $signal->direction,
                'confidence' => $signal->confidence,
                'entry_price' => $signal->entryPrice,
                'stop_loss' => $signal->stopLoss,
                'take_profit' => $signal->takeProfit,
                'reasoning' => $signal->reasoning,
                'was_executed' => false,
                'rejection_reason' => 'Order fehlgeschlagen: '.($response['message'] ?? 'Unbekannt'),
                'indicator_snapshot' => $indicators,
            ]);

            return null;
        }

        $fill = $response['orderFillTransaction'];
        $oandaTradeId = $fill['tradeOpened']['tradeID'] ?? ($fill['id'] ?? null);
        $fillPrice = (float) ($fill['price'] ?? $signal->entryPrice);
        $slippage = abs($fillPrice - $signal->entryPrice);

        // Trade in DB speichern
        $trade = Trade::query()->create([
            'oanda_trade_id' => $oandaTradeId,
            'pair' => $pair,
            'direction' => $signal->direction,
            'strategy' => $signal->strategy,
            'entry_price' => $fillPrice,
            'stop_loss' => $signal->stopLoss,
            'take_profit' => $signal->takeProfit,
            'position_size' => $units,
            'result' => 'OPEN',
            'confluence_score' => $confluenceScore,
            'session' => $session,
            'market_condition' => $marketCondition,
            'indicators_at_entry' => $indicators,
            'slippage' => $slippage,
            'reasoning' => $signal->reasoning,
            'opened_at' => now(),
        ]);

        // Signal als ausgeführt speichern
        Signal::query()->create([
            'pair' => $pair,
            'strategy' => $signal->strategy,
            'direction' => $signal->direction,
            'confidence' => $signal->confidence,
            'entry_price' => $fillPrice,
            'stop_loss' => $signal->stopLoss,
            'take_profit' => $signal->takeProfit,
            'reasoning' => $signal->reasoning,
            'was_executed' => true,
            'indicator_snapshot' => $indicators,
        ]);

        // Peak-Balance aktualisieren
        $peakBalance = BotState::getPeakBalance();
        if ($balance > $peakBalance) {
            BotState::setValue('peak_balance', (string) $balance);
        }

        TradeOpened::dispatch($trade);

        return $trade;
    }
}
