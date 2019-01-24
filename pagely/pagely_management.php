<?php
/*
Plugin Name: Pagely API/v1 Management
Plugin URI: https://api.pagely.com/
Description: Your Managed Hosting Panel, You should not disable it.
Version: 2.5
Author: Page.ly
Author URI: https://pagely.com

*/
// Copyright (c) 2009-2012 Obu Web Technologies Inc., Joshua Strebel
//
// Obu Web Technologies: http://obuweb.com
// DBA: Pagely WordPress Hosting: https://pagely.com
// Joshua Strebel: http://saint-rebel.com Twitter: @strebel

// Released under the GPL license
// http://www.opensource.org/licenses/gpl-license.php
//
// This is an add-on for WordPress
// http://wordpress.org/
//
// **********************************************************************
//		This program is distributed in the hope that it will be useful,
//		but WITHOUT ANY WARRANTY; without even the implied warranty of
//		MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.	See the
//		GNU General Public License for more details.
// **********************************************************************
if( ! defined('DISABLE_WP_CRON') ) 
	define('DISABLE_WP_CRON', true);

if( ! defined('AUTOSAVE_INTERVAL') ) 
	define( 'AUTOSAVE_INTERVAL', 300 ); // Seconds

if( ! defined('WP_CRON_LOCK_TIMEOUT') )
	define( 'WP_CRON_LOCK_TIMEOUT', 120 );

if( ! defined('AUTOMATIC_UPDATER_DISABLED') )
   define( 'AUTOMATIC_UPDATER_DISABLED', true);

if (! defined('WP_AUTO_UPDATE_CORE') ) 
   define('WP_AUTO_UPDATE_CORE', false);

if( ! defined('VARNISH_SERVERS') )
   define( 'VARNISH_SERVERS', '127.0.0.1');

if( ! defined('PMEMCACHED_SERVERS') )
   define( 'PMEMCACHED_SERVERS', '127.0.0.1:11211'); 
   
// backwards compat for p3 and p10   
if ( isset($_SERVER['HTTP_X_PAGELY_SSL']) && 'on' == strtolower( $_SERVER['HTTP_X_PAGELY_SSL'] ) ) {
	$_SERVER['HTTPS'] = 'on';
}

// symlink in debug.log if needed
$log = ABSPATH.'/wp-content/debug.log';
if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG == true) {
    if (file_exists($log) && !is_link($log))
        rename($log, "$log.1");

    if (!file_exists($log)) {
        $tmp = glob(__DIR__."/../mnt/log/*-php.error.log");
        if (isset($tmp[0])) {
            $file = basename($tmp[0]);
            symlink("../../mnt/log/$file", $log);
        }
    }
}
else if (file_exists($log) && is_link($log)) {
    unlink($log);
}


// add a header to reset login rate limiting on successful login
function pagely_add_ratelimit_reset_header() {
    header('X-Pagely-Ratelimit-Reset: login');
}
add_action('wp_login', 'pagely_add_ratelimit_reset_header');

	
# HCS Class
class HCS {
	
	var $api_url		= 'https://api.pagely.com/v1';
	var $config		= array();
	var $client_url		= 'https://atomic.pagely.com';
	var $api_key		= '';
	var $pagely_api_sec  	= '';
	
	var $hcs_varnish_servers = '';

    protected static $instance;

	public static function init() {
		if ( empty($instance) && is_admin() ) {
            self::$instance = new HCS;
			// load the Varnish sub class as well
		}		
		new HCSVarnish;
		return self::$instance;
	}
	
	// main init to run on admin screens
	function HCS() {
		
		global $wp_rewrite,$wp_version,$post,$wpdb;
		
		add_filter( 'got_rewrite', '__return_true' );

		// api key
		
		// TODO we need to check to make sure this is the main site in a multisite, if not, force the options to be pulled from it.
		// right now if on a multisite subsite, these value are probably empty
        $infofile = "/info.json";
        if (!file_exists($infofile) && preg_match('@^/data/s[0-9]+/dom[0-9]+@', __DIR__, $match))
        {
            // we are running in a non chroot setup
            $infofile = "$match[0]/info.json";
        }
        elseif (preg_match('@^/data/s[0-9]+/dom[0-9]+@', $_SERVER['DOCUMENT_ROOT'], $match))
        {
            // we are running in a non chroot setup
            $infofile = "$match[0]/info.json";
        }

        $this->config = json_decode(file_get_contents($infofile), true);

		if (!empty($this->config["apiKey"])) {
			$this->api_key 		= $this->config["apiKey"];
		} else {
			$this->api_key 		= get_option('pagely-apikey');
		}
		$this->pagely_options = get_option('pagely_site_options');
        if (!empty($this->config['apiSecret']))
            $this->pagely_api_sec = $this->config['apiSecret'];
		
		
		if ( (isset($_GET['page']) && $_GET['page'] == 'bulletins') && isset($_GET['mark_read']) ) {
			$this->_mark_bulletin_as_read($_GET['mark_read']);
			wp_safe_redirect($_SERVER['HTTP_REFERER']);
			exit;
		}
				
	
		// remove update core nag
		add_filter( 'pre_site_transient_update_core', create_function( '$a', "return null;" ) );
		
		//grab the sites domain
		if ( is_multisite() ) {
			$basedomain = get_site_url(1);
		} else {
			$basedomain = get_option('siteurl');
		}
		
		// this domain
		$this->site_domain = str_replace( array( 'http://','https://','www.') ,'', strtolower($basedomain) );
		
		// force w3tc_config		
		if ( isset($_GET['page']) && preg_match('/^w3tc_/i',$_GET['page'],$matches) ) {
			add_action('admin_footer',array(&$this, '_hcs_w3tc_config'), 99 );
		}
		
		// make sure we have correct data from api
		add_action('wp', array(&$this, '_update_domain_via_api'),20);
		
		// load menu and styles
			add_action('admin_print_styles', array(&$this, '_head_styles'),20);
			add_action('admin_menu', array(&$this, '_create_menu') ,20); 
			add_action('admin_bar_menu', array(&$this, '_admin_toolbar'), 999 );
		
		// load scripts in footer
		if ( !defined('ALLOW_W3TC') ) {
			add_action('admin_footer', array(&$this, '_footer_scripts'),20);
		}
		// make sure a cache is running // commented out on 2-13-2013
		//add_action('admin_init', array(&$this, '_hcs_cache_check'),20);
		
		// set crons
		add_action('wp', array(&$this, '_crons') );
		
		// mod js
		add_action('wp_enqueue_scripts', array(&$this,'_mod_js') );
 
		
		// Show billing or bulletins
		if (!is_multisite() && current_user_can('publish_posts')) {
			add_action('admin_notices',array(&$this, '_billing_check'), 0 );	 
            if (isset($_GET['page']) && $_GET['page'] != 'bulletins') {
                add_action('admin_notices',array(&$this, '_show_bulletins'), 0 );	 
            }
		} else {
			global $blog_id;
			if ( is_multisite() && $blog_id == 1 ) {
				add_action('admin_notices',array(&$this, '_billing_check'), 0 );	 
                if (isset($_GET['page']) && $_GET['page'] != 'bulletins') {
                    add_action('admin_notices',array(&$this, '_show_bulletins'), 0 );	 
                }
			}		
		}

		// dashboard screen
		//if ($this->hcs_rsinfo->site_name == 'page.ly') {
			//add_action('wp_dashboard_setup',array(&$this, '_pagely_dashboard_rss') );
		//}
		
				
		
		register_deactivation_hook(__FILE__, array(&$this, '_hcs_deactivation') );

		return;

	}
	
	function _show_db_queries() {
		echo "<div style='clear:both;padding:20px;background:#fff'><h2>HyperDB Queries</h2><pre>";
			global $wpdb;
			print_r($wpdb->queries);
		echo "</pre></div>";
	}
	
	/***************************
	* WRAPPER FUNCTION FOR ALL PAGELY API REQUESTS	
	* This function makes a remote request to the Pagely REST API
	* @param string $method GET | POST | PUT | DELETE 
	* @param string $uri the controller and method to call on teh api
	* @param array $params required data for method
 	* @return array|object Array containing 'headers', 'body', 'response', 'cookies', 'filename'. A WP_Error instance upon error
 	* @return[body] returned will be a json response, see our API docs
	*/
	function _pagely_api_request($method = 'GET', $uri = '/controller/method', $params = array() ) {

		// setup the request
		$url 		= $this->api_url . $uri;
		$headers = array( 'X-API-KEY' => $this->api_key);

		// switch based on METHOD		
		/***********
		// GET is for getting reecords
		// POST is for updating existing records and requires an ID
		// PUT is for create NEW records
		// DELETE is for remove records and requires an ID
		************/
		
		switch ($method) {
			case 'GET':
				$params['sess']		= ''; //not used yet
				$querystring 			= 	http_build_query($params);
				// append a query string to the url
				$url 						= $url.'?'.$querystring;
				// unset params on GET
				$params 					= false;
			break;
			case 'POST':
			case 'PUT':
			case 'DELETE':
				// generate some secure hashes			
				$time        		 	= date('U');
				$params['sess']		= ''; //not used yet
				$params['time']    	= $time;
   			$params['hash']    	= sha1($time.$this->pagely_api_sec); // not used yet
   			// pass an object ID as needed
   			$params['id']			= isset($params['id']) ? $params['id'] : ''; // should be object id, like domain_id = 1099; can be empty on PUT
			break;
		}
			
		// make the request
		$req_args = array(
			'method' => $method, 
			'body' => $params, 
			'headers' => $headers, 
			'sslverify' => true  // set to true in live envrio
		);
	
		// make the remote request
		$result = wp_remote_request( $url, $req_args);
		//print_r($result);
		if ( !is_wp_error($result) ) {
			//no error
			return $result['body'];	
		
		} else {
			// error
			return $result->get_error_message();			
		}	
	}

	
	function gen_iframe(){ 
		
	
		$result = $this->_pagely_api_request($method = 'POST', $uri = '/accounts/session', $params = array('api_key'	=> $this->api_key) );
		$result = json_decode($result);
		
		if($result AND $result->result == 2){
			$sec = sha1($result->object->sess.$result->object->secret);
			echo "<div class='wrap'><iframe src='{$this->client_url}/auth?s={$result->object->sess}&h={$sec}' style='width:100%;min-height:900px;border:none;'></iframe></div>";
		}else{
			echo "<p>There was an error: {$result->message}. Contact support if problem persists.</p>";
		}

	}


	
	// create a dashboard RSS feed for page.ly customers
	function _pagely_dashboard_rss() {
		wp_add_dashboard_widget( 'pagely_rss', 'Page.ly WordPress Hosting News and Alerts', array(&$this, '_pagely_rss_output'));
		// Global the $wp_meta_boxes variable (this will allow us to alter the array)
		// Global the $wp_meta_boxes variable (this will allow us to alter the array)
		global $wp_meta_boxes;
		
		// Then we make a backup of your widget
		$my_widget = $wp_meta_boxes['dashboard']['normal']['core']['{widget id here}'];
		
		// We then unset that part of the array
		unset($wp_meta_boxes['dashboard']['normal']['core']['{widget id here}']);
		
		// Now we just add your widget back in
		$wp_meta_boxes['dashboard']['side']['core']['{widget id here}'] = $my_widget;
		
	}
	
	function _pagely_rss_output() {
		echo '<div class="rss-widget">';
		wp_widget_rss_output(array(
			'url' => 'http://blog.page.ly/feed/',	//put your feed URL here
			'title' => 'Page.ly WordPress News', // Your feed title
			'items' => 5, //how many posts to show
			'show_summary' => 0, // 0 = false and 1 = true 
			'show_author' => 0,
			'show_date' => 1
		));
		echo "</div>";
	}
	
	// script to hide w3tc box
	function _footer_scripts() { ?>
		<script type='text/javascript'>
			jQuery(document).ready(function($) {
				
		<?php 
		if ( isset($_GET['page']) && $_GET['page'] == 'w3tc_general' && !defined(ALLOW_W3TC) ) {
		?>
			$('#w3tc.wrap div.postbox').each(function (index,el) {
				h3content = $(el).children('h3').html();
				//alert($(h3content).html());
				var regmatch = /Database Cache|Object Cache|Varnish|Proxy/i.test(h3content);
				if (regmatch) {
					$(el).children('h3').append(' <em class="hcs_w3tc_notice">- Powered by your Hosting system <a href="#" id="postbox'+index+'" class="hcs_show_postbox">Show me anyways</a></em>');
					$(el).children('div.inside').addClass('postbox'+index).hide();
					$(el).find('input.enabled').click( function() { $(this).attr("disabled", true).val(0); alert('This setting is not needed, please leave it turned off. Your hosting system provides this advanced feature on the system level for you.')});
					
				}
				
				// notice
				var pagematch = /Page Cache/i.test(h3content);
				if(pagematch) {
					$(el).children('h3').append(' <em class="hcs_w3tc_notice">- Preferred Setting is On > Memcached.</em>');
				}

				var browsermatch = /Browser/i.test(h3content);
				if(browsermatch) {
					$(el).children('h3').append(' <em class="hcs_w3tc_notice">- Preferred Settings are: On.</em>');
				}


			});
			
			$('.hcs_show_postbox').live( 'click', function() {
				var whichpb = $(this).attr('id');
				$('div.'+whichpb).slideToggle();
				return false;
			});
			
			
			
		<?php }
		if ( isset($_GET['page']) && ($_GET['page'] == 'w3tc_dbcache' || $_GET['page'] == 'w3tc_objectcache' ) ) { ?>
				$('#w3tc form').hide();
				$('#w3tc').append('<p class="hcs_notice"><em>Your sweet WordPress hosting service takes care of this for you.</em><p><p class="hcs_notice">So what! <a href="#" id="hcs_show">Show me this menu anyways</a></p>');
				$('#hcs_show').click( function() {$('#w3tc form').fadeIn(); $('.hcs_notice').hide();});
				
		<?php } echo '});</script>';
	}
	
	// render styles for our nav bar
	function _head_styles() {
	}
	
	// create custom plugin settings menu
	function _create_menu() {
	if ( current_user_can('publish_posts') || current_user_can('manage_network') ) {
		//create new top-level menu
		add_menu_page('Hosting Management', 'Pagely', 'read', __FILE__, array(&$this, '_settings_page'),'',0); 
		add_submenu_page( __FILE__, "Hosting Panel", 'Hosting Panel', 'read', "pagely-control-panel", array(&$this, '_settings_page') ); 
		add_submenu_page( __FILE__, "Notices", 'Notices', 'read', 'bulletins', array(&$this, '_list_bulletins') ); 
	  add_submenu_page( __FILE__, "Purge Cache", 'Purge Cache', 'read', 'purge-cache', array(&$this, '_cache_purge') ); 
	  // add_submenu_page( __FILE__, "CDN TEST ENABLE", 'CDN TEST ENABLE', 'administrator', 'cdn-test', array(&$this, '_disable_cdn_wp') ); 
	  }
	}
	
	function _admin_toolbar( $wp_admin_bar ) {
	if ( current_user_can('publish_posts') || current_user_can('manage_network') ) {
		// add a parent item
		$args = array(
			'id'    => 'pagely',
			'title' => '<span class="ab-icon" style="background: transparent url(https://cdnassets.pagely.com/public/images/pagely-p20x20.png) no-repeat top left;"></span> <span class="ab-label">Pagely</span>',
			'parent' => 'top-secondary'
		);
		$wp_admin_bar->add_node( $args );
	
		// add a child item to our parent item
		$args = array(
			'id'     => 'hosting-panel',
			'title'  => 'Hosting Panel',
			'parent' => 'pagely',
			'href'	=>  admin_url('admin.php?page=pagely-control-panel')
		);
		$wp_admin_bar->add_node( $args );
	
		$args = array(
			'id'     => 'notices',
			'title'  => 'Notices',
			'parent' => 'pagely',
			'href'	=>  admin_url('admin.php?page=bulletins')
		);
		$wp_admin_bar->add_node( $args );
		
		$args = array(
			'id'     => 'purge-cache',
			'title'  => 'Purge ALL Cache',
			'parent' => 'pagely',
			'href'	=> admin_url('admin.php?page=purge-cache')
		);
		$wp_admin_bar->add_node( $args );
	 	}
	}

	// our iframe
	function _settings_page() {	
		//$this->gen_iframe();
		echo "<div class='wrap'><p>This page has moved to <a href='https://atomic.pagely.com' target='_blank'>https://atomic.pagely.com.</p></div>";
	}
	
	function _cache_purge($echo = true) {
       
		if ( function_exists('w3tc_pgcache_flush') ) {
               		// w3tc cache purge
			w3tc_pgcache_flush();
               		w3tc_minify_flush();
               		w3tc_objectcache_flush();
               		w3tc_dbcache_flush();
               		$page_cache = "w3 Total Cache Purge... Ok";
               	} else if (function_exists('wp_cache_clean_cache') ) {
               		// wp super cache cache purge
			global $file_prefix;
               		wp_cache_clean_cache( $file_prefix, true );
               		$page_cache = "WP Super Cache Purge... Ok";
       	       	}

              
       $varnish = new HCSVarnish;
       $varnish->HCSVarnishPurgeAll();
       
       $cdn = $this->_purge_presscdn();
       wp_cache_flush();
       $key_warning = '';
       if (!$this->api_key) {
           $key_warning = 'Pagely API key missing, flushing CDN won\'t work. Please contact <a href="mailto:support@pagely.com">support</a>';
       }
       echo "<div class='wrap'>
               <h2>Purge all the things...</h2>
               <ul>
                       <li>LOCAL PAGE: {$page_cache}</li>
                       <li>VARNISH: Ok</li>
                       <li>OBJECT: Ok</li>
                       <li>PRESSCDN: {$cdn}</li>
                       <li>{$key_warning}</li>
               </ul>
				</div><br/>";

    }
    
   function _purge_presscdn($force = true) {
   	 $this->_update_domain_via_api($force);
	    $cdn = "No PRESSCDN found.";
	    if ( $this->pagely_options->domain->cdn_zone_id ) {
	       // purge the cdn as well
	      $result = $this->_pagely_api_request($method = 'POST', $uri = '/cdn_zone/purge', $params = array('id'	=> $this->pagely_options->domain->cdn_zone_id) );
			$result = json_decode($result);
			$cdn = $result->message;
       }
       
       return $cdn;
    }
   
   // check for bad billing
	function _billing_check() {
	// force a check on bad billing
		if ( false === ( $failed_invoices = get_transient( 'pagely_billing_check' ) ) ) {
			$check_expire_time = 60*30;	
			$failed_invoices = $this->_pagely_api_request($method = 'GET', $uri = '/account_invoices/failedbyaccount');
			$failed_invoices = json_decode($failed_invoices);
			//print_r($failed_invoices);
			set_transient( 'pagely_billing_check', $failed_invoices, $check_expire_time);
		}
		
		if ($failed_invoices && $failed_invoices->count > 0) {
			$this->_billing_problem($failed_invoices->count);
		}
	}
	
	function _show_bulletins() {
		$str = '';
		$bulletins = $this->_get_bulletins();
		$i = 0;
		if (!empty($bulletins->items) && is_array($bulletins->items) && count($bulletins->items) > 0 ) {
			foreach ($bulletins->items as $k => $v) {
				if ( $v['read'] == 0 && $v['date_expires'] > time()  ) {
					$url = admin_url("admin.php?page=bulletins&mark_read={$k}");
					$str .= "<br/>{$v['msg']} | <a href='{$url}'> Mark as Read</a>";
					$i++;
				}
			}
		}
		if (!empty($str)) {
			$i > 1 ? $stat = "Notices" : $stat = "Notice";
			echo "<div id='message' class='updated'><p><strong>Hosting System {$stat}</strong>{$str}</p></div>";
		}
		
	}
	//show bulletins
	function _list_bulletins() {
		
		$bulletins = $this->_get_bulletins(true);
		echo '<div class="wrap"><div id="icon-edit-comments" class="icon32"><br/></div><h2>Hosting System Notices</h2>';
		if (!empty($bulletins->items) && count($bulletins->items) > 0) {
			
		/*
			Should we use the one below, like we have in above function??
			if (is_array($bulletins->items) && count($bulletins->items) > 0 ) {
		*/	
			print('<table class="wp-list-table widefat" cellspacing="0">
						<thead>
							<tr>
								<th scope="col" id="cb" class="manage-column" style="">Posted</th>
								<th scope="col" id="cb" class="manage-column" style="">Message</th>
								<th scope="col" id="cb" class="manage-column" style="">Read</th>
							</tr>
						<thead>');
			
			foreach ($bulletins->items as $k => $v) { 
				$style = '';
				if ($v['read'] == 0) {
                                    	$url = admin_url("admin.php?page=bulletins&mark_read={$k}");
				 	$read = "<strong>No</strong> <br/><a href='{$url}'>Mark as Read</a>";
				 	$style = "style='background-color:#FFFFE0;'";
				} else {
					$read = "<strong>Yes</strong>";
				}
				print('<tr '.$style.'">
							<td>'.date(get_option('date_format'),$v['date_added']).'</td>
							<td>'.$v['msg'].'</td>
							<td>'.$read.'</td>
						</tr>');
				
			}					
			print('</table>');
						
			
		} else {
			echo "<p>No messages to display.</p>";
		}
		echo '</div>';
	}
	
	function _mark_bulletin_as_read($id) {
		$this->pagely_options->bulletins->items[$id]['read'] = '1';
		update_option('pagely_site_options',$this->pagely_options);
	}
		// call home for bulletins
	function _get_bulletins($force = false) {
		
		$interval = 60*60*3;
		
		// force an api call 
        if (empty($this->pagely_options->bulletins))
        {
            $this->pagely_options->bulletins = new stdClass;
            $this->pagely_options->bulletins->items = array();
        }

        $bulletins = $this->pagely_options->bulletins;
		
		//print_r($old_bulletins);
		
		if ($force || $this->pagely_options->bulletin_last_check < time() - $interval ) {
			$result = $this->_pagely_api_request($method = 'GET', $uri = '/reseller_bulletins/all', $params = array('api_key'	=> $this->api_key) );
			$result = json_decode($result);
			
			if ($result->count > 0) {
				
				foreach ($result->objects as $b) {
					$single = array();
					$single['msg'] = $b->msg;
					$single['read'] = 0; 
					$single['date_expires'] = $b->date_expires;
					$single['date_added'] = $b->date_added;
					
					if (!$bulletins->items[$b->id]) {
						$bulletins->items[$b->id] = $single; 
					}
				}
				krsort($bulletins->items);
			}
			
			
			$this->pagely_options->bulletin_last_check = time();
			if (!empty($bulletins->items)) {
				$this->pagely_options->bulletins = $bulletins;
			}
			update_option('pagely_site_options',$this->pagely_options);
		} 
		
		return $this->pagely_options->bulletins;		
	}
	
	function _hcs_cache_check() {
		if ( !function_exists('w3_instance') && !function_exists('wp_super_cache_text_domain') ) {
			// no cache running
			add_action('admin_notices', array(&$this, '_no_cache_warning'),20);
		}
	}
		
	function _flush_rewrite_rules() {
		global $wp_rewrite;
		//flush_rewrite_rules( 'true' );
		//$wp_rewrite->flush_rules();
	}
	
	/* some error messages and warning */
	function _no_cache_warning() {
			echo "<div class='error'><p><strong style='color:#cc0000'>Performance Warning:</strong> Page Caching is not enabled. Please install/activate W3 Total Cache Plugin OR WP Super Cache plugin under the Plugins menu. Contact support with any questions.</p></div>";
	}
	
	function _preferred_settings() {
		echo "<div id='message' class='updated'><p><strong>Performance Notice:</strong> Our system has set the preferred W3 Total Cache plugin settings for you.</p></div>";
	}
	
	function _no_db_cache() {
		echo "<div id='message' class='updated'><p><strong>No Database cache needed:</strong> This is not the Database Caching you are looking for. Our system takes care of this.</p></div>";
	}
	function _failed_hcs_connection() {
		echo "<div class='error'><p>There was an error talking to the mothership. Notify support if this persists for more than a 10 minutes</p></div>";
	}
	
	function _billing_problem($count) {
		echo "<div id='message' class='error'><p><strong>Hosting Billing Error:</strong> You have ({$count}) failed invoices. Your payment method was unable to be charged successfully. <u><a href='https://support.pagely.com/entries/361570-How-do-I-update-my-Billing-information' style='color:#000'>Please add a new payment method</a></u> now to avoid account suspension. Contact support with questions. This notice will update every 30 minutes.</p></div>";
	}
	
	function _failed_varnish_purge() {
		echo "<div class='error'><p>Varnish cache was not purged properly, please contact support.</p></div>";
	}
	
	function _enabled_hyper() {
		echo "<div id='message' class='updated'><p><strong>Switching to HyperDB</strong></p></div>";
	}
	
	function _domain_aliases() {
		echo "<div id='message' class='updated'><p><strong>Aliases mapped to web server. Sorry for the delay.</strong></p></div>";
	}
	
	function _varnish_purged() {
		echo "<div id='message' class='updated'><p><strong>Our Cache servers have been purged.</strong>. You may still wish to purge wp-super-cache or w3tc cache.</p></div>";
	}
	
	
	// CRONS
	function _crons() {
	
	//	if ( ! wp_next_scheduled( 'pagely_w3tc_cron' ) ) {
	//wp_schedule_event( time(), 'daily', 'pagely_w3tc_cron' );
	//	}

		//add_action( 'pagely_w3tc_cron', array(&$this, '_hcs_w3tc_config') );
	}
	
	
	
	
	
	// replace local jquery with goog's
	function _mod_js() {
		  if (!is_admin()) {
				wp_deregister_script( 'jquery' );
				wp_register_script( 'jquery', 'https://ajax.googleapis.com/ajax/libs/jquery/1.8.3/jquery.min.js');
				wp_enqueue_script( 'jquery' );
				
				wp_deregister_script( 'swfobject' );
           wp_register_script( 'swfobject', 'https://ajax.googleapis.com/ajax/libs/swfobject/2.2/swfobject.js');
           wp_enqueue_script( 'swfobject' );
		  }
	}
	
	// decide which url to use in the admin menu
	function _get_menu_url() {
		// check if plugin is in mu-plugins or just /plugins
		$plugin_name = plugin_basename(__FILE__);
		if ( is_plugin_active($plugin_name) ) {
			return '/wp-admin/admin.php?page=pagely_management.php';
		} else {
			return '/wp-admin/admin.php?page=pagely_management.php';
		}
	}
	
	function _update_domain_via_api($forced = false) {
		// if we dont know the domain id yet, get it.
        if (!isset($this->pagely_options->domain->domain_id) || !isset($this->pagely_options->account->account_id) )  {
			if (!empty($this->config["id"])) {
				$dom_id = $this->config["id"];
			} else {
				$path = WP_CONTENT_DIR;
				//$path['dirname'] = '/data/p01/dom5292/httpdocs'; // test
				if ( preg_match('/dom(\d+)/',$path,$match) ) {
					$dom_id = $match[1];
				}
				if (!$dom_id) {
					return false;
				}
			}
			// get the domid from photon
			$result = $this->_pagely_api_request($method = 'GET', $uri = '/domains/single', $params = array('id' => $dom_id,'inc_ext' => 1) );
			$domain = json_decode($result);
			
			if (isset($domain->id))	{
				//save locally for next time.
				$this->pagely_options->domain->domain_id = $dom_id;
				$this->pagely_options->account->account_id = $domain->account_id;
				update_option('pagely_site_options',$this->pagely_options);
			}		
		
		} 
		
		// call the api if forced or if transient has expired
		if ( $forced || false === ( $domain = get_transient( 'pagely_api_check' ) ) ) {
			$check_expire_time = 60*60*2;
			
			$result = $this->_pagely_api_request($method = 'GET', $uri = '/domains/single', $params = array('id' => $this->pagely_options->domain->domain_id,'inc_ext' => 1) );
			
			$domain = json_decode($result);
			set_transient( 'pagely_api_check', $domain, $check_expire_time);
		}

		// we should have a domain so rock and roll.
		if ($domain) {
			// update these main values
			$this->pagely_options->domain->domain_id = $domain->id;
			$this->pagely_options->account->account_id = $domain->account_id;
			
			// if we have a cdn update some options
			if ( isset($domain->ext->cdn_zone) ) {
				$this->pagely_options->domain->cdn_url = $domain->ext->cdn_zone->cdn_url;
				$this->pagely_options->domain->cdn_zone_id = $domain->ext->cdn_zone->id;
				$mapped_cnames = json_decode($domain->ext->cdn_zone->mapped_cnames);
				
				$my_cdn_cnames[] = $this->pagely_options->domain->cdn_url;
				foreach ($mapped_cnames as $cnames) {
					$my_cdn_cnames[] = $cnames->cname;
				}
				
				$this->pagely_options->domain->cdn_endpoints = array_unique($my_cdn_cnames);
	
			} else {
				$this->pagely_options->domain->cdn_url = false;
				$this->pagely_options->domain->cdn_zone_id = false;
				$this->pagely_options->domain->cdn_endpoints = false;
			}
			
			update_option('pagely_site_options',$this->pagely_options);
		}		
		
	}
	
	// enable a CDN in wordpress
	function _enable_cdn_wp($forced = false) {
		
		$this->_update_domain_via_api($forced);		
		
		// now just check the options and lets rock
		
		if ( isset($this->pagely_options->domain->cdn_endpoints) ) {
			$values = array(
			 'cdn.enabled' => true,
			 'cdn.debug' => false,
			 'cdn.engine' => 'mirror',
			 'cdn.uploads.enable' => true,
			 'cdn.includes.enable' => true,
			 'cdn.theme.enable' => true,
			 'cdn.minify.enable' => true,
			 'cdn.custom.enable' => true,
			 'cdn.canonical_header' => true,
			 'cdn.mirror.domain' => array_unique($this->pagely_options->domain->cdn_endpoints)
			);
			
						 	         
			// will do nothing if w3tc plugin does not exist
			if ( $this->_hcs_w3tc_config($values) ) {
				// purge the 
			   $this->_cache_purge();
				return 'YES';
			} else {
				return 'Failed Config';
			}
		}
		
		return 'Failed API lookup';
	}
	
	// disable a CDN in wordpress
	function _disable_cdn_wp($forced = false) {
	
		$this->_update_domain_via_api($forced);

		$values = array(
			 'cdn.enabled' => false,
			 'cdn.engine' => 'mirror',
			 'cdn.mirror.domain' => array(),
			);
		
		// will do nothing if w3tc plugin does not exist
		
	         
		if ( $this->_hcs_w3tc_config($values) ) {
			// purge the cache
		   $this->_cache_purge();
			return 'YES';
		}
		
		return 'Failed';
		
	}
	
	
	// force a config on w3tc
	function _hcs_w3tc_config($values = false)  {	
		
		if (!$values) {
         $values = $this->_get_w3tc_settings();
      }
                
		if (function_exists('w3_config_save')) {
			$current_config = new W3_Config();
			$config_admin = new W3_ConfigAdmin();
			$new_config = $current_config;
			
			foreach ($values as $key => $val) {
				$new_config->set($key,$val);
			}
			 
	     	w3_require_once(W3TC_INC_FUNCTIONS_DIR . '/admin.php');
		   w3_config_save($current_config, $new_config, $config_admin);
			return true;
		}
		
		return false;
	}
	
	function _push_domain_aliases() {
	
			
	}
		
	function _get_w3tc_settings() {
		
		if ( defined('ALLOW_W3TC') ) {
			return array();
		}
		
      $memcached_servers = explode(',',PMEMCACHED_SERVERS);

		$pagecache = 'memcached';
			
		// varnish nodes
		$varnish_servers = explode(',',VARNISH_SERVERS);
		// some array elements will be commented out, 
		// we dont want to overwrite their settings

		return array(
	
	'dbcache.enabled' => false,
	'dbcache.memcached.servers' => array(
		0 => '127.0.0.1:11211',
	),
	'objectcache.enabled' => false,
	'objectcache.memcached.servers' => $memcached_servers,
	'fragmentcache.engine' => ''.$pagecache.'',
	'fragmentcache.memcached.servers' => $memcached_servers,
	'pgcache.enabled' => true,
	'pgcache.engine' => ''.$pagecache.'',
	'pgcache.cache.404' => true,
	'pgcache.memcached.servers' => $memcached_servers,
	'pgcache.cache.ssl' => true,
	'minify.engine' => ''.$pagecache.'',
	'minify.memcached.servers' => $memcached_servers,
	'varnish.enabled' => false,
    /*
	'cdn.custom.files' => array(
		0 => 'favicon.ico',
		1 => 'wp-content/plugins/*.js',
		2 => 'wp-content/plugins/*.css',
		3 => 'wp-content/plugins/*.gif',
		4 => 'wp-content/plugins/*.jpg',
		5 => 'wp-content/plugins/*.png',
		6 => 'wp-content/uploads/*',
		7 => 'wp-content/*',
    ),
     */
	'browsercache.enabled' => false,
	'browsercache.cssjs.last_modified' => true,
	'browsercache.cssjs.compression' => true,
	'browsercache.cssjs.expires' => true,
	'browsercache.cssjs.lifetime' => 31536000,
	'browsercache.cssjs.cache.control' => true,
	'browsercache.cssjs.cache.policy' => 'cache_maxage',
	'browsercache.cssjs.w3tc' => false,
	'browsercache.html.compression' => true,
	'browsercache.html.last_modified' => true,
	'browsercache.html.expires' => true,
	'browsercache.html.lifetime' => 86000,
	'browsercache.html.cache.control' => true,
	'browsercache.html.cache.policy' => 'cache_maxage',
	'browsercache.html.w3tc' => false,
	'browsercache.other.last_modified' => true,
	'browsercache.other.compression' => true,
	'browsercache.other.expires' => true,
	'browsercache.other.lifetime' => 31536000,
	'browsercache.other.cache.control' => true,
	'browsercache.other.cache.policy' => 'cache_maxage',
	'browsercache.other.w3tc' => false,
	'common.tweeted' => true,
	'minify.options' => array(
		'minApp' => array(
			'allowDirs' => array(
				0 => '/wordpress',
				1 => rtrim($_SERVER['DOCUMENT_ROOT'], '/')
			),
		),
	),

	/*'newrelic.enabled' => false,
	'newrelic.api_key' => '',
	'newrelic.account_id' => '',
	'newrelic.application_id' => 0,
	'newrelic.appname' => '',
	'newrelic.accept.logged_roles' => true,
	'newrelic.accept.roles' => array(
		0 => 'contributor',
	),
	'newrelic.use_php_function' => false,
	'notes.new_relic_page_load_notification' => true,
	'newrelic.appname_prefix' => 'Child Site - ',
	'newrelic.merge_with_network' => true,
	'newrelic.cache_time' => 5,
	'newrelic.enable_xmit' => false,
	'newrelic.use_network_wide_id' => false,*/
);

	}

} # end HCS Class

/*
Plugin Name: WordPress Varnish
Plugin URI: http://github.com/pkhamre/wp-varnish
Version: 0.3
Author: <a href="http://github.com/pkhamre/">Pål-Kristian Hamre</a>
Description: A plugin for purging Varnish cache when content is published or edited.

Copyright 2010 Pål-Kristian Hamre  (email : post_at_pkhamre_dot_com)
*/
class HCSVarnish {
	public $wpv_addr_optname;
	public $wpv_port_optname;
	public $wpv_timeout_optname;
	public $wpv_update_pagenavi_optname;
	public $wpv_update_commentnavi_optname;
	
	function HCSVarnish() {
		global $post;
		
		$this->wpv_addr_optname = "HCSVarnish_addr";
		$this->wpv_port_optname = "HCSVarnish_port";
		$this->wpv_timeout_optname = "HCSVarnish_timeout";
		$this->wpv_update_pagenavi_optname = "HCSVarnish_update_pagenavi";
		$this->wpv_update_commentnavi_optname = "HCSVarnish_update_commentnavi";
		$this->wpv_timeout = 5;
		
		if ( isset($_GET['w3tc_note']) && preg_match('/flush/i',$_GET['w3tc_note'],$matches) ) {
			$this->HCSVarnishPurgeAll();
			$hcs = new HCS;
			$hcs->_purge_presscdn();
		}
		
		// ajax handler
		
		add_action("admin_footer", array(&$this,'HCSVarnishShowLink') );
		// purge varnish action
		add_action('wp_ajax_purge_varnish', array(&$this, 'HCSVarnishPurgeAll' ) );

        if (defined('PAGELY_DISABLE_VARNISH_HOOKS') && PAGELY_DISABLE_VARNISH_HOOKS)
            return;
		
        // when editing menus purge all on shutdown, each menu udpate triggers edit_post, so we can't use the standard approach
        if ($_SERVER['SCRIPT_NAME'] == '/wp-admin/nav-menus.php')
        {
            if ($_SERVER['REQUEST_METHOD'] == 'POST')
            { 
                add_action("shutdown", array(&$this, "HCSVarnishPurgeAll"));
            }
        }
		// When posts/pages are published, edited or deleted purge
        else
        {
            add_action('edit_post', array(&$this, 'HCSVarnishPurgePost'), 99);
            add_action('edit_post', array(&$this, 'HCSVarnishPurgeCommonObjects'), 99);
            add_action('edit_post', array(&$this, 'HCSVarnishPurgePostComments'),99);
        }
		
		// When comments are made, edited or deleted
		add_action('comment_post', array(&$this, 'HCSVarnishPurgePostComments'),99);
		add_action('edit_comment', array(&$this, 'HCSVarnishPurgePostComments'),99);
		add_action('trashed_comment', array(&$this, 'HCSVarnishPurgePostComments'),99);
		add_action('untrashed_comment', array(&$this, 'HCSVarnishPurgePostComments'),99);
		add_action('deleted_comment', array(&$this, 'HCSVarnishPurgePostComments'),99);
		add_action('wp_set_comment_status', array(&$this, 'HCSVarnishPurgePostComments'),99);

		// When posts or pages are deleted
		add_action('deleted_post', array(&$this, 'HCSVarnishPurgePost'), 99);
		add_action('deleted_post', array(&$this, 'HCSVarnishPurgeCommonObjects'), 99);
	}
  
	function HCSVarnishShowLink() {
		if ( function_exists('wp_super_cache_text_domain') ) {
			if ( isset($_GET['page']) && $_GET['page'] == 'wpsupercache')  { ?>					
				<script type='text/javascript'>
				jQuery(document).ready(function($) {
					$('.wrap #nav').before('<span><a href="#" id="purgevarnish">Click to purge the Varnish cache.</a> This may be independent of WP super cache purge</span>'); 
					$('#purgevarnish').live('click', function () {
					var data = {
						action: 'purge_varnish',
					};
					$.post(ajaxurl, data, function(response) {
						alert('All content and assets purged. '+response);
					});							
					});
				});
				</script>
	<?php } } // end fun_exists, and GET check 
	}

	function HCSVarnishPurgeCommonObjects() {
		$this->HCSVarnishPurgeObject("/");
		$this->HCSVarnishPurgeObject("/(.*)/feed(.*)");
		//$this->HCSVarnishPurgeObject("/feed/atom/");
	}
	
	// HCSVarnishPurgePostComments - Purge all comments pages from a post
	function HCSVarnishPurgePostComments($wpv_commentid) {
		$comment = get_comment($wpv_commentid);
        if (empty($comment))
            return;

		$wpv_commentapproved = $comment->comment_approved;
		
		// If approved or deleting...
		if ($wpv_commentapproved == 1 || $wpv_commentapproved == 'trash') {
			$wpv_postid = $comment->comment_post_ID;
			
			// get the post too
			$this->HCSVarnishPurgePost($wpv_postid);
		
			// Popup comments
			$this->HCSVarnishPurgeObject('/\\\?comments_popup=' . $wpv_postid);
		
			// Also purges comments navigation
			if (get_option($this->wpv_update_commentnavi_optname) == 1) {
				$this->HCSVarnishPurgeObject('/\\\?comments_popup=' . $wpv_postid . '&(.*)');
			}
		
		}
	}

	// HCSVarnishPurgeAll - Using a regex, clear all blog cache. Use carefully.
	function HCSVarnishPurgeAll() {
		$this->HCSVarnishPurgeObject('/');
		$this->HCSVarnishPurgeObject('/(.*)');
	}
	
	// HCSVarnishPurgePost - Takes a post id (number) as an argument and generates
	// the location path to the object that will be purged based on the permalink.
	function HCSVarnishPurgePost($wpv_postid) {
	  	$wpv_url = get_permalink($wpv_postid);
                $host = str_replace(array('http://','https://'),'',get_bloginfo('wpurl'));
                $wpv_permalink = str_replace(array($host,'http://','https://'),"",$wpv_url);
                $this->HCSVarnishPurgeObject($wpv_permalink);

		//$wpv_url = get_permalink($wpv_postid);
		//$wpv_permalink = str_replace(get_bloginfo('wpurl'),"",$wpv_url);
		//$this->HCSVarnishPurgeObject($wpv_permalink);
  	}

	// HCSVarnishPurgeObject - Takes a location as an argument and purges this object
	// from the varnish cache.
    function HCSVarnishPurgeObject($wpv_url)
    {
        if (empty($this->config))
        {
            $infofile = "/info.json";
            if (!file_exists($infofile) && preg_match('@^/data/s[0-9]+/dom[0-9]+@', __DIR__, $match))
            {
                // we are running in a non chroot setup
                $infofile = "$match[0]/info.json";
            }
            $this->config = json_decode(file_get_contents($infofile), true);
        }

        $hcs_varnish_servers = explode(',',VARNISH_SERVERS);

        $servers = array();
		if ( is_array($hcs_varnish_servers ) ){
			foreach ($hcs_varnish_servers as $server ) {
				list ($host, $port) = explode(':', $server);
				$servers[] = array($host,$port);
			}
		} else {
			add_action('admin_notices', array(&$this, '_failed_varnish_purge') ,20);
            return false;
        }
		
        $site_url = get_site_url();
        $host = parse_url($site_url, PHP_URL_HOST);
        $site_path = parse_url($site_url, PHP_URL_PATH);
        $path = str_replace('//','/', "$site_path$wpv_url");

        $http = _wp_http_get_object();

		$response = '';

        $headers = array('Host' => $host);
        foreach($servers as $server)
        {
            $scheme = 'http';
            if ($server[1] == '443')
            {
                $scheme = 'https';
                $key = $this->config['apiKey'];
                $rand = sha1(mt_rand().microtime());
                $hash = sha1($rand.$this->config['apiSecret']);

                $headers["Authorization"] = "PAGELY $key $rand $hash";
            }

            $response = $http->request("$scheme://$server[0]$path", array('method' => 'PURGE', 'timeout' => 3, 'httpversion' => '1.1', 'headers' => $headers));
            //echo "$scheme://$server[0]$path {$response['response']['code']}<br>";
        }
		return true;
		//return $response;
	}
}

// load the plugin
add_action( 'plugins_loaded', array( 'HCS', 'init' ) );

//purge comments
add_action('comment_post', 'force_purge');
function force_purge($id) {
 $V = new HCSVarnish;
 $V->HCSVarnishPurgePostComments($id);
}


// bust the object cache
add_action('login_form','test_cachebuster');
function test_cachebuster() {
	if ( ( isset($_SERVER['SCRIPT_NAME']) && $_SERVER['SCRIPT_NAME'] == '/wp-login.php' )
		&& isset($_GET['pagely_cachebuster'])
		&& isset($_GET['timestamp'] ) ) {

		$time_stamp = (int) $_GET['timestamp'];
		$hash = hash_hmac('sha1', ('Clear-The-Cache'.$time_stamp), get_option('pagely-apikey'));
		$str = "Pagely | ";
		if( ( $time_stamp < time() + 240 ) AND ( $time_stamp > time() - 240) ){
		       $str .= "TRUE: TimeStamp passed | ";
		
			if ($hash == $_GET['pagely_cachebuster']) {
			      $hcs = new HCS;
			
		      if ( isset($_GET['action']) ) {
		      	$action = $_GET['action'];
	            switch ($action) {
	                      case "enable_cdn_wp":
	                              $str .= $hcs->_enable_cdn_wp(true);
	                              $str .= " CDN ENABLED | ";
	                      break;
	                      case "disable_cdn_wp":
	                              $str .= $hcs->_disable_cdn_wp(true);
	                              $str .= " CDN DISABLED | ";
	                      break;
	              }
		      }
			
		      wp_cache_flush();
		      $str .= "TRUE: Object CACHE FLUSHED ";
			
			
			} else {
			      $str .= "FAIL: Key Validation ";   
			}
		
		} else {
		       $str .= "FAIL: Salt Failed | ";
		}
		echo "<div class='message' style='margin-left:0'><p>$str</p></div>";
	}
}


function pagely_login_fail(){
        // there is some odd case with ajax logins where this doesn't exist, odd
        if (!function_exists('wp_cache_incr'))
            return;

        // store the IP in , and increment failed login attempts.
        $key = "fl".md5($_SERVER['REMOTE_ADDR']);
        $data = 1;
        $group = "failed_login_attempts";

        $howmany = wp_cache_get($key,$group);
        if (false === wp_cache_get($key,$group)) {
                $expire = 86400;
                wp_cache_add($key, $data, $group, $expire);
                $howmany = 1;
        } else {
                wp_cache_incr($key, $n = 1, $group);
        }
        // if too many, drop a cookie that varnish/zues will can use to drop the connection.
        if ($howmany >= 6 && $howmany < 12) {
                if (!isset($_COOKIE['wp_fl'])) {
                        $hour = 60*3;
                        setcookie('wp_fl', $key, time()+$hour);
                }
        } else if ($howmany >= 12 && $howmany < 20) {
                        $day = 60*60*12;
                        setcookie('wp_fl', $key, time()+$day);

        } else if ($howmany >= 20) {
                        $tendays = 60*60*24*10;
                        setcookie('wp_fl', $key, time()+$tendays);

        }
}
add_action('wp_login_failed', 'pagely_login_fail');



// Purge stuff, transient Code used from

/*
Plugin Name:  Purge Transients
Description:  Purge old transients
Version:      0.2
Author:       Seebz https://github.com/Seebz/Snippets/blob/master/Wordpress/plugins/purge-transients/purge-transients.php
*/

if ( ! function_exists('HCS_db_cleanup') ) {
	function HCS_db_cleanup($older_than = '1 days', $safemode = false) {
		global $wpdb;

		$older_than_time = strtotime('-' . $older_than);
		if ($older_than_time > time() || $older_than_time < 1) {
			return false;
		}

		$transients = $wpdb->get_col(
			$wpdb->prepare( "
					SELECT REPLACE(option_name, '_transient_timeout_', '') AS transient_name 
					FROM {$wpdb->options} 
					WHERE option_name LIKE '\_transient\_timeout\__%%'
						AND option_value < %s
			", $older_than_time)
		);
		
		if ($safemode) {
		      foreach($transients as $transient) {
		              get_transient($transient);
		      }
		} else {
		      $option_names = array();
		      foreach($transients as $transient) {
		              $option_names[] = '_transient_' . $transient;
		              $option_names[] = '_transient_timeout_' . $transient;
		      }
		
		      if (count($option_names) > 1) {
		              $options = array_map(array($wpdb, 'escape'), $option_names);
		              $options = "'". implode("','", $options) ."'";
		
		              $result = $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name IN ({$options})" );
		        

		             /* if (!$result) {
		                      return false;
		              }*/
		      }
		}
		
		// nuke other transients
		$result = $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%_site_transient_%'" );
		
		// lets nuke old post revisions as well, older than 90 days.
		$revs = $wpdb->query('DELETE a,b,c FROM wp_posts a
LEFT JOIN wp_term_relationships b ON (a.ID = b.object_id)
LEFT JOIN wp_postmeta c ON (a.ID = c.post_id)
WHERE a.post_type = "revision" and post_modified_gmt < DATE_SUB(CURDATE(),INTERVAL 90 DAY)');

		// remove spam comments older than 3 days
		$spam = $wpdb->query('DELETE a,b FROM wp_comments a LEFT JOIN wp_commentmeta b ON (a.comment_ID = b.comment_id) WHERE comment_approved = "spam" and comment_date < DATE_SUB(CURDATE(),INTERVAL 3 DAY);');

		

        if (ABSPATH == '/wordpress')
            $log_file = '/mnt/log/transients_purge.log';
        else
            $log_file = ABSPATH.'/.transients_purge.log';

        $out = "\r\nPURGE TRANSIENTS|{$wpdb->options}\r\nWHEN|".date('Y-m-d H:i:s',time())."\r\n";
        file_put_contents ($log_file,$out,FILE_APPEND);
		return $transients;
	}
}

function HCS_db_maint() {
	
		$scan_time = get_option('pagely_pscan');
		if (!$scan_time) {
				update_option( 'pagely_pscan', time()+60*60*24*rand(1,3) );							

		}elseif ($scan_time < time() ) {			
			update_option( 'pagely_pscan', time()+60*60*24*rand(1,3) );							
		 	HCS_db_cleanup();
		}	
}
add_action( 'shutdown', 'HCS_db_maint',20);

// remove v.#
remove_action('wp_head', 'wp_generator');


// leaving off closing php tag
