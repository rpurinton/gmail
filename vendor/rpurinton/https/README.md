# rpurinton/https

Abstract Static Class for making HTTPS Requests in PHP/Composer

# Usage

```
composer require rpurinton/https
```

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use RPurinton\HTTPS\HTTPSRequest;

// Define the options for the request
$options = [
    'url' => 'https://raw.githubusercontent.com/rpurinton/https/master/example.json',
    'method' => 'GET',
    'headers' => [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) ' .
            'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/70.0.3538.77 Safari/537.36',
    ],
    'body' => '',
];

// Return the response as a string
$response_string = HTTPSRequest::fetch($options);
echo "Response as String: $response_string\n";

$response_array = json_decode($response_string, true);
echo "Response as Array: " . print_r($response_array, true) . "\n";

$response_object = json_decode($response_string);
echo "Response as Object: " . print_r($response_object, true) . "\n";
```

