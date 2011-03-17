#!/usr/bin/php -dmemory_limit=512M -dsafe_mode=Off
<?php
require_once("common.php");

$args = parseArgs($argv);

$sql = "SELECT id, youtube_id, title, author, date_added, date_updated, removed,
	round(strftime('%J', datetime('now'))-strftime('%J', date_added), 2) as age
	FROM videos";

if($args['removed']) {
	$sql .= " WHERE removed > 0";
}




$result = $db->query($sql, SQLITE_ASSOC, $query_error); 
if ($query_error)
    die("Error: $query_error"); 
    
if (!$result)
    die("Impossible to execute query.");

print $result->numRows()." results\n";

while ($row = $result->fetch(SQLITE_ASSOC))
{ 
    print_r($row); 
}
?>