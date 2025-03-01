<?php

namespace RPurinton\Validators;

use RPurinton\Exceptions\ConfigException;

class ConfigValidators
{
    /**
     * Validates the configuration against required keys.
     *
     * Each value can be:
     * - a string indicating an expected type (e.g., 'int', 'string'),
     * - a nested array for validating sub-arrays,
     * - or a callable that accepts the config value and either returns true or false,
     *   or throws an exception on failure.
     *
     * @param array $keys Expected keys with type or custom validation.
     * @param array $config The configuration data.
     * @param string $context Context information for error messages.
     * @throws ConfigException if a key is missing or validation fails.
     */
    public static function validateRequired(array $keys, array $config): void
    {
        foreach ($keys as $key => $expected) {
            if (!array_key_exists($key, $config)) {
                throw new ConfigException("Missing key '{$key}'.");
            }

            $value = $config[$key];

            if (is_callable($expected)) {
                try {
                    $result = $expected($value);
                    if ($result !== true) {
                        throw new ConfigException("Config validation failed for key '{$key}'.");
                    }
                } catch (\Throwable $e) {
                    throw new ConfigException("Config validation exception for key '{$key}': " . $e->getMessage());
                }
            } elseif (is_array($expected)) {
                if (!is_array($value)) {
                    $got = gettype($value);
                    throw new ConfigException("Invalid type for '{$key}': expected array, got {$got}.");
                }
                self::validateRequired($expected, $value);
            } else {
                // Expected is assumed to be a string type
                $normalizedExpected = self::normalizeType($expected);
                $actualType = gettype($value);
                if ($normalizedExpected !== $actualType) {
                    throw new ConfigException("Invalid type for '{$key}': expected {$expected} ({$normalizedExpected}), got {$actualType}.");
                }
            }
        }
    }

    private static function normalizeType(string $type): string
    {
        $map = [
            'bool'    => 'boolean',
            'boolean' => 'boolean',
            'int'     => 'integer',
            'integer' => 'integer',
            'float'   => 'double',
            'double'  => 'double',
            'string'  => 'string',
            'array'   => 'array'
        ];
        return $map[$type] ?? $type;
    }
}
