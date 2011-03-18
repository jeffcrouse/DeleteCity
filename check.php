#!/usr/bin/php -dmemory_limit=512M -dsafe_mode=Off
<?php
require_once("common.php");
require_once("config.php");
require_once("Video.class.php");

// Check for orphans
print "\n\nStatus: Checking for orphans\n\n";

$dhandle = opendir($cache_dir);
if ($dhandle)
{
	while (false !== ($fname = readdir($dhandle)))
	{
		if ($fname != '.' && $fname != '..' && !is_dir( "./$fname" ) && !strpos($fname, ".part") && $fname!="README")
		{
			$youtube_id = strstr(basename($fname), ".", true);
			if(!empty($youtube_id))
			{
				$video = new Video( $youtube_id );
				
				if(!$video->in_db)
				{
					if($video->fetch_info()) 
					{
						print "Status: Inserting an orphaned video file: $youtube_id.\n";
						$video->save();
					}
					else 
					{
						print "Error: Couldn't fetch info for $youtube_id\n";
					}
				}
			}
		}
	}
	closedir($dhandle);
}


print "\n\nStatus: Checking for removed videos\n\n";


$result = $db->query("SELECT youtube_id FROM videos WHERE removed=0", SQLITE_ASSOC, $query_error); 
if ($query_error)
    die("Error: $query_error"); 
    
if (!$result)
    die("Error: Impossible to execute query.");

print $result->numRows()." results\n";

$total = $result->numRows();
$i=1;
while($row = $result->fetch(SQLITE_ASSOC))
{ 
	$video = new Video( $row['youtube_id'] );

	if($video->check_remote())
	{
		print "Status: [$i/$total] {$row['youtube_id']} still exists\n";
		$video->mark_as_updated();
		
		if($video->age > $max_age)
		{
			$video->delete();
		}
	}
    else
    {
    	print "Status: [$i/$total] {$row['youtube_id']} has been removed!\n";
    	$video->mark_as_removed();
    } 
    $i++;
}
?>