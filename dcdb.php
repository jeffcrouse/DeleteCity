<?php

// This file will be included either from a Wordpress page or from a command line script.  
// If it is from Wordpress, the SQLite file is stored in a Wordpress option.
// If it is the command line script, the file is passed in as a '--db' command line argument.

if(function_exists('get_option'))
{
	$dcdbfile = get_option('dc_db_file');
}

if(isset($_SERVER['argv']) && preg_match("|\-\-db=([^\s]+)|", implode(" ", $_SERVER['argv']), $matches))
{
	$dcdbfile = $matches[1];
}

if(!isset($dcdbfile))
{
	die("You must specify a database file.");
}

$dcdb = new SQLiteDatabase($dcdbfile);
if (!$dcdb)
{
	$error = (file_exists($dcdbfile)) 
		? "Error: Impossible to open database file, check permissions\n" 
		: "Error: Impossible to create database file, check permissions\n";
	die($error);
}

$q = @$dcdb->query("SELECT id FROM videos WHERE id=1");
if (!$q)
{
	$dcdb->queryExec("
	CREATE TABLE videos (
		id 				INTEGER PRIMARY KEY NULL, 
		youtube_id		VarChar NULL UNIQUE,
		title 			CHAR(255) NULL,
		content			Text NULL,
		author			CHAR(255) NULL,
		date_added 		DATETIME NOT NULL,
		seen_in_feed 	DATETIME NOT NULL,
		removed			Boolean NULL DEFAULT 0,
		expired			Boolean NULL DEFAULT 0,
		date_posted 	DATETIME NULL
	);
	CREATE TABLE sources (
		id 				INTEGER PRIMARY KEY NULL, 
		feed_url		VarChar NOT NULL
	);
	INSERT INTO sources (feed_url) VALUES('http://gdata.youtube.com/feeds/api/standardfeeds/most_recent?&orderby=published&max-results=50'); 
	INSERT INTO sources (feed_url) VALUES('http://gdata.youtube.com/feeds/api/standardfeeds/most_recent?&orderby=published&max-results=50&start-index=51'); 
	INSERT INTO sources (feed_url) VALUES('http://gdata.youtube.com/feeds/api/standardfeeds/most_discussed'); 
	INSERT INTO sources (feed_url) VALUES('http://gdata.youtube.com/feeds/api/standardfeeds/top_rated'); 
	INSERT INTO sources (feed_url) VALUES('http://gdata.youtube.com/feeds/api/standardfeeds/most_viewed');
	INSERT INTO sources (feed_url) VALUES('http://gdata.youtube.com/feeds/api/videos?q=slow+loris&orderby=published&max-results=25');", 
	$query_error);
	if ($query_error)
		die("Error: $query_error");
}
?>