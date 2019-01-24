<?php



    /***********************************/
    /*    CUSTOM CONSTANT DEFINITIONS  */
    /***********************************/

/* WordPress Localized Language, defaults to English. */
    // define('WPLANG', 'OTHER_LANGUAGE');

/* For developers: WordPress debugging mode.  */
    // define('WP_DEBUG', true);



/*******************************************************************************************/
/*******************************************************************************************/
/*                                                                                         */
/*    Settings ABOVE this file will take place BEFORE your hosting settings have been set  */
/*                                                                                         */
/*******************************************************************************************/
/*******************************************************************************************/

    /**
     * Your wp-config settings are managed automatically including database configs
     * To make custom changes please know what you are doing or contact a support agent for assistance
     */

	/** Sets up WordPress vars and configs */
	    require_once(__DIR__.'/wp-config-hosting.php');


/*******************************************************************************************/
/*******************************************************************************************/
/*                                                                                         */
/*    Settings BELOW this file will take place AFTER your hosting settings have been set   */
/*                                                                                         */
/*******************************************************************************************/
/*******************************************************************************************/


    /***********************************/
    /*    CUSTOM Variable DEFINITIONS  */
    /***********************************/
    
//Defaults are already set correctly in file above

    // $table_prefix = 'wp_';



/** Sets up WordPress vars and included files. */
require_once(ABSPATH . 'wp-settings.php');
