<?php

namespace RPurinton\Gmail;

use RPurinton\{Config, HTTPS};
use RPurinton\Gmail\Exceptions\GmailException;

/**
 * Class Gmail
 *
 * A wrapper for interacting with the Gmail API.
 *
 * @package RPurinton\Gmail
 */
class Gmail
{
    const SCOPE = "https://mail.google.com/";
    const SEND_URI = 'https://www.googleapis.com/upload/gmail/v1/users/me/messages/send';
    const RECV_URI = 'https://www.googleapis.com/gmail/v1/users/me/messages';

    private Config $gmail;

    /**
     * Gmail constructor.
     */
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

    /**
     * Initializes the OAuth2 flow.
     *
     * @return void
     * @throws GmailException if token retrieval fails.
     */
    public function init(): void
    {
        try {
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
            if (!isset($response['access_token'], $response['refresh_token'], $response['expires_in'])) {
                throw new GmailException('Failed to obtain tokens');
            }
            $this->gmail->config['access_token'] = $response['access_token'];
            $this->gmail->config['refresh_token'] = $response['refresh_token'];
            $this->gmail->config['expires_at'] = time() + $response['expires_in'] - 30;
            $this->gmail->save();
        } catch (\Exception $e) {
            throw new GmailException("Error during initialization: " . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Refreshes the access token using the refresh token.
     *
     * @return void
     * @throws GmailException if the token refresh fails.
     */
    public function refresh_token(): void
    {
        try {
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
        } catch (\Exception $e) {
            throw new GmailException("Error while refreshing token: " . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Ensures that the access token is still valid.
     *
     * @return void
     * @throws GmailException if token refresh fails.
     */
    private function ensureTokenIsValid(): void
    {
        if (time() > $this->gmail->config["expires_at"]) {
            $this->refresh_token();
        }
    }

    /**
     * Sends an email with optional attachments.
     *
     * @param string $from    Sender email address.
     * @param array  $to      Array of recipient email addresses.
     * @param string $subject Email subject.
     * @param string $body    Email body in HTML.
     * @param array  $attachments Array of file paths to attach.
     * @param array  $cc      Array of cc email addresses.
     * @param array  $bcc     Array of bcc email addresses.
     * @return string       API response.
     * @throws GmailException if sending fails.
     */
    public function send(string $from, array $to, string $subject, string $body, array $attachments = [], array $cc = [], array $bcc = []): string
    {
        try {
            $this->ensureTokenIsValid();
            $boundary = uniqid('boundary_');
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
            $rawMessageString .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";
            $rawMessageString .= "\r\n--$boundary\r\n";
            $rawMessageString .= "Content-Type: text/html; charset=\"UTF-8\"\r\n";
            $rawMessageString .= "Content-Transfer-Encoding: 7bit\r\n";
            $rawMessageString .= "\r\n$body\r\n";

            foreach ($attachments as $attachment) {
                if (!file_exists($attachment)) {
                    continue;
                }
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
        } catch (\Exception $e) {
            throw new GmailException("Error while sending email: " . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Lists messages based on the query parameters.
     *
     * @param string $query      Search query.
     * @param int    $maxResults Maximum results to retrieve.
     * @param int    $pageNumber Pagination token as an integer.
     * @return array             List of messages with headers.
     * @throws GmailException if retrieval fails.
     */
    public function list(string $query = '', int $maxResults = 10, int $pageNumber = 1): array
    {
        try {
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
            if (empty($list['messages'])) return ['Results' => 0];
            $messages = [
                'Total Results' => $list['resultSizeEstimate'],
                'Showing Results' => count($list['messages']),
                'Page' => $pageNumber . " of " . ceil($list['resultSizeEstimate'] / $maxResults),
            ];
            foreach ($list['messages'] as $message) $messages['Messages'][] = $this->getHeaders($message['id']);
            return $messages;
        } catch (\Exception $e) {
            throw new GmailException("Error listing messages: " . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Retrieves complete information for a specific email message.
     *
     * @param string $messageId The ID of the message.
     * @return array  Message headers and body.
     * @throws GmailException if retrieval fails.
     */
    public function read(string $messageId): array
    {
        try {
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
        } catch (\Exception $e) {
            throw new GmailException("Error reading message $messageId: " . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Extracts the plain text body from the message. Falls back to stripped HTML.
     *
     * @param array $message The complete message array.
     * @return string        The message body.
     */
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
                    // Fallback to HTML part
                    $htmlText = base64_decode(str_replace(['-', '_'], ['+', '/'], $part['body']['data']));
                }
            }
        }

        return $plainText ?: strip_tags($htmlText);
    }

    /**
     * Updates the labels of a message.
     *
     * @param string $messageId   The ID of the message.
     * @param array  $labelsToAdd Labels to add.
     * @param array  $labelsToRemove Labels to remove.
     * @return string             API response.
     * @throws GmailException if the update fails.
     */
    public function update(string $messageId, array $labelsToAdd = [], array $labelsToRemove = []): string
    {
        try {
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
        } catch (\Exception $e) {
            throw new GmailException("Error updating message $messageId: " . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Extracts headers from a message and returns them in an array.
     *
     * @param string $messageId The ID of the message.
     * @return array  Message headers.
     * @throws GmailException if retrieval fails.
     */
    private function getHeaders(string $messageId): array
    {
        try {
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
        } catch (\Exception $e) {
            throw new GmailException("Error getting headers for message $messageId: " . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Processes the raw message and extracts relevant header fields.
     *
     * @param array $message The complete message array.
     * @return array Processed headers.
     */
    private function extractHeaders(array $message): array
    {
        $headers = [
            'id' => $message['id'],
            'thread' => $message['threadId'],
            'labels' => $message['labelIds'],
        ];
        $headers['to'] = $headers['cc'] = $headers['bcc'] = [];
        foreach ($message['payload']['headers'] as $header) {
            if (in_array($header['name'], ['From', 'To', 'Cc', 'Bcc', 'Subject', 'Date'])) {
                if (!in_array($header['name'], ['To', 'Cc', 'Bcc'])) {
                    $headers[strtolower($header['name'])] = $header['value'];
                } else {
                    $headers[strtolower($header['name'])][] = $header['value'];
                }
            }
        }
        $headers = array_merge(array_flip(['id', 'thread', 'labels', 'from', 'to', 'cc', 'bcc', 'subject', 'date']), $headers);
        if (empty($headers['cc'])) {
            unset($headers['cc']);
        }
        if (empty($headers['bcc'])) {
            unset($headers['bcc']);
        }
        if (empty($headers['attachments'])) {
            unset($headers['attachments']);
        }
        $headers = array_combine(array_map('ucfirst', array_keys($headers)), $headers);
        return $headers;
    }

    /**
     * Retrieves attachment information for a message.
     *
     * @param string $messageId The ID of the message.
     * @return array List of attachments.
     * @throws GmailException if retrieval fails.
     */
    public function getAttachmentIds(string $messageId): array
    {
        try {
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
        } catch (\Exception $e) {
            throw new GmailException("Error getting attachments for message $messageId: " . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Deletes messages in batch.
     *
     * @param array $messageIds Array of message IDs to delete.
     * @return string          API response.
     * @throws GmailException  if deletion fails.
     */
    public function delete(array $messageIds): string
    {
        try {
            $this->ensureTokenIsValid();
            $response = HTTPS::request([
                'url' => self::RECV_URI . '/batchDelete',
                'method' => 'POST',
                'headers' => [
                    "Authorization: Bearer " . $this->gmail->config['access_token'],
                    'Accept: application/json',
                    'Content-Type: application/json',
                ],
                'body' => json_encode(['ids' => $messageIds]),
            ]);

            return $response;
        } catch (\Exception $e) {
            throw new GmailException("Error deleting messages: " . $e->getMessage(), $e->getCode(), $e);
        }
    }
}
