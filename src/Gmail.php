<?php

namespace RPurinton\Gmail;

use RPurinton\{Config, HTTPS};
use RPurinton\Gmail\Exceptions\GmailException;

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

    public function init(): void
    {
        $client_id = $this->gmail->config['web']['client_id'];
        $client_secret = $this->gmail->config['web']['client_secret'];
        $redirect_uri = "https://" . $_SERVER["HTTP_HOST"] . $_SERVER["REQUEST_URI"];
        if (strpos($redirect_uri, "?") !== false) $redirect_uri = substr($redirect_uri, 0, strpos($redirect_uri, "?"));
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
        if (!isset($response['access_token'], $response['refresh_token'], $response['expires_in'])) {
            throw new GmailException('Failed to obtain tokens');
        }
        $this->gmail->config['access_token'] = $response['access_token'];
        $this->gmail->config['refresh_token'] = $response['refresh_token'];
        $this->gmail->config['expires_at'] = time() + $response['expires_in'] - 30;
        $this->gmail->save();
    }

    public function refresh_token(): void
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
        if (!isset($response['access_token'], $response['expires_in'])) {
            throw new GmailException('Failed to refresh token');
        }
        $this->gmail->config['access_token'] = $response['access_token'];
        $this->gmail->config['expires_at'] = time() + $response['expires_in'] - 30;
        $this->gmail->save();
    }

    private function ensureTokenIsValid(): void
    {
        if (time() > $this->gmail->config["expires_at"]) {
            $this->refresh_token();
        }
    }

    public function send(string $from, array $to, string $subject, string $body, array $attachments = [], array $cc = [], array $bcc = []): string
    {
        $this->ensureTokenIsValid();
        $boundary = uniqid('boundary_');
        $rawMessageString = "From: $from\r\n";
        $rawMessageString .= "To: " . implode(", ", $to) . "\r\n";
        $rawMessageString .= "Subject: $subject\r\n";
        if (!empty($cc)) $rawMessageString .= "Cc: " . implode(", ", $cc) . "\r\n";
        if (!empty($bcc)) $rawMessageString .= "Bcc: " . implode(", ", $bcc) . "\r\n";
        $rawMessageString .= "MIME-Version: 1.0\r\n";
        $rawMessageString .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";
        $rawMessageString .= "\r\n--$boundary\r\n";
        $rawMessageString .= "Content-Type: text/html; charset=\"UTF-8\"\r\n";
        $rawMessageString .= "Content-Transfer-Encoding: 7bit\r\n";
        $rawMessageString .= "\r\n$body\r\n";
        foreach ($attachments as $attachment) {
            if (!file_exists($attachment)) continue;
            $rawMessageString .= "\r\n--$boundary\r\n";
            $rawMessageString .= "Content-Type: " . mime_content_type($attachment) . "; name=\"" . basename($attachment) . "\"\r\n";
            $rawMessageString .= "Content-Description: " . basename($attachment) . "\r\n";
            $rawMessageString .= "Content-Disposition: attachment; filename=\"" . basename($attachment) . "\"; size=" . filesize($attachment) . ";\r\n";
            $rawMessageString .= "Content-Transfer-Encoding: base64\r\n";
            $rawMessageString .= "\r\n" . chunk_split(base64_encode(file_get_contents($attachment))) . "\r\n";
        }
        $rawMessageString .= "\r\n--$boundary--";
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

    public function list(string $query = '', int $maxResults = 10, int $pageNumber = 1): array
    {
        $this->ensureTokenIsValid();

        $response = HTTPS::request([
            'url' => self::RECV_URI . '?' . http_build_query([
                'q' => $query,
                'maxResults' => $maxResults,
                'pageToken' => $pageNumber,
            ]),
            'method' => 'GET',
            'headers' => [
                "Authorization: Bearer " . $this->gmail->config['access_token'],
                'Accept: application/json',
            ],
        ]);

        $list = json_decode($response, true);
        $messages = [];
        if (empty($list['messages'])) return $messages;
        foreach ($list['messages'] as $message) $messages[] = $this->getHeaders($message['id']);
        return $messages;
    }

    private function getHeaders(string $messageId): array
    {
        $this->ensureTokenIsValid();
        $response = HTTPS::request([
            'url' => self::RECV_URI . '/' . $messageId,
            'method' => 'GET',
            'headers' => [
                "Authorization: Bearer " . $this->gmail->config['access_token'],
                'Accept: application/json',
            ],
        ]);
        $message = json_decode($response, true);
        return $this->extractHeaders($message);
    }

    private function extractHeaders(array $message): array
    {
        $headers = [
            'id' => $message['id'],
            'thread' => $message['threadId'],
            'labels' => $message['labelIds'],
        ];
        $headers['to'] = $headers['cc'] = $headers['bcc'] = [];
        foreach ($message['payload']['headers'] as $header) if (in_array($header['name'], ['From', 'To', 'Cc', 'Bcc', 'Subject', 'Date']))
            if (!in_array($header['name'], ['To', 'Cc', 'Bcc'])) $headers[strtolower($header['name'])] = $header['value'];
            else $headers[strtolower($header['name'])][] = $header['value'];
        $headers = array_merge(array_flip(['id', 'thread', 'labels', 'from', 'to', 'cc', 'bcc', 'subject', 'date']), $headers);
        if (empty($headers['cc'])) unset($headers['cc']);
        if (empty($headers['bcc'])) unset($headers['bcc']);
        if (empty($headers['attachments'])) unset($headers['attachments']);
        $headers = array_combine(array_map('ucfirst', array_keys($headers)), $headers);
        return $headers;
    }

    public function read(string $messageId): array
    {
        $this->ensureTokenIsValid();
        $response = HTTPS::request([
            'url' => self::RECV_URI . '/' . $messageId,
            'method' => 'GET',
            'headers' => [
                "Authorization: Bearer " . $this->gmail->config['access_token'],
                'Accept: application/json',
            ],
        ]);
        $message = json_decode($response, true);
        $result = $this->extractHeaders($message);
        $result['Body'] = $this->extractBody($message);
        $result['Attachments'] = $this->getAttachmentIds($messageId);
        if (in_array('UNREAD', $result['Labels'])) {
            $result['Labels'] = array_diff($result['Labels'], ['UNREAD']);
            $this->update($messageId, [], ['UNREAD']);
        }
        return $result;
    }

    private function extractBody(array $message): string
    {
        $plainText = '';
        $htmlText = '';

        if (isset($message['payload']['parts'])) {
            foreach ($message['payload']['parts'] as $part) {
                if ($part['mimeType'] === 'text/plain' && isset($part['body']['data'])) {
                    // Prefer the plain text part
                    $plainText = base64_decode(str_replace(['-', '_'], ['+', '/'], $part['body']['data']));
                } elseif ($part['mimeType'] === 'text/html' && isset($part['body']['data'])) {
                    // Store the HTML part for fallback
                    $htmlText = base64_decode(str_replace(['-', '_'], ['+', '/'], $part['body']['data']));
                }
            }
        }

        // Return plain text if available, otherwise return stripped HTML
        return $plainText ?: strip_tags($htmlText);
    }


    public function update(string $messageId, array $labelsToAdd = [], array $labelsToRemove = []): string
    {
        $this->ensureTokenIsValid();
        $response = HTTPS::request([
            'url' => self::RECV_URI . '/' . $messageId . '/modify',
            'method' => 'POST',
            'headers' => [
                "Authorization: Bearer " . $this->gmail->config['access_token'],
                'Accept: application/json',
                'Content-Type: application/json',
            ],
            'body' => json_encode([
                'addLabelIds' => $labelsToAdd,
                'removeLabelIds' => $labelsToRemove,
            ]),
        ]);
        return $response;
    }

    public function getAttachmentIds(string $messageId): array
    {
        $this->ensureTokenIsValid();
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
                        'size' => $part['body']['size'],
                        'contentType' => $part['mimeType'],
                    ];
                }
            }
        }

        return $attachmentIds;
    }
}
