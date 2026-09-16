<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SystemSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'type',
    ];

    public const CACHE_PREFIX = 'system_setting:';

    /**
     * Retrieve a setting by key with optional default fallback.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::rememberForever(self::CACHE_PREFIX . $key, function () use ($key, $default) {
            try {
                $setting = static::where('key', $key)->first();
                if (! $setting) {
                    return $default;
                }

                return match ($setting->type) {
                    'boolean', 'bool' => filter_var($setting->value, FILTER_VALIDATE_BOOLEAN),
                    'integer', 'int' => (int) $setting->value,
                    'float' => (float) $setting->value,
                    'json', 'array' => json_decode($setting->value, true),
                    default => $setting->value,
                };
            } catch (\Throwable $e) {
                return $default;
            }
        });
    }

    /**
     * Store or update a setting by key.
     */
    public static function set(string $key, mixed $value, string $type = 'string'): void
    {
        $serialized = match ($type) {
            'boolean', 'bool' => $value ? '1' : '0',
            'json', 'array' => json_encode($value),
            default => (string) $value,
        };

        static::updateOrCreate(
            ['key' => $key],
            ['value' => $serialized, 'type' => $type]
        );

        Cache::forget(self::CACHE_PREFIX . $key);
    }

    /**
     * Check whether post boosting is enabled platform-wide.
     */
    public static function isBoostEnabled(): bool
    {
        return (bool) static::get('post_boost_enabled', true);
    }

    /**
     * Enable post boost system.
     */
    public static function enableBoost(): void
    {
        static::set('post_boost_enabled', true, 'boolean');
    }

    /**
     * Disable post boost system.
     */
    public static function disableBoost(): void
    {
        static::set('post_boost_enabled', false, 'boolean');
    }
}
