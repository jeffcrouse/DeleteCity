<?php
require_once("Video.class.php");
date_default_timezone_set('UTC'); 

// This file is included by both the plugin file and runcache.php, which doesn't have all of the 
// WP_ constants and stuff.  So we need 2 different definitions.
if(defined("WP_CONTENT_DIR"))
{
	// The directory where the videos are stored
	//$cache_dir = WP_CONTENT_DIR."/dc_cache";
	
	// Where the SQLite database will be stored
	$dcdbfile =  WP_CONTENT_DIR."/deletecity.rsd";
}
else
{
	// The directory where the videos are stored
	//$cache_dir = dirname(__FILE__)."/../../dc_cache";
	
	// Where the SQLite database will be stored
	$dcdbfile = dirname(__FILE__)."/../../deletecity.rsd";
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




// FUNCTIONS

function get_web_page( $url )
{
    $ch      = curl_init( $url );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_HEADER, false );
	curl_setopt( $ch, CURLOPT_ENCODING, "" );
	curl_setopt( $ch, CURLOPT_USERAGENT, "spider" );
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


/**
 * PARSE ARGUMENTS
 * 
 * This command line option parser supports any combination of three types
 * of options (switches, flags and arguments) and returns a simple array.
 * 
 * [pfisher ~]$ php test.php --foo --bar=baz
 *   ["foo"]   => true
 *   ["bar"]   => "baz"
 * 
 * [pfisher ~]$ php test.php -abc
 *   ["a"]     => true
 *   ["b"]     => true
 *   ["c"]     => true
 * 
 * [pfisher ~]$ php test.php arg1 arg2 arg3
 *   [0]       => "arg1"
 *   [1]       => "arg2"
 *   [2]       => "arg3"
 * 
 * [pfisher ~]$ php test.php plain-arg --foo --bar=baz --funny="spam=eggs" --also-funny=spam=eggs \
 * > 'plain arg 2' -abc -k=value "plain arg 3" --s="original" --s='overwrite' --s
 *   [0]       => "plain-arg"
 *   ["foo"]   => true
 *   ["bar"]   => "baz"
 *   ["funny"] => "spam=eggs"
 *   ["also-funny"]=> "spam=eggs"
 *   [1]       => "plain arg 2"
 *   ["a"]     => true
 *   ["b"]     => true
 *   ["c"]     => true
 *   ["k"]     => "value"
 *   [2]       => "plain arg 3"
 *   ["s"]     => "overwrite"
 *
 * @author              Patrick Fisher <patrick@pwfisher.com>
 * @since               August 21, 2009
 * @see                 http://www.php.net/manual/en/features.commandline.php
 *                      #81042 function arguments($argv) by technorati at gmail dot com, 12-Feb-2008
 *                      #78651 function getArgs($args) by B Crawford, 22-Oct-2007
 * @usage               $args = CommandLine::parseArgs($_SERVER['argv']);
 */
function parseArgs($argv){

	array_shift($argv);
	$out                            = array();

	foreach ($argv as $arg){

		// --foo --bar=baz
		if (substr($arg,0,2) == '--'){
			$eqPos                  = strpos($arg,'=');

			// --foo
			if ($eqPos === false){
				$key                = substr($arg,2);
				$value              = isset($out[$key]) ? $out[$key] : true;
				$out[$key]          = $value;
			}
			// --bar=baz
			else {
				$key                = substr($arg,2,$eqPos-2);
				$value              = substr($arg,$eqPos+1);
				$out[$key]          = $value;
			}
		}
		// -k=value -abc
		else if (substr($arg,0,1) == '-'){

			// -k=value
			if (substr($arg,2,1) == '='){
				$key                = substr($arg,1,1);
				$value              = substr($arg,3);
				$out[$key]          = $value;
			}
			// -abc
			else {
				$chars              = str_split(substr($arg,1));
				foreach ($chars as $char){
					$key            = $char;
					$value          = isset($out[$key]) ? $out[$key] : true;
					$out[$key]      = $value;
				}
			}
		}
		// plain-arg
		else {
			$value                  = $arg;
			$out[]                  = $value;
		}
	}
	return $out;
}

/**
 * GET BOOLEAN
 */
function getBoolean($key, $default = false){
	if (!isset(self::$args[$key])){
		return $default;
	}
	$value                          = self::$args[$key];
	if (is_bool($value)){
		return $value;
	}
	if (is_int($value)){
		return (bool)$value;
	}
	if (is_string($value)){
		$value                      = strtolower($value);
		$map = array(
			'y'                     => true,
			'n'                     => false,
			'yes'                   => true,
			'no'                    => false,
			'true'                  => true,
			'false'                 => false,
			'1'                     => true,
			'0'                     => false,
			'on'                    => true,
			'off'                   => false,
		);
		if (isset($map[$value])){
			return $map[$value];
		}
	}
	return $default;
}

function display_xml_error($error, $xml)
{
    $return  = $xml[$error->line - 1] . "\n";
    $return .= str_repeat('-', $error->column) . "^\n";

    switch ($error->level)
    {
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

    if ($error->file)
    {
        $return .= "\n  File: $error->file";
    }

    return "$return\n\n--------------------------------------------\n\n";
}


function getDirectorySize($path) 
{ 
	$totalsize = 0; 
	$totalcount = 0; 
	$dircount = 0; 
	if ($handle = opendir ($path)) 
	{ 
		while (false !== ($file = readdir($handle))) 
		{ 
			$nextpath = $path . '/' . $file; 
			if ($file != '.' && $file != '..' && !is_link ($nextpath)) 
			{ 
				if (is_dir ($nextpath)) 
				{ 
					$dircount++; 
					$result = getDirectorySize($nextpath); 
					$totalsize += $result['size']; 
					$totalcount += $result['count']; 
					$dircount += $result['dircount']; 
				} 
				elseif (is_file ($nextpath)) 
				{ 
					$totalsize += filesize ($nextpath); 
					$totalcount++; 
				} 
			} 
		} 
	} 
	closedir ($handle); 
	$total['size'] = $totalsize; 
	$total['count'] = $totalcount; 
	$total['dircount'] = $dircount; 
	return $total; 
} 

function sizeFormat($size) 
{ 
    if($size<1024) 
    { 
        return $size." bytes"; 
    } 
    else if($size<(1024*1024)) 
    { 
        $size=round($size/1024,1); 
        return $size." KB"; 
    } 
    else if($size<(1024*1024*1024)) 
    { 
        $size=round($size/(1024*1024),1); 
        return $size." MB"; 
    } 
    else 
    { 
        $size=round($size/(1024*1024*1024),1); 
        return $size." GB"; 
    }
}  
?>
