<?php

use App\Models\BotState;

test('BotState kann Werte setzen und lesen', function () {
    BotState::setValue('test_key', 'test_value');

    expect(BotState::getValue('test_key'))->toBe('test_value');
});

test('BotState gibt Default zurück wenn Key nicht existiert', function () {
    expect(BotState::getValue('nonexistent', 'default'))->toBe('default');
});

test('BotState isRunning prüft korrekt', function () {
    BotState::setValue('is_running', 'true');
    expect(BotState::isRunning())->toBeTrue();

    BotState::setValue('is_running', 'false');
    expect(BotState::isRunning())->toBeFalse();
});

test('BotState isPaused prüft korrekt', function () {
    BotState::setValue('is_paused', 'true');
    expect(BotState::isPaused())->toBeTrue();

    BotState::setValue('is_paused', 'false');
    expect(BotState::isPaused())->toBeFalse();
});

test('BotState überschreibt existierenden Wert', function () {
    BotState::setValue('counter', '1');
    BotState::setValue('counter', '2');

    expect(BotState::getValue('counter'))->toBe('2');
    expect(BotState::query()->where('key', 'counter')->count())->toBe(1);
});
