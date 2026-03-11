<?php

namespace Database\Factories;

use App\Models\DailyReport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyReport>
 */
class DailyReportFactory extends Factory
{
    public function definition(): array
    {
        $start = $this->faker->randomFloat(2, 9000, 11000);
        $pnl = $this->faker->randomFloat(2, -200, 300);

        return [
            'report_date' => $this->faker->unique()->dateTimeBetween('-30 days')->format('Y-m-d'),
            'starting_balance' => $start,
            'ending_balance' => $start + $pnl,
            'daily_pnl' => $pnl,
            'daily_pnl_pct' => round(($pnl / $start) * 100, 4),
            'trades_opened' => $this->faker->numberBetween(0, 5),
            'trades_closed' => $this->faker->numberBetween(0, 5),
            'wins' => $this->faker->numberBetween(0, 3),
            'losses' => $this->faker->numberBetween(0, 3),
            'strategy_breakdown' => [],
            'pair_breakdown' => [],
            'new_rules' => [],
        ];
    }
}
