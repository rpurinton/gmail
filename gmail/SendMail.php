<?php

require_once(__DIR__ . "/../views/src/SessionSqlClient.php");

class SendMail
{

    private $sql = null;
    private $token_info;
    public $result = null;

    public function __construct($to, $subject, $body)
    {
        $this->sql = new \Rpurinton\Mir4info\SessionSqlClient("mir4info");
        $this->token_info = $this->sql->single("SELECT * FROM `gmail_tokens` ORDER BY `id` DESC LIMIT 0,1");
        if (time() > $this->token_info["expires_at"]) {
            $this->refresh_token();
        }
        $rawMessageString = "From: \"mir4info.com\" <mir4info.com@gmail.com>\r\n";
        $rawMessageString .= "Reply-To: no-reply@mir4info.com\r\n";
        $rawMessageString .= "To: $to\r\n";
        $rawMessageString .= 'Subject: =?utf-8?B?' . base64_encode($subject) . "?=\r\n";
        $rawMessageString .= "MIME-Version: 1.0\r\n";
        $rawMessageString .= "Content-Type: text/html; charset=utf-8\r\n";
        $rawMessageString .= 'Content-Transfer-Encoding: quoted-printable' . "\r\n\r\n";
        $rawMessageString .= "$body\r\n";

        $ch = curl_init('https://www.googleapis.com/upload/gmail/v1/users/me/messages/send');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array("Authorization: Bearer " . $this->token_info["access_token"], 'Accept: application/json', 'Content-Type: message/rfc822'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $rawMessageString);
        $this->result = curl_exec($ch);
    }

    private function refresh_token()
    {
        extract($this->sql->single("SELECT * FROM `gmail_creds`"));
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $token_uri);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
            'refresh_token' => $this->token_info["refresh_token"],
            'grant_type'    => 'refresh_token',
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response1 = curl_exec($ch);
        curl_close($ch);
        $response = json_decode($response1, true);
        extract($response);
        if (!isset($expires_in)) {
            $expires_at = 0;
        } else $expires_at = time() + $expires_in - 30;
        $refresh_token = $this->token_info["refresh_token"];
        $this->sql->query("INSERT INTO `gmail_tokens` (`access_token`,`expires_at`,`refresh_token`) VALUES ('$access_token','$expires_at','$refresh_token')");
        $this->token_info = $this->sql->single("SELECT * FROM `gmail_tokens` ORDER BY `id` DESC LIMIT 0,1");
    }
}
