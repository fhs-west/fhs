<?php

// custom dashboard widget for main wp-admin page
function pagely_add_dashboard_widget()
{
    if (!pagely_role_check())
        return;

    wp_add_dashboard_widget( 'pagely_dashboard_widget', '<img src="https://cdnassets.pagely.com/pagely-w-on-b20x20.png" style="float:left;margin-right:10px;"/> Pagely&reg; Hosting Status + Notices', 'pagely_dashboard_widget_function' );

    // Globalize the metaboxes array, this holds all the widgets for wp-admin

    global $wp_meta_boxes;

    if (isset($wp_meta_boxes['dashboard']))
    {
        // Get the regular dashboard widgets array 
        // (which has our new widget already but at the end)
        $normal_dashboard = $wp_meta_boxes['dashboard']['normal']['core'];

        // Backup and delete our new dashboard widget from the end of the array
        $pagely_widget_backup = array( 'pagely_dashboard_widget' => $normal_dashboard['pagely_dashboard_widget'] );
        unset( $normal_dashboard['pagely_dashboard_widget'] );

        // Merge the two arrays together so our widget is at the beginning
        $sorted_dashboard = array_merge( $pagely_widget_backup, $normal_dashboard );

        // Save the sorted array back into the original metaboxes 
        $wp_meta_boxes['dashboard']['normal']['core'] = $sorted_dashboard;
    }
} 

/**
* Create the function to output the contents of our Dashboard Widget.
	*/
function pagely_dashboard_widget_function() {

	// grab any bulletins
	$bulletins =  pagely_get_bulletins(true); 
	if (count($bulletins)) { ?>
		<div class="activity-block">
		<ul>			
		<?php foreach ($bulletins as $b) { 	?>
			<li>
				<span style="color:#777777;float:left;margin-right:8px; min-width:150px"><?php echo date(get_option('date_format'),$b->date_added);?></span>
  
				<?php echo $b->msg; ?>
				
			</li>
		<?php } ?>	
		</ul>
		</div>
		<?php 
	} else {
		echo '<p>Nothing new to report.</p>';
	}
}

?>
