<?php

namespace Database\Factories;

use App\Models\TradingRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TradingRule>
 */
class TradingRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->sentence(3),
            'description' => $this->faker->sentence(),
            'type' => $this->faker->randomElement(['session_block', 'indicator_filter', 'strategy_pause']),
            'conditions' => ['strategy' => 'MACDCrossover', 'session' => 'asian'],
            'reason' => $this->faker->sentence(),
            'source' => $this->faker->randomElement(['auto', 'manual']),
            'active' => true,
        ];
    }
}
