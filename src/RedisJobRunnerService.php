<?php

require __DIR__ . '/RedisJobService.php';
require __DIR__ . '/JobRunnerPipeline.php';

class RedisJobRunnerService extends RedisJobService {
	private const AGGR_CACHE_TTL_SEC = 1;

	/**
	 * @return never
	 */
	public function main() {
		$this->notice( "Starting job spawner loop(s)..." );

		$host = gethostname();
		// map of (id => (current priority, since))
		$prioMap = [];
		$pipeline = new JobRunnerPipeline( $this );
		foreach ( $this->loopMap as $loop => $info ) {
			for ( $i = 0; $i < $info['runners']; ++$i ) {
				$pipeline->initSlot( $loop, $i );
				$prioMap[$loop] = [ 'high' => (bool)mt_rand( 0, 1 ), 'since' => time() ];
			}
			$this->notice( "Initialized loop $loop with {$info['runners']} runner(s)." );
		}

		/**
		 * Setup signal handlers...
		 * @return never
		 */
		$handlerFunc = static function ( $signo ) use ( $pipeline ) {
			print "Caught signal ($signo)\n";
			$pipeline->terminateSlots();
			exit( 0 );
		};
		$ok = pcntl_signal( SIGHUP, $handlerFunc )
			&& pcntl_signal( SIGINT, $handlerFunc )
			&& pcntl_signal( SIGTERM, $handlerFunc );
		if ( !$ok ) {
			throw new Exception( 'Could not install singal handlers.' );
		}

		$memLast = memory_get_usage();
		$this->incrStats( "start-runner.$host", 1 );
		while ( true ) {
			pcntl_signal_dispatch();

			$prioSwitches = 0;
			$anyNew = 0;
			$anyFree = 0;
			// Get the list of ready queues
			$pending =& $this->getReadyQueueMap();
			if ( !count( $pending ) ) {
				$this->debug( "No jobs available..." );
				$this->incrStats( "idle.$host", 1 );
				// no jobs
				usleep( 100000 );
				continue;
			}
			// Spawn new runners as slots become available
			foreach ( $prioMap as $loop => &$loopPriority ) {
				$this->debug( "Checking runner loop $loop..." );
				// Implement high/low priority via time-sharing
				if ( $loopPriority['high']
					&& ( time() - $loopPriority['since'] ) > $this->lpMaxDelay
				) {
					$loopPriority['high'] = false;
					$loopPriority['since'] = time();
					$this->debug( "Runner loop $loop now in low priority." );
					++$prioSwitches;
				} elseif ( !$loopPriority['high']
					&& ( time() - $loopPriority['since'] ) > $this->hpMaxDelay
				) {
					$loopPriority['high'] = true;
					$loopPriority['since'] = time();
					$this->debug( "Runner loop $loop now in high priority." );
					++$prioSwitches;
				}
				// Find any free slots and replace them with new processes
				[ $free, $new ] = $pipeline->refillSlots( $loop, $prioMap, $pending );
				$anyFree += $free;
				$anyNew += $new;
				// Rotate the priority from high/low and back if no jobs were found
				if ( !$free ) {
					$this->debug( "Runner loop $loop is full." );
				} elseif ( !$new ) {
					if ( $loopPriority['high'] ) {
						$loopPriority['high'] = false;
						$this->debug( "Runner loop $loop now in low priority." );
					} else {
						$loopPriority['high'] = true;
						$this->debug( "Runner loop $loop now in high priority." );
					}
					$loopPriority['since'] = time();
					$this->debug( "Runner loop $loop has no jobs." );
					++$prioSwitches;
				} else {
					$this->debug( "Done checking loop $loop." );
				}
			}
			unset( $loopPriority );
			$this->incrStats( "spawn.$host", $anyNew );
			$this->incrStats( "prioritychange.$host", $prioSwitches );
			// Backoff if there is nothing to do
			if ( !$anyFree ) {
				$this->debug( "All runner loops full." );
				$this->incrStats( "all-full.$host", 1 );
				usleep( 100000 );
			} elseif ( !$anyNew ) {
				$this->debug( "Loops have free slots, but there are no appropriate jobs." );
				$this->incrStats( "some-full.$host", 1 );
				usleep( 100000 );
			}
			// Track memory usage
			$memCurrent = memory_get_usage();
			$this->debug( "Memory usage: $memCurrent bytes." );
			$this->incrStats( "memory.$host", $memCurrent - $memLast );
			$this->sendStats();
			$memLast = $memCurrent;
		}
	}

	/**
	 * @return array Cached map of (job type => domain => UNIX timestamp)
	 */
	private function &getReadyQueueMap() {
		// cache
		static $pendingDBs = [];
		// UNIX timestamp
		static $cacheTimestamp = 0;

		$now = microtime( true );
		$age = ( $now - $cacheTimestamp );
		if ( $age <= self::AGGR_CACHE_TTL_SEC ) {
			// process cache hit
			return $pendingDBs;
		}

		try {
			$latestPendingDBs = $this->loadReadyQueueMap();
			if ( $latestPendingDBs === false ) {
				// use cache value
				return $pendingDBs;
			}

			$pendingDBs = $latestPendingDBs;
			$cacheTimestamp = $now;
		} catch ( RedisExceptionHA $e ) {
			// use stale/empty cache
		}

		return $pendingDBs;
	}

	/**
	 * @return array|false Map of (job type => domain => UNIX timestamp); false on error
	 */
	private function loadReadyQueueMap() {
		$pendingByType = false;

		try {
			// Match JobQueueAggregatorRedis.php
			$map = $this->redisCmdHA(
				$this->aggrSrvs,
				'hGetAll',
				[ $this->getReadyQueueKey() ]
			);
			if ( is_array( $map ) ) {
				unset( $map['_epoch'] );
				$pendingByType = [];
				foreach ( $map as $key => $time ) {
					[ $type, $domain ] = $this->dencQueueName( $key );
					$pendingByType[$type][$domain] = $time;
				}
			}
		} catch ( RedisExceptionHA $e ) {
			// use stale/empty cache
		}

		return $pendingByType;
	}
}
