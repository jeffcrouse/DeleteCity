<?php
/*
Plugin Name: Delete City
Plugin URI: http://URI_Of_Page_Describing_Plugin_and_Updates
Description: A brief description of the Plugin.
Version: The Plugin's Version Number, e.g.: 1.0
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


$logfile =  dirname(__FILE__)."/deletecity.log";


// --------------------------------------------------------------------------
// ACTIVATION
register_activation_hook( __FILE__, 'deletecity_activate');
function deletecity_activate()
{
	global $logfile;
	
	if (!wp_next_scheduled('runcache_function_hook'))
	{
		$fh = fopen($logfile, 'a');
		fwrite($fh, "[deletecity] ".date("F j, Y, g:i a")." Activating Plugin HOURLY\n");
		wp_schedule_event( time(), 'hourly', 'runcache_function_hook' );
	}
}


// --------------------------------------------------------------------------
// DEACTIVATION
register_deactivation_hook( __FILE__, 'deletecity_deactivate' );
function deletecity_deactivate()
{
	global $logfile;
	
	// Kill all running caching processes
	`ps -ef | grep runcache | grep -v grep | awk '{print $2}' | xargs kill -9 >> $logfile`;
	
	// Unregister the scheduled event
	if($timestamp = wp_next_scheduled('runcache_function_hook'))
	{
		$fh = fopen($logfile, 'a');
		fwrite($fh, "[deletecity] ".date("F j, Y, g:i a")." Deactivating Plugin\n");
		wp_unschedule_event($timestamp, 'runcache_function_hook' );
	}
}


// --------------------------------------------------------------------------
// CACHING EVENT
add_action( 'runcache_function_hook', 'runcache' );
function runcache()
{
	global $logfile;	
	$fh = fopen($logfile, 'a');
	
	$pid = new pid(dirname(__FILE__));
	if($pid->already_running)
	{
		fwrite($fh, "[deletecity] ".date("F j, Y, g:i a")." runcache is already running.\n");
		exit;
	}
	else
	{
		fwrite($fh, "[deletecity] ".date("F j, Y, g:i a")." Starting runcache\n");
		$script = dirname(__FILE__)."/runcache.php";
		$logfile = dirname(__FILE__)."/deletecity.log";
		`$script >> $logfile 2>&1 &`;
	}
}
?>