<?php

namespace RPurinton\Helpers;

use RPurinton\Exceptions\ConfigException;

class ConfigHelpers
{
    /**
     * Reads and decodes the JSON configuration from the given file.
     *
     * @param string $file Path to the JSON configuration file.
     * @return array Decoded configuration array.
     * @throws ConfigException If file is unreadable or JSON is invalid.
     */
    public static function readConfigFromFile(string $file): array
    {
        if (!is_readable($file)) {
            throw new ConfigException("Configuration file at {$file} is not readable. Please verify file permissions.");
        }

        $handle = fopen($file, 'r');
        if (!$handle) {
            throw new ConfigException("Unable to open configuration file at {$file} for reading.");
        }
        if (!flock($handle, LOCK_SH)) {
            fclose($handle);
            throw new ConfigException("Unable to obtain a shared lock while reading the file at {$file}.");
        }

        $content = stream_get_contents($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        if ($content === false) {
            throw new ConfigException("Unable to read the content from {$file}.");
        }

        try {
            return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ConfigException("Invalid JSON in {$file}: " . $e->getMessage());
        }
    }

    /**
     * Returns the configuration directory path.
     *
     * @return string Directory path.
     * @throws ConfigException If the directory doesn't exist.
     */
    public static function getConfigDir(): string
    {
        // Retain the original directory calculation.
        $dir = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR;
        if (!is_dir($dir)) {
            throw new ConfigException("Configuration directory at {$dir} does not exist.");
        }
        return $dir;
    }

    /**
     * Writes the provided JSON string to the target file.
     *
     * @param string $targetFile Path to the target configuration file.
     * @param string $json JSON encoded configuration.
     * @throws ConfigException If writing to the file fails.
     */
    public static function writeJsonToFile(string $targetFile, string $json): void
    {
        if (file_put_contents($targetFile, $json) === false) {
            throw new ConfigException("Failed to write configuration data to {$targetFile}.");
        }
    }
}
