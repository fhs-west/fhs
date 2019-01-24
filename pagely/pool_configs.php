<?php

// cache servers
define('VARNISH_SERVERS', '10.0.14.138');

// session config
ini_set('session.save_handler', 'redis');
ini_set('session.save_path', 'tcp://127.0.0.1:6379');
