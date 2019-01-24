<?php
/**
 * Debug Console for wordpress ONLY accessible from Atomic or Photon
 *
 * @version     1.0
 * @level		./debug-hosting.php
 * @Link		http://site.com/debug-hosting.php
 * 
 * @category    Debug Console
 * @author      Noah Spirakus
 * 				- @noahjs
 * 
 * @Create      2012/09/25
 * @Modify      2012/09/25
 * @Project     Page.ly
 * 
 */

 
	// Dont allow Robots to crawl this page
		header("X-Robots-Tag: noindex, nofollow", true);
	
	
	//Get the IP
		$ip = ( isset( $_SERVER['PROXY_REMOTE_ADDR'] ) ) ? $_SERVER['PROXY_REMOTE_ADDR'] : $_SERVER['REMOTE_ADDR'];
	
	// DC 3 Subnets
		//199.47.22x.xxx
		//199.168.174.xxx
	
	
	if(		/* CHECK THE CALLING IP */
			($ip != '127.0.0.1') AND					// Local
			(substr($ip, 0, 9) != '199.47.22') AND		// DC 3
			(substr($ip, 0, 11) != '199.168.174')		// DC 3
	   ){
		
		//External IP requesting Page
			echo 'No External Debug Available';
			//header( 'Location: '.$_SERVER['SERVER_NAME'] );
		
	}elseif( $_SERVER['HTTP_DEBUG'] != 'TRUE' ){
		
		//Not a Curl with Proper Header
			echo 'No External Debug Available.';
			//header( 'Location: '.$_SERVER['SERVER_NAME'] );
		
	}else{
		
		/*
		 *	Then most likely internal IP being called from API
		 *		AND the DEBUG Header has been set
		 */
		
		//Make wordpress think that this is the 
			$_SERVER['REQUEST_URI'] = '/';
		
		//Set wordpress Debug Mode
			define('WP_DEBUG', true);
		
		//Make sure all errors are going to the screen
			ini_set('display_errors', 'On');
			error_reporting( E_ALL );
		
		/**
		 * Normal Wordpress functions
		 * FROM: index.php
		 **/
			define('WP_USE_THEMES', true);
		
		/**
		 * Start the pages
		 * FROM: index.php
		 **/
			require('./wp-blog-header.php');
		
	}
