<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotState extends Model
{
    protected $fillable = ['key', 'value'];

    /** Wert lesen */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        $state = static::query()->where('key', $key)->first();

        return $state ? $state->value : $default;
    }

    /** Wert setzen */
    public static function setValue(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => (string) $value]
        );
    }

    public static function isRunning(): bool
    {
        return static::getValue('is_running', 'false') === 'true';
    }

    public static function isPaused(): bool
    {
        return static::getValue('is_paused', 'false') === 'true';
    }

    public static function getPeakBalance(): float
    {
        return (float) static::getValue('peak_balance', 0);
    }

    public static function getDailyPnl(): float
    {
        return (float) static::getValue('daily_pnl', 0);
    }
}
