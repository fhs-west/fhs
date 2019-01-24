<?php 
/* load font-awesome css */
function pagely_load_font_awesome() {
	
	wp_enqueue_style( 'font-awesome', 'https://maxcdn.bootstrapcdn.com/font-awesome/4.2.0/css/font-awesome.min.css', false, false, false ); 
}



// checks is admin user is can see pagely controls
if (!function_exists('pagely_role_check'))
{
    function pagely_role_check() {
        if (
                (is_multisite() && current_user_can('manage_network')) ||  // multsite admin only
                (!is_multisite() && current_user_can('manage_options'))
            ) {
                    return true;
        }
        return false;
    }
}


?>
