#!/usr/bin/php -dmemory_limit=512M -dsafe_mode=Off
<?php
require_once("common.php");
require_once("config.php");


$result = $db->query("SELECT id, youtube_id, title, author, date_added, date_updated, 
	round(strftime('%J', datetime('now'))-strftime('%J', date_added), 2) as age
	FROM videos WHERE removed=0", SQLITE_ASSOC, $query_error); 
if ($query_error)
    die("Error: $query_error"); 
    
if (!$result)
    die("Error: Impossible to execute query.");

print $result->numRows()." results\n";

while ($row = $result->fetch(SQLITE_ASSOC))
{ 
    $url = "http://gdata.youtube.com/feeds/api/videos/".$row['youtube_id'];
    $webpage = get_web_page( $url );
    $content = trim($webpage['content']);
    
    $sql=sprintf("UPDATE videos SET date_updated=DATETIME('now') WHERE youtube_id='%s'", 
    		sqlite_escape_string($row['youtube_id']) );
    	if (!$db->queryExec($sql, $error))
    		die("Error: $error");
    
    if(strpos($content, "<?xml")===0)
    {
    	print "Status: {$row['youtube_id']} still exists\n";
    	
    	if($row['age'] > $max_age)
		{
			if(unlink(sprintf("{$cache_dir}/%s.mp4", $row['youtube_id'])))
			{
				$sql=sprintf("DELETE FROM videos WHERE youtube_id='%s'", 
					sqlite_escape_string($row['youtube_id']) );
				if (!$db->queryExec($sql, $error))
					die("Error: $error");
			}
			else
			{
				print "Error: {$row['youtube_id']} is too old, but it cannot be deleted.  Check permissions.\n";
			}
		}
    }
    else
    {
    	print "Status: {$row['youtube_id']} has been removed!\n";
    	$sql=sprintf("UPDATE videos SET removed=1 WHERE youtube_id='%s'", 
    		sqlite_escape_string($row['youtube_id']) );
    	if (!$db->queryExec($sql, $error))
    		die("Error: $error");
    } 
}
?>