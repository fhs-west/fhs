<?php 

class PagelyApi
{
    const API_URL = 'https://api.pagely.com/v1';
    protected static $config = null;

    public function config()
    {
        if (empty(self::$config))
            self::$config = $this->configFromInfofile();

        return self::$config;

    }

    // looks for and loads the info.json 
    function configFromInfofile()
    {
        /* Grab the config for this site */
        // for local testing, but it okay to leave
        $infofile = ABSPATH.'/info.json';

        // chroot root dir
        if (!file_exists($infofile)) {
            $infofile = '/info.json';
        }

        // still cant find it, so guess
        if (!file_exists($infofile) && preg_match('@^/data/s[0-9]+/dom[0-9]+@', __DIR__, $match))
        {
            // we are running in a non chroot setup
            $infofile = "$match[0]/info.json";
        }
        else if (!file_exists($infofile) && preg_match('@^/data/s[0-9]+/dom[0-9]+@', ABSPATH, $match))
        {
            $infofile = "$match[0]/info.json";
        }
        else if (!file_exists($infofile) && !empty($_SERVER['DOCUMENT_ROOT']))
        {
            $infofile = dirname($_SERVER['DOCUMENT_ROOT'])."/info.json";
        }
        else if (!file_exists($infofile))
        {
            error_log("Couldn't find an info.json file for the domain, ABSPATH: ".ABSPATH.", __DIR__: ".__DIR__);
            return new stdClass;
        }

        // why are these not already objects?
        $config =  (object) json_decode(file_get_contents($infofile));
        $config->cdn = (object)$config->cdn;

        return $config;
    }

    public function generateV2AuthorizationHash()
    {
        $key = $this->config()->apiKey;
        $rand = sha1(mt_rand().microtime());
        $hash = sha1($rand.$this->config()->apiSecret);
        return "PAGELY $key $rand $hash";
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
    public function apiRequest($method = 'GET', $uri = '/controller/method', $params = [] )
    {
        $api_url = self::API_URL;

        $config = $this->config();

        if (empty($config->apiKey)) {
            // TODO: deprecate at some point
            $config->apiKey = get_option('pagely-apikey');
        }


        // setup the request
        $url 		= $api_url . $uri;
        $headers = array( 'X-API-KEY' => $config->apiKey);

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
            $params['hash']    	= sha1($time.$config->apiSecret); // not used yet
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
        //print_r($result['body']);
        if ( !is_wp_error($result) ) {
            //no error
            return $result['body'];	

        } else {
            // error
            return $result->get_error_message();			
        }	
    }

}
