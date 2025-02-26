# RPurinton Gmail Library for PHP

## Introduction

The RPurinton Gmail Library is a PHP library designed to facilitate interaction with Gmail using Google's API. It provides functionality for sending, receiving, and managing Gmail messages efficiently.

## Installation

### Composer Installation

To install the library, use Composer:

```bash
composer require rpurinton/gmail
```

### Configuration Setup

1. Create a `config` directory at the same level as the `vendor` directory.
2. Place the `Gmail.json` file with your credentials in the `config` directory.
   - Note: The `access_token`, `refresh_token`, and `expires_at` will be added later by the library.

## Google Cloud Project Setup

1. Create a Google Cloud project.
2. Enable the Gmail API.
3. Create OAuth 2.0 credentials.
4. Download the credentials JSON file and place it in the `config` directory as `Gmail.json`.

## Usage

### Initialization

- Create a new instance of the `Gmail` class.
- Run the `->init()` method to start the OAuth2 authentication flow.
- This method will redirect to Google for authentication and back to your script with the authorization code.
- The `init()` method is crucial for the first-time setup to obtain the `access_token` and `refresh_token`.
- These tokens are stored in the `Gmail.json` configuration file.
- Once the tokens are obtained, the `init()` method is not required again unless the user deauthorizes the app from their Gmail account.

### API Methods

- **`send()`**: Send an email with optional attachments, CC, and BCC.
- **`listMessages()`**: List messages based on a query and maximum results.
- **`getMessage()`**: Retrieve a specific message by ID.
- **`deleteMessage()`**: Delete a specific message by ID.
- **`getAttachmentId()`**: Retrieve attachment IDs from a message.

## Examples

```php
<?php
require 'vendor/autoload.php';

use RPurinton\Gmail\Gmail;

$gmail = new Gmail();
$gmail->init(); // Only needed for the first-time setup

// Example to send an email
$gmail->send('from@example.com', ['to@example.com'], 'Subject', 'Email body');
```

## Troubleshooting

- Ensure that the `Gmail.json` file is correctly configured and accessible.
- Verify that the Google Cloud project is set up with the correct credentials and permissions.
- If you encounter issues with token expiration, ensure the `refresh_token` is valid and the `init()` method was successfully executed initially.

## License

This library is licensed under the MIT License. See the LICENSE file for more information.
