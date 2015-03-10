<?php

class JobRunnerPipeline {
	/** @var RedisJobService */
	protected $srvc;
	/** @var array (loop ID => slot ID => slot status array) */
	protected $procMap = array();

	/**
	 * @param JobRunnerService $service
	 */
	public function __construct( RedisJobService $service ) {
		$this->srvc = $service;
	}

	/**
	 * @param string $loop
	 * @param int $slot
	 */
	public function initSlot( $loop, $slot ) {
		$this->procMap[$loop][$slot] = array(
			'handle'  => false,
			'pipes'   => array(),
			'db'      => null,
			'type'    => null,
			'cmd'     => null,
			'stime'   => 0,
			'sigtime' => 0,
			'stdout' => '',
			'stderr' => ''
		);
	}

	/**
	 * @param integer $loop
	 * @param array $prioMap
	 * @param array $pending
	 * @return array
	 */
	public function refillSlots( $loop, array $prioMap, array &$pending ) {
		$free = 0;
		$new = 0;
		$host = gethostname();
		$cTime = time();
		foreach ( $this->procMap[$loop] as $slot => &$procSlot ) {
			$status = $procSlot['handle'] ? proc_get_status( $procSlot['handle'] ) : null;
			if ( $status ) {
				// Keep reading in any output (nonblocking) to child process lockups
				$procSlot['stdout'] .= fread( $procSlot['pipes'][1], 65535 );
				$procSlot['stderr'] .= fread( $procSlot['pipes'][2], 65535 );
			}
			if ( $status && $status['running'] ) {
				$maxReal = isset( $this->srvc->maxRealMap[$procSlot['type']] )
					? $this->srvc->maxRealMap[$procSlot['type']]
					: $this->srvc->maxRealMap['*'];
				$age = $cTime - $procSlot['stime'];
				if ( $age >= $maxReal && !$procSlot['sigtime'] ) {
					$cmd = $procSlot['cmd'];
					$this->srvc->error( "Runner loop $loop process in slot $slot timed out " .
						"[{$age}s; max: {$maxReal}s]:\n$cmd" );
					posix_kill( $status['pid'], SIGTERM ); // non-blocking
					$procSlot['sigtime'] = time();
					$this->srvc->incrStats( 'runner-status.timeout', 1 );
				} elseif ( $age >= $maxReal && ( $cTime - $procSlot['sigtime'] ) > 5 ) {
					$this->srvc->error( "Runner loop $loop process in slot $slot sent SIGKILL." );
					$this->closeRunner( $loop, $slot, $procSlot, SIGKILL );
					$this->srvc->incrStats( 'runner-status.kill', 1 );
				} else {
					continue; // slot is busy
				}
			} elseif ( $status && !$status['running'] ) {
				// $result will be an array if no exceptions happened
				$result = json_decode( trim( $procSlot['stdout'] ), true );
				if ( $status['exitcode'] == 0 && is_array( $result ) ) {
					// If this finished early, lay off of the queue for a while
					if ( ( $cTime - $procSlot['stime'] ) < $this->srvc->hpMaxTime/2 ) {
						unset( $pending[$procSlot['type']][$procSlot['db']] );
						$this->srvc->debug( "Queue '{$procSlot['db']}/{$procSlot['type']}' emptied." );
					}
					$ok = 0; // jobs that ran OK
					foreach ( $result['jobs'] as $status ) {
						$ok += ( $status['status'] === 'ok' ) ? 1 : 0;
					}
					$failed = count( $result['jobs'] ) - $ok;
					$this->srvc->incrStats( "pop.{$procSlot['type']}.ok.{$host}", $ok );
					$this->srvc->incrStats( "pop.{$procSlot['type']}.failed.{$host}", $failed );
				} else {
					// Mention any serious errors that may have occured
					$cmd = $procSlot['cmd'];
					$error = $procSlot['stderr'] ?: $procSlot['stdout'];
					if ( strlen( $error ) > 4096 ) { // truncate long errors
						$error = mb_substr( $error, 0, 4096 ) . '...';
					}
					$this->srvc->error( "Runner loop $loop process in slot $slot " .
						"gave status '{$status['exitcode']}':\n$cmd\n\t$error" );
					$this->srvc->incrStats( 'runner-status.error', 1 );
				}
				$this->closeRunner( $loop, $slot, $procSlot );
			} elseif ( !$status && $procSlot['handle'] ) {
				$this->srvc->error( "Runner loop $loop process in slot $slot gave no status." );
				$this->closeRunner( $loop, $slot, $procSlot );
				$this->srvc->incrStats( 'runner-status.none', 1 );
			}
			++$free;
			$queue = $this->selectQueue( $loop, $prioMap, $pending );
			if ( !$queue ) {
				break;
			}
			// Spawn a job runner for this loop ID
			$highPrio = $prioMap[$loop]['high'];
			$this->spawnRunner( $loop, $slot, $highPrio, $queue, $procSlot );
			++$new;
		}
		unset( $procSlot );

		return array( $free, $new );
	}

	/**
	 * @param integer $loop
	 * @param array $prioMap
	 * @param array $pending
	 * @return array|boolean
	 */
	protected function selectQueue( $loop, array $prioMap, array $pending ) {
		$include = $this->srvc->loopMap[$loop]['include'];
		$exclude = $this->srvc->loopMap[$loop]['exclude'];
		if ( $prioMap[$loop]['high'] ) {
			$exclude = array_merge( $exclude, $this->srvc->loopMap[$loop]['low-priority'] );
		} else {
			$include = array_merge( $include, $this->srvc->loopMap[$loop]['low-priority'] );
		}
		if ( in_array( '*', $include ) ) {
			$include = array_merge( $include, array_keys( $pending ) );
		}

		$candidateTypes = array_diff( array_unique( $include ), $exclude, array( '*' ) );

		$candidates = array(); // list of (type, db)
		// Flatten the tree of candidates into a flat list so that a random
		// item can be selected, weighing each queue (type/db tuple) equally.
		foreach ( $candidateTypes as $type ) {
			if ( isset( $pending[$type] ) ) {
				foreach ( $pending[$type] as $db => $since ) {
					$candidates[] = array( $type, $db );
				}
			}
		}

		if ( !count( $candidates ) ) {
			return false; // no jobs for this type
		}

		return $candidates[mt_rand( 0, count( $candidates ) - 1 )];
	}

	/**
	 * @param integer $loop
	 * @param integer $slot
	 * @param bool $highPrio
	 * @param array $queue
	 * @param array $procSlot
	 * @return bool
	 */
	protected function spawnRunner( $loop, $slot, $highPrio, array $queue, array &$procSlot ) {
		// Pick a random queue
		list( $type, $db ) = $queue;
		$maxtime = $highPrio ? $this->srvc->lpMaxTime : $this->srvc->hpMaxTime;
		$maxmem = isset( $this->srvc->maxMemMap[$type] )
			? $this->srvc->maxMemMap[$type]
			: $this->srvc->maxMemMap['*'];

		// Make sure the runner is launched with various time/memory limits.
		// Nice the process so things like ssh and deployment scripts are fine.
		$what = $with = array();
		foreach ( compact( 'db', 'type', 'maxtime', 'maxmem' ) as $k => $v ) {
			$what[] = "%($k)u";
			$with[] = rawurlencode( $v );
			$what[] = "%($k)x";
			$with[] = escapeshellarg( $v );
		}
		// The dispatcher might be runJobs.php, curl, or wget
		$cmd = str_replace( $what, $with, $this->srvc->dispatcher );

		$descriptors = array(
			0 => array( "pipe", "r" ), // stdin (child)
			1 => array( "pipe", "w" ), // stdout (child)
			2 => array( "pipe", "w" ) // stderr (child)
		);

		$this->srvc->debug( "Spawning runner in loop $loop at slot $slot ($type, $db):\n\t$cmd." );

		// Start the runner in the background
		$procSlot['handle'] = proc_open( $cmd, $descriptors, $procSlot['pipes'] );
		if ( $procSlot['handle'] ) {
			// Make sure socket reads don't wait for data
			stream_set_blocking( $procSlot['pipes'][1], 0 );
			stream_set_blocking( $procSlot['pipes'][2], 0 );
			// Set a timeout so stream_get_contents() won't block for sanity
			stream_set_timeout( $procSlot['pipes'][1], 1 );
			stream_set_timeout( $procSlot['pipes'][2], 1 );
			// Close the unused STDIN pipe
			fclose( $procSlot['pipes'][0] );
			unset( $procSlot['pipes'][0] ); // unused
		}

		$procSlot['db'] = $db;
		$procSlot['type'] = $type;
		$procSlot['cmd'] = $cmd;
		$procSlot['stime'] = time();
		$procSlot['sigtime'] = 0;
		$procSlot['stdout'] = '';
		$procSlot['stderr'] = '';

		if ( $procSlot['handle'] ) {
			return true;
		} else {
			$this->srvc->error( "Could not spawn process in loop $loop: $cmd" );
			$this->srvc->incrStats( 'runner-status.error', 1 );
			return false;
		}
	}

	/**
	 * @param integer $loop
	 * @param integer $slot
	 * @param array $procSlot
	 * @param integer $signal
	 */
	protected function closeRunner( $loop, $slot, array &$procSlot, $signal = null ) {
		if ( $procSlot['pipes'] ) {
			if ( $procSlot['pipes'][1] !== false ) {
				fclose( $procSlot['pipes'][1] );
				$procSlot['pipes'][1] = false;
			}
			if ( $procSlot['pipes'][2] !== false ) {
				fclose( $procSlot['pipes'][2] );
				$procSlot['pipes'][2] = false;
			}
		}
		if ( $procSlot['handle'] ) {
			$this->srvc->debug( "Closing process in loop $loop at slot $slot." );
			if ( $signal !== null ) {
				// Tell the process to close with a signal
				proc_terminate( $procSlot['handle'], $signal );
			} else {
				// Wait for the process to finish on its own
				proc_close( $procSlot['handle'] );
			}
		}
		$procSlot['handle'] = false;
		$procSlot['db'] = null;
		$procSlot['type'] = null;
		$procSlot['stime'] = 0;
		$procSlot['sigtime'] = 0;
		$procSlot['cmd'] = null;
		$procSlot['stdout'] = '';
		$procSlot['stderr'] = '';
	}

	public function terminateSlots() {
		foreach ( $this->procMap as &$procSlots ) {
			foreach ( $procSlots as &$procSlot ) {
				if ( !$procSlot['handle'] ) {
					continue;
				}
				fclose( $procSlot['pipes'][1] );
				fclose( $procSlot['pipes'][2] );
				$status = proc_get_status( $procSlot['handle'] );
				print "Sending SIGTERM to {$status['pid']}.\n";
				proc_terminate( $procSlot['handle'] );
			}
			unset( $procSlot );
		}
		unset( $procSlots );
	}
}
