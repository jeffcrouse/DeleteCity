<?php
require_once("common.php");

class Video {
	
	// database fields
	var $id=NULL;
	var $title=NULL;
	var $content=NULL;
	var $author=NULL;
	var $removed=false;
	var $youtube_id=NULL;
	var $date_added;
	var $seen_in_feed;
	var $expired=false;
	var $date_posted=NULL;
	
	// other
	var $age=0;
	var $vid_path;
	var $thumb_path;
	var $in_db=false;

	public static $cache_dir;

	// ----------------------------------------------
	function Video( $youtube_id )
	{
		global $dcdb;	
		
		if(!isset(Video::$cache_dir))
		{
			throw new Exception("You must set Video::$cache_dir before constructing any Videos.");
		}
		
		$this->youtube_id = $youtube_id;
		$this->vid_path = Video::$cache_dir."/{$youtube_id}.mp4";
		$this->thumb_path = Video::$cache_dir."/{$youtube_id}.jpg";
		
		if(function_exists('content_url'))
		{
			$wp_content_dir = ABSPATH . 'wp-content';
			$this->thumb_url = content_url() . str_replace($wp_content_dir, "", $this->thumb_path);
			$this->vid_url = content_url() . str_replace($wp_content_dir, "", $this->vid_path);
		}
		
		$sql="SELECT id, title, content, author, date_added, seen_in_feed, removed, expired, date_posted,
			round(strftime('%J', datetime('now'))-strftime('%J', seen_in_feed), 2) as age
			FROM videos WHERE youtube_id='{$youtube_id}'";
			
		$result = $dcdb->query($sql, SQLITE_ASSOC, $query_error); 
		if ($query_error) {
			throw new Exception($query_error);
		}		
		
		if (!$result) {
			throw new Exception("Impossible to execute query.");
		}
		
		if($result->numRows() > 0)
		{
			$row = $result->fetch(SQLITE_ASSOC);
			$this->id = 			$row['id'];
			$this->title = 			$row['title'];
			$this->content = 		$row['content'];
			$this->author = 		$row['author'];
			$this->date_added = 	$row['date_added'];
			$this->seen_in_feed = 	$row['seen_in_feed'];
			$this->removed = 		$row['removed'];
			$this->expired = 		$row['expired'];
			$this->age = 			$row['age'];
			$this->date_posted = 	$row['date_posted'];
			$this->in_db =			true;
		}
		
		if(!file_exists($this->thumb_path))
		{
			$this->download_thumbnail();
		}
	}
	
	
	// ----------------------------------------------
	function download_thumbnail()
	{
		$url = "http://img.youtube.com/vi/{$this->youtube_id}/0.jpg";	
	
		if(ini_get('allow_url_fopen')=="1")
		{
			file_put_contents($this->thumb_path, file_get_contents($url));
		}
		
		if(function_exists("curl_init"))
		{
			$ch = curl_init($url);
			$fp = fopen($this->thumb_path, 'wb');
			curl_setopt($ch, CURLOPT_FILE, $fp);
			curl_setopt($ch, CURLOPT_HEADER, 0);
			curl_exec($ch);
			curl_close($ch);
			fclose($fp);
		}
	}

	
	// ----------------------------------------------
	function check_remote()
	{
		return $this->fetch_info();
	}
	
		
	// ----------------------------------------------
	function fetch_info()
	{
		if(empty($this->youtube_id))
		{
			throw new Exception("You must have a youtube_id to fetch info.");
		}
		
		$url = "http://gdata.youtube.com/feeds/api/videos/{$this->youtube_id}";
		$webpage = get_web_page( $url );
		$content = trim($webpage['content']);
				
		if(strpos($content, "<?xml")===0)
		{
			$xmldoc = new SimpleXMLElement( $content );
			$this->title = $xmldoc->title;
			$this->author = $xmldoc->author->name;
		}
		else
		{
			return false;
		} 
		return true;
	}
	
	// ----------------------------------------------
	function mark_as_expired()
	{
		global $dcdb;
		if(unlink($this->vid_path))
		{
			$sql="UPDATE videos SET expired=1 WHERE youtube_id='{$this->youtube_id}'";
			if (!$dcdb->queryExec($sql, $error))
			{
				throw new Exception($error);
			}
		}
		else
		{
			throw new Exception("Could not delete {$this->youtube_id}.  Check permissions.");
		}
	}
	
	// ----------------------------------------------
	function seen_in_feed()
	{
		global $dcdb;
		$sql="UPDATE videos SET seen_in_feed=DATETIME('now') WHERE youtube_id='{$this->youtube_id}'";	
		$dcdb->query($sql, SQLITE_ASSOC, $query_error);
		if ($query_error)
		{
			throw new Exception( $query_error );
		}
	}
	
	// ----------------------------------------------
	function mark_as_removed()
	{
		global $dcdb;
		$sql="UPDATE videos SET removed=1 WHERE youtube_id='{$this->youtube_id}'";	
		$dcdb->query($sql, SQLITE_ASSOC, $query_error);
		if ($query_error)
		{
			throw new Exception( $query_error );
		}
	}
	
	// ----------------------------------------------
	function mark_as_posted()
	{
		global $dcdb;
		$sql="UPDATE videos SET date_posted=DATETIME('now') WHERE youtube_id='{$this->youtube_id}'";	
		$dcdb->query($sql, SQLITE_ASSOC, $query_error);
		if ($query_error)
		{
			throw new Exception( $query_error );
		}
	}
	
	// ----------------------------------------------
	// Updates or Inserts depending on whether it is already in the database
	function save()
	{
		global $dcdb;
		
		if(empty($this->title) || empty($this->author) || empty($this->youtube_id))
		{
			throw new Exception("Videos must have title, author, youtube_id.");
		}
		
		if($this->in_db)
		{
			$sql=sprintf("UPDATE videos SET title='%s', content='%s', author='%s', removed=%d, expired=%d, date_posted=%d
				WHERE youtube_id='%s'",
				sqlite_escape_string($this->title),
				sqlite_escape_string($this->content),
				sqlite_escape_string($this->author),
				$this->removed ? 1 : 0,
				$this->expired ? 1 : 0,
				$this->date_posted,
				sqlite_escape_string($this->youtube_id) );	
			$dcdb->query($sql, SQLITE_ASSOC, $query_error);
			if ($query_error)
			{
				throw new Exception( $query_error );
			}
		}
		else
		{
			$sql=sprintf("INSERT INTO videos (youtube_id, title, content, author, date_added, seen_in_feed) 
				VALUES ('%s', '%s', '%s', '%s', DATETIME('now'), DATETIME('now'))",
				sqlite_escape_string($this->youtube_id),
				sqlite_escape_string($this->title),
				sqlite_escape_string($this->content),
				sqlite_escape_string($this->author) );		
			$dcdb->query($sql, SQLITE_ASSOC, $query_error);
			if ($query_error)
			{
				throw new Exception( $query_error );
			}
			$this->in_db = true;
			$this->id = $dcdb->lastInsertRowid();
		}
	}
	
	// ----------------------------------------------
	static function get_unposted_removed()
	{
		global $dcdb;
		
		$videos = array();
		$sql = "SELECT youtube_id FROM videos WHERE removed=1 AND date_posted=NULL";
	
		$result = $dcdb->query($sql, SQLITE_ASSOC, $query_error); 
		if ($query_error)
		{
			throw new Exception( $query_error );
		}	
		if (!$result)
		{
			throw new Exception("Impossible to execute query.");
		}
		
		while ($row = $result->fetch(SQLITE_ASSOC))
		{
			$videos[] = new Video( $row['youtube_id'] );
		}
		return $videos;
	}
	
	// ----------------------------------------------
	static function delete($youtube_id)
	{
		global $dcdb;
		$video = new Video($youtube_id);
		$video->mark_as_expired();
		
		$sql="DELETE FROM videos WHERE youtube_id='{$youtube_id}'";
		$query = $dcdb->queryExec($sql, $error);
		if (!$query) 
		{
			throw new Exception( $error );
		} 
	}
}
?>