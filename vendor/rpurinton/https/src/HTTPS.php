<?php

namespace RPurinton;

use RPurinton\Exceptions\HTTPSException;

/**
 * Class HTTPS
 *
 * Provides a simple HTTP client based on cURL. This version demonstrates and documents
 * all available options for making a request.
 *
 * Available Options:
 *
 * 'url' (string, required):
 *  - The full URL to which the request will be sent.
 *  - Must be a valid URL (e.g., "https://example.com").
 *
 * 'method' (string, optional):
 *  - The HTTP method to use.
 *  - Possible values: 'GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD'.
 *  - Defaults to 'GET' if not provided or invalid.
 *
 * 'headers' (array, optional):
 *  - Request headers. Can be an associative array (e.g., ['Content-Type' => 'application/json'])
 *    or an indexed array of header strings (e.g., ['Content-Type: application/json']).
 *  - Defaults to an empty array if not provided.
 *
 * 'body' (string, optional):
 *  - The request body as a string.
 *  - Defaults to an empty string.
 *
 * 'timeout' (int, optional):
 *  - Maximum time in seconds for the entire cURL execution.
 *  - Must be a positive integer.
 *  - Defaults to 10 seconds.
 *
 * 'connect_timeout' (int, optional):
 *  - Maximum time in seconds to wait for the connection to be established.
 *  - Must be a positive integer.
 *  - Defaults to 5 seconds.
 *
 * 'verify' (bool, optional):
 *  - Whether to verify SSL certificates.
 *  - Possible values: true (verify) or false (do not verify).
 *  - Defaults to true.
 *
 * 'retries' (int, optional):
 *  - Number of retry attempts on cURL errors.
 *  - Must be an integer greater than or equal to 0.
 *  - Defaults to 0 (no retries).
 *
 * @package RPurinton
 */
class HTTPS
{
    /**
     * Sends an HTTP request using cURL.
     *
     * Merges the provided options with defaults and executes the request.
     *
     * @param array $options {
     *     @type string  $url             Required. The URL to request.
     *     @type string  $method          Optional. HTTP method. Allowed values are 'GET', 'POST', 'PUT', 
     *                                      'DELETE', 'PATCH', 'HEAD'. Defaults to 'GET'.
     *     @type array   $headers         Optional. Headers either as an associative array or an indexed array of strings.
     *                                      Defaults to [].
     *     @type string  $body            Optional. Request body. Defaults to an empty string.
     *     @type int     $timeout         Optional. Total execution timeout in seconds. Must be >0. Defaults to 10.
     *     @type int     $connect_timeout Optional. Connection timeout in seconds. Must be >0. Defaults to 5.
     *     @type bool    $verify          Optional. Whether to verify SSL certificates. Defaults to true.
     *     @type int     $retries         Optional. Number of retry attempts on error. Must be >= 0. Defaults to 0.
     * }
     *
     * @return string The HTTP response.
     *
     * @throws HTTPSException If an error occurs during execution.
     */
    public static function request(array $options = []): string
    {
        if (!extension_loaded('curl')) {
            throw new HTTPSException('cURL extension is not loaded.');
        }
        $options = self::validate_options($options);
        return self::curl($options);
    }

    /**
     * Provides default options for HTTP requests.
     *
     * @return array Default options.
     */
    private static function default_options(): array
    {
        return [
            'method'          => 'GET',  // Optional. Allowed HTTP methods.
            'headers'         => [],     // Optional. Headers array.
            'body'            => '',     // Optional. Request body.
            'timeout'         => 10,     // Optional. Total timeout in seconds.
            'connect_timeout' => 5,      // Optional. Connection timeout in seconds.
            'verify'          => true,   // Optional. SSL certificate verification.
            'retries'         => 0,      // Optional. Retry attempts on error.
        ];
    }

    /**
     * Validates and normalizes the options.
     *
     * Ensures the URL is valid, method is among the allowed types, headers are in the correct format,
     * and numeric values are valid.
     *
     * @param array $options The options to validate.
     *
     * @return array Validated options.
     *
     * @throws HTTPSException If options are invalid.
     */
    private static function validate_options(array $options): array
    {
        $defaults = self::default_options();
        $options = array_merge($defaults, $options);

        // Validate URL.
        if (!isset($options['url']) || !filter_var($options['url'], FILTER_VALIDATE_URL)) {
            throw new HTTPSException('Invalid or no URL provided.');
        }

        // Validate HTTP method.
        $valid_methods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD'];
        $method = strtoupper($options['method'] ?? '');
        if (!in_array($method, $valid_methods, true)) {
            $options['method'] = 'GET';
        } else {
            $options['method'] = $method;
        }

        // Validate headers.
        if (!is_array($options['headers'])) {
            $options['headers'] = [];
        } else {
            $normalizedHeaders = [];
            foreach ($options['headers'] as $key => $value) {
                if (is_int($key)) {
                    // Indexed array with header strings.
                    if (!is_string($value)) {
                        throw new HTTPSException('Invalid header provided. Headers should be string values.');
                    }
                    $normalizedHeaders[] = $value;
                } else {
                    // Associative array, convert to "Key: value".
                    if (!is_string($value)) {
                        throw new HTTPSException('Invalid header value for ' . $key . '. Headers should be string values.');
                    }
                    $normalizedHeaders[] = $key . ': ' . $value;
                }
            }
            $options['headers'] = $normalizedHeaders;
        }

        // Validate body.
        if (!is_string($options['body'])) {
            throw new HTTPSException('Invalid body provided. Body should be a string.');
        }

        // Validate timeout values.
        if (!is_int($options['timeout']) || $options['timeout'] <= 0) {
            $options['timeout'] = $defaults['timeout'];
        }
        if (!is_int($options['connect_timeout']) || $options['connect_timeout'] <= 0) {
            $options['connect_timeout'] = $defaults['connect_timeout'];
        }

        // Validate verify flag.
        if (!is_bool($options['verify'])) {
            $options['verify'] = $defaults['verify'];
        }

        // Validate retries.
        if (!isset($options['retries']) || !is_int($options['retries']) || $options['retries'] < 0) {
            $options['retries'] = $defaults['retries'];
        }

        return $options;
    }

    /**
     * Executes the HTTP request using cURL.
     *
     * Handles retries on error attempts and throws exceptions on failure.
     *
     * @param array $options Validated options.
     *
     * @return string HTTP response.
     *
     * @throws HTTPSException If the request fails after all retries or on HTTP error status codes.
     */
    private static function curl(array $options): string
    {
        $attempts = 0;
        $maxAttempts = 1 + $options['retries'];
        $lastError = '';
        while ($attempts < $maxAttempts) {
            $curl = curl_init();
            if ($curl === false) {
                throw new HTTPSException('Failed to initialize cURL.');
            }

            try {
                curl_setopt_array($curl, [
                    CURLOPT_URL => $options['url'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => $options['timeout'],
                    CURLOPT_CONNECTTIMEOUT => $options['connect_timeout'],
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => $options['method'],
                    CURLOPT_POSTFIELDS => $options['body'],
                    CURLOPT_HTTPHEADER => $options['headers'],
                    CURLOPT_SSL_VERIFYHOST => $options['verify'] ? 2 : 0,
                    CURLOPT_SSL_VERIFYPEER => $options['verify'],
                    CURLOPT_FAILONERROR => true,
                ]);

                $response = curl_exec($curl);
                if ($response === false) {
                    $lastError = 'cURL error on ' . $options['method'] . ' ' . $options['url'] . ': ' . curl_error($curl);
                    error_log($lastError);
                    $attempts++;
                    continue;
                }

                $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
                if ($httpCode >= 400) {
                    $lastError = 'HTTP error on ' . $options['method'] . ' ' . $options['url'] . ': Received status code ' . $httpCode;
                    error_log($lastError);
                    throw new HTTPSException($lastError);
                }

                return $response;
            } finally {
                curl_close($curl);
            }
        }
        throw new HTTPSException($lastError ?: 'Unknown error occurred during cURL execution.');
    }
}