<?php

namespace RPurinton\Gmail;

use RPurinton\{Config, HTTPS};

class Gmail
{
    const SCOPE = "https://mail.google.com/";
    const AUTH_URI = "https://accounts.google.com/o/oauth2/auth";
    const TOKEN_URI = "https://accounts.google.com/o/oauth2/token";
    const SEND_URI = 'https://www.googleapis.com/upload/gmail/v1/users/me/messages/send';
    const RECV_URI = 'https://www.googleapis.com/gmail/v1/users/me/messages';


    private Config $gmail;

    public function __construct()
    {
        $this->gmail = Config::open("Gmail", [
            "client_id"     => "string",
            "client_secret" => "string",
        ]);
    }

    public function init()
    {
        $client_id = $this->gmail->config['client_id'];
        $client_secret = $this->gmail->config['client_secret'];
        $redirect_uri = "https://" . $_SERVER["HTTP_HOST"] . $_SERVER["REQUEST_URI"];
        $first_url = self::AUTH_URI . "?" . http_build_query([
            "client_id"              => $client_id,
            "redirect_uri"           => $redirect_uri,
            "scope"                  => self::SCOPE,
            "access_type"            => "offline",
            "include_granted_scopes" => "true",
            "state"                  => "state_parameter_passthrough_value",
            "response_type"          => "code",
        ]);

        if (!isset($_GET["code"])) {
            header("Location: $first_url");
            exit;
        }

        $code = $_GET["code"];
        $response = HTTPS::request([
            'url' => self::TOKEN_URI,
            'method' => 'POST',
            'headers' => ['Content-Type: application/x-www-form-urlencoded'],
            'body' => http_build_query([
                'code'          => $code,
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri'  => $redirect_uri,
                'grant_type'    => 'authorization_code',
            ]),
        ]);
        $response = json_decode($response, true);
        $this->gmail->config['access_token'] = $response['access_token'];
        $this->gmail->config['refresh_token'] = $response['refresh_token'];
        $this->gmail->config['expires_at'] = time() + $response['expires_in'] - 30;
        $this->gmail->save();
    }

    public function refresh_token()
    {
        $response = HTTPS::request([
            'url' => self::TOKEN_URI,
            'method' => 'POST',
            'headers' => ['Content-Type: application/x-www-form-urlencoded'],
            'body' => http_build_query([
                'client_id'     => $this->gmail->config['client_id'],
                'client_secret' => $this->gmail->config['client_secret'],
                'refresh_token' => $this->gmail->config['refresh_token'],
                'grant_type'    => 'refresh_token',
            ]),
        ]);

        $response = json_decode($response, true);
        $this->gmail->config['access_token'] = $response['access_token'];
        $this->gmail->config['expires_at'] = time() + $response['expires_in'] - 30;
        $this->gmail->save();
    }

    public function send(string $from, string $to, string $subject, string $body)
    {
        if (time() > $this->gmail->config["expires_at"]) {
            $this->refresh_token();
        }

        $rawMessageString = "From: $from\r\n";
        $rawMessageString .= "To: $to\r\n";
        $rawMessageString .= 'Subject: =?utf-8?B?' . base64_encode($subject) . "?=\r\n";
        $rawMessageString .= "MIME-Version: 1.0\r\n";
        $rawMessageString .= "Content-Type: text/html; charset=utf-8\r\n";
        $rawMessageString .= 'Content-Transfer-Encoding: quoted-printable' . "\r\n\r\n";
        $rawMessageString .= "$body\r\n";

        $response = HTTPS::request([
            'url' => self::SEND_URI,
            'method' => 'POST',
            'headers' => [
                "Authorization: Bearer " . $this->gmail->config['access_token'],
                'Accept: application/json',
                'Content-Type: message/rfc822',
            ],
            'body' => $rawMessageString,
        ]);

        return $response;
    }
}
