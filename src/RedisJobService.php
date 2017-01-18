<?php

/**
 * Base class for job services with main() implemented by subclasses
 */
abstract class RedisJobService {
	const MAX_UDP_SIZE_STR = 512;

	/** @var array List of IP:<port> entries */
	protected $queueSrvs = array();
	/** @var array List of IP:<port> entries */
	protected $aggrSrvs = array();
	/** @var string Redis password */
	protected $password;
	/** @var string IP address or hostname */
	protected $statsdHost;
	/** @var array statsd packets pending sending */
	private $statsdPackets = array();
	/** @var integer Port number */
	protected $statsdPort;

	/** @var bool */
	protected $verbose;
	/** @var array Map of (job type => integer seconds) */
	protected $claimTTLMap = array();
	/** @var array Map of (job type => integer) */
	protected $attemptsMap = array();

	/** @var array Map of (id => (include,exclude,low-priority,count) */
	public $loopMap = array();
	/** @var array Map of (job type => integer) */
	public $maxRealMap = array();
	/** @var array Map of (job type => integer) */
	public $maxMemMap = array();
	/** @var array String command to run jobs and return the status JSON blob */
	public $dispatcher;

	/**
	 * How long can low priority jobs be run until some high priority
	 * jobs should be checked for and run if they exist.
	 * @var integer
	 */
	public $hpMaxDelay = 120;
	/**
	 * The maxtime parameter for runJobs.php for low priority jobs.
	 * The lower this value is, the less jobs of one domain can hog attention
	 * from the jobs on other domains, though more overhead is incurred.
	 * This should be lower than hpmaxdelay.
	 * @var integer
	 */
	public $lpMaxTime = 60;
	/**
	 * How long can high priority jobs be run until some low priority
	 * jobs should be checked for and run if they exist.
	 * @var integer
	 */
	public $lpMaxDelay = 600;
	/**
	 * The maxtime parameter for runJobs.php for high priority jobs.
	 * The lower this value is, the less jobs of one domain/type can hog attention
	 * from jobs of another domain/type, though more overhead is incurred.
	 * This should be lower than lpmaxdelay.
	 * @var integer
	 */
	public $hpMaxTime = 30;

	/** @var array Map of (server => Redis object) */
	protected $conns = array();
	/** @var array Map of (server => timestamp) */
	protected $downSrvs = array();

	/**
	 * @param array $args
	 * @return RedisJobService
	 * @throws Exception
	 */
	public static function init( array $args ) {
		if ( !isset( $args['config-file'] ) || isset( $args['help'] ) ) {
			die( "Usage: php RedisJobRunnerService.php\n"
				. "--config-file=<path>\n"
				. "--help\n"
			);
		}

		$file = $args['config-file'];
		$content = file_get_contents( $file );
		if ( $content === false ) {
			throw new InvalidArgumentException( "Coudn't open configuration file '{$file}''" );
		}

		// Remove comments and load into an array
		$content = trim( preg_replace( '/\/\/.*$/m', '',  $content ) );
		$config = json_decode( $content, true );
		if ( !is_array( $config ) ) {
			throw new InvalidArgumentException( "Could not parse JSON file '{$file}'." );
		}

		$instance = new static( $config );
		$instance->verbose = isset( $args['verbose'] );

		return $instance;
	}

	/**
	 * @param array $config
	 */
	protected function __construct( array $config ) {
		$this->aggrSrvs = $config['redis']['aggregators'];
		if ( !count( $this->aggrSrvs ) ) {
			throw new InvalidArgumentException( "Empty list for 'redis.aggregators'." );
		}
		$this->queueSrvs = $config['redis']['queues'];
		if ( !count( $this->queueSrvs ) ) {
			throw new InvalidArgumentException( "Empty list for 'redis.queues'." );
		}
		$this->dispatcher = $config['dispatcher'];
		if ( !$this->dispatcher ) {
			throw new InvalidArgumentException( "No command provided for 'dispatcher'." );
		}

		foreach ( $config['groups'] as $name => $group ) {
			if ( !is_int( $group['runners'] ) ) {
				throw new InvalidArgumentException(
					"Invalid 'runners' value for runner group '$name'." );
			} elseif ( $group['runners'] == 0 ) {
				continue; // loop disabled
			}

			foreach ( array( 'include', 'exclude', 'low-priority' ) as $k ) {
				if ( !isset( $group[$k] ) ) {
					$group[$k] = array();
				} elseif ( !is_array( $group[$k] ) ) {
					throw new InvalidArgumentException(
						"Invalid '$k' value for runner group '$name'." );
				}
			}
			$this->loopMap[] = $group;
		}

		if ( isset( $config['wrapper'] ) ) {
			$this->wrapper = $config['wrapper'];
		}
		if ( isset( $config['redis']['password'] ) ) {
			$this->password = $config['redis']['password'];
		}

		$this->claimTTLMap['*'] = 3600;
		if ( isset( $config['limits']['claimTTL'] ) ) {
			$this->claimTTLMap = $config['limits']['claimTTL'] + $this->claimTTLMap;
		}

		$this->attemptsMap['*'] = 3;
		if ( isset( $config['limits']['attempts'] ) ) {
			$this->attemptsMap = $config['limits']['attempts'] + $this->attemptsMap;
		}

		// Avoid killing processes before they get a fair chance to exit
		$this->maxRealMap['*'] = 3600;
		$minRealTime = 2 * max( $this->lpMaxTime, $this->hpMaxTime );
		if ( isset( $config['limits']['real'] ) ) {
			foreach ( $config['limits']['real'] as $type => $value ) {
				$this->maxRealMap[$type] = max( (int)$value, $minRealTime );
			}
		}

		$this->maxMemMap['*'] = '300M';
		if ( isset( $config['limits']['memory'] ) ) {
			$this->maxMemMap = $config['limits']['memory'] + $this->maxMemMap;
		}

		if ( isset( $config['statsd'] ) ) {
			if ( strpos( $config['statsd'], ':' ) !== false ) {
				$parts = explode( ':', $config['statsd'] );
				$this->statsdHost = $parts[0];
				$this->statsdPort = (int)$parts[1];
			} else {
				// Use default statsd port if not specified
				$this->statsdHost = $config['statsd'];
				$this->statsdPort = 8125;
			}
		}
	}

	/**
	 * Terminate this script if the OS/PHP environment is incompatible
	 */
	public static function checkEnvironment() {
		if ( !class_exists( 'Redis' ) ) {
			die( "The phpredis extension is not installed; aborting.\n" );
		} elseif ( !function_exists( 'pcntl_signal' ) ) {
			die( "The pcntl module is not available; aborting.\n" );
		} elseif ( !function_exists( 'posix_kill' ) ) {
			die( "posix_kill is not available; aborting.\n" );
		}
	}

	/**
	 * Entry point method that starts the service in earnest and keeps running
	 */
	abstract public function main();

	/**
	 * @return string (per JobQueueAggregatorRedis.php)
	 */
	public function getReadyQueueKey() {
		return "jobqueue:aggregator:h-ready-queues:v2"; // global
	}

	/**
	 * @param string $type
	 * @param string $domain
	 * @return string (per JobQueueAggregatorRedis.php)
	 */
	public function encQueueName( $type, $domain ) {
		return rawurlencode( $type ) . '/' . rawurlencode( $domain );
	}

	/**
	 * @param string $name
	 * @return string (per JobQueueAggregatorRedis.php)
	 */
	public function dencQueueName( $name ) {
		list( $type, $domain ) = explode( '/', $name, 2 );

		return array( rawurldecode( $type ), rawurldecode( $domain ) );
	}

	/**
	 * @param string $server
	 * @return Redis|boolean
	 */
	public function getRedisConn( $server ) {
		// Check the listing "dead" servers which have had a connection errors.
		// Srvs are marked dead for a limited period of time, to
		// avoid excessive overhead from repeated connection timeouts.
		if ( isset( $this->downSrvs[$server] ) ) {
			$now = time();
			if ( $now > $this->downSrvs[$server] ) {
				// Dead time expired
				unset( $this->downSrvs[$server] );
			} else {
				// Server is dead
				return false;
			}
		}

		if ( isset( $this->conns[$server] ) ) {
			return $this->conns[$server];
		}

		try {
			$conn = new Redis();
			if ( strpos( $server, ':' ) === false ) {
				$host = $server;
				$port = null;
			} else {
				list( $host, $port ) = explode( ':', $server );
			}
			$result = $conn->connect( $host, $port, 5 );
			if ( !$result ) {
				$this->error( "Could not connect to Redis server $host:$port." );
				// Mark server down for some time to avoid further timeouts
				$this->downSrvs[$server] = time() + 30;

				return false;
			}
			if ( $this->password !== null ) {
				$conn->auth( $this->password );
			}
		} catch ( RedisException $e ) {
			$this->downSrvs[$server] = time() + 30;

			return false;
		}

		if ( $conn ) {
			$conn->setOption( Redis::OPT_READ_TIMEOUT, 5 );
			$conn->setOption( Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE );
			$this->conns[$server] = $conn;

			return $conn;
		} else {
			return false;
		}
	}

	/**
	 * @param RedisException $e
	 * @param string $server
	 */
	public function handleRedisError( RedisException $e, $server ) {
		unset( $this->conns[$server] );
		$this->error( "Redis error: " . $e->getMessage() );
		$this->incrStats( "redis-error." . gethostname(), 1 );
	}

	/**
	 * @param Redis $conn
	 * @param string $cmd
	 * @param array $args
	 * @return mixed
	 * @throws RedisException
	 */
	public function redisCmd( Redis $conn, $cmd, array $args = array() ) {
		$conn->clearLastError();
		// we had some job runners oom'ing on this call, log what we are
		// doing so there is relevant information next to the oom
		$this->debug( "Redis cmd: $cmd " . json_encode( $args ) );
		$res = call_user_func_array( array( $conn, $cmd ), $args );
		if ( $conn->getLastError() ) {
			// Make all errors be exceptions instead of "most but not all".
			// This will let the caller know to reset the connection to be safe.
			throw new RedisException( $conn->getLastError() );
		}
		return $res;
	}

	/**
	 * Execute a command on the current working server in $servers
	 *
	 * @param array $servers Ordered list of servers to attempt
	 * @param string $cmd
	 * @param array $args
	 * @return mixed
	 * @throws RedisExceptionHA
	 */
	public function redisCmdHA( array $servers, $cmd, array $args = array() ) {
		foreach ( $servers as $server ) {
			$conn = $this->getRedisConn( $server );
			if ( $conn ) {
				try {
					return $this->redisCmd( $conn, $cmd, $args );
				} catch ( RedisException $e ) {
					$this->handleRedisError( $e, $server );
				}
			}
		}

		throw new RedisExceptionHA( "Could not excecute command on any servers." );
	}

	/**
	 * Execute a command on all servers in $servers
	 *
	 * @param array $servers List of servers to attempt
	 * @param string $cmd
	 * @param array $args
	 * @return integer Number of servers updated
	 * @throws RedisExceptionHA
	 */
	public function redisCmdBroadcast( array $servers, $cmd, array $args = array() ) {
		$updated = 0;

		foreach ( $servers as $server ) {
			$conn = $this->getRedisConn( $server );
			if ( $conn ) {
				try {
					$this->redisCmd( $conn, $cmd, $args );
					++$updated;
				} catch ( RedisException $e ) {
					$this->handleRedisError( $e, $server );
				}
			}
		}

		if ( !$updated ) {
			throw new RedisExceptionHA( "Could not excecute command on any servers." );
		}

		return $updated;
	}

	/**
	 * @param string $event
	 * @param integer $delta
	 * @return void
	 */
	public function incrStats( $event, $delta = 1 ) {
		if ( !$this->statsdHost || $delta == 0 ) {
			return; // nothing to do
		}
		$this->statsdPackets[] = $this->getStatPacket( $event, $delta );
	}

	/**
	 * @param string $event
	 * @param integer $delta
	 *
	 * @return string
	 */
	private function getStatPacket( $event, $delta ) {
		return sprintf( "%s:%s|c\n", "jobrunner.$event", $delta );
	}

	/**
	 * Actually send the stats that have been saved in $this->statsdPackets
	 */
	protected function sendStats() {
		if ( $this->statsdHost ) {
			$packets = array_reduce(
				$this->statsdPackets,
				[ __CLASS__, 'reduceStatPackets' ],
				array()
			);
			foreach ( $packets as $packet ) {
				$this->sendStatsPacket( $packet );
			}
		}
		$this->statsdPackets = array();
	}

	/**
	 * This is called from this->sendStats()
	 *
	 * This is taken from StatsdClient::doReduce in https://github.com/liuggio/statsd-php-client
	 * @license MIT
	 * @copyright (c) Giulio De Donato
	 *
	 * This function reduces the number of packets,the reduced has the maximum dimension of self::MAX_UDP_SIZE_STR
	 * Reference:
	 * https://github.com/etsy/statsd/blob/master/README.md
	 * All metrics can also be batch send in a single UDP packet, separated by a newline character.
	 *
	 * @param string[] $reducedMetrics
	 * @param string $metric
	 *
	 * @return string[]
	 */
	private static function reduceStatPackets( array $reducedMetrics, $metric ) {
		$lastReducedMetric = end( $reducedMetrics );
		if ( strlen( $metric ) >= self::MAX_UDP_SIZE_STR || $lastReducedMetric === false ) {
			$reducedMetrics[] = $metric; // full packet sized metric or first metric
		} else {
			$newMetric = "$lastReducedMetric\n$metric";
			if ( strlen( $newMetric ) > self::MAX_UDP_SIZE_STR ) {
				// Merging into the last metric yields too large a packet
				$reducedMetrics[] = $metric;
			} else {
				// Merge this metric into the last one since the packet size is OK
				array_pop( $reducedMetrics );
				$reducedMetrics[] = $newMetric;
			}
		}

		return $reducedMetrics;
	}

	/**
	 * @param string $packet
	 * @return void
	 */
	private function sendStatsPacket( $packet ) {
		if ( !function_exists( 'socket_create' ) ) {
			$this->debug( 'No "socket_create" method available.' );
			return;
		}

		static $socket = null;
		if ( !$socket ) {
			$socket = socket_create( AF_INET, SOCK_DGRAM, SOL_UDP );
		}

		socket_sendto(
			$socket,
			$packet,
			strlen( $packet ),
			0,
			$this->statsdHost,
			$this->statsdPort
		);
	}

	/**
	 * @param string $s
	 */
	public function debug( $s ) {
		if ( $this->verbose ) {
			print date( DATE_ISO8601 ) . " DEBUG: $s\n";
		}
	}

	/**
	 * @param string $s
	 */
	public function notice( $s ) {
		print date( DATE_ISO8601 ) . " NOTICE: $s\n";
	}

	/**
	 * @param string $s
	 */
	public function error( $s ) {
		fwrite( STDERR, date( DATE_ISO8601 ) . " ERROR: $s\n" );
	}
}

class RedisExceptionHA extends Exception {}
