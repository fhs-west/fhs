<?php 
function pagely_api_request($method = 'GET', $uri = '/controller/method', $params = array() )
{
    $api = new PagelyApi();

    return $api->apiRequest($method, $uri, $params);
}
