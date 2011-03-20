<?php
/*
Plugin Name: Delete City
Plugin URI: http://deletecity.com
Description: DeleteCity saves videos from YouTube deletion by caching a shitload of them on your 
server, then checking back periodically to see if they have been taken down.
Version: 0.1
Author: Jeff Crouse
Author URI: http://jeffcrouse.info
License: GPL2

Copyright 2011  Jeff Crouse  (email : jeff@crouse.cc)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License, version 2, as 
	published by the Free Software Foundation.
	
	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.
	
	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

// --------------------------------------------------------------------------
// DEFINE SOME FREQUENCIES
/*
Array
(
    [hourly] => Array
        (
            [interval] => 3600
            [display] => Once Hourly
        )
 
    [twicedaily] => Array
        (
            [interval] => 43200
            [display] => Twice Daily
        )
 
    [daily] => Array
        (
            [interval] => 86400
            [display] => Once Daily
        )
 
)
*/
add_filter('cron_schedules', 'deletecity_cron_definer');    
function deletecity_cron_definer($schedules)
{
  $schedules['weekly'] = array(
      'interval'=> 60*60*24*7,
      'display'=>  __('Once every 7 days')
  );
  return $schedules;
}


// --------------------------------------------------------------------------
// ACTIVATION - Add caching and posting events
register_activation_hook( __FILE__, 'deletecity_activate');
function deletecity_activate()
{	
	$logfile =  dirname(__FILE__)."/deletecity.log";
	$fh = fopen($logfile, 'a');
	
	// Add the two events to th eschedule
	if (!wp_next_scheduled('runcache_function_hook'))
	{
		fwrite($fh, "[deletecity] ".date("F j, Y, g:i a")." Adding caching event to schedule\n");
		wp_schedule_event(time(), 'twicedaily', 'runcache_function_hook' );
	}	
	
	if (!wp_next_scheduled('make_post_function_hook'))
	{
		fwrite($fh, "[deletecity] ".date("F j, Y, g:i a")." Adding posting event to schedule\n");
		wp_schedule_event(time(), 'weekly', 'post_removed_videos_function_hook' );
	}
}


// --------------------------------------------------------------------------
// DEACTIVATION - remove caching and posting events.  
// Also kill caching process if it is running
register_deactivation_hook( __FILE__, 'deletecity_deactivate' );
function deletecity_deactivate()
{
	$logfile =  dirname(__FILE__)."/deletecity.log";
	$fh = fopen($logfile, 'a');
	
	// Kill the runcache process if it is running.
	$pid_file = dirname(__FILE__)."/runcache.php.pid";
	if(file_exists($pid_file))
	{
		$pid = (int)trim(file_get_contents($pid_file));
  		if(posix_kill($pid, 0)) // see if process is running
  		{
  			posix_kill($pid, 9);
			$error = posix_get_last_error();
			if($error==0)
			{
				fwrite($fh, "[deletecity] ".date("F j, Y, g:i a")." Killed process $pid\n");
			}
			else
			{
				fwrite($fh, "[deletecity] ".date("F j, Y, g:i a")." Warning: Couldn't kill caching process ($pid).\n");
				fwrite($fb, posix_strerror($error));
			}
  		}
  		else
  		{
  			fwrite($fh, "[deletecity] ".date("F j, Y, g:i a")." Caching process ($pid) is not running.\n");
  		}
	}

	//`ps -ef | grep runcache | grep -v grep | awk '{print $2}' | xargs kill -9`;
	
	// Unregister the scheduled events
	if($timestamp = wp_next_scheduled('runcache_function_hook'))
	{
		fwrite($fh, "[deletecity] ".date("F j, Y, g:i a")." Removing caching event from schedule\n");
		wp_unschedule_event($timestamp, 'runcache_function_hook' );
	}
	if($timestamp = wp_next_scheduled('make_post_function_hook'))
	{
		fwrite($fh, "[deletecity] ".date("F j, Y, g:i a")." Removing posting event from schedule\n");
		wp_unschedule_event($timestamp, 'make_post_function_hook' );
	}
}


// --------------------------------------------------------------------------
// CACHING EVENT
add_action( 'runcache_function_hook', 'runcache' );
function runcache()
{
	$logfile =  dirname(__FILE__)."/deletecity.log";
	$fh = fopen($logfile, 'a');
	fwrite($fh, "[deletecity] ".date("F j, Y, g:i a")." Starting runcache\n");
	$runcache = dirname(__FILE__)."/runcache.php";
	$logfile = dirname(__FILE__)."/deletecity.log";
	
	// run the cachiing process in the background.
	`$runcache >> $logfile 2>&1 &`;
}


// --------------------------------------------------------------------------
// POSTING EVENT
add_action( 'post_removed_videos_function_hook', 'post_removed_videos' );
function post_removed_videos()
{
	require_once("common.php");
	
	$logfile =  dirname(__FILE__)."/deletecity.log";
	$fh = fopen($logfile, 'a');
	fwrite($fh, "[deletecity] ".date("F j, Y, g:i a")." Adding posts of removed videos\n");
	
	$sql = "SELECT id, youtube_id, title, content, author, date_added, date_updated, removed,
		round(strftime('%J', datetime('now'))-strftime('%J', date_added), 2) as age
		FROM videos WHERE removed > 0";
	
	$result = $db->query($sql, SQLITE_ASSOC, $query_error); 
	if ($query_error)
		die("Error: $query_error"); 
		
	if (!$result)
		die("Impossible to execute query.");
	
	if($result->numRows()<1)
	{
		return;
	}
	
	$content = "<ol>";
	while ($row = $result->fetch(SQLITE_ASSOC))
	{ 
		$url = "http://www.youtube.com/watch?v={$row['youtube_id']}";
		$content = "<li><b><a href=\"$url\">$row['title']</a></b>: $row['content']</li>";
	}
	$content .= "</ol>";
	
	// Create post object
	$title = 'DeleteCity: Removed Videos of '.date("F j, Y, g:i a");
	$my_post = array(
		'post_title' => $title,
		'post_content' => $content,
		'post_status' => 'publish',
		'post_author' => 1,
		'post_category' => array()
	);
	
	// Insert the post into the database
	wp_insert_post( $my_post );
}
?>