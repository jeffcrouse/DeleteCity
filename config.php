<?php

// Videos will be deleted after 'max_age' days
$max_age = 3;

// The directory where the videos are stored
$cache_dir = dirname(__FILE__)."/cache";

// The file that contains a list of YouTube feed URLs
$sources_file = dirname(__FILE__)."/sources";

$youtube_dl = dirname(__FILE__)."/youtube-dl/youtube-dl";

// The user agent to use when downloading stuff from YouTube
$user_agent = "spider";

// Max speed that the videos will be downloaded
$rate_limit="500k";
?>