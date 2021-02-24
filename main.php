<?php

error_reporting(E_ALL);
ini_set("max_execution_time", 0);
set_time_limit(0);

require_once "config.php";
require_once 'autoloader.inc.php';

Log::setLogLevel(Log::DEBUG);

Filter::load();

$serverContext = null;
if (file_exists('server.pem')) {
   $serverContext = stream_context_create([
       'ssl' => [
           'verify_peer' => false,
           'local_cert' => 'server.pem',
       ],
   ]);
}

foreach (LISTEN_ADDR_SMTP as $addr) {
   new SmtpServer($addr, $serverContext);
}
foreach (LISTEN_ADDR_POP3 as $addr) {
   new Pop3Server($addr, $serverContext);
}

Mainloop::main();
