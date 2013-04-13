<?php

class tweet_scroll {
	/******************************************************************
	** database access constants                                     **
	** edit these                                                    **
	******************************************************************/
	private $dbName = '';
	private $dbHost = '';
	private $dbUser = '';                              
	private $dbPass = '';
	// private variables
	private $uniqueTweets;
	private $duplicateTweets;
	private $uniqueUsers;
	private $tweets_per_page;
	private $APIURL;
	private $poll_ID;

	public function __construct($include_retweets=false, $include_entities=false) {
	        //check to see if the database tables exist
	        //if they don't, create them
		$this->databaseVerify();
		//instantiate counting variables to 0
		$this->duplicateTweets = 0;
		$this->uniqueTweets = 0;
		$this->uniqueUsers = 0;
		$this->poll_ID = $this->getPollID();
		//API variables
		$this->tweets_per_page = 100; //must be <= 100
		$this->APIURL = 'https://api.twitter.com/1/statuses/user_timeline.json?';
		//construct the search API url
	    	if (!$include_retweets) {
	    		$this->APIURL .= "&include_rts=false";
	    	} else {
	    		$this->APIURL .= "&include_rts=true";
	    	}
	    
	    	if (!$include_entities) {
	    		$this->APIURL .= "&include_entities=false";
	    	} else {
	    		$this->APIURL .= "&include_entities=true";
	    	}
	    	$this->APIURL .= "&count=" . $this->tweets_per_page;
	}

	private function dbConnect() {
		$link = new mysqli($this->dbHost, $this->dbUser, $this->dbPass, $this->dbName);
		if (mysqli_connect_errno()) {
			echo "Connection failed: " . mysqli_connect_error() . "<br/>";
			exit();
		}

		return $link;
	}

	private function getPollID() {
		//check to see if all users have the same poll ID
		//if they don't, then we revert to the max
		//if they do, then we return max+1
		$link = $this->dbConnect();
		$query = "SELECT poll_id, COUNT(twitter_users.poll_id) as poll_id_count
					FROM twitter_users GROUP BY poll_id ORDER BY poll_id ASC";
		$result = $link->query($query) or die($link->error.__LINE__);

		if ($result->num_rows == 1) { //everyone has the same poll id so return that
			$obj = $result->fetch_object();
			return $obj->poll_id;
		} else if ($result->num_rows == 2) { //different poll IDs, return the minimum
			while ($obj = $result->fetch_object()) {
				return $obj->poll_id; //return the min
			}
		}

		$link->close();
		return false;
	}

	private function databaseVerify() {
		//verifies that the tables needed exist
		//if they don't, they're created
		$link = $this->dbConnect();

		$query = "CREATE TABLE IF NOT EXISTS twitter_users("
					. "id BIGINT(25) NOT NULL, "
					. "addedDate DATETIME NOT NULL, "
   					. "username VARCHAR(100) NOT NULL, "
   					. "user_real_name VARCHAR(100), "
					. "user_location VARCHAR(100), "
   					. "user_lang VARCHAR(5), "
   					. "url_slug VARCHAR(100), "
   					. "poll_ID INTEGER(1) DEFAULT 0, "
   					. "found_first_tweet BOOLEAN DEFAULT 0, "
					. "PRIMARY KEY ( id ))";
		$result = $link->query($query) or die($link->error.__LINE__);

		$query = "CREATE TABLE IF NOT EXISTS tweets("
					. "id BIGINT(25) NOT NULL, "
					. "addedDate DATETIME NOT NULL, "
   					. "username VARCHAR(100) NOT NULL, "
   					. "date VARCHAR(100), "
					. "text VARCHAR(160), "
					. "geo VARCHAR(30), "
					. "PRIMARY KEY ( id ))";
		$result = $link->query($query) or die($link->error.__LINE__);

		$link->close();
	}

	public function duplicateTweets() {
		return $this->duplicateTweets;
	}

	public function uniqueTweets() {
		return $this->uniqueTweets;
	}

	public function uniqueUsers() {
		return $this->uniqueUsers;
	}
 
	private function getTweets($username, $max_id=0, $since_id=0) {
		//this function fetches a page of tweets for a user
		//max_id = twitter ID. Twitter will set the max ID to whatever is specified here, meaning
		//n-1 tweets older than that tweet will be fetched (in addition to this tweet) where n = count
		//since = a date. Twitter will fetch n tweets younger than this date
		//a maximum of one of these variables can be specified; if both are specified, only max_id is used
		//if none are specified, the most recent page of tweets is fetched
		$tweets = array();
    
		$baseURL = $this->APIURL;
    
		if ($max_id > 0) {
			$baseURL .= "&max_id=" . $max_id;
		} else if ($since_id > 0) { //don't allow both to be set
			$baseURL .= "&since_id=" . $since_id;
		}

		$baseURL .= "&screen_name=" . $username; //username of tweeter we're scraping tweets from

		//get this page of tweets in json format
		$ch = curl_init($baseURL);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);
		$response = curl_exec($ch);
    
		$tweet_obj = json_decode($response, true); //associative arrays
 	
		if ($response !== FALSE && !isset($tweet_obj['error'])) {
			foreach ($tweet_obj as $tweet) {        
				$this_tweet['id'] = $tweet['id']; // id
				$this_tweet['username'] = $tweet['user']['screen_name']; // username
				$this_tweet['user_location'] = $tweet['user']['location']; //user's location
				$this_tweet['user_real_name'] = $tweet['user']['name'];
				$this_tweet['user_url'] = $tweet['user']['url'];
				$this_tweet['user_lang'] = $tweet['user']['lang'];
				$this_tweet['tweet'] = addslashes(strip_tags($tweet['text'])); // tweet content
				$this_tweet['date'] = date("Y-m-d G:i:s",strtotime($tweet['created_at'])); // date
				$this_tweet['geo'] = $tweet['geo'];
        
				array_push($tweets, $this_tweet); //push this tweet to an array of tweets
        
				$this->pushTweet($this_tweet); //write the tweet to the database
			}
		} else { //there was a problem
			if (isset($tweet_obj['error'])) { // either the user isn't scrape-able or
				echo $tweet_obj['error'] . "<br/>";     // the rate limit has been reached
				exit(); //kill the script
			}
			return false;
    	}

    	curl_close($ch);
		return $tweets;
	}
  
	private function urlSlug($str) {
		//creates a valid url slug out of a user's twitter username
		$slug = iconv('UTF-8', 'ASCII//TRANSLIT', $str);
		$slug = preg_replace("/[^a-zA-Z0-9\/_|+ -]/", '', $slug);
		$slug = strtolower(trim($slug, '-'));
		$slug = preg_replace("/[\/_|+ -]+/", "-", $slug);

		return $slug;
	}

	private function userExists($username) {
		//checks if a user exists
		$link = $this->dbConnect();
		$query = "SELECT id FROM twitter_users WHERE username = '" . $username . "'";
		$result = $link->query($query) or die($link->error.__LINE__);
		if ($result->num_rows > 0) {
			return true;
		}
		return false;
		$link->close();
	}

	private function updatePollID($username) {
		$link = $this->dbConnect();
		$query = "UPDATE twitter_users SET poll_ID = " . ($this->poll_ID >= 1 ? 0 : 1)
				. " WHERE username = '" . $username . "'";
		$result = $link->query($query) or die($link->error.__LINE__);
		return true;
	}

	public function followUser($username) {
		//adds a user to the twitter_users table
		//grabs the most recent page of their tweets
		//uses that metadata to populate the table

		if ($this->userExists($username)) { return false; } //return false if user already exists

		$link = $this->dbConnect();
		$tweets = $this->getTweets($username); //gets <= 100 most recent tweets
										//we'll gather some metadata from one
										//and create the twitter_user row from that
										//then we'll insert the tweets from that page

		$reference_tweet = $tweets[0]; //most recent tweet

		//create the user in the twitter_users table
		$query = "INSERT INTO twitter_users (id, addedDate, username, user_real_name, user_location,
					user_lang, url_slug, poll_ID)
					VALUES
					(" 
					. $reference_tweet['id'] . ", " 
					. "NOW() , '"
					. $reference_tweet['username'] . "', '"
					. $reference_tweet['user_real_name'] . "', '"
					. $reference_tweet['user_location'] . "', '"
					. $reference_tweet['user_lang'] . "', '"
					. $this->urlSlug($reference_tweet['username']) . "', " . $this->poll_ID . ")";
		$result = $link->query($query) or die($link->error.__LINE__);
		//user was inserted
		//now store the tweets from the page
		foreach ($tweets as $tweet) {
			$this->pushTweet($tweet);
		}

		$link->close();

		return true;
	}
  
	public function getUsers() {
		//get an array of user objects 
		//accessed like array_name[i]->column_name
		$twitter_usernames = array(); 	
  	
		$link = $this->dbConnect();

		$query = "SELECT DISTINCT
					twitter_users.id as id,
					twitter_users.username  as username,
					twitter_users.user_real_name as real_name,
					twitter_users.user_lang as user_lang,
					twitter_users.user_location as user_location,
					twitter_users.url_slug as url_slug,
					twitter_users.poll_ID as poll_ID
				FROM twitter_users
				WHERE (twitter_users.poll_ID = " . $this->poll_ID . ")
				ORDER BY twitter_users.username ASC";

		$result = $link->query($query) or die($link->error.__LINE__);
		while ($obj = $result->fetch_object()) {
			array_push($twitter_usernames, $obj);
		}

		$link->close();

		return $twitter_usernames;
	}
  
	private function getUserIDFromTwitterUsername($username) {
		$link = $this->dbConnect();

		$query = "SELECT id FROM twitter_users WHERE username = '" . 
					$username . "' LIMIT 1";
		$result = $link->query($query) or die($link->error.__LINE__);	

		$obj = $result->fetch_object();

		$link->close();

		return $obj->id;
	}

	private function pushTweet($tweet) {

		$link = $this->dbConnect();

		if ($tweet['id'] > 0 && 
			$tweet['tweet'] != "" && 
			$tweet['username'] != "" && 
			$tweet['date'] != "") 
		{
			//insert into database
			//does this tweet already exist with this id and username?
			$query = "SELECT id FROM tweets WHERE id = " . $tweet['id'] . 
						" AND username = '" . $tweet['username'] . "'";
			$result = $link->query($query) or die($link->error.__LINE__);	
			if ($result->num_rows == 0) {
				//insert the tweet
				$query = "INSERT INTO tweets (id, addedDate, username, date, text, geo)  
							VALUES (" . $tweet['id'] . ", NOW(), '" . $tweet['username'] 
							. "', '" . $tweet['date'] . "', '" 
							. $link->real_escape_string($tweet['tweet']) . "', '"
							. $tweet['geo'] . "')";
				if ($result = $link->query($query)) {
					$this->uniqueTweets++;
				} else {
					$this->duplicateTweets++;
					die( $link->error.__LINE__ );	
				}
			} else {
				return false;
			}
		}

		$link->close();
 
	}

	private function getMaxTweetID($username) {
		$link = $this->dbConnect();
		$query = "SELECT MAX(id) as id FROM tweets WHERE username = '" . $username . "'";
		$result = $link->query($query) or die($link->error.__LINE__);
		$obj = $result->fetch_object();
		$link->close();
		return $obj->id;
	}

	private function getMinTweetID($username) {
		$link = $this->dbConnect();
		$query = "SELECT MIN(id) as id FROM tweets WHERE username = '" . $username . "'";
		$result = $link->query($query) or die($link->error.__LINE__);
		$obj = $result->fetch_object();
		$link->close();
		return $obj->id;
	}

	private function getMaxTweetDate($username) {
		$link = $this->dbConnect();
		$query = "SELECT MAX(date) as date FROM tweets WHERE username = '" . $username . "'";
		$result = $link->query($query) or die($link->error.__LINE__);
		$obj = $result->fetch_object();
		$link->close();
		return $obj->date;
	}

	private function foundFirstTweet($username) {
		$link = $this->dbConnect();
		$query = "UPDATE twitter_users SET found_first_tweet = 1 WHERE username = '" . $username . "'";
		$result = $link->query($query) or die($link->error.__LINE__);
		$link->close();
		return true;
	}

	private function hasFoundFirstTweet($username) {
		$link = $this->dbConnect();
		$query = "SELECT found_first_tweet FROM twitter_users WHERE username = '" . $username . "'";
		$result = $link->query($query) or die($link->error.__LINE__);
		$obj = $result->fetch_object();
		$link->close();
		return $obj->found_first_tweet;		
	}

	public function scrollTweets($username) {
		$completion_count = 0;
		if (!$this->userExists($username)) {
			//insert the user
			$this->followUser($username);
		}

		if (!$this->hasFoundFirstTweet($username)) {
			//if we have this user's first tweet recorded, we don't need to recurse backwards,
			//only forwards
			$tweets = $this->getTweets($username, $max_id=$this->getMinTweetID($username), $since_id=0);
			while (sizeof($tweets) > 0) { // while we're still returning tweets
					              // ie rate limit hasn't been reached
						      // and there are still tweets to scroll 
				$tweets = $this->getTweets($username, $max_id=$this->getMinTweetID($username), $since_id=0);
				if (sizeof($tweets) == 1) { //we reached the very first tweet
					$completion_count++;
					$this->foundFirstTweet($username); 
					break; //break the loop if the min tweet id we have is the user's oldest tweet
				}
			}			
		} else {
			$completion_count++;
		}

		//now we'll update from the very most recent tweet in case this user
		//has posted more tweets since we last queried their first page

		$tweets = $this->getTweets($username, $max_id=0, $since_id=0);
		if ($tweets[0]['id'] > $this->getMaxTweetID($username)) {
			while ($tweets[0]['id'] > $this->getMaxTweetID($username)) { // while we're still returning tweets
									      // ie rate limit hasn't been reached
										  // and there are still tweets to scroll 
				$tweets = $this->getTweets($username, $max_id=$tweets[sizeof($tweets)-1]['id'], $since_id=0);
				if ($tweets[0]['id'] <= $this->getMaxTweetID($username)) { //we reached the most recent tweet
					$completion_count++;
					break; //break the loop if the max tweet id we have is the user's most recent tweet
				}			
			}
		} else {
			$completion_count++;
		}

		if ($completion_count == 2) {       	//update the user's poll ID when we know
			$this->updatePollID($username);     //their complete timeline is stored
		}									    //(we have both their most recent and first tweets)  

		return true;
	}
}



?>
