<?php 


	
//get from api or transient		
function pagely_get_bulletins($force = false) {
	// make a call home and grab anu bulletins we need to display.
	// load up options
	$pagely_options = get_option('pagely_site_options');

	$bulletins = isset($pagely_options->bulletins) && !empty($pagely_options->bulletins) ? $pagely_options->bulletins : new stdClass ;
	
	// transient expiration will update the bulletins
	if ( false === ( $bulletins_query_made = get_transient( 'bulletin_query' ) ) || $force ) {
		
		$result = pagely_api_request($method = 'GET', $uri = '/reseller_bulletins/all' );
		$result = json_decode($result);
		//print_r($result->objects);
			if ($result->count > 0) {
				
				$bulletins = $result->objects;
				//krsort($bulletins->items);
		
			if (!empty($bulletins)) {
				// assign back to wp_option value
				$pagely_options->bulletins = $bulletins;
			}
			
			// save
			update_option('pagely_site_options',$pagely_options);
		} 
		set_transient( 'bulletin_query', $bulletins_query_made, 3 * HOUR_IN_SECONDS );
	}

    // reformat
    $out = [];
    if (!empty($bulletins->items) && count($bulletins->items))
    {
        $now = time();
        foreach($bulletins->items as $item)
        {
            if ($item['date_expires'] < $now)
                $out[] = (object)$item;
        }
    }
	
	return $out;		

}
