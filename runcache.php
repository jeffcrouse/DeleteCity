<?php
require_once("common.php");
require_once("Video.class.php");
require_once("pid.class.php");
require_once("dcdb.php");
libxml_use_internal_errors(true);
$pid = new pid( dirname(__FILE__) );
$start_time = time();


print "--------------[runcache]--------------\n";
print "Status: pid ". getmypid()." starting at " . date("F j, Y, g:i a") . "\n";



/*******************************
*
*	PARSE/SET ARGS
*
*******************************/

$args = parseArgs($_SERVER['argv']);
$max_age = $args['maxage'];
$rate_limit = isset($args['ratelimit']) ? $args['ratelimit'] : '500k';
Video::$cache_dir = $args['cachedir'];
$dcdbfile = $args['db'];
$blacklist = isset($args['blacklist']) 
	? preg_split("/[\s]*[,][\s]*/", $args['blacklist'])
	: array();

$do_cache = true;
$do_orphan_check=true;
$do_check_for_deletions=true;

if(isset($args['find_deleted_only']))
{
	$do_cache = false;
	$do_orphan_check=false;
}

$youtube_dl = dirname(__FILE__)."/youtube-dl";

print "\tDelete videos older than $max_age days\n";
print "\tyoutube-dl: $youtube_dl\n";
print "\tRate Limit: $rate_limit\n";
print "\tCache Dir: {$args['cachedir']}\n";
print "\tDatabase: $dcdbfile\n";
print "\tBlacklist: ".implode(", ", $blacklist)."\n";


/*******************************
*
*	HANDLE SIGNALS
*
*******************************/
if(function_exists('pcntl_signal'))
{
	declare(ticks = 1);
	function sig_handler($signo)
	{
		global $pid;
		switch ($signo) 
		{
			case SIGTERM:
				unset($pid);
				exit;
				break;
			case SIGHUP: break;
			case SIGUSR1: break;
			default:
		}
	}
	
	echo "Status: Installing signal handler...\n";
	pcntl_signal(SIGTERM, "sig_handler");
	pcntl_signal(SIGHUP,  "sig_handler");
	pcntl_signal(SIGUSR1, "sig_handler");
}



/*******************************
*
*	SANITY CHECKS
*
*******************************/

if($pid->already_running)
{
	print "Error: runcache is already running.\n";
	exit;
}

if(!$dcdb)
{
	print "Error: Database not found\n";
	exit;
}

if($do_check_for_deletions && empty($max_age))
{
	print "Error: You must provide the maxage argument\n";
	exit;
}

if($do_cache && !file_exists($youtube_dl))
{
	print "Error: $youtube_dl not found\n";
	exit;
}

if($do_cache && !is_executable($youtube_dl))
{
	print "Error: $youtube_dl not executable\n";
	exit;
}

if($do_cache && !file_exists(Video::$cache_dir))
{
	mkdir(Video::$cache_dir, 0777, true);
}

if($do_cache && !is_writable(Video::$cache_dir))
{
	print "Error: ".Video::$cache_dir." is not writable.\n";
	exit;
}



/*******************************
*
*	CACHING PROCESS
*
*******************************/

if($do_cache)
{
	$result = $dcdb->query("SELECT feed_url FROM sources", SQLITE_ASSOC, $query_error); 
	if ($query_error)
		die("Error: $query_error"); 
		
	if (!$result)
		die("Error: Impossible to execute query.");
	
	$num_feeds = $result->numRows();
	$cur_feed=1;
	
	// Loop through all of the feeds in the database
	while($row = $result->fetch(SQLITE_ASSOC))
	{
		$url = $row['feed_url'];
	
		if(strpos($url, "http://gdata.youtube.com/feeds/api/")!=0)
		{
			print "Error: [$cur_feed/$num_feeds] Must be a gdata.youtube.com/feeds/api feed\n";
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
		
		// Loop through all of the "entries" in the feed.
		foreach($xmldoc->entry as $entry)
		{	
			$vid_url = $entry->link[0]['href'];				// TO DO:  We can't be sure that the href is element 0
			preg_match("/v=([^&]+)/", $vid_url, $matches);
	
			$video = new Video( $matches[1] );
			$video->title = $entry->title;
			$video->content = $entry->content;
			$video->author = $entry->author->name;
			$video->feed = $url;
			
			$blacklisted = array();
			foreach($blacklist as $word)
			{
				if(stristr($video->title, $word) || stristr($video->content, $word)) $blacklisted[] = $word;
			}
			
			// Skip any videos that contain a blacklisted word.
			if(count($blacklisted) > 0)
			{
				$words = implode(", ", $blacklisted);
				print "\tStatus: [$cur_vid/$num_vids] $words found in video \"{$video->title}\" ({$video->youtube_id}). Skipping\n";
			}
			else
			{
				// If we need to download the video, do it!
				if(!$video->expired && !file_exists($video->vid_path))
				{
					print "\tStatus: [$cur_vid/$num_vids] Downloading \"{$entry->title}\" ({$video->youtube_id})\n";
		
					// http://rg3.github.com/youtube-dl/documentation.html#d6
					$cache_dir = Video::$cache_dir;
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
						$video->save();
					}
					else
					{
						print "\tError: [$cur_vid/$num_vids] File was not successfully downloaded.  Not adding to database.\n";
					}
				}
			}
			$cur_vid++;
		}
		$cur_feed++;
		
	} // end while(more YouTube feeds)
}


/*******************************
*
*	CHECK FOR LOCAL ORPHANED VIDEOS
*	downloaded videos that don't have an entry in the database
*
*******************************/

if($do_orphan_check)
{
	print "Status: Checking for orphans\n";
	$dhandle = opendir(Video::$cache_dir);
	if ($dhandle)
	{
		while (false !== ($fname = readdir($dhandle)))
		{
			if ($fname!='.' && $fname!='..' && !is_dir("./$fname") && !strpos($fname,".part"))
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
}


/*******************************
*
*	CHECK FOR DELETED VIDEOS
*
*******************************/
if($do_check_for_deletions)
{
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
				print "\tStatus: [$i/$total] {$row['youtube_id']} has expired.  Deleting video file.\n";
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
}

/*******************************
*
*	DONE
*
*******************************/

$elapsed_time = time() - $start_time;
$minutes = $elapsed_time / 60.0;
print "Status: elapsed time: {$minutes} minutes\n\n";
print "--------------[/runcache]--------------\n";
?>