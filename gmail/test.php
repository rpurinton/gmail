<?php

require_once("SendMail.php");
$mailer = new SendMail("russell.purinton@gmail.com", "test", "test");
print_r($mailer->result);
