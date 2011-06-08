<?php
$schema_updates = array();

// Original schema - May 10, 2011
$schema_updates[] = "CREATE TABLE videos (
		id 				INTEGER PRIMARY KEY NULL, 
		youtube_id		VARCHAR NULL UNIQUE,
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
		feed_url		VARCHAR NOT NULL
	);
	CREATE TABLE schema_version (
		id 				INTEGER PRIMARY KEY NULL, 
		version			INTEGER NOT NULL,
		date_installed 	DATETIME NOT NULL
	);
	INSERT INTO sources (feed_url) VALUES('http://gdata.youtube.com/feeds/api/standardfeeds/most_recent?&orderby=published&max-results=50'); 
	INSERT INTO sources (feed_url) VALUES('http://gdata.youtube.com/feeds/api/standardfeeds/most_recent?&orderby=published&max-results=50&start-index=51'); 
	INSERT INTO sources (feed_url) VALUES('http://gdata.youtube.com/feeds/api/standardfeeds/most_discussed'); 
	INSERT INTO sources (feed_url) VALUES('http://gdata.youtube.com/feeds/api/standardfeeds/top_rated'); 
	INSERT INTO sources (feed_url) VALUES('http://gdata.youtube.com/feeds/api/standardfeeds/most_viewed');
	INSERT INTO sources (feed_url) VALUES('http://gdata.youtube.com/feeds/api/videos?q=slow+loris&orderby=published&max-results=25');";



// Update June 8, 2011
// I have to do all of this bullshit because SQLite doesn't support ALTER syntax
$schema_updates[] = "CREATE TEMPORARY TABLE videos_tmp (
		id 				INTEGER PRIMARY KEY NULL, 
		youtube_id		VARCHAR NULL UNIQUE,
		title 			CHAR(255) NULL,
		content			Text NULL,
		author			CHAR(255) NULL,
		date_added 		DATETIME NOT NULL,
		seen_in_feed 	DATETIME NOT NULL,
		removed			Boolean NULL DEFAULT 0,
		expired			Boolean NULL DEFAULT 0,
		date_posted 	DATETIME NULL);
		INSERT INTO videos_tmp (id,youtube_id,title,content,author,date_added,seen_in_feed,removed,expired,date_posted) 
			SELECT id,youtube_id,title,content,author,date_added,seen_in_feed,removed,expired,date_posted FROM videos;
		DROP TABLE videos;
		CREATE TABLE videos (
			id 				INTEGER PRIMARY KEY NULL, 
			youtube_id		VARCHAR NULL UNIQUE,
			title 			CHAR(255) NULL,
			content			Text NULL,
			author			CHAR(255) NULL,
			date_added 		DATETIME NOT NULL,
			seen_in_feed 	DATETIME NOT NULL,
			removed			Boolean NULL DEFAULT 0,
			expired			Boolean NULL DEFAULT 0,
			date_posted 	DATETIME NULL,
			feed			VARCHAR NULL); 
		INSERT INTO videos (id,youtube_id,title,content,author,date_added,seen_in_feed,removed,expired,date_posted) 
			SELECT id,youtube_id,title,content,author,date_added,seen_in_feed,removed,expired,date_posted FROM videos_tmp;
		DROP TABLE videos_tmp;";
?>