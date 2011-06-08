<?php
require_once("schema.php");	// this is where all of the schema info is.

// This file will be included either from a Wordpress page or from a command line script.  
// If it is from Wordpress, the SQLite file is stored in a Wordpress option.
// If it is the command line script, the file is passed in as a '--db' command line argument.
$dcdbfile = NULL; // FIND A VALUE FOR THIS!

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



// Now try to construct the database reference that will be used throughout the plugin
$dcdb = new SQLiteDatabase($dcdbfile);
if (!$dcdb)
{
	$error = (file_exists($dcdbfile)) 
		? "Error: Impossible to open database file, check permissions\n" 
		: "Error: Impossible to create database file, check permissions\n";
	die($error);
}



// Determine which schema update to start with.
$sql="SELECT version FROM schema_version ORDER BY date_installed DESC LIMIT 1";
$q = @$dcdb->query($sql, SQLITE_ASSOC, $query_error); 
if (!$q) $current_version = -1;
else
{
	$row = $q->fetch(SQLITE_ASSOC);
	$current_version = $row['version'];	
}


//Run all necessary schema updates
for($i=$current_version+1; $i<count($schema_updates); $i++)
{
	$dcdb->queryExec($schema_updates[$i], $query_error);
	if ($query_error) die("Error: $query_error");

	// Bump up the version number
	$dcdb->queryExec("INSERT INTO schema_version (version, date_installed) VALUES ($i, DATETIME('now'));", $query_error);
	if ($query_error) die("Error: $query_error"); 
}

?>