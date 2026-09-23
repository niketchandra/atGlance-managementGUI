<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class AdminSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'setting_group',
        'setting_key',
        'setting_value',
        'is_encrypted',
    ];

    protected $casts = [
        'is_encrypted' => 'boolean',
    ];

    public static function getValue(string $key, mixed $default = null): mixed
    {
        $setting = static::query()->where('setting_key', $key)->first();

        if (!$setting || $setting->setting_value === null) {
            return $default;
        }

        $value = $setting->setting_value;

        if ($setting->is_encrypted) {
            try {
                $value = Crypt::decryptString($value);
            } catch (\Throwable $e) {
                return $default;
            }
        }

        return $value;
    }

    public static function putValue(string $group, string $key, mixed $value, bool $encrypt = false): void
    {
        $storedValue = $value;

        if (is_array($storedValue)) {
            $storedValue = json_encode($storedValue, JSON_UNESCAPED_UNICODE);
        }

        if ($storedValue !== null && $encrypt) {
            $storedValue = Crypt::encryptString((string) $storedValue);
        }

        static::query()->updateOrCreate(
            ['setting_key' => $key],
            [
                'setting_group' => $group,
                'setting_value' => $storedValue,
                'is_encrypted' => $encrypt,
            ]
        );
    }
}
