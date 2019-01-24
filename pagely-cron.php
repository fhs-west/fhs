<?php
/**
 * Hacked version of wp-cron.php used by pworker to do cli php cron runs
 */

if ( empty($argv[1]) || empty($argv[2]) || empty($argv[3]) || empty($argv[4]))
	die();

$host = $argv[1];
$uri = $argv[2];
$server = $argv[3];
$timeout = $argv[4];

echo "Simulating request to: {$host}{$uri}\n";
$_SERVER['SERVER_NAME'] = $server;
$_SERVER['REQUEST_URI'] = $uri;
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_HOST'] = $host;
$_SERVER['QUERY_STRING'] = '';
define('WP_DEBUG', true);

chdir('/httpdocs/');
define('PAGELY_CRON', true);
define('WP_CRON_LOCK_TIMEOUT', $timeout);
include "/pagely/setup.php";

/**
 * Tell WordPress we are doing the CRON task.
 *
 * @var bool
 */
define('DOING_CRON', true);

if ( !defined('ABSPATH') ) {
	/** Set up WordPress environment */
	require_once( dirname( __FILE__ ) . '/wp-load.php' );
}

// Uncached doing_cron transient fetch
function _get_cron_lock() {
	global $wpdb;

	$value = 0;
	if ( wp_using_ext_object_cache() ) {
		/*
		 * Skip local cache and force re-fetch of doing_cron transient
		 * in case another process updated the cache.
		 */
		$value = wp_cache_get( 'doing_cron', 'transient', true );
	} else {
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s LIMIT 1", '_transient_doing_cron' ) );
		if ( is_object( $row ) )
			$value = $row->option_value;
	}

	return $value;
}

if ( false === $crons = _get_cron_array() )
	return;

$keys = array_keys( $crons );
$gmt_time = microtime( true );

if ( isset($keys[0]) && $keys[0] > $gmt_time )
	return;

$doing_cron_transient = get_transient( 'doing_cron');

// Use global $doing_wp_cron lock otherwise use the GET lock. If no lock, trying grabbing a new lock.
if ( empty( $doing_wp_cron ) ) {
	if ( empty( $_GET[ 'doing_wp_cron' ] ) ) {
		// Called from external script/job. Try setting a lock.
		if ( $doing_cron_transient && ( $doing_cron_transient + WP_CRON_LOCK_TIMEOUT > $gmt_time ) )
			return;
		$doing_cron_transient = $doing_wp_cron = sprintf( '%.22F', microtime( true ) );
		set_transient( 'doing_cron', $doing_wp_cron );
	} else {
		$doing_wp_cron = $_GET[ 'doing_wp_cron' ];
	}
}

// Check lock
if ( $doing_cron_transient != $doing_wp_cron )
{
    echo "Failed to get cron lock\n";
    return;
}

foreach ( $crons as $timestamp => $cronhooks ) {
	if ( $timestamp > $gmt_time )
		break;

	foreach ( $cronhooks as $hook => $keys ) {

		foreach ( $keys as $k => $v ) {

			$schedule = $v['schedule'];

			if ( $schedule != false ) {
				$new_args = array($timestamp, $schedule, $hook, $v['args']);
				call_user_func_array('wp_reschedule_event', $new_args);
			}

            echo "Running $timestamp $hook\n";
			wp_unschedule_event( $timestamp, $hook, $v['args'] );

			/**
			 * Fires scheduled events.
			 *
			 * @internal
			 * @since 2.1.0
			 *
			 * @param string $hook Name of the hook that was scheduled to be fired.
			 * @param array  $args The arguments to be passed to the hook.
			 */
 			do_action_ref_array( $hook, $v['args'] );

			// If the hook ran too long and another cron process stole the lock, quit.
			if ( _get_cron_lock() != $doing_wp_cron )
            {
                echo "Timed out and lost the lock\n";
                return;
            }
		}
	}
}

if ( _get_cron_lock() == $doing_wp_cron )
{
    delete_transient( 'doing_cron' );
}

