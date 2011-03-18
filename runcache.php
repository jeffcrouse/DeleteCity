#!/usr/bin/php -dmemory_limit=512M -dsafe_mode=Off
<?php
require_once("common.php");
require_once("config.php");


$urls = file("sources", FILE_SKIP_EMPTY_LINES);

foreach($urls as $url)
{
	print "Status: Fetching $url\n";

	$xmlstr = get_web_page( $url );
	$xmldoc = new SimpleXMLElement( $xmlstr['content'] );
	
	$num_vids = count($xmldoc->entry);
	$i=1;
	foreach($xmldoc->entry as $entry)
	{
		$vid_url = $entry->link[0]['href'];				// TO DO:  We can't be sure that the href is element 0
		preg_match("/v=([^&]+)/", $vid_url, $matches);
		$youtube_id = $matches[1];
		
		$sql="SELECT id FROM videos WHERE youtube_id='".addslashes($youtube_id)."'";
		$result = $db->query($sql, SQLITE_ASSOC, $query_error); 
		if ($query_error)
			die("Error: $query_error"); 
			
		if (!$result)
			die("Error: Impossible to execute query.");
    
		if($result->numRows() > 0)
		{
			print "Status: [$i/$num_vids] $youtube_id already exists in database.  Skipping.\n";
			if(!file_exists("{$cache_dir}/{$youtube_id}.mp4"))
			{
				print "Error: Record exists in database, but video file doesn't exist!";
			}
		}
		else
		{
			$title = $entry->title;
			$published = $entry->published;
			$updated = $entry->updated;
			$author = $entry->author->name;
			print "Status: [$i/$num_vids] Downloading \"$title\" ($youtube_id)\n";
			
			// http://rg3.github.com/youtube-dl/documentation.html#d6
			`youtube-dl/youtube-dl --continue --no-overwrites --ignore-errors --format=18 --output="{$cache_dir}/%(id)s.%(ext)s" --rate-limit=$rate_limit $vid_url`;
			
			if(file_exists("{$cache_dir}/{$youtube_id}.mp4"))
			{
				print "Status: Adding \"$title\" ({$youtube_id}) to database\n";
				$sql=sprintf("INSERT INTO videos (youtube_id, title, author, date_added, date_updated) 
					VALUES ('%s', '%s', '%s', DATETIME('now'), DATETIME('now'))",
					sqlite_escape_string($youtube_id),
					sqlite_escape_string($title),
					sqlite_escape_string($author)
				);
				$db->query($sql, SQLITE_ASSOC, $query_error);
				if ($query_error)
					die("Error: $query_error\n"); 
			}
			else
			{
				print "Error: File was not successfully downloaded.  Not adding to database.";
			}
		}
		$i++;
	}
}
?>