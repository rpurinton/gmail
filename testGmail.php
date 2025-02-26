<?php
require 'vendor/autoload.php';

use Google\Client;
use Google\Service\Gmail;
use Google\Service\Gmail\Message;

function sendEmail($to, $subject, $bodyText) {
    // Load the OAuth 2.0 credentials
    $client = new Client();
    $client->setAuthConfig('/root/.google/ashcordbot.json');
    $client->addScope(Gmail::GMAIL_SEND);
    $client->setAccessType('offline');

    // Get the authorized Gmail service
    $service = new Gmail($client);

    // Create the email content
    $strRawMessage = "From: me\r\n";
    $strRawMessage .= "To: $to\r\n";
    $strRawMessage .= "Subject: $subject\r\n";
    $strRawMessage .= "\r\n";
    $strRawMessage .= $bodyText;

    // Encode the message
    $rawMessage = base64_encode($strRawMessage);
    $rawMessage = strtr($rawMessage, array('+' => '-', '/' => '_'));

    // Create a new message
    $message = new Message();
    $message->setRaw($rawMessage);

    // Send the email
    try {
        $service->users_messages->send('me', $message);
        echo "Email sent successfully!";
    } catch (Exception $e) {
        echo 'An error occurred: ' . $e->getMessage();
    }
}

// Usage
sendEmail('russell.purinton@gmail.com', 'Test Subject', 'This is a test email.');
