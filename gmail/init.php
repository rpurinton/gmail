<?php

require_once(__DIR__ . "/../SqlClient.php");
$sql = new SqlClient;
extract($sql->single("SELECT * FROM `gmail_creds`"));
$redirect_uri = "https://" . $_SERVER["HTTP_HOST"] . "/gmail/init.php";
$url = $auth_uri . "?" . http_build_query([
    "client_id"              => $client_id,
    "redirect_uri"           => $redirect_uri,
    "scope"                  => $scope,
    "access_type"            => "offline",
    "include_granted_scopes" => "true",
    "state"                  => "state_parameter_passthrough_value",
    "response_type"          => "code",
]);
if (!isset($_GET["code"])) {
    header("Location: $url");
    exit;
}
$code = $_GET["code"];
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $token_uri);
curl_setopt($ch, CURLOPT_POST, TRUE);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'code'          => $code,
    'client_id'     => $client_id,
    'client_secret' => $client_secret,
    'redirect_uri'  => $redirect_uri,
    'grant_type'    => 'authorization_code',
]));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response1 = curl_exec($ch);
curl_close($ch);
$response = json_decode($response1, true);
extract($response);
$expires_at = time() + $expires_in - 30;
$sql->query("INSERT INTO `gmail_tokens` (`access_token`, `expires_at`, `refresh_token`) VALUES ('$access_token', '$expires_at', '$refresh_token')");
