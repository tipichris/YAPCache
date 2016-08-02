APC Cache
=========

An APCu based caching plugin for the [YOURLS](http://yourls.org/) URL shortener. 

This plugin is designed to remove a lot of the database traffic from YOURLS, primarily the write load from doing the logging and click tracking. We have attempted to strike a balance between keeping most information, but spilling it in some cases in the name of higher performance. 


Installation
------------

1. Download the latest apc-cache-plugin.
2. Copy the plugin folder into your user/plugins folder for YOURLS.
3. Set up the parameters for apc-cache-plugin in YOURLS configuration user/config.php (see below).
4. Copy the cache.php file into user/.
5. There is no need to activate this plugin

A recent version of APC is required.

Configuration
-------------

The plugin comes with working defaults, but you will probably want to adjust things to your particular needs. The following constants can be defined in `user/config.php` to alter the plugins behaviour, eg 

```php
define("APC_CACHE_READ_TIMEOUT", 360);
```

### APC_CACHE_WRITE_TIMEOUT
_Interger. Default: 120._ 
Number of seconds to cache data for before writing it out to the database. A value of 0 will disable writing based on time


### APC_CACHE_READ_TIMEOUT
_Interger. Default: 3600._
Number of seconds to cache reads from the database. We do try to delete cached data that has been changed, but not all YOURLS functions that change data have hooks we can use, so it is possible that for a time some stats etc will appear out of date in the admin interface. Redirects should be current, however

### APC_CACHE_LONG_TIMEOUT
_Interger. Default: 86400._
A timeout used for a few long lasting indexes. You probably don't need to change this

### APC_CACHE_LOCK_TIMEOUT
_Interger. Default: 30._
Maximum number of seconds to hold a lock on the indexes whilst doing a database write. If things are working this shouldn't take anywhere near 30 seconds (may 2 maximum)

### APC_CACHE_MAX_LOAD
_Interger. Default: 0.7._
If the system load exceeds this value apc-cache will delay writes to the database. A sensible value for this will depend, amongst other things, on how many processors you have. A value of 0 means don't check the load before writing. This setting has no effect on Windows.

When the time cached exceeds APC_CACHE_WRITE_HARD_TIMEOUT writes will be done no matter what the load.

### APC_CACHE_WRITE_HARD_TIMEOUT
_Interger. Default: 600._
Number of seconds before a write of cached data to the database is forced, even if the load exceeds APC_CACHE_WRITE_HARD_TIMEOUT.

### APC_CACHE_BACKOFF_TIME
_Interger. Default: 30._
Number of seconds to delay the next attempt to write to the database if the load exceeds APC_CACHE_MAX_LOAD

### APC_CACHE_MAX_UPDATES
_Interger. Default: 200._
The maximum number of updates of each type (clicks and logs) to hold in the cache before writing out to the database. When the number of cached updates exceeds this value they will be written to the database, irrespective of how long they have been cached. However, they won't be written out if the load exceeds APC_CACHE_MAX_LOAD. A value of 0 means never write out on the basis of the number of cached updates.

### APC_CACHE_API_USER
_String. Default: empty string_
The name of a user who is allowed to use the `flushcache` API call to force a write to the database. If set to false, the `flushcache` API call is disabled. If set to an empty string, and user may force a database write.

### APC_CACHE_STATS_SHUNT
_Boolean. Default: false._ 
If true this will cause the caching of the clicks and logredirects to be disabled, and the queries logged as normal. This is handy if you want to keep the URL caching, but still have 100% accurate stats (though the benefit of the plugin will be pretty small then).

### APC_CACHE_STATS_SHUNT
_Boolean. Default: false._
If true this will cause the clicks and log redirect information to be dropped completely (a more aggressive NOSTATS) - so there will be no clicks or logredirects logged.

### APC_CACHE_SKIP_CLICKTRACK
_Boolean. Default: false._
If true this will cause the plugin to take no action on click/redirect logging (if this is being handled by another plugin for example).

### APC_CACHE_ID
_String. Default: ycache-_
A string which is prepended to all APC keys. This is useful if you run two or more instances of YOURLS on the same server and need to avoid their APC cache keys clashing.

### APC_CACHE_DEBUG
_Boolean. Default: false._ 
If true additional debug information is sent using PHP's `error_log()` function. You will find this whereever your server is configured to send PHP errors to.

### APC_CACHE_REDIRECT_FIRST
_Boolean. Default: false._ 
Set this to true to send redirects to the client first, and deal with logging and database updates later. This is experimental and highly likely to interact badly with certain other plugins. In particular, is known to not work with some plugins which change the default HTTP status code to 302. To work around this use the APC_CACHE_REDIRECT_FIRST_CODE setting instead.

If you are potentially caching large numbers of updates, a request that triggers a database write may result in a slow response as normally the database writes are performed first, then a response is sent to the client. This option allows you to respond to the client first and do the slow stuff later. If you are only doing updates based on an API call this setting probably has no benefit and is best avoided.

### APC_CACHE_REDIRECT_FIRST_CODE
_Interger. Default: 301_
The HTTP status code to send with redirects when APC_CACHE_REDIRECT_FIRST is true. Defaults to 301 (moved permanantly). 302 is the most likely alternative (although 303 or 307 are possible)

What the plugin does
--------------------

There are roughly four processes in the plugin: 

Keyword Cache: We cache the keyword -> long URL look up in APC - this is done on request, and cached for about 2 minutes (this can be changed by tweaking the define)

Options Cache: Just caches the get all options query

Click caching: Rather than writing to the database, we will write first to APC. We also create another cache item, with an expiry of 2 minutes (unless changed). When we can create that timer (so there never was one, or a previous one had expired) we will write the click to the database along with any other clicks that have been tracked in the cache. Practically this means the first request hits the DB, then it will at maximum before one DB write per 2 minutes for click tracking. The downside is that you are almost guaranteed to miss some clicks - if the last person to click does trigger a DB write, their click will likely never be tracked. This was more than acceptable to us. 

Log caching: Similar to the click tracking, but in this case we consider all log writes as separate events in the same buffer. We use an incrementing counter to track our unwritten logs, and after a 2 minute expiry using the same mechanism as before we will flush the cached log entries to the database. Log entries can still be lost, and of course APC may clear items to free cache space at any time, or on webserver restart. 

This plugin is really only for a narrow range of cases - if you want full speed it's probably best to just disable logging any of the log/click information at all, so there are no writes because of it. If you want full information, it's best to log each click. That said, please report any issues in the Github issue tracker. 
