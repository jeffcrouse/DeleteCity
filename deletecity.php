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
require_once("Video.class.php");
require_once("dcdb.php");

ini_set('display_errors', 1); 
error_reporting(E_ALL);
date_default_timezone_set('UTC'); 

global $dcdb, $dclogfile, $runcache;
$dclogfile = dirname(__FILE__)."/deletecity.log";
$runcache = dirname(__FILE__)."/runcache.php";


// Make these into settings.
$max_age = 3;		// Videos will be deleted after 'max_age' days
$rate_limit="100k";	// Max speed that the videos will be downloaded


// --------------------------------------------------------------------------
// WARNINGS
add_action('admin_notices', 'deletecity_warning');
function deletecity_warning()
{
	global $runcache;
	$youtube_dl = dirname(__FILE__)."/youtube-dl";
	
	$not_executable = array();
	if(!is_executable($runcache)) $not_executable[] = $runcache;
	if(!is_executable($youtube_dl)) $not_executable[] = $youtube_dl;
	
	if(!is_executable($runcache) || !is_executable($youtube_dl)):
	?>
	<div class='error fade'>
		<p>
		<strong>DeleteCity has detected a problem. The following files need to be executable.</strong> 
		<ul><li>
		<?php echo implode($not_executable, "</li><li>"); ?>
		</li></ul>
		<a target="_blank" href="http://www.xaviermedia.com/documents/chmod755.php">How do I make a file executable?</a>
		</p>
	</div>
	<?php endif;
	
	if(!file_exists(get_option('dc_cache_dir')))
	{
		mkdir(get_option('dc_cache_dir'), 0777, true);
	}
	
	if(!is_writable(get_option('dc_cache_dir'))): ?>
		<div class='error fade'>
		<p><strong>DeleteCity has detected a problem. <?php echo get_option('dc_cache_dir'); ?> needs to be writable.</strong></p>
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
	if(!get_option('dc_cache_schedule'))
	{
		add_option('dc_cache_schedule', 'twicedaily');
	}
	
	if(!get_option('dc_post_schedule'))
	{
		add_option('dc_post_schedule', 'weekly');
	}
	
	if(!get_option('dc_cache_dir'))
	{
		add_option('dc_cache_dir', WP_CONTENT_DIR."/dc_cache");
	}
	
	if(!get_option('dc_db_file'))
	{
		add_option('dc_db_file',  WP_CONTENT_DIR."/deletecity.rsd");
	}
	
	// Add the two events to th eschedule
	if (!wp_next_scheduled('runcache_function_hook'))
	{
		dc_log("Adding caching event to schedule");
		wp_schedule_event(time(), get_option('dc_cache_schedule'), 'runcache_function_hook' );
	}	
	
	if (!wp_next_scheduled('post_videos_function_hook'))
	{
		dc_log("Adding posting event to schedule");
		wp_schedule_event(time(), get_option('dc_post_schedule'), 'post_videos_function_hook' );
	}
}


// --------------------------------------------------------------------------
// DEACTIVATION - kill cache process, remove caching and posting events.  
register_deactivation_hook( __FILE__, 'deletecity_deactivate' );
function deletecity_deactivate()
{	
	stopcache();
	
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
	
	if(filesize($dclogfile) > 1024*1024*10)
	{
		$newname = "deletecity-".date('h-i-s-j-m-y').".log";
		rename($dclogfile, $newname);
	}

	dc_log("Starting runcache");
	
	$dir = get_option('dc_cache_dir');
	$db = get_option('dc_db_file');
	// run the cachiing process in the background.
	`php $runcache --ratelimit=$rate_limit --maxage=$max_age --cachedir=$dir --db=$db >> $dclogfile 2>&1 &`;
}


// --------------------------------------------------------------------------
function stopcache()
{
	// Kill the runcache process if it is running.
	// It would be great to use the pcntl here, but it's not widely supported
	$process = trim(`ps aux | grep runcache | grep -v grep`);
	if(empty($process))
	{
		dc_log("Caching process is not running.");
	}
	else
	{
		$parts = preg_split("|\s+|", $process);
		$pid = (int)$parts[1];
  		if(posix_kill($pid, 0)) // see if process is running
  		{
  			posix_kill($pid, 9);
			$error = posix_get_last_error();
			if($error==0)
			{
				dc_log("Killed process $pid");
				$pid_file = dirname(__FILE__)."/runcache.php.pid";
				if(file_exists($pid_file))
				{
					unlink($pid_file);
				}
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
	// Handle submitted data
	if(isset($_REQUEST['dc_do_action']) && $_REQUEST['dc_do_action']=='true')
	{
		switch($_REQUEST['action'])
		{
			case 'stopcache':
				stopcache();
				break;
			case 'runcache':
				runcache();
				break;
			case 'post_videos':
				post_removed_videos();
				break;
			case 'save_options':
				
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
				update_option('dc_cache_schedule', $_REQUEST['dc-cache-schedule']);
				update_option('dc_post_schedule',  $_REQUEST['dc-post-schedule']);
				deletecity_deactivate();
				deletecity_activate();
				break;
		}
	}


	// --------------------------------------------
	add_action('admin_menu', 'deletecity_admin_menu');
	function deletecity_admin_menu()
	{
		add_options_page('Delete City Settings', 'Delete City Settings', 'administrator', 'deletecity', 'deletecity_settings_page');
		add_plugins_page("Delete City Status", "Delete City Status", 'administrator', 'deletecity-status', 'deletecity_status_page' );
	}


	// --------------------------------------------
	function deletecity_status_page()
	{
		global $dclogfile, $dcdb;
		
		$x = WP_PLUGIN_URL.'/'.str_replace(basename( __FILE__),"",plugin_basename(__FILE__));
		$process = trim(`ps aux | grep runcache | grep -v grep`);
		?>
		
		<div style="padding-bottom: 40px;";>
			<h2>Delete City Status</h2>
 
			<?php if(empty($process)): ?>
				The caching process is not running.
			<?php else: ?>
				<img src="<?php echo $x; ?>/ajax-loader.gif" /> <a title="<?php echo  $process; ?>">The cache process is running.</a>
			<?php endif; ?>
			<br />

			<textarea readonly name="log" id="log" style="width: 95%; height: 100px;"></textarea>

			<p>
			<form method="post" action="">
				<?php if(empty($process)): ?>
				<button name="action" value="runcache" type="submit">Run Cache Now</button>
				<?php else: ?>
				<button name="action" value="stopcache" type="submit">Stop Cache Now</button>
				<?php endif; ?>
				<button name="action" value="post_videos" type="submit">Post Videos Now</button>
				<input type="hidden" name="dc_do_action" value="true" />
			</form>
			</p>
			
			<h2>Videos</h2>
			<p>all <input type="radio" name="filter" value="all" checked /> removed <input type="radio" name="filter" value="removed" /></p>
			
			<div id="videos" style="width: 95%;"></div>				
			
			<script type="text/javascript" src="http://ajax.googleapis.com/ajax/libs/jquery/1.4.1/jquery.min.js"></script>
			<script type="text/javascript">
			$.ajaxSetup({cache:false});
			function refresh_dc()
			{
				var filter = $('input[name=filter]:checked').val();
				var data = {action: 'dc_get_vids', page: '1', 'filter': filter};
				$("#videos").load(ajaxurl, data);
				$("#log").load("<?php echo $x ?>/deletecity.log", function() {
					$("#log").scrollTop($("#log")[0].scrollHeight);
				});
			}
			refresh_dc();
			setInterval(refresh_dc, 5000);
			</script>

		</div>
		<?php
	}

	// --------------------------------------------
	// http://codex.wordpress.org/AJAX_in_Plugins
	add_action('wp_ajax_dc_get_vids', 'dc_get_vids');
	function dc_get_vids()
	{
		global $dcdb, $dclogfile;
	
		$page = $_POST['page'];
		$filter = $_POST['filter'];
	
		$sql="SELECT youtube_id FROM videos";
		if($filter=='removed')
		{
			$sql .= " WHERE removed>0 ";
		}
		$sql .= " ORDER BY date_added DESC";
		$result = $dcdb->query($sql, SQLITE_ASSOC, $query_error); 
		if ($query_error)
			die("Error: $query_error"); 
			
		if (!$result)
			die("Error: Impossible to execute query.");
		
		$total = $result->numRows();
		$ar=getDirectorySize(get_option('dc_cache_dir')); 
		echo $filter;
		?>
		
		<b>Total Cache Size:</b> <?php echo floor($ar['count']/2); ?> videos, <?php echo sizeFormat($ar['size']); ?><br /> 
		<br /> 
		<?php
		if($total==0)
		{
			print "There are no videos that match that request.";
			die();
		}
		
		$i=$total;
		
		while($row = $result->fetch(SQLITE_ASSOC))
		{ 
			$video = new Video( get_option('dc_cache_dir'), $row['youtube_id'] );
			?>
			<div style="width: 250px; float: left; padding-bottom: 20px;">
				<div style="height: 20px; width: 220px; font-weight: bold;">
					[<?php echo $i; ?>/<?php echo $total; ?>] <?php echo $video->title; ?>
				</div>
				<a href="<?php echo $video->vid_url; ?>" target="_blank">
					<img src="<?php echo $video->thumb_url; ?>" style="width: 240px; height: 180px;" />
				</a>
				<b>age: </b> <?php echo ($video->age > 1) ? "{$video->age} days" : ($video->age*24)." hours"; ?><br />
				<b>by: </b> <a href="http://www.youtube.com/user/<?php echo $video->author; ?>" target="_blank"><?php echo $video->author; ?></a>
			</div>
			<?php
			$i--;
		}
		die(); // this is required to return a proper result
	}

	
	//--------------------------------------
	// Render the settings page
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
		<?php $process = trim(`ps aux | grep runcache | grep -v grep`); ?>
		<?php if(!empty($process)): ?>
		<p style="color: #FF0000;">WARNING:  The caching process is currently running.  Saving options now will restart it.</p>
		<?php endif; ?>
		<form method="post" action="">

			<h3>Cache Directory</h3>
			<input name="dc-cache-dir" type="text" value="<?php echo get_option('dc_cache_dir'); ?>"  style="width: 90%;" disabled />

			<h3>Database File</h3>
			<input name="dc-db-file" type="text" value="<?php echo get_option('dc_db_file'); ?>"  style="width: 90%;" disabled />

			<h3>Cache Frequency</h3>
			<p>How often should DeleteCity download videos from the sources?</p>
			<ul>
			<?php foreach($schedules as $slug => $schedule): ?>
			<li>
				<?php $checked = (get_option('dc_cache_schedule')==$slug) ? 'checked' : ''; ?>
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
				<?php $checked = (get_option('dc_post_schedule')==$slug) ? 'checked' : ''; ?>
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
				<input type="hidden" name="action" value="save_options" />
				<input type="hidden" name="dc_do_action" value="true" />
				<input type="submit" value="<?php _e('Save Changes') ?>" />
			</p>
			
		</form>
		</div>
	<?php
	}	
}
?>