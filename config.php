<?php

date_default_timezone_set('America/New_York');

// Videos will be deleted after 'max_age' days
$max_age = 3;

// The directory where the videos are stored
$cache_dir = dirname(__FILE__)."/cache";

// The file that contains a list of YouTube feed URLs
$sources_file = dirname(__FILE__)."/sources";

// Path to the youtube-dl executable
$youtube_dl = dirname(__FILE__)."/youtube-dl";

// Where the SQLite database will be stored
$dbfile = dirname(__FILE__)."/deletecity.rsd";

// The user agent to use when downloading stuff from YouTube
$user_agent = "spider";

// Max speed that the videos will be downloaded
$rate_limit="100k";
?>