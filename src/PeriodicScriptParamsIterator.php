<?php

class PeriodicScriptParamsIterator implements Iterator {
	/** @var RedisJobChronService */
	private $service;
	/** @var string[] JSON encoded queue name list */
	private $queueIds;

	/**
	 * limit on the number of jobs to change state in a Lua script
	 */
	private const LUA_MAX_JOBS = 500;

	/**
	 * @param RedisJobChronService $service
	 * @param array $queueIds JSON encoded queue name list (type, domain)
	 */
	public function __construct( RedisJobChronService $service, array $queueIds ) {
		$this->service = $service;
		$this->queueIds = $queueIds;
	}

	public function rewind(): void {
		reset( $this->queueIds );
	}

	#[ReturnTypeWillChange]
	public function current() {
		$queueId = current( $this->queueIds );
		if ( $queueId === false ) {
			return false;
		}

		[ $type, $domain ] = json_decode( $queueId );
		$now = time();

		return [
			'queue' => [ $type, $domain ],
			'params' => [
				# KEYS[1]
				"{$domain}:jobqueue:{$type}:z-claimed",
				# KEYS[2]
				"{$domain}:jobqueue:{$type}:h-attempts",
				# KEYS[3]
				"{$domain}:jobqueue:{$type}:l-unclaimed",
				# KEYS[4]
				"{$domain}:jobqueue:{$type}:h-data",
				# KEYS[5]
				"{$domain}:jobqueue:{$type}:z-abandoned",
				# KEYS[6]
				"{$domain}:jobqueue:{$type}:z-delayed",
				# KEYS[7]
				"global:jobqueue:s-queuesWithJobs",
				# ARGV[1]
				$now - $this->service->getTTLForType( $type ),
				# ARGV[2]
				$now - 7 * 86400,
				# ARGV[3]
				$this->service->getAttemptsForType( $type ),
				# ARGV[4]
				$now,
				# ARGV[5]
				$queueId,
				# ARGV[6]
				self::LUA_MAX_JOBS
			],
			# number of first argument(s) that are keys
			'keys' => 7
		];
	}

	#[ReturnTypeWillChange]
	public function key() {
		return key( $this->queueIds );
	}

	public function next(): void {
		next( $this->queueIds );
	}

	public function valid(): bool {
		return key( $this->queueIds ) !== null;
	}

	/**
	 * @return string
	 */
	public static function getChronScript() {
		static $script =
			<<<LUA
		local kClaimed, kAttempts, kUnclaimed, kData, kAbandoned, kDelayed, kQwJobs = unpack(KEYS)
		local rClaimCutoff, rPruneCutoff, rAttempts, rTime, queueId, rLimit = unpack(ARGV)
		local released,abandoned,pruned,undelayed,ready = 0,0,0,0,0
		-- Short-circuit if there is nothing at all in the queue
		if redis.call('exists',kData) == 0 then
			redis.call('sRem',kQwJobs,queueId)
			return {released,abandoned,pruned,undelayed,ready}
		end
		-- Get all non-dead jobs that have an expired claim on them.
		-- The score for each item is the last claim timestamp (UNIX).
		local staleClaims = redis.call('zRangeByScore',kClaimed,0,rClaimCutoff,'limit',0,rLimit)
		for k,id in ipairs(staleClaims) do
			local timestamp = redis.call('zScore',kClaimed,id)
			local attempts = 1*redis.call('hGet',kAttempts,id)
			if attempts < 1*rAttempts then
				-- Claim expired and attempts left: re-enqueue the job
				redis.call('rPush',kUnclaimed,id)
				released = released + 1
			else
				-- Claim expired and no attempts left: mark the job as dead
				redis.call('zAdd',kAbandoned,timestamp,id)
				abandoned = abandoned + 1
			end
			redis.call('zRem',kClaimed,id)
		end
		-- Get all of the dead jobs that have been marked as dead for too long.
		-- The score for each item is the last claim timestamp (UNIX).
		local deadClaims = redis.call('zRangeByScore',kAbandoned,0,rPruneCutoff,'limit',0,rLimit)
		for k,id in ipairs(deadClaims) do
			-- Stale and out of attempts: remove any traces of the job
			redis.call('zRem',kAbandoned,id)
			redis.call('hDel',kAttempts,id)
			redis.call('hDel',kData,id)
			pruned = pruned + 1
		end
		-- Get the list of ready delayed jobs, sorted by readiness (UNIX timestamp)
		local ids = redis.call('zRangeByScore',kDelayed,0,rTime,'limit',0,rLimit)
		-- Migrate the jobs from the "delayed" set to the "unclaimed" list
		for k,id in ipairs(ids) do
			redis.call('lPush',kUnclaimed,id)
			redis.call('zRem',kDelayed,id)
		end
		undelayed = #ids
		ready = redis.call('lLen',kUnclaimed)
		-- Keep track of whether this queue has jobs
		local aliveCount = ready + redis.call('zCard',kClaimed) + redis.call('zCard',kDelayed)
		if aliveCount > 0 then
			redis.call('sAdd',kQwJobs,queueId)
		else
			redis.call('sRem',kQwJobs,queueId)
		end
		return {released,abandoned,pruned,undelayed,ready}
LUA;

		return $script;
	}
}
