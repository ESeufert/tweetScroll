<?php
	require_once "tweetScroll.class.php";
	
	$ts = new tweet_scroll($include_retweets=false, $include_entities=false);

	$ts->followUser('rickygervais');
	$usernames = $ts->getUsers();
	for ($i = 0; $i<sizeof($usernames); $i++) {
		echo "USER: " . $usernames[$i]->username . ", PollID: " . $usernames[$i]->poll_ID . "<br/>";
		$ts->scrollTweets($usernames[$i]->username);
	}
	
	echo $ts->uniqueTweets() . " tweets added to database.<br/>";

?>
