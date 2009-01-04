<?php
/* ===========================================================================
  GoogleAlertApi - a PHP class implemting an API to Google Alerts
  Copyright (C) 2009 Nicholas de Jong
  =========================================================================== */

// GoogleAlertApi( string[username], string[password], string[cookiefile], string[useragent] )
//   The only required input parameters for this class are the google account username and password.  Reasonable defaults are chosen for
//   all other parameters, which can be adjusted if needed.
//   
//   username   = [required] google account username
//   password   = [required] corrosponding google account password
//   cookiefile = location of an alternate cookie file to be used.  The cookie file manages and maintains the logged in session state.  You must manage this file if you plan on using more than one google account !!
//   useragent  = adjust the user agent presented if you feel the need.
// 
//   Example class instantiation
//     $gapi = new GoogleAlertApiClass('GOOGLE_USERNAME','GOOGLE_PASSWORD');
//     $gapi = new GoogleAlertApiClass('GOOGLE_USERNAME','GOOGLE_PASSWORD',null,null);
//
//   Functions available
//     alertList() -- Can be caled once a GoogleAlertApiClass has been created.  Returns an array of alert terms and their respective RSS feed URLs.
//     alertAdd(string[TERM]) -- adds a new google alert.  Returns 1 of sucess or 0 on failure.
//     alertRemove(string[TERM]) -- removes a google alert term. Returns 1 of sucess or 0 on failure.
//     alertEdit(string[OLD_TERM], string[NEW_TERM]) -- shortcut function, simply calls alertRemove and alertAdd on their respective terms. Returns 1.


// GoogleAlertApiClass class
// ====================================================================
class GoogleAlertApi
{

	// Define the class vars
	var $username	= null;    //
	var $password 	= null;    //
	var $cookiefile	= null;    //
	var $useragent	= null;    //
	var $alertlist  = array(); // array of the google alert terms and RSS links
	var $logger	 	= null;
	
	// GoogleAlertApi creator
	// ===========================================================================
	function GoogleAlertApi($username,$password,$cookidfile=null,$useragent=null)
	{
		// Establish a logger
		$this->logger = &Log::singleton('composite');
		
		// Confirm the google username
		if(is_null($username) or empty($username)) {
			die("GoogleAlertApi must be created with a username and password set.");
		} else {
			$this->username   = $username;
		}
		
		// Confirm the google account password
		if(is_null($password) or empty($password)) {
			die("GoogleAlertApi must be created with a username and password set.");
		} else {
			$this->password   = $password;
		}
		
		// Create or confirm the googleapicookie file
		if(is_null($cookiefile)) {
			$this->cookiefile = sys_get_temp_dir().'/googlealertapi.'.md5($username.$password).'.cookie';
		} else {
			$this->cookiefile = $cookiefile;
		}
		
		// Establish a useragent string
		if(is_null($useragent)) {
			$this->useragent = "Mozilla/4.0 (compatible; MSIE 7.0; Windows NT 5.1)";
		} else {
			$this->useragent  = $useragent;
		}
		
		// Refresh the alert list, umm, real work in the constructor, I'm going to hell.
		$this->refreshAlertList();
	}

	// ====================================================================
	// PUBLIC FUNCTIONS
	// ====================================================================

	// alertList()
	//   Return the list of Google Alert terms
	// ====================================================================
	public function alertList()
	{
		$this->log(__FUNCTION__."() called.",'debug');
		
		if(isset($this->alertlist['alerts'])) {
			$this->log(__FUNCTION__."() ".count($this->alertlist['alerts'])." alerts returned.",'debug');
			$results=array();
			foreach($this->alertlist['alerts'] as $term=>$value){
				$results[$term] = $value['rss'];
			}
			$this->log(__FUNCTION__."() returns ".count($results)." results.",'debug');
			return $results;
		} else {
			$this->log(__FUNCTION__."() no alerts are defined for this account.",'warning');
			$this->log(__FUNCTION__."() returns FALSE",'debug');
			return FALSE;
		}
	}

	// alertAdd(string[term])
	//   Function to add a new Google Alert
	// ====================================================================
	public function alertAdd($term)
	{
		$this->log(__FUNCTION__."() called with term=".$term.".",'debug');
		
		// Set the case of everything to uppercase since google does not consider capitalization
		// http://www.google.com/support/bin/static.py?page=searchguides.html&ctx=basics&hl=en#case
		$term = strtolower($term);
	
		// Ensure we have a google session happening
		if(!$this->googleSessionCookieEnsure()){
			$this->log(__FUNCTION__."() unable to establish session with google for this account",'err');
			$this->log(__FUNCTION__."() returns FALSE",'debug');
			return FALSE;
		}
		
		// Make sure this term is not already present on the alert list
		if(is_array($this->alertlist['alerts'])){
			if(in_array($term,array_keys($this->alertlist['alerts']))) {
				$this->log(__FUNCTION__."() not adding term '".$term."' since it already exists.",'warning');
				$this->log(__FUNCTION__."() returns FALSE",'debug');
				return FALSE;
			}
		} else {
			$this->log(__FUNCTION__."() no alert terms apper to exist for this account, this will be the first.",'info');
		}
		
		// Go to the form for adding new alerts
		$params = array(
			'user-agent'	=> $this->useragent,
			'referer'	=> 'http://www.google.com/alerts',
			'url'		=> 'http://www.google.com/alerts?hl=en&gl=us',
		);
		// Make this CURL call and confirm we received the form page required
		$content = $this->callCurl($params);
		if(!preg_match("/conditionallySetSingleSelectValue/",$content)) {
			$this->log(__FUNCTION__."() unable to get the required form to add a google alert term.",'err');
			$this->log(__FUNCTION__."() returns FALSE",'debug');
			return FALSE;
		}
		
		// Grab the required form sig value
		$sig = null;
		$matches=null;if(!preg_match("/<input type=hidden name=\"sig\" value=\"(.*?)\">/",$content,$matches)) {
			$this->log(__FUNCTION__."() unable to get the required form value 'sig' to add a google alert term.",'err');
			$this->log(__FUNCTION__."() returns FALSE",'debug');
			return FALSE;
		} else {
			$sig = $matches[1];
		}
		
		// Add the term
		$params = array(
			'user-agent'	=> $this->useragent,
			'data'		=> array(
						'sig'	=> urlencode($sig),	// form 'sig' va;ue
						'q'	=> urlencode($term),	// the serach term
						't'	=> urlencode('7'),	// comprehensive search
						'e'	=> urlencode('feed'),	// establish an rss feed
			),
			'referer'	=> 'http://www.google.com/alerts',
			'url'		=> 'http://www.google.com/alerts/create?hl=en&gl=us',
		);
		// Make this CURL call and confirm we received the result page expected
		$content = $this->callCurl($params);
		if(!preg_match("/The document has moved/",$content)) {
			$this->log(__FUNCTION__."() unable to set the requested google alert term.",'err');
			$this->log(__FUNCTION__."() returns FALSE",'debug');
			return FALSE;
		}
		
		// Just give Google just a moment to catch up here...
		// NOTE: Outside clobbering with refresh attempts, not clear what else what we can do  :(
		sleep(2); // Don't go below 2 seconds, else you will race condition google
		
		// Refresh the alert list
		$this->refreshAlertList();
		
		// Confirm this new term is on the list
		if(!in_array($term,array_keys($this->alertlist['alerts']))) {
			$this->log(__FUNCTION__."() unable to confirm new alert term has been correctly added.",'err');
			$this->log(__FUNCTION__."() returns FALSE",'debug');
			return FALSE;
		} else {
			$this->log(__FUNCTION__."() returns TRUE",'debug');
			return TRUE;
		}
		die(); // never should get here
	}

	// alertRemove(string[term])
	//   Function to remove an existing Google Alert
	// ====================================================================
	public function alertRemove($term)
	{
		$this->log(__FUNCTION__."() called with term=".$term.".",'debug');
		
		if(!$this->googleSessionCookieEnsure()){
			$this->log(__FUNCTION__."() unable to establish session with google for this account.",'err');
			$this->log(__FUNCTION__."() returns FALSE",'debug');
			return FALSE;
		}
		
		// Make sure this term is on the alert list
		if(!in_array($term,array_keys($this->alertlist['alerts']))) {
			$this->log(__FUNCTION__."() term does not appear to exist in order to remove it.",'warning');
			$this->log(__FUNCTION__."() returns FALSE",'debug');
			return FALSE;
		}
		
		// Remove the term
		$params = array(
			'user-agent'	=> $this->useragent,
			'data'		=> array(
						'e'	=> urlencode($this->alertlist['form_data']['e']),	// account email address
						'sig'	=> urlencode($this->alertlist['form_data']['sig']),	// form 'sig' value
						's'	=> urlencode($this->alertlist['alerts'][$term]['s']),	// this terms s value
						'da'	=> urlencode('Delete'),					// action
			),
			'referer'	=> 'http://www.google.com/alerts/manage?hl=en&gl=us',
			'url'		=> 'http://www.google.com/alerts/save?hl=en&gl=us',
		);
		// Make this CURL call and confirm we received the result page expected
		$content = $this->callCurl($params);
		if(!preg_match("/The document has moved/",$content)) {
			$this->log(__FUNCTION__."() unable to set the requested google alert term.",'err');
			$this->log(__FUNCTION__."() returns FALSE",'debug');
			return FALSE;
		}
		
		// Just give Google just a moment to catch up here...
		// NOTE: Outside clobbering with refresh attempts, not clear what else what we can do  :(
		sleep(2); // Don't go below 2 seconds, else you will race condition google
		
		// Refresh the alert list
		$this->refreshAlertList();
		
		// Confirm this term has been removed from the list
		if(!in_array($term,array_keys($this->alertlist['alerts']))) {
			$this->log(__FUNCTION__."() returns TRUE",'debug');
			return TRUE;
		} else {
			$this->log(__FUNCTION__."() unable to confirm alert term has been correctly removed.",'warning');
			$this->log(__FUNCTION__."() returns FALSE",'debug');
			return FALSE;
		}
		die(); // never should get here
	}

	// alertPurgeAll()
	//   Purge all alerts from a Google Alerts 
	// ====================================================================
	public function alertPurgeAll()
	{
		$this->log(__FUNCTION__."() called",'debug');
		
		// Get the current list of alerts and remove them all
		$count=0;
		if(isset($this->alertlist['alerts'])) {
			$results=array();
			foreach($this->alertlist['alerts'] as $term=>$value){
				$this->alertRemove($term);
				$count=$count+1;
			}
		} else {
			$this->log(__FUNCTION__."() no alerts are defined for this account hence nothing to purge.",'warning');
			$this->log(__FUNCTION__."() returns FALSE",'debug');
			return FALSE;
		}
		$this->log(__FUNCTION__."() removed ".$count." alerts.",'info');
		$this->log(__FUNCTION__."() returns TRUE",'debug');
		return TRUE;
	}

	// alertEdit(string[old_term], string[new_term])
	//   Function to edit an existing Google Alert
	// ====================================================================
	public function alertEdit($old_term,$new_term)
	{
		$this->log(__FUNCTION__."() called with old_term=".$old_term." and new_term=".$new_term.".",'debug');
		
		// Remove the old term if it appears on the list, throw warning if not
		$this->alertRemove($old_term);
		
		// Add the new term to the list
		$this->alertAdd($new_term);
		
		$this->log(__FUNCTION__."() returns TRUE",'debug');
		return TRUE;
	}
	
	// ====================================================================
	// PRIVATE FUNCTIONS
	// ====================================================================

	// refreshAlertList(bool[return or send to class var])
	//   List the Google Alert terms assigned to this account
	// ====================================================================
	private function refreshAlertList($return=FALSE)
	{
		$this->log(__FUNCTION__."() called with return=".$return.".",'debug');

		if(!$this->googleSessionCookieEnsure()){
			$this->log(__FUNCTION__."() unable to establish session with google for this account.",'err');
			$this->log(__FUNCTION__."() returns FALSE",'debug');
			return FALSE;
		}
		
		// Make call to google to list out existing google alerts
		$params = array(
			'user-agent'	=> $this->useragent,
			'referer'	=> 'http://www.google.com/alerts',
			'url'		=> 'http://www.google.com/alerts/manage?hl=en&gl=us',
		);
		// Make this CURL call and confirm we received a sucessful login
		$content = $this->callCurl($params);
		if(!preg_match("/conditionallySetSingleSelectValue/",$content)) {
			$this->log(__FUNCTION__."() unable to retrieve list of alerts for this account.",'err');
			$this->log(__FUNCTION__."() returns FALSE",'debug');
			return FALSE;
		}
		
		// Check if this user does not yet have any alerts set
		$matches=null; preg_match("/You don't have any Google Alerts/",$content,$matches);
		if(isset($matches[0])) {
			$this->log(__FUNCTION__."() account does not yet have any google alerts set.",'warning');
			$this->log(__FUNCTION__."() returns FALSE",'debug');
			return FALSE;
		}
		
		// Result data goes into $data
		$data = array();
		
		// Extract the form sig value as required to edit anything here
		$matches=null; preg_match("/<input type=hidden name=\"sig\" value=\"(.*?)\">/",$content,$matches);
		if(isset($matches[1])) { $data['form_data']['sig'] = $matches[1]; } else {
			$this->log(__FUNCTION__."() unable to retrieve required alert list form element 'sig'.",'error');
			$this->log(__FUNCTION__."() returns FALSE",'debug');
			return FALSE;
		}

		// Extract the user email address as it is required in return forms
		$matches=null; preg_match("/<input type=hidden name=e value=\"(.*?)\">/",$content,$matches);
		if(isset($matches[1])) { $data['form_data']['e'] = $matches[1]; } else {
			$this->log(__FUNCTION__."() unable to retrieve required alert list form element 'e'.",'error');
			$this->log(__FUNCTION__."() returns FALSE",'debug');
			return FALSE;
		}
		
		// Extract all the terms listed on this page
		$matches=null; preg_match_all("/<tr class=\"data_row\">(.*?)<\/tr>/s",$content,$matches);
		if(!isset($matches[1])) {
			$this->log(__FUNCTION__."() no alerts are defined for this account.",'warning');
			$this->log(__FUNCTION__."() returns FALSE",'debug');
			return FALSE;
		} else {
			foreach($matches[1] as $data_row){
			
				// Extract term
				$term = null; $matches=null; preg_match("/<a href=\"http:\/\/news\.google\.com.*?\">(.*?)<\/a>/s",$data_row,$matches);
				if(isset($matches[1])) { $term = $matches[1]; $term = htmlspecialchars_decode($term); } else { continue; } 
				
				// Extract the feed URL
				$matches=null; preg_match("/<a href=\"(http:\/\/www\..*?)\">Feed<\/a>/s",$data_row,$matches);
				if(isset($matches[1])){ $data['alerts'][$term]['rss'] = $matches[1]; }
			
				// Extract out the s value
				$matches=null; preg_match("/<input type=checkbox name=\"s\" value=\"(.*?)\">/s",$data_row,$matches);
				if(isset($matches[1])){ $data['alerts'][$term]['s'] = $matches[1]; }
			}
		}
		$this->log(__FUNCTION__."() ".count($data['alerts'])." alerts defined for this account",'debug');
		
		// Decide how if we should return this data or just assign to the class var.
		if($return) {
			$this->log(__FUNCTION__."() returning alert list data directly.",'info');
			
			$this->log(__FUNCTION__."() returns ".count($data)." values",'debug');
			return $data;
		} else {
			$this->log(__FUNCTION__."() assigning alert list data to class var.",'debug');
			$this->alertlist = $data;
			
			$this->log(__FUNCTION__."() returns TRUE",'debug');
			return TRUE;
		}
		die(); // should never get here.
	}

	// googleSessionLogin()
	//   Login to a Google account
	// ====================================================================
	private function googleSessionLogin($remove_cookie=null)
	{
		$this->log(__FUNCTION__."() called with remove_cookie=".$remove_cookie.".",'debug');
	
		// If we are told to remove a cookie then do it
		if($remove_cookie) {
			$this->log(__FUNCTION__."() called with request to remove the existing cookie file at ".$this->cookiefile.".",'err');
			if(file_exists($this->cookiefile)) {
				if(unlink($this->cookiefile)){
					$this->log(__FUNCTION__."() removed cookie file at ".$this->cookiefile.".",'err');
				} else {
					$this->log(__FUNCTION__."() unable to remove cookie file at ".$this->cookiefile.".",'warning');
				}
				$this->log(__FUNCTION__."() no existing cookie file at ".$this->cookiefile.".",'info');
			}
		}
		
		// Set a new cookiefile into place with a call to Google
		$params = array(
			'user-agent'	=> $this->useragent,
			'data'		=> array(
						'Email'			=> urlencode($this->username),
						'Passwd'		=> urlencode($this->password),
						'PersistentCookie'	=> urlencode('yes'),
						'rmShown'		=> urlencode('1'),
						'signIn'		=> urlencode('Sign in'),
						'asts'			=> urlencode(''),
			),
			'referer'	=> 'https://www.google.com/accounts/Login',
			'url'		=> 'https://www.google.com/accounts/LoginAuth',
		);
		// Make this CURL call and confirm we received a sucessful login
		if(!preg_match("/LoginDoneHtml/",$this->callCurl($params))) {
			$this->log(__FUNCTION__."() unable to login to google account, possibly due to bad credentials for ".$this->username,'err');
			$this->log(__FUNCTION__."() returns FALSE",'debug');
			return FALSE;
		}
		
		// Confirm this new cookie for Google to let them know you have it
		$params = array(
			'user-agent'	=> $this->useragent,
			'referer'	=> 'https://www.google.com/accounts/Login',
			'url'		=> 'https://www.google.com/accounts/CheckCookie?chtml=LoginDoneHtml',
		);
		// Make this CURL call and confirm we received a sucessful login
		if(!preg_match("/ManageAccount/",$this->callCurl($params))) {
			$this->log(__FUNCTION__."() unable to confirm cookie contents for Google, while the credentials appear to be successful.",'err');
			$this->log(__FUNCTION__."() returns FALSE",'debug');
			return FALSE;
		}
		
		// If there is a cookie file then we are going to assume all is good
		if(file_exists($this->cookiefile)) {
			$this->log(__FUNCTION__."() sucessfull login to this google account.",'info');
			$this->log(__FUNCTION__."() returns TRUE",'debug');
			return TRUE;
		} else {
			$this->log(__FUNCTION__."() unable to login to this google account.",'err');
			$this->log(__FUNCTION__."() returns FALSE",'debug');
			return FALSE;
		}
		die(); // should never get here.
	}

	// googleSessionEnsure()
	//   Checks if we have an active google session and creates one if not.
	// ====================================================================
	private function googleSessionCookieEnsure()
	{
		$this->log(__FUNCTION__."() called",'debug');
	
		// Check if $this->cookiefile exists and if it does have a go at using it first
		if(file_exists($this->cookiefile)){
			$this->log(__FUNCTION__."() existing cookie file found at ".$this->cookiefile.", using this cookie file.",'debug');
			$this->log(__FUNCTION__."() returns TRUE",'debug');
			return TRUE;
		} else {
			if($this->googleSessionLogin() && file_exists($this->cookiefile)){
				$this->log(__FUNCTION__."() created a new session cookie file at ".$this->cookiefile.".",'debug');
				$this->log(__FUNCTION__."() returns TRUE",'debug');
				return TRUE;
			} else {
				$this->log(__FUNCTION__."() failed to create a new session cookie at ".$this->cookiefile.".",'debug');
				$this->log(__FUNCTION__."() returns FALSE",'debug');
				return FALSE;
			}
		}
		die(); // should never get here.
	}
	
	// callCurl([parameters])
	//   Makes a HTTP get or post using CURL to craft and issue the request
	// ====================================================================
	private function callCurl($params)
	{
		$this->log(__FUNCTION__."() called",'debug');
		
		// Make sure minimum required parameters are set
		if(isset($params['user-agent'])) {
			$this->log(__FUNCTION__."() user-agent: ".$params['user-agent'],'debug');
		} else {
			$this->log(__FUNCTION__."() required parameter 'user-agent' not set.",'err');
			$this->log(__FUNCTION__."() returns FALSE",'debug');
			return FALSE;
		}
		
		if(isset($params['referer'])) {
			$this->log(__FUNCTION__."() referer: ".$params['referer'],'debug');
		} else {
			$this->log(__FUNCTION__."() required parameter 'referer' not set.",'err');
			$this->log(__FUNCTION__."() returns FALSE",'debug');
			return FALSE;
		}
		
		if(isset($params['url'])) {
			$this->log(__FUNCTION__."() url: ".$params['url'],'debug');
		} else {
			$this->log(__FUNCTION__."() required parameter 'url' not set.",'err');
			$this->log(__FUNCTION__."() returns FALSE",'debug');
			return FALSE;
		}
		
		// Encode any POST data that may have been provided
		$post_string = null;
		if(isset($params['data'])){
			foreach($params['data'] as $key=>$value) {
				$post_string .= $key.'='.$value.'&';
			}
			$this->log(__FUNCTION__."() post-data:".$post_string,'debug');
		}
		rtrim($post_data,'&');
		
		// Open a Curl.
		$this->log(__FUNCTION__."() curl_init()",'debug');
		$curl = curl_init();

		// Set the required curl options
		curl_setopt($curl,CURLOPT_NOPROGRESS,TRUE);
		curl_setopt($curl,CURLOPT_VERBOSE,FALSE);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		if(isset($params['data'])){ curl_setopt($curl,CURLOPT_POST,TRUE); curl_setopt($curl,CURLOPT_POSTFIELDS,$post_string); }
		curl_setopt($curl,CURLOPT_USERAGENT,$params['user-agent']);
		curl_setopt($curl,CURLOPT_REFERER,$params['referer']);
		curl_setopt($curl,CURLOPT_COOKIEFILE,$this->cookiefile);
		curl_setopt($curl,CURLOPT_COOKIEJAR,$this->cookiefile);
		curl_setopt($curl,CURLOPT_URL,$params['url']);
		
		// Execute the curl
		$this->log(__FUNCTION__."() curl_exec()",'debug');
		$result = curl_exec($curl);

		// Close the curl
		$this->log(__FUNCTION__."() curl_close()",'debug');
		curl_close($curl);
		
		$this->log(__FUNCTION__."() returns with string length=".strlen($result),'debug');
		return $result;
	}

	// log
	//   Deals with setting up required log transports and getting the
	//   log message out via the PEAR Log
	//   http://www.indelible.org/php/Log/guide.html
	// ====================================================================
	public function log($message=null,$level='info')
	{
		// Ensure we have an application name set
		if(!defined(APP_NAME))
		{
			define('APP_NAME',__CLASS__);
		}
		
		// Ensure we created a Log class before this
		if(!is_object($this->logger))
		{
			die("FATAL: log() can not be called without an available PEAR Log class created.");
		}
		else 
		{
			// Determine the active loggers
			$loggers_active=array();
			foreach($this->logger->_children as $logger_type) {
				array_push($loggers_active,get_class($logger_type));
			}
			
			if(defined('LOG_CONF_DISPLAY') and !in_array('Log_display',$loggers_active))
			{
				$log_conf=array(); $conf = unserialize(LOG_CONF_DISPLAY);
				if(isset($conf['error_prepend'])) { $log_conf['error_prepend'] = $conf['error_prepend']; } else { $log_conf['error_prepend'] = '<font color="#ff0000"><tt>'; }
				if(isset($conf['error_append']))  { $log_conf['error_append']  = $conf['error_append'];  } else { $log_conf['error_append']  = '</tt></font>'; }
				$log_display = &Log::singleton('display', '', APP_NAME, $log_conf, LOG_LEVEL_DISPLAY);
				$this->logger->addChild($log_display);
			}
		
			if(defined('LOG_CONF_FILE') and !in_array('Log_file',$loggers_active))
			{ 
				$log_conf=array(); $filename=null; $conf = unserialize(LOG_CONF_FILE);
				if(isset($conf['filename']))   { $filename               = $conf['filename'];   } else { $filename               = sys_get_temp_dir().'/'.APP_NAME.'.log'; }
				if(isset($conf['mode']))       { $log_conf['mode']       = $conf['mode'];       } else { $log_conf['mode']       = '0644'; }
				if(isset($conf['timeFormat'])) { $log_conf['timeFormat'] = $conf['timeFormat']; } else { $log_conf['timeFormat'] = '%F %H:%M:%S'; }
				$log_file = &Log::singleton('file',$filename, APP_NAME, $log_conf, LOG_LEVEL_FILE);
				$this->logger->addChild($log_file);
			}
		
			if(defined('LOG_CONF_MAIL') and !in_array('Log_mail',$loggers_active)) 
			{ 
				$log_conf=array(); $email_addr=null;$conf = unserialize(LOG_CONF_MAIL);
				if(isset($conf['email_addr']))   { $email_addr              = $conf['email_addr']; } else { $email_addr              = 'root@localhost'; }
				if(isset($conf['from']))         { $log_conf['from']        = $conf['from'];       } else { $log_conf['from']        = $email_addr; }
				if(isset($conf['subject']))      { $log_conf['subject']     = $conf['subject'];    } else { $log_conf['subject']     = APP_NAME.' Log'; }
				if(isset($conf['mailBackend']))  { $log_conf['mailBackend'] = $conf['mailBackend'];} else { $log_conf['mailBackend'] = ''; }
				if(isset($conf['timeFormat'])) { $log_conf['timeFormat'] = $conf['timeFormat']; } else { $log_conf['timeFormat'] = '%F %H:%M:%S'; }
				$log_mail = &Log::singleton('mail', $email_addr, APP_NAME, $log_conf, LOG_LEVEL_MAIL);
				$this->logger->addChild($log_mail);
			}
		}
		
		// Deal with sending the log message
		if('emerg'==$level) {
				$this->logger->emerg($message);
		} elseif ('alert'==$level) {
				$this->logger->alert($message);
		} elseif ('crit'==$level) {
				$this->logger->crit($message);
		} elseif ('err'==$level) {
				$this->logger->err($message);
		} elseif ('warning'==$level) {
				$this->logger->warning($message);
		} elseif ('notice'==$level) {
				$this->logger->notice($message);
		} elseif ('debug'==$level) {
				$this->logger->debug($message);
		} else {
				$this->logger->info($message);
		}
		
		// return true
		return 1;
	}
}

