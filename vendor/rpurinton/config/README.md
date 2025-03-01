# RPurinton Configuration Management

This directory contains the configuration management system for the RPurinton project. The primary component is the `Config` class, which provides a robust mechanism for handling configuration data stored in JSON format.

## Overview

The `Config` class is designed to facilitate the management of configuration data by providing methods to read, validate, and write configuration files. It ensures that configuration files are always in a consistent state, even in the event of a system failure.

## Features

- **JSON Configuration**: The configuration data is stored in JSON format, allowing for easy readability and editing.
- **Automatic File Creation**: If the specified configuration file does not exist, an initial empty configuration file is created automatically.
- **Validation**: The class supports validation of required configuration keys and their types, ensuring that all necessary configuration data is present and correctly formatted.
- **Atomic Writes**: Configuration changes are written to a temporary file and then atomically moved to the target location, preventing data corruption.
- **Error Handling**: Comprehensive error handling is implemented to manage file operations and JSON encoding/decoding issues.

## Usage

### Initialization

To initialize a configuration, create an instance of the `Config` class:

```php
use RPurinton\Config;

$config = new Config('config_name', [
    'required_key' => 'string',
    'nested_key' => [
        'sub_key' => 'int'
    ]
]);
```

- **`config_name`**: The base name of the configuration file (without extension).
- **`required`**: An associative array defining required keys and their expected types.

### Reading Configuration

The configuration data can be accessed directly from the `config` property:

```php
$data = $config->config;
```

### Saving Configuration

To save changes to the configuration, use the `save` method:

```php
$config->save();
```

### Static Access

For quick access to configuration data without the need to save changes, use the static `get` method:

```php
$data = Config::get('config_name', [
    'required_key' => 'string'
]);
```

## Error Handling

The `Config` class throws `ConfigException` for any errors encountered during file operations or JSON processing. Ensure to handle these exceptions appropriately in your application.

## Directory Structure

- **`src/Config.php`**: Contains the `Config` class implementation.
- **`config/`**: The directory where configuration files are stored. This directory is created automatically if it does not exist.

## Requirements

- PHP 7.4 or higher
- JSON extension enabled

## License

This project is licensed under the MIT License. See the LICENSE file for details.
