#!/usr/bin/php -dmemory_limit=512M -dsafe_mode=Off
<?php
require_once("common.php");
require_once("config.php");
require_once("Video.class.php");
require_once("pid.class.php");
libxml_use_internal_errors(true);


$start_time = time();
print "Status: Cache starting at " . date("F j, Y, g:i a") . "\n\n";



/*******************************
*
*	SANITY CHECKS
*
*******************************/

$pid = new pid( dirname(__FILE__) );
if($pid->already_running)
{
	print "Error: runcache is already running.\n";
	exit;
}

if(!file_exists($youtube_dl))
{
	print "Error: $youtube_dl not found";
	exit;
}

if(!file_exists($cache_dir))
{
	mkdir($cache_dir, 0777, true);
}

if(!is_writable($cache_dir))
{
	print "Error: $cache_dir is not writable.\n";
	exit;
}


/*******************************
*
*	CACHING PROCESS
*
*******************************/


$result = $dcdb->query("SELECT feed_url FROM sources", SQLITE_ASSOC, $query_error); 
if ($query_error)
    die("Error: $query_error"); 
    
if (!$result)
    die("Error: Impossible to execute query.");

$num_feeds = $result->numRows();
$cur_feed=1;

while($row = $result->fetch(SQLITE_ASSOC))
{
	$url = $row['feed_url'];

	if(strpos($url, "http://gdata.youtube.com/feeds/api/")!=0)
	{
		print "Error:  [$cur_feed/$num_feeds] Must be a gdata.youtube.com/feeds/api feed\n";
		continue;
	}

	print "Status: Fetching feed $cur_feed of $num_feeds: $url\n";

	// Download feed
	$response = get_web_page( $url );
	if($response['errno'] >0)
	{
		print "Error: {$response['errmsg']}\n";
		continue;
	}
	
	// Try to parse feed
	$xmlstr = $response['content'];
	$xmldoc = simplexml_load_string($xmlstr);
	if (!$xmldoc)
	{
		$errors = libxml_get_errors();
		foreach ($errors as $error)
		{
			echo display_xml_error($error, $xml);
		}
		libxml_clear_errors();
		continue;
	}

	if(!isset($xmldoc->entry) || count($xmldoc->entry)==0)
	{
		print "Error: Feed doesn't contain any entries\n";
		continue;
	}
	
	// TO DO:  Check to make sure this is a valid YouTube feed
	// maybe some XPaths to make sure the status is OK and that it has entries, etc.
	
	$num_vids = count($xmldoc->entry);
	$cur_vid=1;
	foreach($xmldoc->entry as $entry)
	{
		$vid_url = $entry->link[0]['href'];				// TO DO:  We can't be sure that the href is element 0
		preg_match("/v=([^&]+)/", $vid_url, $matches);

		$video = new Video( $matches[1] );
		
		// If we need to download the video, do it!
		if(!$video->expired && !file_exists($video->vid_path))
		{
			print "\tStatus: [$cur_vid/$num_vids] Downloading \"{$entry->title}\" ({$video->youtube_id})\n";
			
			// http://rg3.github.com/youtube-dl/documentation.html#d6
			`$youtube_dl --continue --no-overwrites --ignore-errors --format=18 --output="{$cache_dir}/%(id)s.%(ext)s" --rate-limit=$rate_limit $vid_url`;
		}
		
		// If the video hasn't been saved to the database, save it!
		if($video->in_db)
		{
			$video->seen_in_feed();
		}
		else
		{
			if(file_exists($video->vid_path))
			{
				print "\tStatus: [$cur_vid/$num_vids] Adding \"{$entry->title}\" ({$video->youtube_id}) to database\n";
				$video->title = $entry->title;
				$video->content = $entry->content;
				$video->author = $entry->author->name;
				$video->save();
			}
			else
			{
				print "\tError: [$cur_vid/$num_vids] File was not successfully downloaded.  Not adding to database.\n";
			}
		}
		$cur_vid++;
	}
	$cur_feed++;
}



/*******************************
*
*	CHECK FOR LOCAL ORPHANED VIDEOS
*	downloaded videos that don't have an entry in the database
*
*******************************/

print "Status: Checking for orphans\n";
$dhandle = opendir($cache_dir);
if ($dhandle)
{
	while (false !== ($fname = readdir($dhandle)))
	{
		if ($fname!='.' && $fname!='..' && !is_dir("./$fname") && !strpos($fname,".part") && $fname!="README")
		{
			$path_parts = pathinfo($fname);
			$youtube_id = $path_parts['filename'];
			if(!empty($youtube_id))
			{
				$video = new Video( $youtube_id );
				if(!$video->in_db)
				{
					if($video->fetch_info()) 
					{
						print "\tStatus: Inserting an orphaned video file: $youtube_id.\n";
						$video->save();
					}
					else 
					{
						print "\tError: Couldn't fetch info for $youtube_id.  Skipping\n";
					}
				}
			}
		}
	}
	closedir($dhandle);
}



/*******************************
*
*	CHECK FOR DELETED VIDEOS
*
*******************************/

// Now loop through every video where removed=0 and check to see if it still exists on YouTube
print "Status: Checking for removed videos\n";

$result = $dcdb->query("SELECT youtube_id FROM videos WHERE removed=0 AND expired=0", SQLITE_ASSOC, $query_error); 
if ($query_error)
    die("Error: $query_error"); 
    
if (!$result)
    die("Error: Impossible to execute query.");


$total = $result->numRows();
$i=1;
while($row = $result->fetch(SQLITE_ASSOC))
{ 
	$video = new Video( $row['youtube_id'] );

	if($video->check_remote())
	{
		print "\tStatus: [$i/$total] {$row['youtube_id']} still exists.  Age={$video->age}\n";
		if($video->age > $max_age)
		{
			$video->mark_as_expired();
		}
	}
    else
    {
    	print "\tStatus: [$i/$total] {$row['youtube_id']} has been removed!\n";
    	$video->mark_as_removed();
    } 
    $i++;
}


/*******************************
*
*	DONE
*
*******************************/

$elapsed_time = time() - $start_time;
$minutes = $elapsed_time / 60.0;
print "Status: elapsed time: {$minutes} minutes\n\n";
?>