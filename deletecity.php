<?php
/*
Plugin Name: Delete City
Plugin URI: http://URI_Of_Page_Describing_Plugin_and_Updates
Description: DeleteCity saves videos from YouTube deletion
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

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('error_log', $logfile);


// --------------------------------------------------------------------------
// ACTIVATION
register_activation_hook( __FILE__, 'deletecity_activate');
function deletecity_activate()
{	
	$logfile =  dirname(__FILE__)."/deletecity.log";
	$fh = fopen($logfile, 'a');
	
	if (!wp_next_scheduled('runcache_function_hook'))
	{
		fwrite($fh, "[deletecity] ".date("F j, Y, g:i a")." Activating Plugin HOURLY\n");
		wp_schedule_event(time(), 'hourly', 'runcache_function_hook' );
	}
	else
	{
		fwrite($fh, "[deletecity] ".date("F j, Y, g:i a")." Caching event already present\n");
	}
}


// --------------------------------------------------------------------------
// DEACTIVATION
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
	
	// Unregister the scheduled event
	if($timestamp = wp_next_scheduled('runcache_function_hook'))
	{
		fwrite($fh, "[deletecity] ".date("F j, Y, g:i a")." Deactivating Plugin\n");
		wp_unschedule_event($timestamp, 'runcache_function_hook' );
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
	$script = dirname(__FILE__)."/runcache.php";
	$logfile = dirname(__FILE__)."/deletecity.log";
	`$script >> $logfile 2>&1 &`;
}

?>