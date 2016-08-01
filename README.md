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
Interger. Default 120. Number of seconds to cache data before writing it out to the database. A value of 0 will disable writing based on time


### APC_CACHE_STATS_SHUNT
Boolean. Default false. If true this will cause the caching of the clicks and logredirects to be disabled, and the queries logged as normal. This is handy if you want to keep the URL caching, but still have 100% accurate stats (though the benefit of the plugin will be pretty small then).

### APC_CACHE_STATS_SHUNT
Boolean. Default false. If true this will cause the clicks and log redirect information to be dropped completely (a more aggressive NOSTATS) - so there will be no clicks or logredirects logged.

### APC_CACHE_SKIP_CLICKTRACK"
Boolean. Default false. If true this will cause the plugin to take no action on click/redirect logging (if this is being handled by another plugin for example).

What the plugin does
--------------------

There are roughly four processes in the plugin: 

Keyword Cache: We cache the keyword -> long URL look up in APC - this is done on request, and cached for about 2 minutes (this can be changed by tweaking the define)

Options Cache: Just caches the get all options query

Click caching: Rather than writing to the database, we will write first to APC. We also create another cache item, with an expiry of 2 minutes (unless changed). When we can create that timer (so there never was one, or a previous one had expired) we will write the click to the database along with any other clicks that have been tracked in the cache. Practically this means the first request hits the DB, then it will at maximum before one DB write per 2 minutes for click tracking. The downside is that you are almost guaranteed to miss some clicks - if the last person to click does trigger a DB write, their click will likely never be tracked. This was more than acceptable to us. 

Log caching: Similar to the click tracking, but in this case we consider all log writes as separate events in the same buffer. We use an incrementing counter to track our unwritten logs, and after a 2 minute expiry using the same mechanism as before we will flush the cached log entries to the database. Log entries can still be lost, and of course APC may clear items to free cache space at any time, or on webserver restart. 

This plugin is really only for a narrow range of cases - if you want full speed it's probably best to just disable logging any of the log/click information at all, so there are no writes because of it. If you want full information, it's best to log each click. That said, please report any issues in the Github issue tracker. 
