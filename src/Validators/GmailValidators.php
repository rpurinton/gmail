<?php

namespace RPurinton\Gmail\Validators;

use RPurinton\Gmail\Exceptions\GmailValidatorsException;

class GmailValidators
{
    public static function validateClientId(string $clientId): bool
    {
        if (preg_match('/[^a-zA-Z0-9]/', $clientId)) throw new GmailValidatorsException("Invalid client id");
        return true;
    }

    public static function validateClientSecret(string $clientSecret): bool
    {
        if (preg_match('/[^a-zA-Z0-9]/', $clientSecret)) throw new GmailValidatorsException("Invalid client secret");
        return true;
    }

    public static function validateAuthUri(string $authUri): bool
    {
        if (substr($authUri, 0, 8) !== "https://") throw new GmailValidatorsException("Uri must start with https://");
        if (filter_var($authUri, FILTER_VALIDATE_URL) === false) throw new GmailValidatorsException("Invalid auth uri");
        return true;
    }

    public static function validateTokenUri(string $tokenUri): bool
    {
        if (substr($tokenUri, 0, 8) !== "https://") throw new GmailValidatorsException("Uri must start with https://");
        if (filter_var($tokenUri, FILTER_VALIDATE_URL) === false) throw new GmailValidatorsException("Invalid token uri");
        return true;
    }
}
