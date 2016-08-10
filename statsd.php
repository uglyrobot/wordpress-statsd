<?php
/*
Plugin Name: StatsD WordPress Client
Version: 0.2
Plugin URI: http://wordpress.org/extend/plugins/statsd/
Description: For no-latency application metric tracking and code profiling via <a href="http://codeascraft.com/2011/02/15/measure-anything-measure-everything/">Etsy's StatsD</a> + Graphite. Tracks logins, password resets, users, registrations, posting, commenting, attachments, XML-RPC, multiiste blog actions, load time, query count, http requests, and more! Requires <a href="https://github.com/etsy/statsd">StatsD</a> on the local or private network server. See API usage instructions: https://github.com/domnikl/statsd-php/blob/develop/README.md
Author: Aaron Edwards
Author URI: http://uglyrobot.com
Network: true

Copyright 2017 UglyRobot Web Development (http://uglyrobot.com)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License (Version 2 - GPLv2) as published by
the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/


//set default config if not set in wp-config
if (!defined('STATSD_IP')) define( 'STATSD_IP', '127.0.0.1' );
if (!defined('STATSD_PORT')) define( 'STATSD_PORT', 8125 );
if (!defined('STATSD_SAMPLE_RATE')) define( 'STATSD_SAMPLE_RATE', 0.5 ); //allows you to override the sample rate for calls that run every page load


/* This one is very important, controls how it shows up in Graphite stats.
 * should be segmented, left to right general to specific. All "." trigger segments. For example:
 * applicationname.server like 'wpmudev.app1' or 'edublogs.web4' or 'campus.cornell.app3'
 * That allows for drilling down, but can still wrapup in graphs with 'edublogs.*' etc. */
if (!defined('STATSD_NAMESPACE')) {
	//if not set build a default namespace off of domain name
	$domain = parse_url(network_home_url());
	$namespace = (empty($domain['path']) || $domain['path'] == '/') ? $domain['host'] : ($domain['host'] . '_' . $domain['path']);
	$namespace = preg_replace('/[^A-Za-z0-9-]/', '_', $namespace); //replace other characters with underscores
	define( 'STATSD_NAMESPACE', $namespace );
}


//the plugin class to track default WP application stats
class WordPress_StatsD {

	private $statsd;

	public function __construct() {
		//create global
		global $statsd;
		$statsd_connection = new StatsD_Connect(STATSD_IP, STATSD_PORT);
		$statsd = new StatsD($statsd_connection, STATSD_NAMESPACE);
		$this->statsd = &$statsd;

		//action hooks
		add_action( 'wp_login', array(&$this, 'login') );
		add_action( 'wp_logout', array(&$this, 'logout') );
		add_action( 'wp_login_failed', array(&$this, 'login_fail') );
		add_action( 'retrieve_password_key', array(&$this, 'password_reset_gen') );
		add_action( 'password_reset', array(&$this, 'password_reset_complete') );
		add_action( 'user_register', array(&$this, 'user_register') );

		add_action( 'publish_post', array(&$this, 'publish_post') );
		add_action( 'wp_trash_post', array(&$this, 'trash_post') );
		add_action( 'delete_post', array(&$this, 'delete_post') );

		add_action( 'wp_insert_comment', array(&$this, 'new_comment') );
		add_action( 'wp_set_comment_status', array(&$this, 'approve_comment'), 10, 2 );
		add_action( 'trash_comment', array(&$this, 'trash_comment') );
		add_action( 'spam_comment', array(&$this, 'spam_comment') );
		add_action( 'unspam_comment', array(&$this, 'unspam_comment') );

		add_action( 'add_attachment', array(&$this, 'add_attachment') );
		add_action( 'edit_attachment', array(&$this, 'edit_attachment') );
		add_action( 'delete_attachment', array(&$this, 'delete_attachment') );

		//multisite only hooks
		if (is_multisite()) {
			add_action( 'wpmu_new_user', array(&$this, 'user_register') );
			add_action( 'wpmu_new_blog', array(&$this, 'new_blog') );
			add_action( 'make_spam_blog', array(&$this, 'spam_blog') );
			add_action( 'make_ham_blog', array(&$this, 'ham_blog') );
			add_action( 'make_spam_user', array(&$this, 'spam_user') );
			add_action( 'make_ham_user', array(&$this, 'ham_user') );
			add_action( 'archive_blog', array(&$this, 'archive_blog') );
			add_action( 'unarchive_blog', array(&$this, 'unarchive_blog') );
			add_action( 'make_delete_blog', array(&$this, 'delete_blog') );
			add_action( 'make_undelete_blog', array(&$this, 'undelete_blog') );
			add_action( 'init', array(&$this, 'blog_count') );
		}

		add_action( 'init', array(&$this, 'user_count') ); //multisite aware

		//http request timing
		add_filter( 'pre_http_request', array(&$this, 'pre_http'), 10, 3 );
		add_action( 'http_api_debug', array(&$this, 'post_http'), 10, 5 );

		//wpdb
		add_action( 'shutdown', array(&$this, 'num_queries') );

		add_action( 'shutdown', array(&$this, 'load_time') );

		add_action( 'xmlrpc_call', array(&$this, 'xmlrpc_call') );

		//trac wp cron
		add_action( 'init', array(&$this, 'wp_cron') );

		//wp_mail
		add_filter( 'wp_mail', array(&$this, 'wp_mail') );
	}

	/* logins/registration */
	public function login($username) {
		$this->statsd->increment("wordpress.logins.login");
	}

	public function logout() {
		$this->statsd->increment("wordpress.logins.logout");
	}

	public function login_fail($username) {
		$this->statsd->increment("wordpress.logins.fail");
	}

	public function password_reset_gen($username) {
		$this->statsd->increment("wordpress.logins.reset_start");
	}

	public function password_reset_complete($user) {
		$this->statsd->increment("wordpress.logins.reset_complete");
	}

	public function user_register($user_id) {
		$this->statsd->increment("wordpress.users.register");
	}

	/* normal blog actions */
	public function publish_post($id) {
		$this->statsd->increment("wordpress.posts.publish");
	}

	public function trash_post($id) {
		$this->statsd->increment("wordpress.posts.trash");
	}

	public function delete_post($id) {
		$this->statsd->increment("wordpress.posts.delete");
	}

	public function new_comment($id) {
		$this->statsd->increment("wordpress.comments.new");
	}

	public function approve_comment($id, $status) {
		if ('approve' == $status)
			$this->statsd->increment("wordpress.comments.approve");
	}

	public function spam_comment($id) {
		$this->statsd->increment("wordpress.comments.spam");
	}

	public function unspam_comment($id) {
		$this->statsd->increment("wordpress.comments.unspam");
	}

	public function trash_comment($id) {
		$this->statsd->increment("wordpress.comments.trash");
	}

	public function add_attachment($id) {
		$this->statsd->increment("wordpress.attachments.add");
	}

	public function edit_attachment($id) {
		$this->statsd->increment("wordpress.attachments.edit");
	}

	public function delete_attachment($id) {
		$this->statsd->increment("wordpress.attachments.delete");
	}

	/* multisite */
	public function new_blog($id) {
		$this->statsd->increment("wordpress.blogs.new");
	}

	public function spam_blog($id) {
		$this->statsd->increment("wordpress.blogs.spam");
	}

	public function ham_blog($id) {
		$this->statsd->increment("wordpress.blogs.unspam");
	}

	public function spam_user($id) {
		$this->statsd->increment("wordpress.users.spam");
	}

	public function ham_user($id) {
		$this->statsd->increment("wordpress.users.unspam");
	}

	public function delete_blog($id) {
		$this->statsd->increment("wordpress.blogs.delete");
	}

	public function undelete_blog($id) {
		$this->statsd->increment("wordpress.blogs.undelete");
	}

	public function archive_blog($id) {
		$this->statsd->increment("wordpress.blogs.archive");
	}

	public function unarchive_blog($id) {
		$this->statsd->increment("wordpress.blogs.unarchive");
	}

	public function blog_count() {
		//Only send this gauge on every hundredth request, it doesn't change often
		$sample = mt_rand() / mt_getrandmax();
		if ($sample <= 0.01) {
			$this->statsd->gauge("wordpress.blogs.count", get_blog_count());
		}
	}

	public function user_count() {
		//Only send this gauge on every hundredth request, it doesn't change often
		$sample = mt_rand() / mt_getrandmax();
		if ($sample <= 0.01) {
			if (is_multisite()) {
				$user_count = get_user_count();
			} else {
				//$user_count = count_users();
				//$user_count = $user_count['total_users'];
				global $wpdb;
				$user_count = $wpdb->get_var( "SELECT COUNT(ID) as c FROM $wpdb->users" ); //don't go by role, make it simple
			}
			$this->statsd->gauge("wordpress.users.count", $user_count);
		}
	}

	public function pre_http($false, $r, $url) {
		if ( ! is_multisite() || defined( 'SKIP_URL_METRICS' ) ) {
			if ( false !== strpos( parse_url($url, PHP_URL_PATH), 'wp-cron.php' ) ) {
				$url = 'wp_cron';
			} else {
				$url = preg_replace('/[^A-Za-z0-9-]/', '_', parse_url($url, PHP_URL_HOST)); //replace other characters with underscores for graphite
			}
		} else {
			$url = 'all'; //in multisite the unique http urls can be too high and clog statsd
		}
		$this->statsd->startTiming("wordpress.http.requests.$url");
		return $false;
	}

	public function post_http($response, $type, $class, $args, $url) {
		if ( ! is_multisite() || defined( 'SKIP_URL_METRICS' ) ) {
			if ( false !== strpos( parse_url($url, PHP_URL_PATH), 'wp-cron.php' ) ) {
				$url = 'wp_cron';
			} else {
				$url = preg_replace('/[^A-Za-z0-9-]/', '_', parse_url($url, PHP_URL_HOST)); //replace other characters with underscores for graphite
			}
		} else {
			$url = 'all'; //in multisite the unique http urls can be too high and clog statsd
		}
		$this->statsd->startBatch();
		$this->statsd->endTiming("wordpress.http.requests.$url");
		$this->statsd->increment("wordpress.http.counts.$url");
		$this->statsd->endBatch();
	}

	public function num_queries() {
		//if query tracking is on get specific query details
		if ( defined( 'SAVEQUERIES' ) ) {
			global $wpdb;
			if ( is_array( $wpdb->queries ) && count( $wpdb->queries ) ) {
				//generate rollups by query type
				$counts = $times = array();
				foreach ( $wpdb->queries as $query ) {
					$type = strtolower( strtok( $query[0], " " ) ); //get query type (insert, delete, update, etc)
					$time = $query[1];
					$times[$type] = isset( $times[$type] ) ? $times[$type] + $time : $time;
					$counts[$type] = isset( $counts[$type] ) ? $counts[$type] + 1 : 1;
				}

				//now loop through types and send agregate data for each
				$this->statsd->startBatch();
				foreach ( $counts as $type => $count ) {
					$this->statsd->timing("wordpress.wpdb.queries.$type", round( $times[$type] * 1000 ) );
					$this->statsd->count("wordpress.wpdb.queries.$type", $count);
				}
				$this->statsd->endBatch(); //send batched stats
			}

		} else { //SAVEQUERIES off

			$this->statsd->count("wordpress.wpdb.queries.all", get_num_queries(), STATSD_SAMPLE_RATE);

		}
	}

	public function load_time() {
		$load_time = round( 1000 * timer_stop(0) );
		$this->statsd->timing("wordpress.load_time", $load_time, STATSD_SAMPLE_RATE);

		//prints all udp calls made in footer
		if ( defined( 'STATSD_DEBUG' ) && STATSD_DEBUG ) {
			var_dump($this->statsd->msgs);
		}
	}

	public function xmlrpc_call($type) {
		//track the actual call types here
		$this->statsd->increment("wordpress.xmlrpc.$type");
	}

	public function wp_cron() {
		if ( defined('DOING_CRON') ) {
			$this->statsd->increment("wordpress.cron");
		}
	}

	public function wp_mail($wp_mail) {
		$this->statsd->increment("wordpress.email");
		return $wp_mail;
	}
}

new WordPress_StatsD;


/* Based off php statsd client by Dominik Liebler <liebler.dominik@googlemail.com>
 * https://github.com/domnikl/statsd-php
 */

/**
 * the statsd client
 *
 * @author Dominik Liebler <liebler.dominik@googlemail.com>
 */
class StatsD
{
    /**
     * Connection object that messages get send to
     *
     * @var Connection
     */
    protected $_connection;

    /**
     * holds all the timings that have not yet been completed
     *
     * @var array
     */
    protected $_timings = array();

    /**
     * holds all memory profiles like timings
     *
     * @var array
     */
    protected $_memoryProfiles = array();

    /**
     * global key namespace
     *
     * @var string
     */
    protected $_namespace = '';

	/**
	 * stores the batch after batch processing was started
	 *
	 * @var array
	 */
	protected $_batch = array();

	/**
	 * batch mode?
	 *
	 * @var boolean
	 */
	protected $_isBatch = false;

    /**
     * inits the Client object
     *
     * @param Connection $connection
     * @param string $namespace global key namespace
     */
    public function __construct($connection, $namespace = '')
    {
        $this->_connection = $connection;
        $this->_namespace = (string) $namespace;
    }

    /**
     * increments the key by 1
     *
     * @param string $key
     * @param int $sampleRate
     *
     * @return void
     */
    public function increment($key, $sampleRate = 1)
    {
        $this->count($key, 1, $sampleRate);
    }

    /**
     * decrements the key by 1
     *
     * @param string $key
     * @param int $sampleRate
     *
     * @return void
     */
    public function decrement($key, $sampleRate = 1)
    {
        $this->count($key, -1, $sampleRate);
    }
    /**
     * sends a count to statsd
     *
     * @param string $key
     * @param int $value
     * @param int $sampleRate (optional) the default is 1
     *
     * @return void
     */
    public function count($key, $value, $sampleRate = 1)
    {
        $this->_send($key, (int) $value, 'c', $sampleRate);
    }

    /**
     * sends a timing to statsd (in ms)
     *
     * @param string $key
     * @param int $value the timing in ms
     * @param int $sampleRate the sample rate, if < 1, statsd will send an average timing
     *
     * @return void
     */
    public function timing($key, $value, $sampleRate = 1)
    {
        $this->_send($key, (int) $value, 'ms', $sampleRate);
    }

    /**
     * starts the timing for a key
     *
     * @param string $key
     *
     * @return void
     */
    public function startTiming($key)
    {
        $this->_timings[$key] = gettimeofday(true);
    }

    /**
     * ends the timing for a key and sends it to statsd
     *
     * @param string $key
     * @param int $sampleRate (optional)
     *
     * @return void
     */
    public function endTiming($key, $sampleRate = 1)
    {
        $end = gettimeofday(true);

        if (array_key_exists($key, $this->_timings)) {
            $timing = ($end - $this->_timings[$key]) * 1000;
            $this->timing($key, $timing, $sampleRate);
            unset($this->_timings[$key]);
        }
    }

    /**
     * start memory "profiling"
     *
     * @param string $key
     *
     * @return void
     */
    public function startMemoryProfile($key)
    {
        $this->_memoryProfiles[$key] = memory_get_usage();
    }

    /**
     * ends the memory profiling and sends the value to the server
     *
     * @param string $key
     * @param int $sampleRate
     *
     * @return void
     */
    public function endMemoryProfile($key, $sampleRate = 1)
    {
        $end = memory_get_usage();

        if (array_key_exists($key, $this->_memoryProfiles)) {
            $memory = ($end - $this->_memoryProfiles[$key]);
            $this->memory($key, $memory, $sampleRate);

            unset($this->_memoryProfiles[$key]);
        }
    }

    /**
     * report memory usage to statsd. if memory was not given report peak usage
     *
     * @param string $key
     * @param int $memory
     * @param int $sampleRate
     *
     * @return void
     */
    public function memory($key, $memory = null, $sampleRate = 1)
    {
        if (null === $memory) {
            $memory = memory_get_peak_usage();
        }

        $this->count($key, (int) $memory, $sampleRate);
    }

    /**
     * executes a Closure and records it's execution time and sends it to statsd
     * returns the value the Closure returned
     *
     * @param string $key
     * @param \Closure $_block
     * @param int $sampleRate (optional) default = 1
     *
     * @return mixed
     */
    public function time($key, Closure $_block, $sampleRate = 1)
    {
        $this->startTiming($key);
        $return = $_block();
        $this->endTiming($key, $sampleRate);

        return $return;
    }

	/**
	 * sends a gauge, an arbitrary value to StatsD
	 *
	 * @param string $key
	 * @param int $value
	 *
	 * @return void
	 */
	public function gauge($key, $value)
	{
		$this->_send($key, (int) $value, 'g', 1);
	}

    /**
     * actually sends a message to to the daemon and returns the sent message
     *
     * @param string $key
     * @param int $value
     * @param string $type
     * @param int $sampleRate
     *
     * @return void
     */
    protected function _send($key, $value, $type, $sampleRate)
    {
			if (0 != strlen($this->_namespace)) {
					$key = sprintf('%s.%s', $this->_namespace, $key);
			}

			$message = sprintf("%s:%d|%s", $key, $value, $type);
			$sampledData = '';

			if ($sampleRate < 1) {
					$sample = mt_rand() / mt_getrandmax();

					if ($sample <= $sampleRate || $this->_connection->forceSampling()) {
							$sampledData = sprintf('%s|@%s', $message, $sampleRate);
					}
			} else {
					$sampledData = $message;
			}

			if ( empty( $sampledData ) ) return false; //skip sending empty data

			if (!$this->_isBatch) {
						$this->_connection->send($sampledData);
			} else {
				$this->_batch[] = $sampledData;
			}
    }

    /**
     * changes the global key namespace
     *
     * @param string $namespace
     *
     * @return void
     */
    public function setNamespace($namespace)
    {
        $this->_namespace = (string) $namespace;
    }

    /**
     * gets the global key namespace
     *
     * @return string
     */
    public function getNamespace()
    {
        return $this->_namespace;
    }

	/**
	 * is batch processing running?
	 *
	 * @return boolean
	 */
	public function isBatch()
	{
		return $this->_isBatch;
	}

	/**
	 * start batch-send-recording
	 *
	 * @return void
	 */
	public function startBatch()
	{
		$this->_isBatch = true;
	}

	/**
	 * ends batch-send-recording and sends the recorded messages to the connection
	 *
	 * @return void
	 */
	public function endBatch()
	{
		$this->_isBatch = false;
		$this->_connection->send(join("\n", $this->_batch));
		$this->_batch = array();
	}

	/**
	 * stops batch-recording and resets the batch
	 *
	 * @return void
	 */
	public function cancelBatch()
	{
		$this->_isBatch = false;
		$this->_batch = array();
	}
}

/**
 * encapsulates the connection to the statsd service
 *
 * @author Dominik Liebler <liebler.dominik@googlemail.com>
 */
class StatsD_Connect
{
    /**
     * host name
     *
     * @var string
     */
    protected $_host;

    /**
     * port number
     *
     * @var int
     */
    protected $_port;

    /**
     * the used socket resource
     *
     * @var resource
     */
    protected $_socket;

    /**
     * is sampling allowed?
     *
     * @var bool
     */
    protected $_forceSampling = false;

    /**
     * instantiates the Connection object and a real connection to statsd
     *
     * @param string $host
     * @param int $port
     */
    public function __construct($host = 'localhost', $port = 8125)
    {
        $this->_host = (string) $host;
        $this->_port = (int) $port;
        $this->_socket = fsockopen(sprintf("udp://%s", $this->_host), $this->_port);
				stream_set_blocking($this->_socket, 0); //set nonblocking
    }

    /**
     * sends a message to the UDP socket
     *
     * @param $message
     *
     * @return void
     */
    public function send($message)
    {
        if (0 != strlen($message) && $this->_socket) {
            try {
                @fwrite($this->_socket, $message);
								if ( defined( 'STATSD_DEBUG' ) && STATSD_DEBUG ) {
									global $statsd;
									$statsd->msgs[] = $message;
								}
            } catch (Exception $e) {
                // ignore it: stats logging failure shouldn't stop the whole app
            }
        }
    }

    /**
     * @return string
     */
    public function getHost()
    {
        return $this->_host;
    }


    /**
     * @return int
     */
    public function getPort()
    {
        return $this->_port;
    }

    /**
     * is sampling forced?
     *
     * @return boolean
     */
    public function forceSampling()
    {
        return (bool) $this->_forceSampling;
    }
}
