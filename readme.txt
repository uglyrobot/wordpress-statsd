=== StatsD WordPress Client ===
Contributors: uglyrobot, WPMUDEV
Tags: statsd, stats, metrics, graphite, multisite, monitoring
Requires at least: 3.7
Tested up to: 3.9.1
Stable tag: 0.1
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

For no-latency massively-scalable WP application metric tracking and code profiling via Etsy's StatsD + Graphite.

== Description ==
For live environment no-latency massively-scalable application metric tracking and code profiling via <a href="http://codeascraft.com/2011/02/15/measure-anything-measure-everything/">Etsy's StatsD</a> + Graphite.

Tracks everything in WordPress and Multisite:

*  Logins (success, fails, logout) 
*  Password resets (attempts/successes)
*  User count (guage)
*  Users (registrations, spam, ham)
*  Posting (publish, trash, delete)
*  Commenting (received, approved, trashed, spam, unspam)
*  Attachments (Add, edit, delete)
*  XML-RPC (every command individually, you can rollup)
*  Multisite blog count (guage)
*  Multiiste blog actions (new, spam, ham, archive, unarchive, delete, undelete)
*  Page generation times
*  Query count (type + time when SAVEQUERIES defined)
*  Remote HTTP requests (count, time - by host)
*  WP Cron calls
*  WP Emails
*  and more!

Requires <a href="https://github.com/etsy/statsd">StatsD</a> on localhost or a server on your private network.

You can also call the $statsd global class in other plugin/theme code for instant tracking of any application metric. See API usage instructions: https://github.com/domnikl/statsd-php/blob/develop/README.md

== Installation ==

1.  Install <a href="https://github.com/etsy/statsd">StatsD</a> on localhost or a or a server on your private network.
1.  If StatsD is not on localhost, define the local daemon IP in wp-config.php: `define( 'STATSD_IP', 'x.x.x.x' );`
1.  Install the plugin
1.  Activate or Network Activate on multisite
1.  That's it!

See the FAQ for more advanced configuration.

== Screenshots ==

1. Example dashboard created in Graphite with data collected from this plugin.
2. Track any other application stat in your WordPress site with 1 line of code in your plugin/theme.

== Frequently Asked Questions ==
By default the parent namespace used for stats is "yourdomain_yourpath.wordpress.*" where yourdomain_yourpath would be "www_domain_com_blog" if your site is http://www.domain.com/blog/. You can override the parent namespace via the `define('STATSD_NAMESPACE', 'mysite.myserver');` define in wp-config.php. This one is very important, controls how it shows up in Graphite stats.
This should be segmented, left to right general to specific. All "." trigger segments. For example:
applicationname.server like 'wpmudev.app1' or 'edublogs.web4'. That allows for drilling down, but can still wrapup in graphs with 'edublogs.*' etc.

Can also be run as an mu-plugin by dropping statsd.php in `/wp-content/mu-plugins/`.

If needed you can overide the default UDP port of 8125 via `define('STATSD_PORT', xxxx);` define.

If you have a very high traffic site you can lower the default 0.5 sample rate for per-pageload calls via `STATSD_SAMPLE_RATE`.

Contribute at <a href="https://github.com/uglyrobot/wordpress-statsd">GitHub</a>.

== To Do ==
Want to implement batch collection and send of metrics via one or minimal UDP packets required based on connection time.

== Changelog ==

= 0.1 =
* Initial Release