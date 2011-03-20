<?php
require_once("common.php");
require_once("config.php");

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
	
	// other
	var $age=0;
	var $vid_path;
	var $in_db=false;


	// ----------------------------------------------
	function Video( $youtube_id )
	{
		global $db, $cache_dir;
	
		$this->youtube_id = $youtube_id;
		$this->vid_path = "{$cache_dir}/{$youtube_id}.mp4";
		
		$sql="SELECT id, title, content, author, date_added, seen_in_feed, removed, expired,
			round(strftime('%J', datetime('now'))-strftime('%J', seen_in_feed), 2) as age
			FROM videos WHERE youtube_id='{$youtube_id}'";
			
		$result = $db->query($sql, SQLITE_ASSOC, $query_error); 
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
			$this->in_db =			true;
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
		global $db;
		if(unlink($this->vid_path))
		{
			$sql="UPDATE videos SET expired=1 WHERE youtube_id='{$this->youtube_id}'";
			if (!$db->queryExec($sql, $error))
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
		global $db;
		$sql="UPDATE videos SET seen_in_feed=DATETIME('now') WHERE youtube_id='{$this->youtube_id}'";	
		$db->query($sql, SQLITE_ASSOC, $query_error);
		if ($query_error)
		{
			throw new Exception( $query_error );
		}
	}
	
	// ----------------------------------------------
	function mark_as_removed()
	{
		global $db;
		$sql="UPDATE videos SET removed=1 WHERE youtube_id='{$this->youtube_id}'";	
		$db->query($sql, SQLITE_ASSOC, $query_error);
		if ($query_error)
		{
			throw new Exception( $query_error );
		}
	}
	
	// ----------------------------------------------
	// Updates or Inserts depending on whether it is already in the database
	function save()
	{
		global $db;
		
		if(empty($this->title) || empty($this->author) || empty($this->youtube_id))
		{
			throw new Exception("Videos must have title, author, youtube_id.");
		}
		
		if($this->in_db)
		{
			$sql=sprintf("UPDATE videos SET title='%s', content='%s', author='%s', removed=%d, expired=%d WHERE youtube_id='%s'",
				sqlite_escape_string($this->title),
				sqlite_escape_string($this->content),
				sqlite_escape_string($this->author),
				$this->removed ? 1 : 0,
				$this->expired ? 1 : 0,
				sqlite_escape_string($this->youtube_id) );	
			$db->query($sql, SQLITE_ASSOC, $query_error);
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
			$db->query($sql, SQLITE_ASSOC, $query_error);
			if ($query_error)
			{
				throw new Exception( $query_error );
			}
			$this->in_db = true;
			$this->id = $db->lastInsertRowid();
		}
	}
}
?>