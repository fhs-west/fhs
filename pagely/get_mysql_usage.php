<?php

$howmany = 10;


$memcache_obj = new Memcache;
$memcache_obj->pconnect('199.168.175.120', 22122);

$mysqli = new mysqli('db3.sql.pressip.com', 'page01sa', 'SvgFVg2k6it6', 'com_hcs');

if ($mysqli->connect_error) {
    die('Connect Error (' . $mysqli->connect_errno . ') '
            . $mysqli->connect_error);
}

echo 'Success... ' . $mysqli->host_info . "\n";

$usage = array();

/* Select queries return a resultset */
if ($result = $mysqli->query("select id,domain from domains where active =1 limit 5000")) {
	global $usage;
    printf("Select returned %d rows.\n", $result->num_rows);
    while($row=$result->fetch_assoc()) 
      {
       $key = "db_dom{$row['id']}.mysql_usage";
       $num = $memcache_obj->get($key);
       if ($num > $howmany) {
       	echo "db_dom{$row['id']} Master Usage: " . $memcache_obj->get($key) ." - {$row['domain']}\n";
       }
      }
    

     $result->close();
} else {
	echo 'no result';
}

/* free result set */
$mysqli->close();
?>