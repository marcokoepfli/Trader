<?php

namespace Database\Factories;

use App\Models\Trade;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Trade>
 */
class TradeFactory extends Factory
{
    public function definition(): array
    {
        $direction = $this->faker->randomElement(['BUY', 'SELL']);
        $entry = $this->faker->randomFloat(5, 1.05, 1.15);
        $atr = $this->faker->randomFloat(5, 0.001, 0.005);
        $sl = $direction === 'BUY' ? $entry - $atr * 1.5 : $entry + $atr * 1.5;
        $tp = $direction === 'BUY' ? $entry + $atr * 2.25 : $entry - $atr * 2.25;

        return [
            'oanda_trade_id' => (string) $this->faker->unique()->numberBetween(1000, 99999),
            'pair' => $this->faker->randomElement(config('trading.pairs', ['EUR_USD'])),
            'direction' => $direction,
            'strategy' => $this->faker->randomElement(['MACDCrossover', 'RSIReversal', 'BollingerBounce', 'EMACrossover', 'BreakoutStrategy', 'FibonacciPullback']),
            'entry_price' => $entry,
            'stop_loss' => round($sl, 5),
            'take_profit' => round($tp, 5),
            'position_size' => $this->faker->numberBetween(1000, 10000),
            'result' => 'OPEN',
            'confluence_score' => $this->faker->randomFloat(2, 0.4, 0.95),
            'session' => $this->faker->randomElement(['london', 'newyork', 'asian', 'overlap']),
            'market_condition' => $this->faker->randomElement(['trending', 'ranging', 'volatile', 'quiet']),
            'indicators_at_entry' => ['rsi' => ['value' => $this->faker->numberBetween(20, 80)]],
            'reasoning' => $this->faker->sentence(),
            'opened_at' => now(),
        ];
    }

    public function win(): static
    {
        return $this->state(fn (array $attributes) => [
            'result' => 'WIN',
            'exit_price' => $attributes['take_profit'],
            'pnl' => $this->faker->randomFloat(2, 10, 200),
            'pnl_pct' => $this->faker->randomFloat(4, 0.1, 2.0),
            'hit_take_profit' => true,
            'closed_at' => now(),
        ]);
    }

    public function loss(): static
    {
        return $this->state(fn (array $attributes) => [
            'result' => 'LOSS',
            'exit_price' => $attributes['stop_loss'],
            'pnl' => $this->faker->randomFloat(2, -200, -10),
            'pnl_pct' => $this->faker->randomFloat(4, -2.0, -0.1),
            'hit_stop_loss' => true,
            'closed_at' => now(),
        ]);
    }
}
