#!/usr/bin/php -dmemory_limit=512M -dsafe_mode=Off
<?php
require_once("common.php");
require_once("config.php");
require_once("Video.class.php");

$start_time = time();
print "\n\n[deletecity] starting at " . date("F j, Y, g:i a") . "\n\n";


// Load in the URLs from the 'sources' file
$urls = file("sources", FILE_SKIP_EMPTY_LINES);
{
	print "Error: Couldn't open sources\n";
	exit;
}

// Loop through all of the URLs from the 'sources' file
foreach($urls as $url)
{
	print "Status: Fetching $url\n";

	// Parse a feed file
	$xmlstr = get_web_page( $url );
	$xmldoc = new SimpleXMLElement( $xmlstr['content'] );
	
	// Check to make sure this is a valid YouTube feed
	// maybe some XPaths to make sure the status is OK and that it has entries, etc.
	
	$num_vids = count($xmldoc->entry);
	$i=1;
	foreach($xmldoc->entry as $entry)
	{
		$vid_url = $entry->link[0]['href'];				// TO DO:  We can't be sure that the href is element 0
		preg_match("/v=([^&]+)/", $vid_url, $matches);

		$video = new Video( $matches[1] );
		
		// If we need to download the video, do it!
		if(!file_exists($video->vid_path))
		{
			print "\tStatus: [$i/$num_vids] Downloading \"{$entry->title}\" ({$video->youtube_id})\n";
			
			// http://rg3.github.com/youtube-dl/documentation.html#d6
			`youtube-dl/youtube-dl --continue --no-overwrites --ignore-errors --format=18 --output="{$cache_dir}/%(id)s.%(ext)s" --rate-limit=$rate_limit $vid_url`;
		}
		
		
		// If the video hasn't been saved to the database, save it!
		if(!$video->in_db)
		{
			if(file_exists($video->vid_path))
			{
				print "\tStatus: [$i/$num_vids] Adding \"{$entry->title}\" ({$video->youtube_id}) to database\n";
				$video->title = $entry->title;
				$video->author = $entry->author->name;
				$video->save();
			}
			else
			{
				print "\tError: [$i/$num_vids] File was not successfully downloaded.  Not adding to database.";
			}
		}
		$i++;
	}
}

$elapsed_time = time() - $start_time;
$minutes = $elapsed_time / 60.0;
print "[deletecity] elapsed time: {$minutes} minutes\n\n";
?>