<?php

require_once __DIR__ . '/vendor/autoload.php';

use RPurinton\HTTPS;

// Define the options for the request demonstrating all available options:
//
// 'url'             (string, required):
//                   The full URL to which the request will be sent. Must be a valid URL.
// 'method'          (string, optional):
//                   The HTTP method to use.
//                   Possible values: 'GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD'.
//                   Defaults to 'GET' if not provided or invalid.
// 'headers'         (array, optional):
//                   Request headers. Can be an associative array (e.g., ['Content-Type' => 'application/json'])
//                   or an indexed array of header strings (e.g., ['Content-Type: application/json']).
//                   Defaults to an empty array.
// 'body'            (string, optional):
//                   The request body as a string. Defaults to an empty string.
// 'timeout'         (int, optional):
//                   Maximum time in seconds for the entire cURL execution.
//                   Must be a positive integer. Defaults to 10.
// 'connect_timeout' (int, optional):
//                   Maximum time in seconds to wait for the connection to be established.
//                   Must be a positive integer. Defaults to 5.
// 'verify'          (bool, optional):
//                   Whether to verify SSL certificates.
//                   Possible values: true (verify) or false (do not verify). Defaults to true.
// 'retries'         (int, optional):
//                   Number of retry attempts on cURL errors.
//                   Must be an integer greater than or equal to 0. Defaults to 0.
$options = [
    'url'             => 'https://raw.githubusercontent.com/rpurinton/https/master/example.json',  // Required URL
    'method'          => 'GET',        // Optional, possible values: GET, POST, PUT, DELETE, PATCH, HEAD
    'headers'         => [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', // Example of a user agent header
        'Accept'     => 'application/json', // Example of an accept header
        'Cookie'     => 'foo=bar; baz=qux', // Example of a cookie header
    ],                    // `headers` Optional, defaults to an empty array if omitted
    'body'            => '',           // Optional, defaults to an empty string if omitted
    'timeout'         => 10,           // Optional, positive int, defaults to 10 seconds
    'connect_timeout' => 5,            // Optional, positive int, defaults to 5 seconds
    'verify'          => true,         // Optional, boolean; true to verify SSL certificates, defaults to true
    'retries'         => 0,            // Optional, integer >= 0; number of retry attempts, defaults to 0
];

// Execute the HTTP request using the HTTPS client
try {
    // Return the response as a string
    $response_string = HTTPS::request($options);
    echo "Response as String:\n$response_string\n";

    // Decode the response as an associative array
    $response_array = json_decode($response_string, true);
    echo "Response as Array:\n" . print_r($response_array, true) . "\n";

    // Decode the response as an object
    $response_object = json_decode($response_string);
    echo "Response as Object:\n" . print_r($response_object, true) . "\n";
} catch (\RPurinton\HTTPSException $e) {
    echo "An error occurred: " . $e->getMessage() . "\n";
}