YAPCache
========

YAPCache is an APC based caching plugin for the [YOURLS](http://yourls.org/) URL shortener. 

YAPCache is designed to remove a lot of the database traffic from YOURLS, primarily the write load from doing the logging and click tracking. We have attempted to strike a balance between keeping most information, but spilling it in some cases in the name of higher performance. 

YAPCache is a fork of [Ian Barber's YOURLS APC Cache](https://github.com/ianbarber/Yourls-APC-Cache), with a few changes [listed below](#difference-from-yourls-apc-cache). It retains many of the function and constant names found in that plugin, as well as the same directory structure. The two plugins cannot co-exist on the same installation.


Installation
------------

1. Download the latest version of YAPCache
2. Copy the plugin folder into your user/plugins folder for YOURLS
3. Set up the parameters for YAPCache in YOURLS configuration user/config.php ([see below](#configuration))
4. Copy the cache.php file into user/
5. There is no need to activate this plugin

A recent version of APC is required.

Operation
---------

### Caches

There are four separate caches operated by the plugin: 

**Keyword Cache**: A read cache which caches the keyword -> long URL look up in APC. This is done on request, and by default is cached for about one hour. This period is configurable with `YAPC_READ_CACHE_TIMEOUT`. This cache will be destroyed if updates are made to the keyword.

**Options Cache**: A read cache which caches YOURLS options in APC to avoid the need to retrieve these from the database at every request. Cache period is defined by `YAPC_READ_CACHE_TIMEOUT` and is one hour by default. This cache will be destroyed if any options are updated.

**Click caching**: A write cache for records of clicks. Rather than writing directly to the database, we write clicks to APC. We keep a record of how long it is since we last wrote clicks to the database and if that period exceeds `YAPC_WRITE_CACHE_TIMEOUT` seconds (default 120) we write all cached clicks to the database. We also keep an eye on how many URLs we are caching click information for and will write to the database if this figure exceeds `YAPC_MAX_UPDATES` (default 200). Additionally, if the number of clicks stored for a single URL exceeds `YAPC_MAX_CLICKS` all clicks are written to the database. Since database writes can involve a lot of updates in quick succession, in either case, if the current server load exceeds `YAPC_MAX_LOAD` we delay the write. We bundle update queries up in a single transaction, which will reduce the overhead involved considerably as long as your table supports transactions.

**Log caching**: A write cache similar to the click tracking, but tracking the log entries for each request. Note that each request for the same URL will increase the number of log table records being cached. In contrast, multiple requests for the same URL will not increase the number of records being cached, only the number of clicks recorded in a single record. The consequence of this is that log caching is likely to reach `YAPC_MAX_UPDATES` faster than click caching.

### Flushing the cache with an API call

It is also possible to manually flush the cache out to the database using an API call. If your YOURLS installation is private you will need to authenticate in the usual way. If `YAPC_API_USER` is defined and not empty only the user defined can flush the cache. To flush you [construct an API call in the usual way](http://yourls.org/#API) using the action `flushcache`. eg

```
http://your-own-domain-here.com/yourls-api.php?action=flushcache&signature=1002a612b4
```

The API call is useful if you want to be sure that the cache will be written out at a defined interval even if there are periods when few requests are coming in. You can, for example, call it every five minutes from crontab with something likely

```
*/5  *  *  *  *  curl -s "https://your-own-domain-here.com/yourls-api.php?action=flushcache&signature=1002a612b4" > /dev/null
```

You might also consider flushing the cache before restarts of the webserver. Many log rotation scripts, for example, will restart Apache after rotating the log, so it can be useful to use a script to flush the cache immediately before the log is rotated. 

It is possible to disable writing to the database as part of a normal request by setting both `YAPC_WRITE_CACHE_TIMEOUT` and `YAPC_MAX_UPDATES`. Writes to the database will then only be triggered by flushcache API call. As database writes can be slow this approach may improve user experience by ensuring that redirects are never delayed by writing data out to the database.

### Will you loose clicks?
Almost certainly. Whilst we've taken care to try to minimise this there will be times when clicks and logs cached in APC disappear before they have been written to the database. APC is not really designed for holding volatile data that isn't stored elsewhere yet. If it runs low on memory it will start pruning its cached data and that could mean clicks and logs. Webserver restarts will also clear APC's cache, and on many systems these happen regularly when the logs are rotated. In deciding on suitable values for the various configuration options you will need to balance performance against the risk of loosing data. The longer you cache writes for, the more likely you are to loose data, and the more data you are likely to loose. 

If loosing the odd click is unacceptable to you you probably shouldn't use this plugin.

Configuration
-------------

The plugin comes with working defaults, but you will probably want to adjust things to your particular needs. The following constants can be defined in `user/config.php` to alter the plugin's behaviour, eg 

```php
define("YAPC_READ_CACHE_TIMEOUT", 1800);
```

### YAPC_WRITE_CACHE_TIMEOUT
_Interger. Default: 120._  
Number of seconds to cache data for before writing it out to the database. A value of 0 will disable writing based on time

### YAPC_READ_CACHE_TIMEOUT
_Interger. Default: 3600._  
Number of seconds to cache reads from the database. We do try to delete cached data that has been changed, but not all YOURLS functions that change data have hooks we can use, so it is possible that for a time some stats etc will appear out of date in the admin interface. Redirects should be current, however

### YAPC_LONG_TIMEOUT
_Interger. Default: 86400._  
A timeout used for a few long lasting indexes. You probably don't need to change this

### YAPC_LOCK_TIMEOUT
_Interger. Default: 30._  
Maximum number of seconds to hold a lock on the indexes whilst doing a database write. If things are working this shouldn't take anywhere near 30 seconds (maybe 2 maximum)

### YAPC_MAX_LOAD
_Interger. Default: 0.7._  
If the system load exceeds this value apc-cache will delay writes to the database. A sensible value for this will depend, amongst other things, on how many processors you have. A value of 0 means don't check the load before writing. This setting has no effect on Windows.

When the time cached exceeds YAPC_WRITE_HARD_TIMEOUT writes will be done no matter what the load.

### YAPC_WRITE_HARD_TIMEOUT
_Interger. Default: 600._  
Number of seconds before a write of cached data to the database is forced, even if the load exceeds YAPC_MAX_LOAD. This setting has no effect if YAPC_WRITE_CACHE_TIMEOUT is set to 0.

### YAPC_BACKOFF_TIME
_Interger. Default: 30._  
Number of seconds to delay the next attempt to write to the database if the load exceeds YAPC_MAX_LOAD

### YAPC_MAX_UPDATES
_Interger. Default: 200._  
The maximum number of updates of each type (clicks and logs) to hold in the cache before writing out to the database. When the number of cached updates exceeds this value they will be written to the database, irrespective of how long they have been cached. However, they won't be written out if the load exceeds YAPC_MAX_LOAD. A value of 0 means never write out on the basis of the number of cached updates.

### YAPC_MAX_CLICKS
_Interger. Default: 30._  
The maximum number of clicks that will be stored for a single URL. If a single URL has had more than this number of clicks since the last time the click cache was written to the database then a write will be performed. However, no write will be done if the load exceeds YAPC_MAX_LOAD. A value of 0 disables this test.

### YAPC_API_USER
_String. Default: empty string_  
The name of a user who is allowed to use the `flushcache` API call to force a write to the database. If set to false, the `flushcache` API call is disabled. If set to an empty string, any user may force a database write.

### YAPC_STATS_SHUNT
_String. Default: Undefined._  
If set to `none` this will cause the caching of the clicks and logredirects to be disabled, and the queries logged as normal. This is handy if you want to keep the URL caching, but still have 100% accurate stats (though the benefit of the plugin will be pretty small then).

If set to `drop` this will cause the clicks and log redirect information to be dropped completely (a more aggressive NOSTATS). There will be no clicks or logredirects logged.

### YAPC_SKIP_CLICKTRACK
_Boolean. Default: false._  
If true this will cause the plugin to take no action on click/redirect logging (if this is being handled by another plugin for example).

### YAPC_ID
_String. Default: ycache-_  
A string which is prepended to all APC keys. This is useful if you run two or more instances of YOURLS on the same server and need to avoid their APC cache keys clashing.

### YAPC_DEBUG
_Boolean. Default: false._  
If true additional debug information is sent using PHP's `error_log()` function. You will find this wherever your server is configured to send PHP errors to.

### YAPC_REDIRECT_FIRST
_Boolean. Default: false._  
Set this to true to send redirects to the client first, and deal with logging and database updates later. This is experimental and highly likely to interact badly with certain other plugins. In particular, it is known to not work with some plugins which change the default HTTP status code to 302. To work around this use the YAPC_REDIRECT_FIRST_CODE setting instead.

If you are potentially caching large numbers of updates, a request that triggers a database write may result in a slow response as normally the database writes are performed first, then a response is sent to the client. This option allows you to respond to the client first and do the slow stuff later. If you are only doing updates based on an API call this setting probably has no benefit and is best avoided.

### YAPC_REDIRECT_FIRST_CODE
_Interger. Default: 301_  
The HTTP status code to send with redirects when YAPC_REDIRECT_FIRST is true. Defaults to 301 (moved permanantly). 302 is the most likely alternative (although 303 or 307 are possible). Has no effect if YAPC_REDIRECT_FIRST is false

Difference from Yourls-APC-Cache
--------------------------------

The main differences between YAPCache and Ian Barber's original Yourls-APC-Cache are summarised below

* YAPCache uses a different strategy for caching clicks. Instead of one timer for each URL, YAPCache uses a single timer for all URLs. This is somewhat more aggressive, ie clicks are more likely to be cached. It also means that multiple URLs are updated at the same time. By wrapping these in a transaction the transaction overhead is reduced (ie one transaction for multiple updates, rather than the one transaction per update implied by autocommit)

* YAPCache uses a different approach to timers. YAPCache writes the time into an APC key and then checks that, rather than relying on the key's TTL. This allows a bit more flexibility in the logic of when to write out to the database, allowing some other changes, including

  * Option to do writes on the basis of the number of records cached as well as / instead of the time since the last write

  * Writes can be delayed if the server load exceeds a threshold

  * YAPCache provides an API call that can be used to trigger a write out to the database. 

* YAPCache includes an experimental option to send the redirect to the client first and delay the slower work of updated the database until afterwards

* A few minor bugs have been fixed

### Which one should you use?

It's your choice. YAPCache has a few extra features. Yourls-APC-Cache is older and has been in use for several years longer.


