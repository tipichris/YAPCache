<?php
/*
Plugin Name: APC Cache
Plugin URI: http://virgingroupdigital.wordpress.com
Description: Caches most database traffic at the expense of some accuracy
Version: 0.3.2
Author: Ian Barber <ian.barber@gmail.com>
Author URI: http://phpir.com/
*/

// Verify APC is installed, suggested by @ozh
if( !function_exists( 'apc_exists' ) ) {
   yourls_die( 'This plugin requires the APC extension: http://pecl.php.net/package/APC' );
}

if(!defined('APC_WRITE_CACHE_TIMEOUT')) {
	define('APC_WRITE_CACHE_TIMEOUT', 120);
}
if(!defined('APC_READ_CACHE_TIMEOUT')) {
	define('APC_READ_CACHE_TIMEOUT', 360);
}
define('APC_CACHE_LOG_INDEX', 'cachelogindex');
define('APC_CACHE_LOG_TIMER', 'cachelogtimer');
define('APC_CACHE_CLICK_INDEX', 'cacheclickindex');
define('APC_CACHE_CLICK_TIMER', 'cacheclicktimer');
define('APC_CACHE_CLICK_KEY_PREFIX', 'cacheclicks-');
define('APC_CACHE_ALL_OPTIONS', 'cache-get_all_options');
define('APC_CACHE_YOURLS_INSTALLED', 'cache-yourls_installed');
if(!defined('APC_CACHE_LONG_TIMEOUT')) {
	define('APC_CACHE_LONG_TIMEOUT', 86400);
}

yourls_add_action( 'pre_get_keyword', 'apc_cache_pre_get_keyword' );
yourls_add_filter( 'get_keyword_infos', 'apc_cache_get_keyword_infos' );
if(!defined('APC_CACHE_SKIP_CLICKTRACK')) {
	yourls_add_filter( 'shunt_update_clicks', 'apc_cache_shunt_update_clicks' );
	yourls_add_filter( 'shunt_log_redirect', 'apc_cache_shunt_log_redirect' );
}
yourls_add_filter( 'shunt_all_options', 'apc_cache_shunt_all_options' );
yourls_add_filter( 'get_all_options', 'apc_cache_get_all_options' );
yourls_add_filter( 'activated_plugin', 'apc_cache_plugin_statechange' );
yourls_add_filter( 'deactivated_plugin', 'apc_cache_plugin_statechange' );
yourls_add_filter( 'edit_link', 'apc_cache_edit_link' );

/**
 * Return cached options is available
 *
 * @param bool $false 
 * @return bool true
 */
function apc_cache_shunt_all_options($false) {
	global $ydb; 
	
	$key = APC_CACHE_ALL_OPTIONS; 
	if(apc_exists($key)) {
		$ydb->option = apc_fetch($key);
		$ydb->installed = apc_fetch(APC_CACHE_YOURLS_INSTALLED);
		return true;
	} 
	
	return false;
}

/**
 * Cache all_options data. 
 *
 * @param array $options 
 * @return array options
 */
function apc_cache_get_all_options($option) {
	apc_store(APC_CACHE_ALL_OPTIONS, $option, APC_READ_CACHE_TIMEOUT);
        // Set timeout on installed property twice as long as the options as otherwise there could be a split second gap
	apc_store(APC_CACHE_YOURLS_INSTALLED, true, (2 * APC_READ_CACHE_TIMEOUT));
	return $option;
}

/**
 * Clear the options cache if a plugin is activated or deactivated
 *
 * @param string $plugin 
 */
function apc_cache_plugin_statechange($plugin) {
        apc_delete(APC_CACHE_ALL_OPTIONS);
}

/**
 * If the URL data is in the cache, stick it back into the global DB object. 
 * 
 * @param string $args
 */
function apc_cache_pre_get_keyword($args) {
	global $ydb;
	$keyword = $args[0];
	$use_cache = isset($args[1]) ? $args[1] : true;
	
	// Lookup in cache
	if($use_cache && apc_exists($keyword)) {
		$ydb->infos[$keyword] = apc_fetch($keyword); 	
	}
}

/**
 * Store the keyword info in the cache
 * 
 * @param array $info
 * @param string $keyword
 */
function apc_cache_get_keyword_infos($info, $keyword) {
	// Store in cache
	apc_store($keyword, $info, APC_READ_CACHE_TIMEOUT);
	return $info;
}

/**
 * Delete a cache entry for a keyword if that keyword is edited.
 * 
 * @param array $return
 * @param string $url
 * @param string $keyword
 * @param string $newkeyword
 * @param string $title
 * @param bool $new_url_already_there
 * @param bool $keyword_is_ok
 */
function apc_cache_edit_link( $return, $url, $keyword, $newkeyword, $title, $new_url_already_there, $keyword_is_ok ) {
	if($return['status'] != 'fail') {
		apc_delete($keyword);
	}
	return $return;
}

/**
 * Update the number of clicks in a performant manner.  This manner of storing does
 * mean we are pretty much guaranteed to lose a few clicks. 
 * 
 * @param string $keyword
 */
function apc_cache_shunt_update_clicks($false, $keyword) {
	global $ydb;
	
	if(defined('APC_CACHE_STATS_SHUNT')) {
		if(APC_CACHE_STATS_SHUNT == "drop") {
			return true;
		} else if(APC_CACHE_STATS_SHUNT == "none"){
			return false;
		}
	} 
	
	$keyword = yourls_sanitize_string( $keyword );
	$key = APC_CACHE_CLICK_KEY_PREFIX . $keyword;
	
	// Store in cache
	$added = false; 
	if(!apc_exists($key)) {
		$added = apc_add($key, 1);
	}
	if(!$added) {
		apc_cache_key_increment($key);
	}
        
        /* we need to keep a record of which keywords we have
         * data cached for. We do this in an associative array
         * stored at APC_CACHE_CLICK_INDEX, with keyword as the keyword
         */
	$idxkey = APC_CACHE_CLICK_INDEX;
	if(apc_exists($idxkey)) {
		$clickindex = apc_fetch($idxkey);
	} else {
		$clickindex = array();
	}
	$clickindex[$keyword] = 1;
	apc_store ( $idxkey, $clickindex);
	
	if(apc_add(APC_CACHE_CLICK_TIMER, time(), APC_WRITE_CACHE_TIMEOUT)) {
		apc_cache_write_clicks();
	}
	
	return true;
}

/**
 * write any cached clicks out to the database 
 */
function apc_cache_write_clicks() {
	global $ydb;
	apc_cache_debug("Writing clicks to database");

	$idxkey = APC_CACHE_CLICK_INDEX;
	if(apc_exists($idxkey)) {
		$clickindex = apc_fetch($idxkey);
		apc_delete($idxkey); // TODO something here to check that it deleted, cycle through again if no?
	} else {
		return;
	}
	
	/* as long as the tables support transactions, it's much faster to wrap all the updates
	 * up into a single transaction. Reduces the overhead of starting a transaction for each
	 * query. The down side is that if one query errors we'll loose the log
	 */
	$ydb->query("START TRANSACTION");
	foreach ($clickindex as $keyword => $z) {
		$key = APC_CACHE_CLICK_KEY_PREFIX . $keyword;
		$value = 0;
		if(apc_exists($key)) {
			$value += apc_cache_key_zero($key);
		}
		apc_cache_debug("Adding $value clicks for $keyword");
		// Write value to DB
		$ydb->query("UPDATE `" . 
						YOURLS_DB_TABLE_URL. 
					"` SET `clicks` = clicks + " . $value . 
					" WHERE `keyword` = '" . $keyword . "'");
	}
	apc_cache_debug("Committing changes");
	$ydb->query("COMMIT");

}

/**
 * Update the log in a performant way. There is a reasonable chance of losing a few log entries. 
 * This is a good trade off for us, but may not be for everyone. 
 *
 * @param string $keyword
 */
function apc_cache_shunt_log_redirect($false, $keyword) {
	global $ydb;
	
	if(defined('APC_CACHE_STATS_SHUNT')) {
		if(APC_CACHE_STATS_SHUNT == "drop") {
			return true;
		} else if(APC_CACHE_STATS_SHUNT == "none"){
			return false;
		}
	}
	
	$args = array(
		yourls_sanitize_string( $keyword ),
		( isset( $_SERVER['HTTP_REFERER'] ) ? yourls_sanitize_url( $_SERVER['HTTP_REFERER'] ) : 'direct' ),
		yourls_get_user_agent(),
		yourls_get_IP(),
		yourls_geo_ip_to_countrycode( $ip )
	);
	
	// Separated out the calls to make a bit more readable here
	$key = APC_CACHE_LOG_INDEX;
	$logindex = 0;
	$added = false;
	
	if(!apc_exists($key)) {
		$added = apc_add($key, 0);
	} 
	
	if(!$added) {
		$logindex = apc_cache_key_increment($key);
	}
	
	// We now have a reserved logindex, so lets cache
	apc_store(apc_cache_get_logindex($logindex), $args, APC_CACHE_LONG_TIMEOUT);
	
	// If we've been caching for over a certain amount do write
	if(apc_add(APC_CACHE_LOG_TIMER, time(), APC_WRITE_CACHE_TIMEOUT)) {
		// We can add, so lets flush the log cache
		apc_cache_write_log();
	} 
	
	return true;
}

/**
 * write any cached log entries out to the database 
 */
function apc_cache_write_log() {
	global $ydb;
	apc_cache_debug("Writing log to database");

	$key = APC_CACHE_LOG_INDEX;
	$index = apc_fetch($key);
	$fetched = -1;
	$loop = true;
	$values = array();
	
	// Retrieve all items and reset the counter
	while($loop) {
		for($i = $fetched+1; $i <= $index; $i++) {
			$values[] = apc_fetch(apc_cache_get_logindex($i));
		}
		
		$fetched = $index;
		
		if(apc_cas($key, $index, 0)) {
			$loop = false;
		} else {
			usleep(500);
		}
	}

	// Insert all log message - we're assuming input filtering happened earlier
	$query = "";

	foreach($values as $value) {
		if(strlen($query)) {
			$query .= ",";
		}
		$query .= "(NOW(), '" . 
			$value[0] . "', '" . 
			$value[1] . "', '" . 
			$value[2] . "', '" . 
			$value[3] . "', '" . 
			$value[4] . "')";
	}

	$ydb->query( "INSERT INTO `" . YOURLS_DB_TABLE_LOG . "` 
				(click_time, shorturl, referrer, user_agent, ip_address, country_code)
				VALUES " . $query);

}

/**
 * Helper function to return a cache key for the log index.
 *
 * @param string $key 
 * @return string
 */
function apc_cache_get_logindex($key) {
	return APC_CACHE_LOG_INDEX . "-" . $key;
}

/**
 * Helper function to do an atomic increment to a variable, 
 * 
 *
 * @param string $key 
 * @return void
 */
function apc_cache_key_increment($key) {
	do {
		$result = apc_inc($key);
	} while(!$result && usleep(500));
	return $result;
}

/**
 * Reset a key to 0 in a atomic manner
 *
 * @param string $key 
 * @return old value before the reset
 */
function apc_cache_key_zero($key) {
	$old = 0;
	do {
		$old = apc_fetch($key);
		if($old == 0) {
			return $old;
		}
		$result = apc_cas($key, $old, 0);
	} while(!$result && usleep(500));
	return $old;
}

function apc_cache_debug ($msg) {
	if (defined('APC_CACHE_DEBUG') && APC_CACHE_DEBUG) { 
		error_log("yourls_apc_cache: " . $msg);
	}
}
