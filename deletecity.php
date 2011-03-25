<?php
/*
Plugin Name: Delete City
Plugin URI: http://deletecity.com
Description: DeleteCity saves videos from YouTube deletion by caching a shitload of them on your server, then checking back periodically to see if they have been taken down.
Version: 0.1.3
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

// Make sure we don't expose any info if called directly
if ( !function_exists( 'add_action' ) ) {
	echo "Hi there!  I'm just a plugin, not much I can do when called directly.";
	exit;
}

require_once("common.php");
global $cache_dir, $dcdb, $dclogfile, $runcache;
$dclogfile = dirname(__FILE__)."/deletecity.log";
$runcache = dirname(__FILE__)."/runcache.php";


// Make these into settings.
$max_age = 3;		// Videos will be deleted after 'max_age' days
$rate_limit="100k";	// Max speed that the videos will be downloaded


// --------------------------------------------------------------------------
// WARNINGS
add_action('admin_notices', 'deletecity_warning');
function deletecity_warning() {
	global $runcache, $cache_dir;
	
	if(!is_executable($runcache)):
	?>
	<div class='error fade'>
		<p>
		<strong>DeleteCity has detected a problem. <?php echo $runcache; ?> needs to be executable.</strong> 
		<a target="_blank" href="http://www.xaviermedia.com/documents/chmod755.php">How do I make a file executable?</a>
		</p>
	</div>
	<?php endif;
	
	if(!file_exists($cache_dir))
	{
		mkdir($cache_dir, 0777, true);
	}
	
	if(!is_writable($cache_dir)): ?>
		<div class='error fade'>
		<p><strong>DeleteCity has detected a problem. <?php echo $cache_dir; ?> needs to be writable.</strong></p>
		</div>
	<?php endif;
}


// --------------------------------------------------------------------------
// LOGGING
function dc_log($message) 
{
	global $dclogfile;
	$loghandle = fopen($dclogfile, 'a');
	fwrite($loghandle, "[deletecity] ".date("F j, Y, g:i a")." $message\n");
}


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
		dc_log("Adding caching event to schedule");
		wp_schedule_event(time(), get_option('cache_schedule'), 'runcache_function_hook' );
	}	
	
	if (!wp_next_scheduled('post_videos_function_hook'))
	{
		dc_log("Adding posting event to schedule");
		wp_schedule_event(time(), get_option('post_schedule'), 'post_videos_function_hook' );
	}
}


// --------------------------------------------------------------------------
// DEACTIVATION - remove caching and posting events.  
// Also kill caching process if it is running
register_deactivation_hook( __FILE__, 'deletecity_deactivate' );
function deletecity_deactivate()
{	
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
				dc_log("Killed process $pid");
			}
			else
			{
				dc_log("Warning: Couldn't kill caching process ($pid).");
				dc_log(posix_strerror($error));
			}
  		}
  		else
  		{
  			dc_log("Caching process ($pid) is not running.");
  		}
	}

	//`ps -ef | grep runcache | grep -v grep | awk '{print $2}' | xargs kill -9`;
	
	// Unregister the scheduled events
	if($timestamp = wp_next_scheduled('runcache_function_hook'))
	{
		dc_log("Removing caching event from schedule");
		wp_unschedule_event($timestamp, 'runcache_function_hook' );
	}
	if($timestamp = wp_next_scheduled('post_videos_function_hook'))
	{
		dc_log("Removing posting event from schedule");
		wp_unschedule_event($timestamp, 'post_videos_function_hook' );
	}
}


// --------------------------------------------------------------------------
// CACHING EVENT
add_action( 'runcache_function_hook', 'runcache' );
function runcache()
{
	global $dclogfile, $runcache, $rate_limit, $max_age;

	dc_log("Starting runcache");
	
	// run the cachiing process in the background.
	`php $runcache --ratelimit=$rate_limit --maxage=$max_age >> $dclogfile 2>&1 &`;
}


// --------------------------------------------------------------------------
// POSTING EVENT
add_action( 'post_videos_function_hook', 'post_removed_videos' );
function post_removed_videos()
{	
	$videos = Video::get_unposted_removed();
	
	if(count($videos) < 1)
	{
		dc_log("No videos to post");
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
	dc_log("Adding posts of removed videos");
	wp_insert_post( $my_post );
}


// --------------------------------------------------------------------------
// ADMIN MENU
if ( is_admin() )
{
	add_action('admin_menu', 'deletecity_admin_menu');
	function deletecity_admin_menu()
	{
		add_options_page('Delete City Settings', 'Delete City Settings', 'administrator', 'deletecity', 'deletecity_settings_page');
		add_plugins_page("Delete City Stats", "Delete City Stats", 'administrator', 'deletecity-stats', 'deletecity_stats_page' );
	}
	
	function deletecity_stats_page()
	{
		global $dcdb, $cache_dir, $dclogfile;
		?>
		
		<div style="padding-bottom: 40px;";>
			<h2>Delete City Stats</h2>
			
			<h4>Caching Status</h4>
			<?php if(file_exists(dirname(__FILE__)."/runcache.php.pid")): ?>
			<p>The caching process is currently running.</p>
			<?php else: ?>
			<p>The caching process is not running.</p>
			<?php endif;
			$ar=getDirectorySize($cache_dir); 
			?>
			
			<h4>Cache Directory</h4>
			<b>Path:</b> <?php echo $cache_dir; ?><br /> 
			<b>Total size:</b> <?php echo sizeFormat($ar['size']); ?><br /> 
			<b>No. of videos:</b> <?php echo $ar['count']; ?><br /> 

			<h4>Log</h4>
			<textarea name="log" id="log" style="width: 98%; height: 300px;"><?php readfile($dclogfile); ?></textarea>
			<script type="text/javascript">
			setInterval("log.scrollTop = log.scrollHeight", 1000);
			</script>
			
			<h4>Videos</h4>
			<?php
			$result = $dcdb->query("SELECT youtube_id FROM videos WHERE removed>0", SQLITE_ASSOC, $query_error); 
			if ($query_error)
				die("Error: $query_error"); 
				
			if (!$result)
				die("Error: Impossible to execute query.");
			?>
			<b>Removed Videos Found:</b> <?=$result->numRows()?><br />
		</div>
		<?php
	}
		
	function deletecity_settings_page()
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