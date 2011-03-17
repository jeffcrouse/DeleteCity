<?php
require_once("config.php");

$dbfile = "deletecity.rsd";
$db = new SQLiteDatabase($dbfile);
if (!$db)
{
	$error = (file_exists($dbfile)) 
		? "Impossible to open, check permissions\n" 
		: "Impossible to create, check permissions\n";
	die($error);
}

$q = @$db->query("SELECT id FROM videos WHERE id=1");
if (!$q)
{
	$db->queryExec("CREATE TABLE videos (
		id 				INTEGER PRIMARY KEY NULL, 
		youtube_id		VarChar NULL,
		title 			CHAR(255) NULL,
		author			CHAR(255) NULL,
		date_added 		DATETIME NOT NULL,
		date_updated 	DATETIME DEFAULT CURRENT_TIMESTAMP,
		removed			Boolean NULL DEFAULT 0
	);", $query_error);
	if ($query_error)
		die("Error: $query_error");
}


// FUNCTIONS

function get_web_page( $url )
{
	global $user_agent;

    $options = array(
        CURLOPT_RETURNTRANSFER => true,     // return web page
        CURLOPT_HEADER         => false,    // don't return headers
        CURLOPT_FOLLOWLOCATION => true,     // follow redirects
        CURLOPT_ENCODING       => "",       // handle all encodings
        CURLOPT_USERAGENT      => $user_agent, // who am i
        CURLOPT_AUTOREFERER    => true,     // set referer on redirect
        CURLOPT_CONNECTTIMEOUT => 120,      // timeout on connect
        CURLOPT_TIMEOUT        => 120,      // timeout on response
        CURLOPT_MAXREDIRS      => 10,       // stop after 10 redirects
    );

    $ch      = curl_init( $url );
    curl_setopt_array( $ch, $options );
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
?>
