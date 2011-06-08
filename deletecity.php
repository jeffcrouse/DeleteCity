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


Contents:
1. admin_notices
2. init
3. wp-head
4. wp-footer
5. cron_schedules
6. deletecity_activate
7. deletecity_deactivate
8. runcache_function_hook
9. post_videos_function_hook
10. Admin
	- admin_menu
	- deletecity_settings_page
	- deletecity_status_page
	- AJAX functions
11. Utility functions
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

// Set some vaaaaaarrrrs
global $dcdb; //, $dclogfile, $runcache;
$dclogfile = dirname(__FILE__)."/deletecity.log";
$runcache = dirname(__FILE__)."/runcache.php";
$dc_plugin_dir = WP_PLUGIN_URL.'/'.str_replace(basename( __FILE__),"",plugin_basename(__FILE__));
Video::$cache_dir = get_option('dc_cache_dir', WP_CONTENT_DIR."/dc_cache");
$max_age = 3;		// Videos will be deleted after 'max_age' days


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



// --------------------------------------------
add_action('init', 'dc_load');
function dc_load()
{
	global $dc_plugin_dir;
	wp_enqueue_style( 'dc-style', "{$dc_plugin_dir}styles.css"); 
	
	wp_enqueue_style( 'jquery-style', 'http://ajax.googleapis.com/ajax/libs/jqueryui/1.8.1/themes/smoothness/jquery-ui.css'); 
	wp_enqueue_script( 'jw-player' , "{$dc_plugin_dir}mediaplayer-5.6/jwplayer.js");

  	wp_deregister_script( 'jquery' );
    wp_register_script( 'jquery', 'http://ajax.googleapis.com/ajax/libs/jquery/1.6/jquery.min.js');
    wp_enqueue_script( 'jquery' );

	wp_enqueue_script( 'jquery-ui-core' );
	wp_enqueue_script( 'jquery-ui-dialog' );
	
	// declare the URL to the file that handles the AJAX request (wp-admin/admin-ajax.php)
	wp_localize_script( 'jquery', 'MyAjax', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ) ) );
}


// --------------------------------------------
add_action('wp_head', 'dc_js_header' );
function dc_js_header() // this is a PHP function
{
?>
	<script type="text/javascript">
	function dc_show_video(id)
	{
		var data = {action: 'dc_load_player', youtube_id: id};
		$("#dc-video-box").load(MyAjax.ajaxurl, data, function(result) {
			$("#dc-video-box").dialog({width: 680, height:530, modal: true});
		});
	}
	</script>
<?php
} 

// --------------------------------------------
add_action('wp_footer', 'dc_footer' );
function dc_footer() // this is a PHP function
{
	?><div id="dc-video-box"></div><?php
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
	
	if(!get_option('dc_blacklist'))
	{
		add_option('dc_blacklist',  "sexy, milf, whores, porn, xxx, pokemon, anal, shemale, fetish");
	}
	
	if(!get_option('dc_max_cache_size'))
	{
		add_option('dc_max_cache_size', "8000");
	}
	
	if(!get_option('dc_rate_limit'))
	{
		add_option('dc_rate_limit', "300k");
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
	global $dclogfile, $runcache, $max_age;
	
	if(filesize($dclogfile) > 1024*1024*10)
	{
		$newname = "deletecity-".date('h-i-s-j-m-y').".log";
		rename($dclogfile, $newname);
	}

	dc_log("Starting runcache");
	
	$dir = escapeshellarg(get_option('dc_cache_dir'));
	$db = escapeshellarg(get_option('dc_db_file'));
	$rate_limit = escapeshellarg(get_option('dc_rate_limit'));
	$blacklist = escapeshellarg(get_option('dc_blacklist'));
	
	// run the cachiing process in the background.
	`php $runcache --ratelimit=$rate_limit --maxage=$max_age --cachedir=$dir --db=$db --blacklist=$blacklist >> $dclogfile 2>&1 &`;
}



// --------------------------------------------------------------------------
// POSTING EVENT
add_action( 'post_videos_function_hook', 'post_removed_videos' );
function post_removed_videos()
{	
	try {
		$videos = Video::get_unposted_removed();
	} catch (Exception $e) {
		dc_log($e->getMessage());
	}

	$num_videos = count($videos);
	if($num_videos == 0)
	{
		dc_log("No videos to post");
		return;
	}
	
	// Create the HTML to post
	ob_start();
	$i=0;
	?>
	
	<div class="dc-videos">
		<?php foreach($videos as $video): ?>
			<div class="dc-video">
				<div class="dc-title"><?php echo $video->title; ?></div>
				<a href="javascript:dc_show_video('<?php echo $video->youtube_id; ?>');">
				<img src="<?php echo $video->thumb_url; ?>" class="dc-thumbnail" />
				</a>
				<div class="dc-author">
					<b>by:</b><a href="http://www.youtube.com/user/<?php echo $video->author; ?>" target="_blank"><?php echo $video->author; ?></a>
				</div>
			</div>
			<?php
			if($i==6) 
			{
				$more = $num_videos-6;
				print "<!--more {$more} more videos after the break. -->";
			}
			?>
		<?php $i++; endforeach; ?>
	</div>
	
	<?php
	$content = ob_get_contents();
	ob_end_clean();
	
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




/*******************************
*
*	ADMIN
*
**********************************/

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
				// The script needs a minute to start
				// delay so that the GUI reflects that it is running
				while(!get_runcache_pid())
				{
					sleep(1);
				}
				break;
			case 'check_for_deleted':
				$dir = escapeshellarg(get_option('dc_cache_dir'));
				$db = escapeshellarg(get_option('dc_db_file'));
				`php $runcache --find_deleted_only --maxage=$max_age --db=$db --cachedir=$dir >> $dclogfile 2>&1 &`;
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
				update_option('dc_blacklist',  $_REQUEST['dc-blacklist']);
				update_option('dc_max_cache_size',  $_REQUEST['dc-max-cache-size']);
				update_option('dc_rate_limit',  $_REQUEST['dc-rate-limit']);
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
		global $dclogfile, $dcdb, $dc_plugin_dir;
		$pid = get_runcache_pid();
		?>
		
		<div style="padding-bottom: 40px;";>
			<h2>Delete City Status</h2>
 
 			<div style="width: 95%; height: 20px;">
 				<div style="float: left;">
					<?php if(!$pid): ?>
						The caching process is not running.
					<?php else: ?>
						<img src="<?php echo $dc_plugin_dir; ?>ajax-loader.gif" /> <a title="<?php echo  `ps aux | grep runcache | grep -v grep`; ?>">The cache process is running.</a>
					<?php endif; ?>
				</div>
				<div style="float: right; padding-right: 6px; padding-top: 10px;">
					<a href="<?php echo $dc_plugin_dir.basename($dclogfile); ?>" target="_blank"><img src="<?php echo $dc_plugin_dir; ?>pop-out-arrow.gif" /></a>
				</div>
			</div>

			<textarea readonly name="log" id="log" style="width: 95%; height: 100px;"></textarea>

			<p>
			<form method="post" action="">
				<?php if($pid): ?>
				<button name="action" value="stopcache" type="submit">Stop Cache Now (pid <?php echo $pid; ?>)</button>
				<?php else: ?>
				<button name="action" value="runcache" type="submit">Run Cache Now</button>
				<?php endif; ?>
				<button name="action" value="post_videos" type="submit">Post Videos Now</button>
				<button name="action" value="check_for_deleted" type="submit">Check for Deleted Videos Now</button>
				<input type="hidden" name="dc_do_action" value="true" />
			</form>
			</p>
			
			<h2>Videos</h2>
			<p>
				<input type="radio" name="filter" value="all" /> All Cached Videos &nbsp;&nbsp;&nbsp;
				<input type="radio" name="filter" value="removed" checked/> Videos Saved by Delete City
			</p>
			
			<div id="videos" style="width: 95%;"></div>				
			<div id="video-player"></div>
			

			<script type="text/javascript">
			$.ajaxSetup({cache:false});
			$('input[name=filter]').click(function(){
				current_page = 0;
				dc_refresh();
			});
			
			var current_page = 0;
			
			function dc_refresh()
			{
				var filter = $('input[name=filter]:checked').val();
				var data = {action: 'dc_get_vids', dc_page: current_page, dc_filter: filter};
				$("#videos").load(ajaxurl, data);
				$("#log").load("<?php echo $dc_plugin_dir; ?>deletecity.log", function() {
					$("#log").scrollTop($("#log")[0].scrollHeight);
				});
			}
			function dc_set_page(new_page)
			{
				current_page = new_page;
				dc_refresh();
			}
			function dc_delete_video(id)
			{
				var filter = $('input[name=filter]:checked').val();
				var data = {action: 'dc_get_vids', dc_page: current_page, dc_filter: filter, dc_delete: id};
				$("#videos").load(ajaxurl, data);
			}
			function dc_show_video(id)
			{
				var data = {action: 'dc_load_player', youtube_id: id};
				$("#video-player").load(ajaxurl, data, function(result) {
					$("#video-player").dialog({width: 680, height:530, modal: true});
				});
			}
			dc_refresh();
			<?php if($pid): ?>
			setInterval(dc_refresh, 10000);
			<?php endif; ?>
			</script>
		</div>
		<?php
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
		<?php $pid = get_runcache_pid(); ?>
		<?php if($pid): ?>
		<p style="color: #FF0000;">WARNING:  The caching process is currently running.  Saving options now will restart it.</p>
		<?php endif; ?>
		<form method="post" action="">

			<h3>Cache</h3>
			Directory: <input name="dc-cache-dir" type="text" value="<?php echo get_option('dc_cache_dir'); ?>"  style="width: 500px;" disabled /><br />
			Max Size: <input name="dc-max-cache-size" type="text" value="<?php echo get_option('dc_max_cache_size'); ?>"  style="width: 80px;" /> MB<br />
			Rate Limit: <input name="dc-rate-limit" type="text" value="<?php echo get_option('dc_rate_limit'); ?>"  style="width: 80px;" /> (e.g. 50k or 44.6m)

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
			<textarea name="dc-sources" style="width: 90%; height: 150px;"><?php echo $urls?></textarea>

			<h3>Blacklist</h3>
			<p>Videos containing these words in title or description will be skipped.</p>
			<textarea name="dc-blacklist" style="width: 90%; height: 100px;"><?php echo get_option('dc_blacklist'); ?></textarea>
			<p>
				<input type="hidden" name="action" value="save_options" />
				<input type="hidden" name="dc_do_action" value="true" />
				<input type="submit" value="<?php _e('Save Changes') ?>" />
			</p>
		</form>
		</div>
	<?php
	}	

	// --------------------------------------------------------------------------
	// Prints a player for a single video
	// Args: youtube_id
	// http://codex.wordpress.org/AJAX_in_Plugins
	add_action('wp_ajax_dc_load_player', 'dc_load_video_player');
	add_action('wp_ajax_nopriv_dc_load_player', 'dc_load_video_player');
	function dc_load_video_player()
	{
		global $dc_plugin_dir;
		$youtube_id = $_POST['youtube_id'];
		$video = new Video($youtube_id);
		?>
			<div class="video-title" style="font-size: 18px; font-weight: bold;"><?php echo $video->title; ?></div>
			<p>by <a href="http://www.youtube.com/user/<?php echo $video->author; ?>" target="_blank"><?php echo $video->author; ?></a></p>
			<div id="video-<?php echo $youtube_id; ?>">Loading the player ...</div>
			[<a href="http://www.youtube.com/watch?v=<?php echo $video->youtube_id; ?>">original URL</a>]
			<p><?php echo $video->content; ?></p>
			<script type="text/javascript"> 
			jwplayer("video-<?php echo $youtube_id; ?>").setup({
				image: "<?php echo $video->thumb_url; ?>",
				flashplayer: "<?php echo $dc_plugin_dir; ?>mediaplayer-5.6/player.swf", 
				file: "<?php echo $video->vid_url; ?>", 
				width: 640,
				height: 360 
			});
			</script>
		<?php
		die();
	}

	// --------------------------------------------
	// Prints pages of videos for the admin interface
	// Args: 
	//		dc_delete - the youtube_id of a video to delete
	//		dc_page - 0-based page number
	//		dc_filter - (removed | all)
	add_action('wp_ajax_dc_get_vids', 'dc_get_vids');
	function dc_get_vids()
	{
		global $dcdb, $dclogfile, $dc_plugin_dir;
	
		if(isset($_POST['dc_delete']))
		{
			Video::delete( $_POST['dc_delete'] );
		}
		
		$page = $_POST['dc_page'];
		$filter = $_POST['dc_filter'];
		$results_per_page = 12;
		$offset = $results_per_page * $page;
	
		// Assemble the SQL 
		$sql="SELECT youtube_id FROM videos";
		if($filter=='removed')
		{
			$sql .= " WHERE removed>0 ";
		}
		$sql .= " ORDER BY date_added DESC ";
		
		// Get the total number of videos
		$result = $dcdb->query($sql, SQLITE_ASSOC, $query_error); 
		if ($query_error) 	die("Error: $query_error"); 
		if (!$result) 		die("Error: Impossible to execute query.");
		$total = $result->numRows();
	
		// Get the videos for this page
		$sql .= " LIMIT $results_per_page OFFSET $offset";
		$result = $dcdb->query($sql, SQLITE_ASSOC, $query_error); 
		if ($query_error) 	die("Error: $query_error"); 
		if (!$result) 		die("Error: Impossible to execute query.");
	
		$ar=getDirectorySize(get_option('dc_cache_dir'), "mp4"); 
		?>
		
		<p><b>Total Cache Size:</b> <?php echo $ar['count']; ?> videos, <?php echo sizeFormat($ar['size']); ?></p> 
		<?php
		if($total==0)
		{
			print "There are no videos that match that request.";
			die();
		}
		
		while($row = $result->fetch(SQLITE_ASSOC))
		{ 
			$video = new Video( $row['youtube_id'] );
			?>
			<div style="width: 250px; float: left; padding-bottom: 20px;">
				<div style="height: 20px; width: 220px; font-weight: bold;">
					<a href="javascript:dc_delete_video('<?php echo $video->youtube_id; ?>')">
						<img src="<?php echo $dc_plugin_dir; ?>delete_icon.gif" />
					</a>
					<?php echo $video->title; ?>
				</div>
				<a href="javascript:dc_show_video('<?php echo $video->youtube_id; ?>');">
					<img src="<?php echo $video->thumb_url; ?>" style="width: 240px; height: 180px;" />
				<a>
				<b>age: </b> <?php echo ($video->age > 1) ? "{$video->age} days" : ($video->age*24)." hours"; ?><br />
				<b>by: </b> <a href="http://www.youtube.com/user/<?php echo $video->author; ?>" target="_blank"><?php echo $video->author; ?></a>
			</div>
			<?php
		}
		
		$pages = ceil($total / $results_per_page);
		?>
		
		<br style="clear: both;" />
		<div style="height: 30px; border-top: 1px solid black; padding-bottom: 10px; padding-top: 10px;">
		
			<?php if($page > 0): ?>
			<div style="float: left; width: 90px; font-size: 16px; padding-bottom: 5px;">
			<a href="javascript:dc_set_page(<?php echo $page-1; ?>);">Previous</a>
			</div>
			<?php endif; ?>
			<?php for($i=0; $i<$pages; $i++): ?>
				<div style="float: left; width: 40px; font-size: 16px; padding-bottom: 5px;">
				<?php if($i!=$page): ?>
				<a href="javascript:dc_set_page(<?php echo $i; ?>);"><?php echo $i+1; ?></a>
				<?php else: ?>
				<?php echo $i+1; ?>
				<?php endif; ?>
				</div>
			<?php endfor; ?>
			
			<?php if($page < $pages-1): ?>
			<div style="float: right; width: 60px; font-size: 16px; padding-bottom: 5px;">
			<a href="javascript:dc_set_page(<?php echo $page+1; ?>);">Next</a>
			</div>
			<?php endif; ?>
			
		</div>
		
		<?php
		die(); // this is required to return a proper result
	}
}




/*******************************
*
*	Utility Functions
*
********************************/
	
// --------------------------------------------------------------------------
// If the process is still running, it returns the ID.  false otherwise
function get_runcache_pid()
{
	// Finds the file created by runcache that contains its PID
	$pid_file = dirname(__FILE__)."/runcache.php.pid";
	if( !file_exists($pid_file) )
	{
		return false;
	}
	$pid = (int)trim( file_get_contents($pid_file) );
	if(!is_numeric($pid))
	{
		return false;
	}
	$process = trim(`ps aux | grep $pid | grep -v grep`);
	if(empty($process))
	{
		unlink($pid_file);
		return false;
	}
	return $pid;
}

// --------------------------------------------------------------------------
function stopcache()
{
	// Kill the runcache process if it is running.
	// It would be great to use the pcntl here, but it's not widely supported
	$pid = get_runcache_pid();
	if(!$pid)
	{
		dc_log("Caching process is not running.");
		return;
	}
	if(posix_kill($pid, 0)) // see if process is running
	{
		posix_kill($pid, 9);
		$error = posix_get_last_error();
		if($error==0)
		{
			dc_log("Killed process $pid");
			unlink(dirname(__FILE__)."/runcache.php.pid");
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

// --------------------------------------------------------------------------
// LOGGING
function dc_log($message) 
{
	global $dclogfile;
	$loghandle = fopen($dclogfile, 'a');
	fwrite($loghandle, "[deletecity] ".date("F j, Y, g:i a")." $message\n");
}
?>