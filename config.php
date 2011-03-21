<?php

date_default_timezone_set('America/New_York');

// Videos will be deleted after 'max_age' days
$max_age = 3;

// Path to the youtube-dl executable
$youtube_dl = dirname(__FILE__)."/youtube-dl";

// The user agent to use when downloading stuff from YouTube
$user_agent = "spider";

// Max speed that the videos will be downloaded
$rate_limit="100k";


if(defined("WP_CONTENT_DIR"))
{
	// The directory where the videos are stored
	$cache_dir = WP_CONTENT_DIR."/dc_cache";
	
	// Where the SQLite database will be stored
	$dbfile =  WP_CONTENT_DIR."/deletecity.rsd";
}
else
{
	// The directory where the videos are stored
	$cache_dir = dirname(__FILE__)."/../../dc_cache";
	
	// Where the SQLite database will be stored
	$dbfile = dirname(__FILE__)."/../../deletecity.rsd";
}
?>