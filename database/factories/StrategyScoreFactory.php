<?php

namespace Database\Factories;

use App\Models\StrategyScore;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StrategyScore>
 */
class StrategyScoreFactory extends Factory
{
    public function definition(): array
    {
        $total = $this->faker->numberBetween(10, 100);
        $wins = (int) ($total * $this->faker->randomFloat(2, 0.3, 0.7));

        return [
            'strategy' => $this->faker->unique()->randomElement(['MACDCrossover', 'RSIReversal', 'BollingerBounce', 'EMACrossover', 'BreakoutStrategy', 'FibonacciPullback']),
            'score' => $this->faker->randomFloat(2, 0.2, 0.9),
            'win_rate' => round(($wins / $total) * 100, 2),
            'avg_pnl' => $this->faker->randomFloat(2, -50, 100),
            'profit_factor' => $this->faker->randomFloat(2, 0.5, 3.0),
            'total_trades' => $total,
            'wins' => $wins,
            'losses' => $total - $wins,
        ];
    }
}
