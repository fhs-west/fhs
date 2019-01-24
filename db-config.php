<?php
	
	// Page.lys hyperdb config
	$p_dbname = 'db_dom29147';
	$p_dbuser = 'db_dom29147';
	$p_dbpass = 'ZhyGE1oYKIm1h4Ab5QZ4O/MbUnamJFMEEPjmn277';
	$p_MASTER = 'vps-virginia-aurora-7-cluster.cluster-czvuylgsbq58.us-east-1.rds.amazonaws.com';
	$p_SLAVES = array();
	/** Variable settings **/
	
	/**
	 * save_queries (bool)
	 * This is useful for debugging. Queries are saved in $wpdb->queries. It is not
	 * a constant because you might want to use it momentarily.
	 * Default: false
	 */
	$wpdb->save_queries = false;
    if (defined('SAVEQUERIES') && SAVEQUERIES)
        $wpdb->save_queries = true;
	
	/**
	 * persistent (bool)
	 * This determines whether to use mysql_connect or mysql_pconnect. The effects
	 * of this setting may vary and should be carefully tested.
	 * Default: false
	 */
	$wpdb->persistent = false;
	
	/**
	 * max_connections (int)
	 * This is the number of mysql connections to keep open. Increase if you expect
	 * to reuse a lot of connections to different servers. This is ignored if you
	 * enable persistent connections.
	 * Default: 10
	 */
	$wpdb->max_connections = 7;
	
	/**
	 * check_tcp_responsiveness
	 * Enables checking TCP responsiveness by fsockopen prior to mysql_connect or
	 * mysql_pconnect. This was added because PHPs mysql functions do not provide
	 * a variable timeout setting. Disabling it may improve average performance by
	 * a very tiny margin but lose protection against connections failing slowly.
	 * Default: true
	 */
	$wpdb->check_tcp_responsiveness = true;
	
	
	
	// MASTER db for writes and subsequent reads
	$wpdb->add_database(array(
		"host"	  	=> $p_MASTER,	  // If port is other than 3306, use host:port.
		"user"	  	=> $p_dbuser,
		"password" 	=> $p_dbpass,
		"name"	  	=> $p_dbname,
		"write"		=> 1,
		"read"		=> 2,
	));
	
	// READ ONLY SLAVES
	foreach ($p_SLAVES as $slave) {
		$slaves_to_add = array(
			"host"		=> $slave,		// If port is other than 3306, use host:port.
			"user"		=> $p_dbuser,
			"password" 	=> $p_dbpass,
			"name"		=> $p_dbname,
			"write"	  	=> 0,
			"read"		=> 1, // higher numbers are lower priority
			"timeout"	=> 500,
			);
		//add the slaves to the pool
		$wpdb->add_database($slaves_to_add);
	}
	
	$wpdb->lag_cache_ttl = 30;
	$wpdb->default_lag_threshold = 10;
	$wpdb->add_callback( 'get_lag_cache', 'get_lag_cache' );
	$wpdb->add_callback( 'get_lag',       'get_lag' );
	
	function get_lag_cache( $wpdb ) {
        $lag_data = apc_fetch('hyperdb_lag');
        if ( !is_array( $lag_data ) || !is_array( $lag_data[ $wpdb->lag_cache_key ] ) )
                return false;

        if ( $wpdb->lag_cache_ttl < time() - $lag_data[ $wpdb->lag_cache_key ][ 'timestamp' ] )
                return false;

        return $lag_data[ $wpdb->lag_cache_key ][ 'lag' ];

	}
	
	function get_lag( $wpdb ) {
        $host = substr($wpdb->lag_cache_key,0,-5);
        $mysqli = new mysqli($host, '', '', 'slavecheck');
        if ($mysqli->connect_error) {
                 return "Connect failed: %s\n $mysqli->connect_error";
        }

        if ($result = $mysqli->query("SELECT UNIX_TIMESTAMP() - UNIX_TIMESTAMP(ts) AS lag FROM heartbeat LIMIT 1")) {
                $row = $result->fetch_array(MYSQLI_ASSOC);

                /* free result set */
                $result->close();

                //cache the result
                $lag_data[ $wpdb->lag_cache_key ] = array( 'timestamp' => time(), 'lag' => $row[ 'lag' ] );
                apc_store ( 'hyperdb_lag' , $lag_data, 10 );
                return $row['lag'];
        } else {
                return false;
		  }
	}

// The ending PHP tag is omitted. This is actually safer than including it.
