<?php
require_once("config.php");

$db = new SQLiteDatabase($dbfile);
if (!$db)
{
	$error = (file_exists($dbfile)) 
		? "Error: Impossible to open database file, check permissions\n" 
		: "Error: Impossible to create database file, check permissions\n";
	die($error);
}

$q = @$db->query("SELECT id FROM videos WHERE id=1");
if (!$q)
{
	$db->queryExec("
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
		posted			Boolean NULL DEFAULT 0
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


// FUNCTIONS

function get_web_page( $url )
{
	global $user_agent;

    $ch      = curl_init( $url );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_HEADER, false );
	curl_setopt( $ch, CURLOPT_ENCODING, "" );
	curl_setopt( $ch, CURLOPT_USERAGENT, $user_agent );
	curl_setopt( $ch, CURLOPT_AUTOREFERER, true );
	curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 120 );
	curl_setopt( $ch, CURLOPT_TIMEOUT, 120 );
	curl_setopt( $ch, CURLOPT_MAXREDIRS, 10 );
    $content = curl_exec( $ch );
    $err     = curl_errno( $ch );
    $errmsg  = curl_error( $ch );
    $header  = curl_getinfo( $ch );
    curl_close( $ch );

    $header['errno']   = $err;
    $header['errmsg']  = $errmsg;
    $header['content'] = $content;
    return $header;
}


function parseArgs($argv)
{
    array_shift($argv); $o = array();
    foreach ($argv as $a){
        if (substr($a,0,2) == '--'){ $eq = strpos($a,'=');
            if ($eq !== false){ $o[substr($a,2,$eq-2)] = substr($a,$eq+1); }
            else { $k = substr($a,2); if (!isset($o[$k])){ $o[$k] = true; } } }
        else if (substr($a,0,1) == '-'){
            if (substr($a,2,1) == '='){ $o[substr($a,1,1)] = substr($a,3); }
            else { foreach (str_split(substr($a,1)) as $k){ if (!isset($o[$k])){ $o[$k] = true; } } } }
        else { $o[] = $a; } }
    return $o;
}

function display_xml_error($error, $xml)
{
    $return  = $xml[$error->line - 1] . "\n";
    $return .= str_repeat('-', $error->column) . "^\n";

    switch ($error->level) {
        case LIBXML_ERR_WARNING:
            $return .= "Warning $error->code: ";
            break;
         case LIBXML_ERR_ERROR:
            $return .= "Error $error->code: ";
            break;
        case LIBXML_ERR_FATAL:
            $return .= "Fatal Error $error->code: ";
            break;
    }

    $return .= trim($error->message) .
               "\n  Line: $error->line" .
               "\n  Column: $error->column";

    if ($error->file) {
        $return .= "\n  File: $error->file";
    }

    return "$return\n\n--------------------------------------------\n\n";
}
?>
