<?php
/*
Plugin Name: Delete City
Plugin URI: http://deletecity.com
Description: DeleteCity saves videos from YouTube deletion by caching a shitload of them on your server, then checking back periodically to see if they have been taken down.
Version: 0.1.2
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
	
	if(!get_option('cache_schedule'))
	{
		add_option('cache_schedule', 'twicedaily');
	}
	
	if(!get_option('post_schedule'))
	{
		add_option('post_schedule', 'weekly');
	}
	
	// Add the two events to th eschedule
	if (!wp_next_scheduled('runcache_function_hook'))
	{
		fwrite($fh, "[deletecity] ".date("F j, Y, g:i a")." Adding caching event to schedule\n");
		wp_schedule_event(time(), get_option('cache_schedule'), 'runcache_function_hook' );
	}	
	
	if (!wp_next_scheduled('post_videos_function_hook'))
	{
		fwrite($fh, "[deletecity] ".date("F j, Y, g:i a")." Adding posting event to schedule\n");
		wp_schedule_event(time(), get_option('post_schedule'), 'post_videos_function_hook' );
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
	if($timestamp = wp_next_scheduled('post_videos_function_hook'))
	{
		fwrite($fh, "[deletecity] ".date("F j, Y, g:i a")." Removing posting event from schedule\n");
		wp_unschedule_event($timestamp, 'post_videos_function_hook' );
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
	
	// run the cachiing process in the background.
	`$runcache >> $logfile 2>&1 &`;
}


// --------------------------------------------------------------------------
// POSTING EVENT
add_action( 'post_videos_function_hook', 'post_removed_videos' );
function post_removed_videos()
{	
	$logfile =  dirname(__FILE__)."/deletecity.log";
	$fh = fopen($logfile, 'a');
	
	$videos = Video::get_unposted_removed();
	
	if(count($videos) < 1)
	{
		fwrite($fh, "[deletecity] ".date("F j, Y, g:i a")." No videos to post\n");
		return;
	}
	
	$content = "<ol>";
	foreach($videos as $video)
	{ 
		$url = sprintf("%s/%s", WP_PLUGIN_URL, str_replace(basename( __FILE__), "", $video->vid_path));
		$content .= "<li><b><a href=\"$url\">{$video->title}</a></b>: {$video->content}</li>";
		$video->mark_as_posted();
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
	fwrite($fh, "[deletecity] ".date("F j, Y, g:i a")." Adding posts of removed videos\n");
	wp_insert_post( $my_post );
}


// --------------------------------------------------------------------------
// ADMIN MENU
if ( is_admin() )
{
	require_once("common.php");
	
	add_action('admin_menu', 'deletecity_admin_menu');
	function deletecity_admin_menu()
	{
		add_options_page('Delete City', 'Delete City', 'administrator', 'deletecity', 'deletecity_html_page');
	}
	
	function deletecity_html_page()
	{
		global $dcdb;
	
		//must check that the user has the required capability 
		if (!current_user_can('manage_options'))
		{
			wp_die( __('You do not have sufficient permissions to access this page.') );
		} 
		
		$result = $dcdb->query("SELECT feed_url FROM sources", SQLITE_ASSOC, $query_error); 
		if ($query_error)
			die("Error: $query_error"); 
			
		if (!$result)
			die("Error: Impossible to execute query.");
		
		$urls = "";
		while($row = $result->fetch(SQLITE_ASSOC))
		{
			$urls .= $row['feed_url']."\n";
		}
		$schedules = wp_get_schedules();
		?>
	
		<div>
		<h2>Delete City Options</h2>
		<?php if(file_exists(dirname(__FILE__)."/runcache.php.pid")): ?>
		<p style="color: #FF0000;">WARNING:  The caching process is currently running.  Saving options now will restart it.</p>
		<?php endif; ?>
		<form method="post" action="">

			<h3>Cache Frequency</h3>
			<p>How often should DeleteCity download videos from the sources?</p>
			<ul>
			<?php foreach($schedules as $slug => $schedule): ?>
			<li>
				<?php $checked = (get_option('cache_schedule')==$slug) ? 'checked' : ''; ?>
				<input type="radio" name="dc-cache-schedule" value="<?php echo $slug; ?>" <?php echo $checked; ?> /> 
				<?php echo $schedule['display']; ?>
			</li>
			<?php endforeach; ?>
			</ul>
			
			<h3>Post Frequency</h3>
			<p>How often should DeleteCity post the videos it finds to your blog?</p>
			<ul>
			<?php foreach($schedules as $slug => $schedule): ?>
			<li>
				<?php $checked = (get_option('post_schedule')==$slug) ? 'checked' : ''; ?>
				<input type="radio" name="dc-post-schedule" value="<?php echo $slug; ?>" <?php echo $checked; ?> /> 
				<?php echo $schedule['display']; ?>
			</li>
			<?php endforeach; ?>
			</ul>
			
			<h3>Sources</h3>
			<p>Check out <a href="http://code.google.com/apis/youtube/2.0/reference.html#Searching_for_videos">
			this page for more information about source URLS</a>.</p>
			<textarea name="dc-sources" style="width: 90%; height: 200px;"><?php echo $urls?></textarea>

			<p>
				<input type="hidden" name="dc_submit_options" value="true" />
				<input type="submit" value="<?php _e('Save Changes') ?>" />
			</p>
			
		</form>
		</div>
	<?php
	}
	
	// Handle submitted data
	if(isset($_REQUEST['dc_submit_options']) && $_REQUEST['dc_submit_options']=='true')
	{
		$dcdb->queryExec("DELETE FROM sources;", $query_error);
		if ($query_error)
		{
			die("Error: $query_error");
		}
		$sources = explode("\n", $_REQUEST['dc-sources']);
		foreach($sources as $source)
		{
			$source=trim($source);
			if(empty($source))
			{
				continue;
			}
			$source = sqlite_escape_string($source);
			$dcdb->queryExec("INSERT INTO sources (feed_url) VALUES('$source');", $query_error);
			if ($query_error)
			{
				die("Error: $query_error");
			}
		}
	
		update_option('cache_schedule', $_REQUEST['dc-cache-schedule']);
		update_option('post_schedule',  $_REQUEST['dc-post-schedule']);
	
		deletecity_deactivate();
		deletecity_activate();	
	}
	
}
?>