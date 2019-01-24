<?php
/**
 * Pagely Management plugin wordpress integration
 *
 * Loads various hooks on init
 * 
 */
class WP_Pagely
{
	
   protected static $instance;

	public static function init() {
		if ( empty($instance) && is_admin() ) {
            self::$instance = new WP_Pagely;
			// load the Varnish sub class as well
		}		
	
	}

	/***************************
	* Main Function that is run at init()
	*/
    function __construct()
    {
		global $wp_rewrite,$wp_version,$post,$wpdb,$wp;
		add_filter( 'got_rewrite', '__return_true' );
		
		// FILTERS AND ACTIONS
		
		// load menu and styles
		add_action('admin_print_styles', array(&$this, '_head_styles'),1);
		add_action('admin_menu', array(&$this, '_create_menu') ,20); 
		
		
		// add a dashboard widget, only admins can see
		add_action('wp_dashboard_setup','pagely_add_dashboard_widget');
		add_action('wp_network_dashboard_setup','pagely_add_dashboard_widget');
		
		// remove update core nag
		add_filter( 'pre_site_transient_update_core', function() { return null; });

		// setup crons
		add_action('wp', array(&$this, '_crons') );
		
		// mod any js
		//add_action('wp_enqueue_scripts', array(&$this,'_mod_js') ); - Commented out per jeichorn t105442/t106495		
		
		// remove v.#
		remove_action('wp_head', 'wp_generator');
		
		// register deactivation hook
		register_deactivation_hook(__FILE__, array($this, '_wp_pagely_deactivation') );
		
		
		// DIRECT GET/POST
		
		if (isset($_POST['purge_all_cache'])) {
			check_admin_referer( 'purge_all_cache' );
			
			apply_filters('pagely_purge_all',1);
			apply_filters('pagely_purge_cdn_all',1);
			
		}
		
		if (isset($_POST['purge_cdn'])) {
			check_admin_referer( 'purge_cdn' );
			apply_filters('pagely_purge_cdn_all',true);
			
		}
		
		if (isset($_POST['purge_cache'])) {
			check_admin_referer( 'purge_cache' );
			apply_filters('pagely_purge_all',true);		
		}
	}
	
		
	/**
	 * Anything we need to do at admin_print_styles action
	 */
    function _head_styles()
    {
		$screen = get_current_screen();
		
		// only load our styles if on one of our screens
		if ( strpos($screen->base,'pagely') !== false )  
		{
            $src = plugin_dir_url(dirname(__DIR__).'/foo').'css/pagely_styles.css';
			wp_enqueue_style( 'pagely-styles', $src, false, false, false ); 
			pagely_load_font_awesome();
		}
	}
	
	/**
	 * Any crons we want to register/setup
	 */
    function _crons()
    {
	
	}
	
	/**
	 * Create the plugin menu in the WP dashabord
	 */
    function _create_menu()
    {
        if ( pagely_role_check() )
        {
			//create new top-level menu
			$icon = 'data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0idXRmLTgiPz4NCjwhLS0gR2VuZXJhdG9yOiBBZG9iZSBJbGx1c3RyYXRvciAxNi4wLjAsIFNWRyBFeHBvcnQgUGx1Zy1JbiAuIFNWRyBWZXJzaW9uOiA2LjAwIEJ1aWxkIDApICAtLT4NCjwhRE9DVFlQRSBzdmcgUFVCTElDICItLy9XM0MvL0RURCBTVkcgMS4xLy9FTiIgImh0dHA6Ly93d3cudzMub3JnL0dyYXBoaWNzL1NWRy8xLjEvRFREL3N2ZzExLmR0ZCI+DQo8c3ZnIHZlcnNpb249IjEuMSIgaWQ9IkxheWVyXzEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHg9IjBweCIgeT0iMHB4Ig0KCSB3aWR0aD0iNDAwcHgiIGhlaWdodD0iNDAwcHgiIHZpZXdCb3g9IjAgMCA0MDAgNDAwIiBlbmFibGUtYmFja2dyb3VuZD0ibmV3IDAgMCA0MDAgNDAwIiB4bWw6c3BhY2U9InByZXNlcnZlIj4NCjxnPg0KCTxwYXRoIGZpbGw9IiNDQ0NDQ0MiIGQ9Ik0yMDQuNDg5LDg4LjkwOGgtNzEuMTI2VjMxMS4xOGgxNy43ODJWMTE1LjU4MXYtOC44OTFjMCwwLDUxLjUwNiwwLDUzLjM0NSwwDQoJCWMyNC41NTIsMCw0NC40NTQsMTkuOTAyLDQ0LjQ1NCw0NC40NTVjMCwyNC41NTItMTkuOTAyLDQ0LjQ1NC00NC40NTQsNDQuNDU0aC0zNS41NjNWMzExLjE4aDE3Ljc4MnYtODAuMDE4VjIxMy4zOGgxNy43ODINCgkJYzM0LjM3MSwwLDYyLjIzNi0yNy44NjQsNjIuMjM2LTYyLjIzNUMyNjYuNzI2LDExNi43NzIsMjM4Ljg2LDg4LjkwOCwyMDQuNDg5LDg4LjkwOHoiLz4NCgk8cGF0aCBmaWxsPSIjQ0NDQ0NDIiBkPSJNMjMxLjE2MiwxNTEuMTQ1YzAtMTQuNzMxLTExLjk0MS0yNi42NzMtMjYuNjczLTI2LjY3M2MtMi42NCwwLTM1LjU2MywwLTM1LjU2MywwdjUzLjM0NWgzNS41NjMNCgkJQzIxOS4yMjEsMTc3LjgxNywyMzEuMTYyLDE2NS44NzUsMjMxLjE2MiwxNTEuMTQ1eiIvPg0KCTxwYXRoIGZpbGw9IiNDQ0NDQ0MiIGQ9Ik0wLDB2NDAwLjA4OGg0MDAuMDg4VjBIMHogTTIwNC40ODksMjMxLjE2MnY5Ny43OTloLTg4LjkwOFY4MC4wMTh2LTguODkxaDguODkxaDgwLjAxOA0KCQljNDQuMTkyLDAsODAuMDE4LDM1LjgyNiw4MC4wMTgsODAuMDE4QzI4NC41MDcsMTk1LjMzNiwyNDguNjgyLDIzMS4xNjIsMjA0LjQ4OSwyMzEuMTYyeiIvPg0KPC9nPg0KPC9zdmc+DQo=';
			add_menu_page('Pagely App Management', 'Pagely&reg;', 'read', 'wp_pagely', array($this, '_settings_page'),$icon,0); 
			add_submenu_page( 'wp_pagely', "App Dashboard", 'App Dashboard', 'read', 'wp_pagely', array($this, '_settings_page') ); 
			
			//app stats
			if (function_exists('pagely_app_stats_options')) {
			 add_submenu_page('wp_pagely', 'App Stats', 'App Stats','read', 'app_stats', 'pagely_app_stats_options');
			}
			
			//cdn
			if (function_exists('pagely_cdn_options')) {
				add_submenu_page( 'wp_pagely', "PressCDN&trade;", 'PressCDN&trade;', 'read', 'press_cdn', 'pagely_cdn_options' ); 
			}
			
			//cache_control
			if (function_exists('pagely_cache_control_options')) {
			 add_submenu_page('wp_pagely', 'PressCACHE&trade;', 'PressCACHE&trade;','read', 'press_cache', 'pagely_cache_control_options');
			}
			
		 }
	}
	
	
	/**
	 * Main Settings Page
	 */
    function _settings_page()
    {
		include dirname(__DIR__).'/views/dashboard.php';
	}

	/**
	 * Hide w3tc settings
	 */
    function _footer_scripts()
    { 
        include dirname(__DIR__)."/views/footer.php";
    }
}

// LOAD THE PLUGIN AT INIT
add_action( 'plugins_loaded', array( 'WP_Pagely', 'init' ) );
