<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');

// define some vars
define( 'OMEMCACHED_SERVERS', 	'p9-cache-object-s.tftue1.0001.use1.cache.amazonaws.com:11211' ); 		// object cache
define( 'PMEMCACHED_SERVERS', 	'p9-cache-page.tftue1.0001.use1.cache.amazonaws.com:11211');    		// page cache
ini_set('session.save_path' , 	'tcp://p9-cache-session.tftue1.0001.use1.cache.amazonaws.com:11211');	// set some php ini's
define( 'VARNISH_SERVERS', 	'199.47.221.226:80,199.47.221.66:80,134.0.76.211:80');	  		// varnish servers
// load the class

define('DB_NAME', 'dbtest');


require('/data/c01/wordpress/bin/object-cache.php');

wp_cache_init();

// add item
$key = 'mydatakey';
$data = array('1' => 'adsadsad','2' => md5('adad') );
$expire = 60;
$group = 'default';

wp_cache_add($key, $data, $group, $expire );
$item = wp_cache_get($key,$group);

/*
$wp_object_cache = new WP_Object_Cache();
$mc =& $wp_object_cache->get_mc($group);

$allSlabs = $mc->getExtendedStats( 'slabs' );
$items = $mc->getExtendedStats( 'items' );
foreach ( $allSlabs as $server => $slabs ) {
    foreach( $slabs as $slabId => $slabMeta ) {
        $cdump = $mc->getExtendedStats( 'cachedump', (int) $slabId );
        foreach( $cdump as $keys => $arrVal ) {
            if ( !is_array( $arrVal ) ) continue;
            foreach( $arrVal as $k => $v ) {
                $list[] = $k;
            }
        }
    }
}

$keymaps = array();
foreach ( $list as $item ) {
    $parts = explode( ':', $item );
    if ( is_numeric( $parts[0] ) ) {
	$blog_id = array_shift( $parts );
	$group = array_shift( $parts );
    } else {
	$group = array_shift( $parts );
	$blog_id = 0;
    }

    if ( count( $parts ) > 1 ) {
	$key = join( ':', $parts );
    } else {
	$key = $parts[0];
    }
    $group_key = $blog_id . $group;
    if ( isset( $keymaps[$group_key] ) ) {
        $keymaps[$group_key][2][] = $key;
    } else {
	$keymaps[$group_key] = array( $blog_id, $group, array( $key ) );
    }
}

ksort( $keymaps );
foreach ( $keymaps as $group => $values ) {
    list( $blog_id, $group, $keys ) = $values;
    foreach ( $keys as $key ) {
        echo $key."\n";
    }
}
die();
*/
echo "Set cache item, now getting \n";
echo '<pre>';
print_r($item);
echo '</pre>';
echo "\nNow delete it.. \n";

wp_cache_delete($key,$group);

$item = wp_cache_get($key,$group);
if (!$item) {
	echo "DELETED\n";
} else {
	echo "!!!\nNOT DELETED!!!\n";
	echo '<pre>';
	print_r($item);
	echo '</pre>';
}

//$wp_object_cache->debug = true;
//$wp_object_cache->stats();

?>