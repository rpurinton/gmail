<?php

namespace RPurinton;

use RPurinton\Helpers\ConfigHelpers;
use RPurinton\Exceptions\ConfigException;
use RPurinton\Validators\ConfigValidators;

/**
 * Manages JSON configuration file.
 */
class Config
{
    /** @var string Path to the JSON configuration file */
    private string $file;

    /** @var array Configuration data */
    public array $config;

    /**
     * Initializes the configuration.
     *
     * @param string $name Base name for the JSON file.
     * @param array $required Keys required in the configuration.
     * @throws ConfigException If file not found or config fails.
     */
    public function __construct(string $input, array $required = [], ?array $config = null)
    {
        if ($config) {
            $this->config = $config;
        } else {
            $this->file = ConfigHelpers::getConfigDir() . $input . '.json';

            if (!file_exists($this->file)) {
                throw new ConfigException("Configuration file at {$this->file} does not exist.'");
            }

            $this->config = ConfigHelpers::readConfigFromFile($this->file);
        }

        if (!empty($required)) {
            ConfigValidators::validateRequired($required, $this->config);
        }
    }

    /**
     * Opens a new instance of the configuration.
     *
     * @param string $name Base name for the JSON file.
     * @param array $required Required keys.
     * @return Config The configuration instance.
     */
    public static function open(string $name, array $required = []): Config
    {
        return new Config($name, $required);
    }

    /**
     * Returns configuration array from a new instance.
     *
     * @param string $name Base name for the JSON file.
     * @param array $required Required keys.
     * @return array The configuration.
     */
    public static function get(string $input, array $required = [], ?array $config = null): array
    {
        return (new Config($input, $required, $config))->config;
    }

    /**
     * Atomically saves the current configuration.
     *
     * @throws ConfigException If saving the configuration fails.
     */
    public function save(): void
    {
        if (!$this->config) {
            throw new ConfigException("No configuration data to save.");
        }
        try {
            $json = json_encode($this->config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ConfigException("Failed to encode config to JSON: " . $e->getMessage());
        }

        ConfigHelpers::writeJsonToFile($this->file, $json);
    }
}
