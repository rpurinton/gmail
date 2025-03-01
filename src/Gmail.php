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
        if (!isset($response['access_token'], $response['refresh_token'], $response['expires_in'])) return;
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

    public function list($query = '', $maxResults = 10, $pageNumber = 1)
    {
        if (time() > $this->gmail->config["expires_at"]) {
            $this->refresh_token();
        }

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
        foreach ($list['messages'] as $message) $messages[] = $this->get($message['id']);
        return $messages;
    }

    public function get($messageId)
    {
        if (time() > $this->gmail->config["expires_at"]) $this->refresh_token();
        $response = HTTPS::request([
            'url' => self::RECV_URI . '/' . $messageId,
            'method' => 'GET',
            'headers' => [
                "Authorization: Bearer " . $this->gmail->config['access_token'],
                'Accept: application/json',
            ],
        ]);
        $message = json_decode($response, true);
        return $this->extract($message);
    }

    public function extract(array $message): array
    {
        $headers = [
            'id' => $message['id'],
            'threadId' => $message['threadId'],
            'labelIds' => $message['labelIds'],
            'snippet' => $message['snippet'],
        ];
        foreach ($message['payload']['headers'] as $header) {
            if (in_array($header['name'], ['From', 'To', 'Cc', 'Bcc', 'Subject', 'Date'])) {
                $headers[strtolower($header['name'])] = $header['value'];
            }
        }
        $headers['attachments'] = $this->getAttachmentIds($message['id']);
        $headers = array_merge(array_flip(['from', 'to', 'cc', 'bcc', 'subject', 'date', 'attachments']), $headers);
        return $headers;
    }

    public function read($messageId)
    {
        if (time() > $this->gmail->config["expires_at"]) $this->refresh_token();
        $response = HTTPS::request([
            'url' => self::RECV_URI . '/' . $messageId,
            'method' => 'GET',
            'headers' => [
                "Authorization: Bearer " . $this->gmail->config['access_token'],
                'Accept: application/json',
            ],
        ]);
        $this->update($messageId, [], ['UNREAD']);
        return json_decode($response, true);
    }

    public function update($messageId, $labelsToAdd = [], $labelsToRemove = [])
    {
        if (time() > $this->gmail->config["expires_at"]) $this->refresh_token();
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

    public function getAttachmentIds($messageId)
    {
        if (time() > $this->gmail->config["expires_at"]) $this->refresh_token();
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

/*Array
(
    [id] => 195444c186cd34af
    [threadId] => 19544437780f8f8f
    [labelIds] => Array
        (
            [0] => UNREAD
            [1] => IMPORTANT
            [2] => CATEGORY_PERSONAL
            [3] => INBOX
        )

    [snippet] => Thanks Sent from my iPhone On Feb 26, 2025, at 4:57 PM, Ash &lt;ashcordbot@gmail.com&gt; wrote: ﻿ This is a test email with the Gmail.php file attached. &lt;Gmail.php&gt;
    [payload] => Array
        (
            [partId] =>
            [mimeType] => multipart/alternative
            [filename] =>
            [headers] => Array
                (
                    [0] => Array
                        (
                            [name] => Delivered-To
                            [value] => ashcordbot@gmail.com
                        )

                    [1] => Array
                        (
                            [name] => Received
                            [value] => by 2002:a05:7010:a894:b0:43a:8226:594a with SMTP id ck20csp1232073mdb;        Wed, 26 Feb 2025 14:06:33 -0800 (PST)
                        )

                    [2] => Array
                        (
                            [name] => X-Received
                            [value] => by 2002:a0c:e5d1:0:b0:6e8:8791:54e5 with SMTP id 6a1803df08f44-6e8879155bdmr44395416d6.26.1740607592897;        Wed, 26 Feb 2025 14:06:32 -0800 (PST)
                        )

                    [3] => Array
                        (
                            [name] => ARC-Seal
                            [value] => i=1; a=rsa-sha256; t=1740607592; cv=none;        d=google.com; s=arc-20240605;        b=Xi8+Mmi5zQ6AkY6oMgZ0wS8Bl/xX9Dl94hzKdBEAM9j8bfZvORs1TA8ZJHRX+EiINr         tEGYQBlQ2rOiA3Hbven9e+Cm/BQjrcU7J1U4Ysaqr7hW0nQeCAFt1fuvHuXrwy1SebFs         LVBnEu8FgYknW3vQMoszLqSa02xtdoHrl8XdkIHsicMfx3fx+BR1Fsf2geV58uu/gTuC         icVHt7XOPO6tYb6UQDDBtKgTUTqroVF/Mw0zeLXzLTjwlfDRGQ4rdhNyoDl9JnZfqLes         mHK2gl70Sa43StUnJVD9EwiJEYXbqf3xaPzvYpohPaJXqdFNfWLWqwAR+4iJrbsqWTGd         q8gw==
                        )

                    [4] => Array
                        (
                            [name] => ARC-Message-Signature
                            [value] => i=1; a=rsa-sha256; c=relaxed/relaxed; d=google.com; s=arc-20240605;        h=to:in-reply-to:references:message-id:date:subject:mime-version:from         :content-transfer-encoding:dkim-signature;        bh=KnjlDzXx5S3rdXKfZvtdHCMQjimhCtKY+06zBO8lTys=;        fh=zCdf3mWfJcAz9cPp8AFLqDT+Cyq+TFckogmbqn/kO1g=;        b=aflNfRTjE2lwSVC+z2pMqGZ62vtKK8nNtwYz0Mkbqx2/OJdhY/oBYIbHjA6qrT7njH         ckBJI9zijB1tOGfyhKUA2a5SMSggs0SRgNVM7jWYiUuQLU8W9wOIYGSV3goAHGdgbeFa         CS2/3RgxCwbN2vjSrbzpSKjNisNxMWTZio4QsN2uL543ou106sFqXZToq3kQQRhInFNa         1k+X4PjyxpZ9vSoBdl2M0CZW0rBtNERLKQpXHDXhAhe5WZzjSqAk1GxSHVOGt/S7NVFI         bZ83usvewTUFC1EUlMJTzUz4ejrIzeVw9rW4vGH/X0p1Vn/9xW0XrGZ2HctDXYPQhk39         rDFQ==;        dara=google.com
                        )

                    [5] => Array
                        (
                            [name] => ARC-Authentication-Results
                            [value] => i=1; mx.google.com;       dkim=pass header.i=@gmail.com header.s=20230601 header.b=blSgCqxY;       spf=pass (google.com: domain of russell.purinton@gmail.com designates 209.85.220.41 as permitted sender) smtp.mailfrom=russell.purinton@gmail.com;       dmarc=pass (p=NONE sp=QUARANTINE dis=NONE) header.from=gmail.com;       dara=pass header.i=@gmail.com
                        )

                    [6] => Array
                        (
                            [name] => Return-Path
                            [value] => <russell.purinton@gmail.com>
                        )

                    [7] => Array
                        (
                            [name] => Received
                            [value] => from mail-sor-f41.google.com (mail-sor-f41.google.com. [209.85.220.41])        by mx.google.com with SMTPS id af79cd13be357-7c378da49c5sor22257985a.12.2025.02.26.14.06.32        for <ashcordbot@gmail.com>        (Google Transport Security);        Wed, 26 Feb 2025 14:06:32 -0800 (PST)
                        )

                    [8] => Array
                        (
                            [name] => Received-SPF
                            [value] => pass (google.com: domain of russell.purinton@gmail.com designates 209.85.220.41 as permitted sender) client-ip=209.85.220.41;
                        )

                    [9] => Array
                        (
                            [name] => Authentication-Results
                            [value] => mx.google.com;       dkim=pass header.i=@gmail.com header.s=20230601 header.b=blSgCqxY;       spf=pass (google.com: domain of russell.purinton@gmail.com designates 209.85.220.41 as permitted sender) smtp.mailfrom=russell.purinton@gmail.com;       dmarc=pass (p=NONE sp=QUARANTINE dis=NONE) header.from=gmail.com;       dara=pass header.i=@gmail.com
                        )

                    [10] => Array
                        (
                            [name] => DKIM-Signature
                            [value] => v=1; a=rsa-sha256; c=relaxed/relaxed;        d=gmail.com; s=20230601; t=1740607592; x=1741212392; dara=google.com;        h=to:in-reply-to:references:message-id:date:subject:mime-version:from         :content-transfer-encoding:from:to:cc:subject:date:message-id         :reply-to;        bh=KnjlDzXx5S3rdXKfZvtdHCMQjimhCtKY+06zBO8lTys=;        b=blSgCqxYC7YjecXfNp6lafplzIThxziRaMastshtjiwHHWewGRlCjZNkfxf+f5kNAz         yiz4Ql0tVluRlB4NFt3AMP9h60WcXxlQNivudSNTKbpTupyhBEM22ODAAQYEVt8anbKs         Bv53e1gs0wafOLe5x2dirR5WG3+H2O9kL2CxqzrNLLPK9Z9qwjlOu7OSYuqYhqjy5ZId         A5Hk4+iIDrHAnALz9z7j03EvfCkcdl0Ak3K2U6sirgJE6q3CNMfWGvDPn9+pRQjxGtFc         G5erxcZR2Kxtno23sQ4PaLBenrWevD/TjHnG7Exmisp8JZykHazZOhL4k96R/2rRWsia         rL0A==
                        )

                    [11] => Array
                        (
                            [name] => X-Google-DKIM-Signature
                            [value] => v=1; a=rsa-sha256; c=relaxed/relaxed;        d=1e100.net; s=20230601; t=1740607592; x=1741212392;        h=to:in-reply-to:references:message-id:date:subject:mime-version:from         :content-transfer-encoding:x-gm-message-state:from:to:cc:subject         :date:message-id:reply-to;        bh=KnjlDzXx5S3rdXKfZvtdHCMQjimhCtKY+06zBO8lTys=;        b=YHFD/dFTSs11m9TMfa2aOx+PvlAWyK0Pgsa+LpHcpayKvfMZy9UB5x3j2HR0nYPG5L         S6Vv1n0PqaKylRX6lHDfDNjiX+/uRzcmLDFxMNuOXLzPRJN4uF+vBgWOJQEnYvy/T17s         sqRHjL0aF05UeV8QfQJC4J4F1eUssaXUia83Z041h2ulgZPCU8u2JwoVzhueIdr8067/         fM/ex568t0PjkXaiV6EW3Vg/gRm/r8ya5AB+kKSBWAbl39d+rAyrjHM9Oa4RgbgHAm8S         xLG6o67ErXngtSVyFqhJxv956a7XEsWMo5PILAJBtYaXUqqnze7rnTYo68hB/EyhYZR0         Goxg==
                        )

                    [12] => Array
                        (
                            [name] => X-Gm-Message-State
                            [value] => AOJu0YzRuw7iLc99IXGlOgHFrPNfFTawOrG1rOjPRUd549TMxtk2VY1B RfYea80ZhdXj6Xq9WKNDbvBuxAhPwNA9njUCjaAhx01ZR3W+7vnmwsLRFg==
                        )

                    [13] => Array
                        (
                            [name] => X-Gm-Gg
                            [value] => ASbGncuz7FNEgVBDWxVNCzqfgnv/V80jDXiUMXiZED6Ms4sUrUN+qQZoAvCgdojIJuo Pj6B0NTWzsluDO2zFNsanPnBkG1PVgSXdIAYsmlOtAZ6fGYAmsIfQBQY+SCW3gl4wrAZtcNp1eo ZaqkJbrIhfI3mxYh063OztN0OHm6sx5nYaO/rVYAYMt1BUJ5+yevVTm3+Tb8dYg3czr6kvaCYo8 RZue538k8zj8LDxl7dBODtVlwTw9dQdxLJxCd1SzdeMl7r0hyqd2amJMX8FcNE0zxeX1BYRyp3c PfRIgkxIvf/M5omqNOn6KvRxe29JhNwZkatUJaYENo6+U8xsfK4E5ZgBJXUnYD2B0zSjQZDgcpi ebyJm6CXkwF4jiECi
                        )

                    [14] => Array
                        (
                            [name] => X-Google-Smtp-Source
                            [value] => AGHT+IEZZI93V77+Z+mags4rXq+Bkg+3jic/ql2JTMPwIdYojh4FvDKMITWl2xeyOXLEy8okDENlTw==
                        )

                    [15] => Array
                        (
                            [name] => X-Received
                            [value] => by 2002:a05:620a:f02:b0:7c0:a389:f26f with SMTP id af79cd13be357-7c23c047145mr1181714485a.56.1740607592258;        Wed, 26 Feb 2025 14:06:32 -0800 (PST)
                        )

                    [16] => Array
                        (
                            [name] => Return-Path
                            [value] => <russell.purinton@gmail.com>
                        )

                    [17] => Array
                        (
                            [name] => Received
                            [value] => from smtpclient.apple (syn-072-227-104-244.res.spectrum.com. [72.227.104.244])        by smtp.gmail.com with ESMTPSA id af79cd13be357-7c36fef6822sm12566985a.47.2025.02.26.14.06.31        for <ashcordbot@gmail.com>        (version=TLS1_3 cipher=TLS_AES_128_GCM_SHA256 bits=128/128);        Wed, 26 Feb 2025 14:06:31 -0800 (PST)
                        )

                    [18] => Array
                        (
                            [name] => Content-Type
                            [value] => multipart/alternative; boundary=Apple-Mail-66C1E31D-8C5E-4F2A-BF33-49965489573F
                        )

                    [19] => Array
                        (
                            [name] => Content-Transfer-Encoding
                            [value] => 7bit
                        )

                    [20] => Array
                        (
                            [name] => From
                            [value] => Russell Thomas <russell.purinton@gmail.com>
                        )

                    [21] => Array
                        (
                            [name] => Mime-Version
                            [value] => 1.0 (1.0)
                        )

                    [22] => Array
                        (
                            [name] => Subject
                            [value] => Re: Test Email with Attachment
                        )

                    [23] => Array
                        (
                            [name] => Date
                            [value] => Wed, 26 Feb 2025 17:06:20 -0500
                        )

                    [24] => Array
                        (
                            [name] => Message-Id
                            [value] => <2C82867F-2467-42D1-B56D-DC9CAA948296@gmail.com>
                        )

                    [25] => Array
                        (
                            [name] => References
                            [value] => <CAHw6UJJsB+YXa73CiUwd6UgKHADF0kwaSQRBp9fy04sG7UY4=Q@mail.gmail.com>
                        )

                    [26] => Array
                        (
                            [name] => In-Reply-To
                            [value] => <CAHw6UJJsB+YXa73CiUwd6UgKHADF0kwaSQRBp9fy04sG7UY4=Q@mail.gmail.com>
                        )

                    [27] => Array
                        (
                            [name] => To
                            [value] => Ash <ashcordbot@gmail.com>
                        )

                    [28] => Array
                        (
                            [name] => X-Mailer
                            [value] => iPhone Mail (22E5200s)
                        )

                )

            [body] => Array
                (
                    [size] => 0
                )

            [parts] => Array
                (
                    [0] => Array
                        (
                            [partId] => 0
                            [mimeType] => text/plain
                            [filename] =>
                            [headers] => Array
                                (
                                    [0] => Array
                                        (
                                            [name] => Content-Type
                                            [value] => text/plain; charset=utf-8
                                        )

                                    [1] => Array
                                        (
                                            [name] => Content-Transfer-Encoding
                                            [value] => quoted-printable
                                        )

                                )

                            [body] => Array
                                (
                                    [size] => 187
                                    [data] => VGhhbmtzDQpTZW50IGZyb20gbXkgaVBob25lDQoNCj4gT24gRmViIDI2LCAyMDI1LCBhdCA0OjU34oCvUE0sIEFzaCA8YXNoY29yZGJvdEBnbWFpbC5jb20-IHdyb3RlOg0KPiANCj4g77u_DQo-IFRoaXMgaXMgYSB0ZXN0IGVtYWlsIHdpdGggdGhlIEdtYWlsLnBocCBmaWxlIGF0dGFjaGVkLg0KPiANCj4gPEdtYWlsLnBocD4NCg==
                                )

                        )

                    [1] => Array
                        (
                            [partId] => 1
                            [mimeType] => text/html
                            [filename] =>
                            [headers] => Array
                                (
                                    [0] => Array
                                        (
                                            [name] => Content-Type
                                            [value] => text/html; charset=utf-8
                                        )

                                    [1] => Array
                                        (
                                            [name] => Content-Transfer-Encoding
                                            [value] => quoted-printable
                                        )

                                )

                            [body] => Array
                                (
                                    [size] => 509
                                    [data] => PGh0bWw-PGhlYWQ-PG1ldGEgaHR0cC1lcXVpdj0iY29udGVudC10eXBlIiBjb250ZW50PSJ0ZXh0L2h0bWw7IGNoYXJzZXQ9dXRmLTgiPjwvaGVhZD48Ym9keSBkaXI9ImF1dG8iPlRoYW5rczxiciBpZD0ibGluZUJyZWFrQXRCZWdpbm5pbmdPZlNpZ25hdHVyZSI-PGRpdiBkaXI9Imx0ciI-U2VudCBmcm9tIG15IGlQaG9uZTwvZGl2PjxkaXYgZGlyPSJsdHIiPjxicj48YmxvY2txdW90ZSB0eXBlPSJjaXRlIj5PbiBGZWIgMjYsIDIwMjUsIGF0IDQ6NTfigK9QTSwgQXNoICZsdDthc2hjb3JkYm90QGdtYWlsLmNvbSZndDsgd3JvdGU6PGJyPjxicj48L2Jsb2NrcXVvdGU-PC9kaXY-PGJsb2NrcXVvdGUgdHlwZT0iY2l0ZSI-PGRpdiBkaXI9Imx0ciI-77u_PHA-VGhpcyBpcyBhIHRlc3QgZW1haWwgd2l0aCB0aGUgPGNvZGU-R21haWwucGhwPC9jb2RlPiBmaWxlIGF0dGFjaGVkLjwvcD4NCjxkaXY-Jmx0O0dtYWlsLnBocCZndDs8L2Rpdj48L2Rpdj48L2Jsb2NrcXVvdGU-PC9ib2R5PjwvaHRtbD4=
                                )

                        )

                )

        )

    [sizeEstimate] => 7329
    [historyId] => 1349
    [internalDate] => 1740607580000
)*/