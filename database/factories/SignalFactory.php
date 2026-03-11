<?php

namespace Database\Factories;

use App\Models\Signal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Signal>
 */
class SignalFactory extends Factory
{
    public function definition(): array
    {
        $entry = $this->faker->randomFloat(5, 1.05, 1.15);

        return [
            'pair' => $this->faker->randomElement(config('trading.pairs', ['EUR_USD'])),
            'strategy' => $this->faker->randomElement(['MACDCrossover', 'RSIReversal', 'EMACrossover']),
            'direction' => $this->faker->randomElement(['BUY', 'SELL']),
            'confidence' => $this->faker->randomFloat(2, 0.3, 0.95),
            'entry_price' => $entry,
            'stop_loss' => $entry - 0.003,
            'take_profit' => $entry + 0.005,
            'reasoning' => $this->faker->sentence(),
            'was_executed' => $this->faker->boolean(70),
            'indicator_snapshot' => ['rsi' => ['value' => 45]],
        ];
    }
}
