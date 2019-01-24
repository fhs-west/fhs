<?php
// Before removing this file, please verify the PHP ini setting `auto_prepend_file` does not point to this.

if (file_exists('/www/foods_444/public/wp-content/plugins/wordfence/waf/bootstrap.php')) {
	define("WFWAF_LOG_PATH", '/www/foods_444/public/wp-content/wflogs/');
	include_once '/www/foods_444/public/wp-content/plugins/wordfence/waf/bootstrap.php';
}
?>