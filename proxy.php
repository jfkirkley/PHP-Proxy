<?php>
/*
Version: 0.1
Author: John Kirkley   jofeki@rogers.com
Licensed under The MIT License
Redistributions of files must retain the above copyright notice.
*/


// some pages get quite large, this should handle most memory needs
ini_set("memory_limit","127M");

include('simple_html_dom.php');

define("TARGET_HOST_PARAM_NAME", "__target_host");
define("LAST_TARGET_HOST_FILE_NAME", "./__target_host.txt");
define("PATH_PARAM_NAME", "__target_path");

class Proxy {

  private $targetHOST;
  private $path;

  // for stripping trailing '/' from paths
  function stripSlashSuffix($s) {
	$lastIndex = strlen($s)-1;
	if($s[$lastIndex] === '/') {
	  return substr($s,0,$lastIndex);
	}
	return $s;
  }

  function parseQueryString($str) {
    $op = array();
    $pairs = explode("&", $str);
    foreach ($pairs as $pair) {
	  list($k, $v) = array_map("urldecode", explode("=", $pair));
	  $op[$k] = $v;
    }
    return $op;
  }

  // get target HOST if set, otherwise retrieve it from the temporary host name file
  function getTargetHost() {

	if (array_key_exists(TARGET_HOST_PARAM_NAME, $_GET) ) {
	  $this->targetHOST = $this->stripSlashSuffix($_GET[TARGET_HOST_PARAM_NAME]);
	  file_put_contents(LAST_TARGET_HOST_FILE_NAME, $this->targetHOST);
	} else {
	  $this->targetHOST = file_get_contents(LAST_TARGET_HOST_FILE_NAME);
	}
  }

  // get the path
  function getPath() {

	if (array_key_exists(PATH_PARAM_NAME, $_GET) ) {
	  $this->path = $_GET[PATH_PARAM_NAME];
	} else {
	  $this->path = "";
	}
  }

  // get the full page url including the proxy path
  function curPageURL() {
	$pageURL = 'http';
	if ($_SERVER["HTTPS"] == "on") {$pageURL .= "s";}
	$pageURL .= "://";
	if ($_SERVER["SERVER_PORT"] != "80") {
	  $pageURL .= $_SERVER["SERVER_NAME"].":".$_SERVER["SERVER_PORT"].$_SERVER["REQUEST_URI"];
	} else {
	  $pageURL .= $_SERVER["SERVER_NAME"].$_SERVER["REQUEST_URI"];
	}
	return $pageURL;
  }

  // compose the new target url from the requested proxy url and the target host
  function getReqURL() {
	$pageURL = $this->curPageURL();

	$info = parse_url( $pageURL );
	$newURL = $this->targetHOST . $this->path;

	if (array_key_exists("query",$info)) {
	  $q = "";
	  $amp = "";

	  $params = $this->parseQueryString($info['query']);

	  foreach ($params as $n => $p) {
		if($n != TARGET_HOST_PARAM_NAME &&
		   $n != PATH_PARAM_NAME ) {

		  $q .= $amp . urlencode($n) . "=" . urlencode($p);
		  $amp = "&";
		}
	  }

	  if($q != "") {
		$newURL .=  "?" . $q;
	  }

	}

	if (array_key_exists("fragment",$info)) {
	  $newURL .=  "#" . $info['fragment'];
	} 

	return $newURL;
  }

  // massage the link so it works with the proxy
  function fixLink($link, $useProxy) {
	$currTargetHOST = $this->targetHOST;


	if(strncmp($link, "/", 1) == 0) {
      // no host, rooted at document root
	  $info = parse_url( $this->targetHOST . $link); 

	} else if(strncmp($link, "http", 4) != 0) {
      // no host, relative to current path
	  $info = parse_url( $this->targetHOST . $this->path . "/" . $link); 

	} else {
      // fully qualified url
	  $info = parse_url($link); 

	  if (array_key_exists("host",$info)) {
		$currTargetHOST = $info['scheme'] . "://" . $info['host'] . "/";
	  }
	  if (array_key_exists("port",$info)) {
		$currTargetHOST .=  ":" . $info['port'];
	  }
	}

	// currently we only proxy html content, go direct for everything else
	if($useProxy) {
	  $newLink = '/proxy.php';

	  if (array_key_exists("query",$info)) {
		$newLink .= '?' . $info['query'] . "&" . TARGET_HOST_PARAM_NAME . "=" . $currTargetHOST;
	  } else {
		$newLink .= '?' . TARGET_HOST_PARAM_NAME . "=" . $currTargetHOST;
	  }

	  if (array_key_exists("path",$info)) {
		$newLink .= "&" . PATH_PARAM_NAME . "=" . $info['path'];
	  }
	  if (array_key_exists("fragment",$info)) {
		$newLink .=  "#" . $info['fragment'];
	  }

	} else {

	  $newLink = $currTargetHOST . $info['path'];
	  if (array_key_exists("query",$info)) {
		$newLink .= "?" . $info['query'];
	  }
	}

	return $newLink;

  }

  // reset the links for the given tag and attribute
  function resetLink($html, $tagName, $attributeName, $useProxy) {
	foreach($html->find($tagName) as $tag) {

	  if(!isset($tag->attr[$attributeName])) continue;

	  $tag->attr[$attributeName] = $this->fixLink($tag->attr[$attributeName], $useProxy);

	}
  }


  // detect images (TODO: need to detect all binaries!)
  function isImage() {
	$req_headers = getallheaders();
	if(isset($req_headers['Accept'])){
	  $accept = $req_headers['Accept'];
	  if(strncmp($accept, "image/", 6) == 0) {
		return true;
	  } 
	}
	return false;
  }


  function doProxy() {
	
	$this->getTargetHost();
	$this->getPath();

	$newURL = $this->getReqURL();

	if($this->isImage()) {
	  // curl binary stuff
	  $curl = curl_init($newURL);

	  curl_setopt($curl, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);
	  curl_setopt($curl, CURLOPT_FOLLOWLOCATION, FALSE);
	  curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
	  curl_setopt($curl, CURLOPT_AUTOREFERER, TRUE);
	  $result = curl_exec($curl);		

	} else {
	  // parse html, and reset links so they work with the proxy
	  $html = file_get_html($newURL);

	  $this->resetLink($html, "a", "href", true);
	  $this->resetLink($html, "img", "src", false);
	  $this->resetLink($html, "script", "src", false);
	  $this->resetLink($html, "link", "href", false);
	  $this->resetLink($html, "iframe", "src", false);
	  $this->resetLink($html, "object", "data", false);

	  // dump to browser
	  echo $html->root->innertext();
	}
  }
}

$proxy = new Proxy;

$proxy->doProxy();
?>



