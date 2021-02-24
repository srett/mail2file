<?php

define('LISTEN_ADDR_SMTP', ['tcp://0.0.0.0:10025', 'ssl://0.0.0.0:10465']);
define('LISTEN_ADDR_POP3', ['tcp://0.0.0.0:10110', 'ssl://0.0.0.0:10995']);

// This is really only used in POP3 and SMTP banners, so it's up to you
// whether to set that to an actual fqdn that maps to your host
define('CONFIG_DOMAIN', 'mydomain.dyndns.net');
