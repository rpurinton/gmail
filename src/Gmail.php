<?php

namespace RPurinton\Gmail;

use RPurinton\{Config, HTTPS};

class Gmail
{
    const SCOPE = "https://mail.google.com/";
    const SEND_URI = 'https://www.googleapis.com/upload/gmail/v1/users/me/messages/send';
    const RECV_URI = 'https://www.googleapis.com/gmail/v1/users/me/messages';

    private Config $gmail;

    public function __construct()
    {
        $this->gmail = Config::open("Gmail", [
            "web" => [
                "client_id"     => "string",
                "client_secret" => "string",
                "auth_uri"      => "string",
                "token_uri"     => "string",
            ],
        ]);
    }

    public function init()
    {
        $client_id = $this->gmail->config['web']['client_id'];
        $client_secret = $this->gmail->config['web']['client_secret'];
        $redirect_uri = "https://" . $_SERVER["HTTP_HOST"] . $_SERVER["REQUEST_URI"];
        if (strpos($redirect_uri, "?") !== false) {
            $redirect_uri = substr($redirect_uri, 0, strpos($redirect_uri, "?"));
        }
        $first_url = $this->gmail->config['web']['auth_uri'] . "?" . http_build_query([
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
            'url' => $this->gmail->config['web']['token_uri'],
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
            'url' => $this->gmail->config['web']['token_uri'],
            'method' => 'POST',
            'headers' => ['Content-Type: application/x-www-form-urlencoded'],
            'body' => http_build_query([
                'client_id'     => $this->gmail->config['web']['client_id'],
                'client_secret' => $this->gmail->config['web']['client_secret'],
                'refresh_token' => $this->gmail->config['refresh_token'],
                'grant_type'    => 'refresh_token',
            ]),
        ]);

        $response = json_decode($response, true);
        $this->gmail->config['access_token'] = $response['access_token'];
        $this->gmail->config['expires_at'] = time() + $response['expires_in'] - 30;
        $this->gmail->save();
    }

    public function send(string $from, array $to, string $subject, string $body, array $attachments = [], array $cc = [], array $bcc = [])
    {
        if (time() > $this->gmail->config["expires_at"]) {
            $this->refresh_token();
        }

        $rawMessageString = "From: $from\r\n";
        $rawMessageString .= "To: " . implode(", ", $to) . "\r\n";
        $rawMessageString .= "Subject: $subject\r\n";
        if (!empty($cc)) {
            $rawMessageString .= "Cc: " . implode(", ", $cc) . "\r\n";
        }
        if (!empty($bcc)) {
            $rawMessageString .= "Bcc: " . implode(", ", $bcc) . "\r\n";
        }
        $rawMessageString .= "MIME-Version: 1.0\r\n";
        $rawMessageString .= "Content-Type: multipart/mixed; boundary=\"foo_bar_baz\"\r\n";
        $rawMessageString .= "\r\n--foo_bar_baz\r\n";
        $rawMessageString .= "Content-Type: text/html; charset=\"UTF-8\"\r\n";
        $rawMessageString .= "Content-Transfer-Encoding: 7bit\r\n";
        $rawMessageString .= "\r\n$body\r\n";
        foreach ($attachments as $attachment) {
            if (!file_exists($attachment)) {
                continue;
            }
            $rawMessageString .= "\r\n--foo_bar_baz\r\n";
            $rawMessageString .= "Content-Type: " . mime_content_type($attachment) . "; name=\"" . basename($attachment) . "\"\r\n";
            $rawMessageString .= "Content-Description: " . basename($attachment) . "\r\n";
            $rawMessageString .= "Content-Disposition: attachment; filename=\"" . basename($attachment) . "\"; size=" . filesize($attachment) . ";\r\n";
            $rawMessageString .= "Content-Transfer-Encoding: base64\r\n";
            $rawMessageString .= "\r\n" . chunk_split(base64_encode(file_get_contents($attachment))) . "\r\n";
        }
        $rawMessageString .= "\r\n--foo_bar_baz--";
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

    public function listMessages($query = '', $maxResults = 10)
    {
        if (time() > $this->gmail->config["expires_at"]) {
            $this->refresh_token();
        }

        $response = HTTPS::request([
            'url' => self::RECV_URI . '?' . http_build_query([
                'q' => $query,
                'maxResults' => $maxResults,
            ]),
            'method' => 'GET',
            'headers' => [
                "Authorization: Bearer " . $this->gmail->config['access_token'],
                'Accept: application/json',
            ],
        ]);

        return json_decode($response, true);
    }

    public function getMessage($messageId)
    {
        if (time() > $this->gmail->config["expires_at"]) {
            $this->refresh_token();
        }

        $response = HTTPS::request([
            'url' => self::RECV_URI . '/' . $messageId,
            'method' => 'GET',
            'headers' => [
                "Authorization: Bearer " . $this->gmail->config['access_token'],
                'Accept: application/json',
            ],
        ]);

        return json_decode($response, true);
    }

    public function deleteMessage($messageId)
    {
        if (time() > $this->gmail->config["expires_at"]) {
            $this->refresh_token();
        }

        $response = HTTPS::request([
            'url' => self::RECV_URI . '/' . $messageId,
            'method' => 'DELETE',
            'headers' => [
                "Authorization: Bearer " . $this->gmail->config['access_token'],
                'Accept: application/json',
            ],
        ]);

        return $response;
    }

public function getAttachmentId($messageId)
{
    if (time() > $this->gmail->config["expires_at"]) {
        $this->refresh_token();
    }

    $response = HTTPS::request([
        'url' => self::RECV_URI . '/' . $messageId,
        'method' => 'GET',
        'headers' => [
            "Authorization: Bearer " . $this->gmail->config['access_token'],
            'Accept: application/json',
        ],
    ]);

    $message = json_decode($response, true);
    $attachmentIds = [];

    if (isset($message['payload']['parts'])) {
        foreach ($message['payload']['parts'] as $part) {
            if (isset($part['filename']) && !empty($part['filename']) && isset($part['body']['attachmentId'])) {
                $attachmentIds[] = [
                    'filename' => $part['filename'],
                    'attachmentId' => $part['body']['attachmentId'],
                ];
            }
        }
    }

    return $attachmentIds;
}
}
