<?php
/*
Plugin Name: HostedClientService
Plugin URI: http://hostedclientservice.com/
Description: Your Managed Hosting Panel, You should not disable it.
Version: 1.9
Author: HCS
Author URI: http://hostedclientservice.com

*/
// Copyright (c) 2009-2011 Obu Web Technologies Inc., Joshua Strebel
//
// Obu Web Technologies: http://obuweb.com
// DBA: Page.ly WordPress Hosting: http://page.ly
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

		
# HCS Class
class HCS {
	
	var $hcs_url = 'https://hostedclientservice.com';
	var $hcs_url_n = 'http://hostedclientservice.com';
	var $hcs_key = null;
	var $hcs_skey = null;
	var $hcs_sdomain = null;
	var $hcs_rsinfo = null;
	var $hcs_varnish_servers = array( '199.47.221.226:80' , '199.47.221.66:80' );

	function &init() {
		static $instance = false;
		if ( !$instance && is_admin() ) {
			$instance = new HCS;
			// load the Varnish sub class as well
			new HCSVarnish;
		}
		
		
		return $instance;

	}
	
	// main init to run on admin screens
	function HCS() {
                
		global $wp_rewrite,$wp_version,$post;

		//echo 'loaded';
		$this->hcs_key = get_option('page.ly');
		$this->hcs_skey = md5( $this->hcs_key . strftime( 'YmdH' ) );
		if ( is_multisite() ) {
			$basedomain = get_site_url(1);
		} else {
			$basedomain = get_option('siteurl');
		}
		
		$this->hcs_sdomain = str_replace( array( 'http://','https://','www.') ,'', strtolower($basedomain) );
		// Get any existing copy of our transient data
		if ( false === ( $this->hcs_rsinfo = get_transient( 'hcs_rsinfo' ) ) ) {
	
			// It wasn't there, so regenerate the data and save the transient
			
			if( !class_exists( 'WP_Http' ) ) {
				include_once( ABSPATH . WPINC. '/class-http.php' );
			}
			$request = new WP_Http;
			$result = $request->request( $this->hcs_url_n.'/rsinfo/g/'.$this->hcs_sdomain , array( 'timeout' => 15000) );
			//print_r($result);
			if ( !is_wp_error($result) ) {
				$this->hcs_rsinfo = json_decode( $result['body'] );	
				// set the transient for only 3 minutes
				set_transient( 'hcs_rsinfo', $this->hcs_rsinfo , 60*5 );							
			} else {
				add_action('admin_notices', array(&$this, '_failed_hcs_connection'), 20);
			}
			//echo 'foo';
		} 
		
		if ( isset($_GET['page']) && preg_match('/^w3tc_/i',$_GET['page'],$matches) ) {
			add_action('admin_footer',array(&$this, '_hcs_w3tc_config'), 99 );
		}
		// load up the actions needed.
		if ( current_user_can('administrator') ) {
			add_action('admin_notices', array(&$this, '_render_nav'), 20);
			add_action('admin_print_styles', array(&$this, '_head_styles'),20);
			add_action('admin_menu', array(&$this, '_create_menu') ,20); 
		}
		
		add_action('admin_footer', array(&$this, '_footer_scripts'),20);
		// make sure a cache is running
		add_action('init', array(&$this, '_hcs_cache_check'),20);
		
		// set crons
		add_action('wp', array(&$this, '_crons') );
		add_action('hcs_disable_bad_plugins', array(&$this, '_check_bad_plugins') );
		add_action('hcs_cache_settings', array(&$this, '_hcs_w3tc_config') );
		
		// mod js
		add_action('wp_enqueue_scripts', array(&$this,'_mod_js') );
 
		// remove v.#
		remove_action('wp_head', 'wp_generator');
		
		// dashboard screen
		if ($this->hcs_rsinfo->site_name == 'page.ly') {
			add_action('wp_dashboard_setup',array(&$this, '_pagely_dashboard_rss') );
		}
		
		add_action('admin_footer',array(&$this, '_hyperdb_check'), 0 );	 
		
		// push domain aliases			
		if (isset($_GET['page']) && $_GET['page'] == 'dm_domains_admin') {
			add_action('admin_footer', array(&$this, '_push_domain_aliases') );
			
		}
		 
		register_deactivation_hook(__FILE__, array(&$this, '_hcs_deactivation') );
		 
		return;

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
	
	// render the nav bar for our plugin
	function _render_nav() {
		if ( current_user_can('administrator') ) {
			$options_page = $this->_get_menu_url();
			$bulletin = "";
			if ( isset($this->hcs_rsinfo->error_msg) ) {
				$error_msg = str_replace("{{link}}",$options_page,$this->hcs_rsinfo->error_msg);
				$bulletin = "<div class='error'><p><span></span><strong style='color:#cc0000'>Hosting Message:</strong> ".$error_msg."</p></div>";
			} else if (isset($this->hcs_rsinfo->statusmessage)) {
				$bulletin = "<div id='message' class='updated'><p><span></span><strong>Hosting Message:</strong> ".$this->hcs_rsinfo->statusmessage."</p></div>";
			}
				print($bulletin);
		}		
	}
	
	// script to hide w3tc box
	function _footer_scripts() { ?>
		<script type='text/javascript'>
			jQuery(document).ready(function($) {
				
		<?php 
		if ( isset($_GET['page']) && $_GET['page'] == 'w3tc_general' ) {
		?>
			$('#w3tc.wrap div.postbox').each(function (index,el) {
				h3content = $(el).children('h3').html();
				//alert($(h3content).html());
				var regmatch = /Database Cache|Object Cache|Varnish/i.test(h3content);
				if (regmatch) {
					$(el).children('h3').append(' <em class="hcs_w3tc_notice">- Powered by your Hosting system <a href="#" id="postbox'+index+'" class="hcs_show_postbox">So what show me anyways</a></em>');
					$(el).children('div.inside').addClass('postbox'+index).hide();
					$(el).find('input.enabled').click( function() { $(this).attr("disabled", true).val(0); alert('This setting is not needed, please leave it turned off. Your hosting system provides this advanced feature on the system level for you.')});
					
				}
				
				// notice
				var pagematch = /Page Cache/i.test(h3content);
				if(pagematch) {
					$(el).children('h3').append(' <em class="hcs_w3tc_notice">- Preferred Setting is On > Disk:Basic.</em>');
				}

				var browsermatch = /Browser/i.test(h3content);
				if(browsermatch) {
					$(el).children('h3').append(' <em class="hcs_w3tc_notice">- Preferred Settings are: On > But leave gzip compression off.</em>');
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
				$('#w3tc').append('<p class="hcs_notice"><em>Your sweet WordPress hosting service takes care of this for you. You are welcome.</em><p><p class="hcs_notice">So what! <a href="#" id="hcs_show">Show me this menu anyways</a></p>');
				$('#hcs_show').click( function() {$('#w3tc form').fadeIn(); $('.hcs_notice').hide();});
				
		<?php } echo '});</script>';
	}

	// render styles for our nav bar
	function _head_styles() {
		//wp_enqueue_style( 'thickbox');
		 //wp_print_styles();
		global $wp_version;
		if ( current_user_can('manage_options') ) {
			$this->hcs_rsinfo->css = json_decode($this->hcs_rsinfo->css );
			
		?>
		<style type='text/css'>
			.hcs_w3tc_notice {color:black;background:#fff8bc;font-size:80%;}
			iframe#hostingpanel {border:0px;}
			</style>
		<?php
		}
	}
	
	// create custom plugin settings menu
	function _create_menu() {
		//create new top-level menu
		add_menu_page('Hosting Management', 'Hosting Panel', 'administrator', __FILE__, array(&$this, '_settings_page'),'',0); 
	}
	
	// our iframe
	function _settings_page() {	
		print('<div class="wrap">
		<h2>Hosting Management Control Panel</h2>
		<iframe id="hostingpanel" src="'.$this->hcs_url.'/amod/account/'.$this->hcs_skey.'/'.$this->hcs_sdomain.'" width="100%" height="650"></iframe>
		</div>');
	}
	
	
	function _hcs_cache_check() {
		if ( !class_exists('W3_Plugin_TotalCache') && !function_exists('wp_super_cache_text_domain') ) {
			// no cache running
			add_action('admin_notices', array(&$this, '_no_cache_warning'),20);
		}
	}
	
	function _hcs_w3tc_config() {
		//ini_set('display_errors',1);
		//error_reporting(E_ALL ^ E_NOTICE ^ E_USER_NOTICE);
		global $wp_rewrite;
		if (class_exists('W3_Plugin_TotalCache')) {
				
			if (file_exists(W3TC_CONFIG_PREVIEW_PATH) ) {
				@unlink(W3TC_CONFIG_PREVIEW_PATH);
			}
			
				// check for which w3tc plugin v is working..	
			if (method_exists('W3_Plugin_TotalCache', 'instance')) {
				$w3tc = & W3_Plugin_TotalCache::instance();
				$current_w3tc_config = & W3_Config::instance();

			} elseif (function_exists('w3_instance')) {
				$w3tc = & w3_instance('W3_Plugin_TotalCache');
				$w3tc_admin = & w3_instance('W3_Plugin_TotalCacheAdmin');
				$current_w3tc_config = & w3_instance('W3_Config');
			} else {
				return false;
			}
			
			// this holds the currently loaded config
			$current_w3tc_config->load();
			
			// assign to a new object we will change
			$new_w3tc_config = $current_w3tc_config;
				  
			foreach ( $this->_get_w3tc_settings() as $key => $value ) {
				$new_w3tc_config->set($key,$value);
			}
			
			// save config
			if (method_exists('W3_Plugin_TotalCacheAdmin','config_save')) {
				$w3tc_admin->config_save( &$current_w3tc_config, &$new_w3tc_config,false );
				$w3tc_admin->flush_all();
			} else {
				// old way
				$w3tc->config_save( &$current_w3tc_config, &$new_w3tc_config,false );
				$w3tc->flush_all();
			}
			
			// on w3tc save			
			if ( isset($_GET['w3tc_note']) && $_GET['w3tc_note'] == 'config_save') {
				add_action('admin_notices', array(&$this, '_preferred_settings'),20);
			}
			//add_action('admin_footer', array(&$this, '_flush_rewrite_rules') );
		}		 
			 
	}
	
	function _flush_rewrite_rules() {
		global $wp_rewrite;
		//flush_rewrite_rules( 'true' );
		//$wp_rewrite->flush_rules();
	}
	
	/* some error messages and warning */
	function _no_cache_warning() {
			echo "<div class='error'><p><strong style='color:#cc0000'>Performance Warning:</strong> Page Caching is not enabled. Please install/activate <strong>W3 Total Cache Plugin</strong> (<em>Preferred</em>) OR WP Super Cache plugin under the Plugins menu. Contact support with questions.</p></div>";
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
	
	function _failed_varnish_purge() {
		echo "<div class='error'><p>Varnish cache was not purged properly, please contact support.</p></div>";
	}
	
	function _enabled_hyper() {
		echo "<div id='message' class='updated'><p><strong>Switching to HyperDB</strong></p></div>";
	}
	
	function _domain_aliases() {
		echo "<div id='message' class='updated'><p><strong>Aliases mapped to web server. Sorry for the delay.</strong></p></div>";
	}
	function _no_bad_plugins() {
		echo "<div id='message' class='error'><p>One or more plugins have been removed by our system. We do not allow most statistics plugins due to overuse of mysql db. Use Google Analytics, or some other offsite stats tool instead.</p></div>";
	}
	
	// make a request home for a JSON encoded configuration for w3tc
	function _get_w3tc_settings() {
		$url = $this->hcs_url_n."/rsinfo/cacheconfig/";
		$request = new WP_Http;
		if ( !is_wp_error($result) ) {
			$result = $request->request( $url , array( 'timeout' => 15000)	 );
			$json = $result['body'];
			return json_decode($json);
		} else {
			//add_action('admin_notices', 'failed_hcs_connection',20);
		}
	}
	
	// CRONS
	function _crons() {
		if ( !wp_next_scheduled( 'hcs_disable_bad_plugins' ) ) {
			wp_schedule_event(time(), 'daily', 'hcs_disable_bad_plugins' );
		}
	
		if ( !wp_next_scheduled( 'hcs_cache_settings' ) ) {
			wp_schedule_event(time(), 'daily', 'hcs_cache_settings' );
		}
	}
	
	// calls deactivate plugins and grabs our list
	function _check_bad_plugins() {
		add_action('shutdown', array(&$this, '_remove_plugins'),20);
	}
	function _remove_plugins() {
        	if (function_exists('deactivate_plugins')) {
			deactivate_plugins( $this->_get_list_of_banned_plugins() );
			$d = delete_plugins( $this->_get_list_of_banned_plugins() );

		} else {
			require_once( ABSPATH . 'wp-admin/includes/plugin.php');	
	                require_once( ABSPATH . 'wp-admin/includes/file.php');
			deactivate_plugins( $this->_get_list_of_banned_plugins() );
			$d = delete_plugins( $this->_get_list_of_banned_plugins() );

		}
		//	add_action('admin_footer', array(&$this, '_no_bad_plugins'),20 );
	}
	
	
	// register a hook to remove crons we may have added
	function _hcs_deactivation() {
		wp_clear_scheduled_hook(array(&$this, '_disable_bad_plugins') );
		wp_clear_scheduled_hook(array(&$this, '_cache_settings') );
	}
	
	// make a request home for a JSON encoded configuration for w3tc
	function _get_list_of_banned_plugins() {
			$url = $this->hcs_url_n."/rsinfo/bannedplugins/";
			$request = new WP_Http;
			$result = $request->request( $url , array( 'timeout' => 15000) );
			if ( !is_wp_error($result) ) {
	
				$json = $result['body'];
				return json_decode($json);
			} else {
				//add_action('admin_notices', 'failed_hcs_connection',20);
			}
	}
	
	
	// ping our tracking script
	function _tracking() {
	 print("<script type='text/javascript' src='http://hostedclientservice.com/ct/track/".$this->hcs_sdomain."'></script>\n");
	}
	//add_action('wp_footer','_tracking');
	
	
	// replace local jquery with goog's
	function _mod_js() {
		  if (!is_admin()) {
				wp_deregister_script( 'jquery' );
				wp_register_script( 'jquery', 'https://ajax.googleapis.com/ajax/libs/jquery/1.7.2/jquery.min.js');
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
			return '/wp-admin/admin.php?page=hcs-client/hcs.php';
		} else {
			return '/wp-admin/admin.php?page=hcs.php';
		}
	}
	
	
	// make sure we a running hyperdb.
	/* This will first check for and deactivate w3tc db caching
	* then write our hyperdb db-config.php and wp-content/db.php
	* even if w3tc added wp-content/db.php itself
	*/
	function _hyperdb_check() {	
	
		// check for w3tc
		if (class_exists('W3_Plugin_TotalCache')) {
	
				// check for which w3tc plugin v is working..	
			if (method_exists('W3_Plugin_TotalCache', 'instance')) {
				$w3tc = & W3_Plugin_TotalCache::instance();
				$current_w3tc_config = & W3_Config::instance();

			} elseif (function_exists('w3_instance')) {
				$w3tc = & w3_instance('W3_Plugin_TotalCache');
				$w3tc_admin = & w3_instance('W3_Plugin_TotalCacheAdmin');
				$current_w3tc_config = & w3_instance('W3_Config');
			} else {
				return false;
			}
			
						
			// this holds the currently loaded config
			$current_w3tc_config->load();
			
			// assign to a new object we will change
			$new_w3tc_config = $current_w3tc_config;
			 
				
			if ( $current_w3tc_config->get_boolean('dbcache.enabled') ) {
				$current_w3tc_config->set('dbcache.enabled',false);		
				// save config
				if (method_exists('W3_Plugin_TotalCacheAdmin','config_save')) {
					$w3tc_admin->config_save( &$current_w3tc_config, &$new_w3tc_config,false );
					$w3tc_admin->flush_all();
	
				} else {
					// old way
					$w3tc->config_save( &$current_w3tc_config, &$new_w3tc_config,false );
					$w3tc->flush_all();
				}
	
				//nadd_action('admin_notices', '_no_db_cache',20);
				add_action('admin_footer', array(&$this, '_flush_rewrite_rules') );

				//redirect after flush
				//wp_redirect( home_url().'/wp-admin', 302 );

				//exit;
			} 
	
		}
	
		if( class_exists( 'Hyperdb' ) ) {
			// we have hyperdb
			if( WP_SHARD == false && !file_exists( ABSPATH . 'db-config.php') ) {
				// no db-config.php, lets make one.
				// get the local db creds:
				$db_config_file = @fopen(ABSPATH . 'db-config.php', "w");
	
				if (!$db_config_file === false) {
					fwrite($db_config_file, $this->_hyperdbconfig());
					fclose($db_config_file);
					//add_action('admin_notices', 'enabled_hyper',20);
				}
			}
			
		} else {
			// no hyperdb at all.. we should add it
			@unlink(ABSPATH . 'wp-content/db.php');
			$repopath = "http://plugins.svn.wordpress.org/hyperdb/trunk/db.php";
			$db_contents = file_get_contents($repopath);
			$db_file = @fopen(ABSPATH . 'wp-content/db.php', "w");
	
			if (!$db_file === false) {
				fwrite($db_file, $db_contents);
				fclose($db_file);
			}
			// no db-config.php, lets make one.
			// get the local db creds:
			$db_config_file = @fopen(ABSPATH . 'db-config.php', "w");
			if (!$db_config_file === false) {
				fwrite($db_config_file, $this->_hyperdbconfig());
				fclose($db_config_file);
			}
			add_action('admin_notices', array(&$this, '_enabled_hyper') ,20);
		}
	
	}
	
	function _hyperdbconfig() {
		return $file_content = '<?php
		// Page.lys hyperdb config
		$p_dbname = \''.DB_NAME.'\';
		$p_dbuser = \''.DB_USER.'\';
		$p_dbpass = \''.DB_PASSWORD.'\';
		$p_MASTER = \'database1.pagely.com\';
		$p_SLAVES = array(\'dbslave.pagely.com\');
		/** Variable settings **/
	
		/**
		 * save_queries (bool)
		 * This is useful for debugging. Queries are saved in $wpdb->queries. It is not
		 * a constant because you might want to use it momentarily.
		 * Default: false
		 */
		$wpdb->save_queries = false;
	
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
		$wpdb->max_connections = 20;
	
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
			"host"	  => $p_MASTER,	  // If port is other than 3306, use host:port.
			"user"	  => $p_dbuser,
			"password" => $p_dbpass,
			"name"	  => $p_dbname,
			"write"		 => 1,
			"read"		=> 2,
		));
	
		// READ ONLY SLAVES
		foreach ($p_SLAVES as $slave) {
			$slaves_to_add = array(
				"host"		=> $slave,		// If port is other than 3306, use host:port.
				"user"		=> $p_dbuser,
				"password" => $p_dbpass,
				"name"		=> $p_dbname,
				"write"	  => 0,
				"read"		 => 1, // higher numbers are lower priority
				"timeout"	=> 500,
				);
			//add the slaves to the pool
			$wpdb->add_database($slaves_to_add);
		}
	
		// The ending PHP tag is omitted. This is actually safer than including it.
		';
	}
	
	function _push_domain_aliases() {
		global $wpdb;
		// mu domain mapping plugin page
		// query the db and get all domains
		$table_name = 'wp_domain_mapping';
		$domain_aliases = $wpdb->get_results( "SELECT domain FROM $table_name" );
		//echo count($domain_aliases).'GGGGGGGGGGGG';
		if ( false === ( $num_domains = get_transient( 'wp_domains_mapped' ) ) || $num_domains != count($domain_aliases) ) {
			set_transient( 'wp_domains_mapped', count($domain_aliases) , 60*60 );
			//add_action('admin_notices', array(&$this, '_domain_aliases'),20);

			$body = array('aliases' => $domain_aliases);
			// return this back to HCS
			$request = new WP_Http;
			$post_result = $request->request( $this->hcs_url_n.'/rsinfo/aliases/'.$this->hcs_sdomain , array( 'timeout' => 15000,'method' => 'POST', 'body' => $body) );
			if ( !is_wp_error($post_result) ) {
//				print_r($post_result['body']);
				} else {
	//			echo 'failed';
				}
			}
		
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
class HCSVarnish extends HCS {
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
		}
		
		// ajax handler
		
		add_action("admin_footer", array(&$this,'HCSVarnishShowLink') );
		// purge varnish action
		add_action('wp_ajax_purge_varnish', array(&$this, 'HCSVarnishPurgeAll' ) );
		
		// When theme is changed
		add_action('switch_theme', array(&$this, 'HCSVarnishPurgeAll' ) );
		
		// When posts/pages are published, edited or deleted
		add_action('edit_post', array(&$this, 'HCSVarnishPurgePost'), 99);
		add_action('edit_post', array(&$this, 'HCSVarnishPurgeCommonObjects'), 99);
		add_action('edit_post', array(&$this, 'HCSVarnishPurgePostComments'),99);
		
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
		$this->HCSVarnishPurgeObject("/feed/");
		$this->HCSVarnishPurgeObject("/feed/atom/");
	}
	
	// HCSVarnishPurgePostComments - Purge all comments pages from a post
	function HCSVarnishPurgePostComments($wpv_commentid) {
		$comment = get_comment($wpv_commentid);
		$wpv_commentapproved = $comment->comment_approved;
		
		// If approved or deleting...
		if ($wpv_commentapproved == 1 || $wpv_commentapproved == 'trash') {
			$wpv_postid = $comment->comment_post_ID;
		
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
		$this->HCSVarnishPurgeObject('/(.*)');
	}
	
	// HCSVarnishPurgePost - Takes a post id (number) as an argument and generates
	// the location path to the object that will be purged based on the permalink.
	function HCSVarnishPurgePost($wpv_postid) {
		$wpv_url = get_permalink($wpv_postid);
		$wpv_permalink = str_replace(get_bloginfo('wpurl'),"",$wpv_url);
		$this->HCSVarnishPurgeObject($wpv_permalink);
  	}

	// HCSVarnishPurgeObject - Takes a location as an argument and purges this object
	// from the varnish cache.
	function HCSVarnishPurgeObject($wpv_url) {
		if ( PAGELY_VARNISH ) {
			$varnish_servers = explode(',',PAGELY_VARNISH);
		} else {
			$varnish_servers = $this->hcs_varnish_servers;
		}
		if ( is_array( $varnish_servers ) ) {
			foreach ($varnish_servers as $server ) {
				list ($host, $port) = explode(':', $server);
				$wpv_purgeaddr[] = $host;
				$wpv_purgeport[] = $port;
			}
		} else {
			add_action('admin_notices', array(&$this, '_failed_varnish_purge') ,20);
		}
		
		// array of hosts/domains to purge
		$domains_to_purge = array();
		$domains_to_purge[] = str_replace(array('http://','https://'),'',get_bloginfo('wpurl') );
		if (function_exists('domain_mapping_siteurl') ) {
			// reset the array
			global $wpdb;
			
			// get mapped domains 
			$wpdb->dmtable = $wpdb->base_prefix . 'domain_mapping';
			$domains = $wpdb->get_results( "SELECT * FROM {$wpdb->dmtable} WHERE blog_id = '{$wpdb->blogid}'", ARRAY_A );
			foreach ($domains as $d ) {
				$domains_to_purge[] = str_replace(array('http://','https://'),'',$d['domain']);
			}
		
		}
		// loop array of domains
		foreach ($domains_to_purge as $domain) {
			$uri = $wpv_url;
         		$wpv_wpurl = strtolower($domain);
         		$wpv_replace_wpurl = '/^([^\/]+)(.*)/i';
         		$wpv_host = preg_replace($wpv_replace_wpurl, "$1", $wpv_wpurl);
         		$wpv_blogaddr = preg_replace($wpv_replace_wpurl, "$2", $wpv_wpurl);
         		$uri = $wpv_blogaddr . str_replace($wpv_host,'',$uri);
		
			for ($i = 0; $i < count ($wpv_purgeaddr); $i++) {
				$varnish_sock = fsockopen($wpv_purgeaddr[$i], $wpv_purgeport[$i], $errno, $errstr, $this->wpv_timeout);
				if (!$varnish_sock) {
					error_log("wp-varnish error: $errstr ($errno)");
				} else {
					$out = "PURGE $uri HTTP/1.0\r\n";
					$out .= "Host: $wpv_host\r\n";
					$out .= "Connection: Close\r\n\r\n";
					fwrite($varnish_sock, $out);
					$d .= fgets($varnish_sock, 128);
					fclose($varnish_sock);
				}
				return $d;
			}
		}
	}
}
// load the plugin
add_action( 'plugins_loaded', array( 'HCS', 'init' ) );
// leaving off closing php tag
