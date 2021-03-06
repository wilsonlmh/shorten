<?php

\OCP\User::checkLoggedIn();
\OCP\App::checkAppEnabled('shorten');

function startsWith($haystack, $needle) {
    return $needle === "" || strrpos($haystack, $needle, -strlen($haystack)) !== FALSE;
}

function rand_chars($length) {
	$urlString = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
	$arr = str_split($urlString);
	shuffle($arr);
	$arr = array_slice($arr, 0, $length);
	$str = implode('', $arr);
	return $str;
} 

function getShortcode($url) {
	$shortcode = '';
	$query = OCP\DB::prepare('SELECT shortcode FROM *PREFIX*shorten WHERE type=\'internal\' AND url=?');
	$results = $query->execute(Array($url))->fetchAll();
	if ($results) {
		foreach($results as $result) {
			$shortcode = $result['shortcode'];	
		}
	}
	if ($shortcode == "") {
		$shortcode = rand_chars(6);
		$found = true;
		while ($found) {
			$query = OCP\DB::prepare('SELECT id FROM *PREFIX*shorten WHERE type=\'internal\' AND shortcode=?');
			$results = $query->execute(Array($shortcode))->fetchAll();
			if (!$results) {
				$found = false;
				$uid = \OCP\User::getUser();
				$query = OCP\DB::prepare('INSERT INTO *PREFIX*shorten (uid, shortcode, url, type) VALUES (?,?,?,\'internal\')');
				$query->execute(Array($uid,$shortcode,$url));
				$id = OCP\DB::insertid('*PREFIX*shorten');
			} else
				$shortcode = rand_chars(6);
		}
	}
	return $shortcode;
}

function generateUrl() {
	//$newHost = "https://nowsci.com/s/";
	$host = OCP\Config::getAppValue('shorten', 'host', '');
	$type = OCP\Config::getAppValue('shorten', 'type', '');
	$customUrl = OCP\Config::getAppValue('shorten', 'customUrl', '');
	$customJSON = OCP\Config::getAppValue('shorten', 'customJSON', '');
	$api = OCP\Config::getAppValue('shorten', 'api', '');
	$curUrl = $_POST['curUrl'];
	$ret = "";
	if (isset($type) && ($type == "" || $type == "internal")) {
		if ($host == "" || startsWith($curUrl, $host)) {
			$ret = $curUrl;
		} else {
			$shortcode = getShortcode($curUrl);
			$newUrl = $host."?".$shortcode;
			$ret = $newUrl;
		}
	} elseif ($type == "googl") {
		if ($api && $api != "") {
			require_once __DIR__ . '/../lib/class.googl.php';
			$googl = new googl($api);
			$short = $googl->s($curUrl);
			$ret = $short;
		} else {
			$ret = $curUrl;
		}
	} elseif ($type == "custom") {
		$ret = $curUrl; //Failover
		//Check for exist
		$shortcode = '';
		$query = OCP\DB::prepare('SELECT shortcode FROM *PREFIX*shorten WHERE type=\'custom\' AND url=?');
		$results = $query->execute(Array($curUrl))->fetchAll();
		if ($results) {
			foreach($results as $result) {
				$shortcode = $result['shortcode'];	
			}
		}
		if ($shortcode == '') {
			//use CURL to query
			$query = sprintf($customUrl,urlencode($curUrl));
			if (!((in_array(parse_url($query, PHP_URL_SCHEME),array('http','https')))) && (filter_var($query, FILTER_VALIDATE_URL))) { $query = ''; }
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $query); 
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
	        	$raw = curl_exec($ch); 
	        	curl_close($ch);
	        	//OK, parse the JSON
	        	$customJSON = preg_replace("/[^a-zA-Z0-9\-\>]/", "", $customJSON); //remove unwanted characters due to security reason
	        	ob_start();
	        	ob_flush();
	        	eval('$veryVeryVeryVeryVeryVeryVeryLoooongLoooongLoooongNameOfVarOfJSON = json_decode("' . addslashes($raw) . '");'); //use long name due to security reason
	        	ob_clean();
			$url = eval('return $veryVeryVeryVeryVeryVeryVeryLoooongLoooongLoooongNameOfVarOfJSON' . $customJSON . ';');
		 	ob_get_contents();
			ob_end_clean();
		
			//Debug only
            		OCP\Util::writeLog('shorten',"\$raw=$raw",0);
            		OCP\Util::writeLog('shorten',"\$url=$url",0);   
            	
            		//Finally output url after check if URL is valid
            		if ((in_array(parse_url($url, PHP_URL_SCHEME),array('http','https'))) && (filter_var($url, FILTER_VALIDATE_URL))) {
            			$found = false;
				$uid = \OCP\User::getUser();
				$query = OCP\DB::prepare('INSERT INTO *PREFIX*shorten (uid, shortcode, url, type) VALUES (?,?,?,\'custom\')');
				$query->execute(Array($uid,$url,$curUrl));
				$id = OCP\DB::insertid('*PREFIX*shorten');
            			$ret = $url;
            		} 
		} else {
			$ret = $shortcode;
		}
	}else {
		$ret = $curUrl;
	}
	return $ret;
}

?>
