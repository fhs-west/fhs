<?php
// Cleans up the environment accounting for the chroot, also defines Pagely constants

// detect chroot and if so fix DOCUMENT_ROOT
if (__DIR__ == '/pagely')
{
    if (is_link('/httpdocs'))
        $_SERVER["DOCUMENT_ROOT"] = '/'.readlink('/httpdocs').'/';
    else
        $_SERVER["DOCUMENT_ROOT"] = '/httpdocs/';

    define( 'PAGELYBIN', '/pagely' );
}
else if (preg_match('@^/data/s[0-9]+/dom[0-9]+@', __DIR__, $match))
{
        define( 'PAGELYBIN', "$match[0]/pagely" );
}
else if (preg_match('@^/data/s[0-9]+/dom[0-9]+@', $_SERVER['DOCUMENT_ROOT'], $match))
{
        define( 'PAGELYBIN', "$match[0]/pagely" );
}
elseif (!empty($_SERVER['PWD']) && preg_match('@^/data/s[0-9]+/dom[0-9]+@', $_SERVER['PWD'], $match))
{
            define( 'PAGELYBIN', "$match[0]/pagely" );
}
else
{
    define( 'PAGELYBIN', realpath('/srv/wordpress/current/bin'));
}

// fix is_ssl detection
if (isset($_SERVER['HTTP_HTTPS']))
{
    $_SERVER['HTTPS'] = $_SERVER['HTTP_HTTPS'];
}

require (PAGELYBIN . '/pool_configs.php');

// wordpress config
define('AUTOMATIC_UPDATER_DISABLED', true);
define('DISABLE_WP_CRON', true);
define('AUTOSAVE_INTERVAL', 300);
define('WP_CRON_LOCK_TIMEOUT', 120);

// force disable timthumb webshot
define('WEBSHOT_ENABLED', false);

// force disable wordpress theme editing
define( 'DISALLOW_FILE_EDIT', true );

// PHP basic auth compat
if (!empty($_SERVER["REMOTE_AUTHORIZATION"]))
{
    $d = base64_decode($_SERVER["REMOTE_AUTHORIZATION"]);
    list($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']) = explode(':', $d);
}

// Authorization header compat
if (empty($_SERVER['HTTP_AUTHORIZATION']) && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION']))
{
    $_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
}

// umask will be wrong on hhvm fix
if (php_sapi_name() == 'srv')
     umask(002);

// Set file permissions using umask
define('FS_CHMOD_DIR', (0777 & ~ umask()));
define('FS_CHMOD_FILE', (0666 & ~ umask()));

// remote cruft headers that confuse some plugins like ithemes better security
// REMOTE_ADDR has the correct ip
unset($_SERVER['HTTP_X_CLUSTER_CLIENT']);
unset($_SERVER['HTTP_X_CLUSTER_CLIENT_IP']);
unset($_SERVER['HTTP_X_FORWARDED_FOR']);

$user_setup = __DIR__.'/../user/setup.php';
if (file_exists($user_setup))
    include $user_setup;
