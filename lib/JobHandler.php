<?php

namespace Resque;

use InvalidArgumentException;
use Resque\Job\PID;
use Resque\Job\Status;
use Resque\Exceptions\DoNotPerformException;
use Resque\Job\FactoryInterface;
use Resque\Job\Factory;
use Error;

/**
 * Resque job.
 *
 * @package		Resque/JobHandler
 * @author		Chris Boulton <chris@bigcommerce.com>
 * @license		http://www.opensource.org/licenses/mit-license.php
 */
class JobHandler implements \Stringable
{
	/**
	 * @var \Resque\Worker\Resque Instance of the Resque worker running this job.
	 */
	public $worker;

	/**
	 * @var object|\Resque\Job\JobInterface Instance of the class performing work for this job.
	 */
	private $instance;

	/**
	 * @var \Resque\Job\FactoryInterface
	 */
	private $jobFactory;

	/**
	 * Instantiate a new instance of a job.
	 *
	 * @param string $queue The queue that the job belongs to.
	 * @param array $payload array containing details of the job.
	 */
	public function __construct(public $queue, public $payload)
 {
 }

	/**
	 * Create a new job and save it to the specified queue.
	 *
	 * @param string $queue The name of the queue to place the job in.
	 * @param string $class The name of the class that contains the code to execute the job.
	 * @param array $args Any optional arguments that should be passed when the job is executed.
	 * @param boolean $monitor Set to true to be able to monitor the status of a job.
	 * @param string $id Unique identifier for tracking the job. Generated if not supplied.
	 * @param string $prefix The prefix needs to be set for the status key
	 *
	 * @return string
	 * @throws \InvalidArgumentException
	 */
	public static function create($queue, $class, $args = null, $monitor = false, $id = null, $prefix = "")
	{
		if (is_null($id)) {
			$id = Resque::generateJobId();
		}

		if ($args !== null && !is_array($args)) {
			throw new InvalidArgumentException(
				'Supplied $args must be an array.'
			);
		}
		Resque::push($queue, ['class'	     => $class, 'args'	     => [$args], 'id'	     => $id, 'prefix'     => $prefix, 'queue_time' => microtime(true)]);

		if ($monitor) {
			Status::create($id, $prefix);
		}

		return $id;
	}

	/**
	 * Find the next available job from the specified queue and return an
	 * instance of JobHandler for it.
	 *
	 * @param string $queue The name of the queue to check for a job in.
	 * @return false|object Null when there aren't any waiting jobs, instance of Resque\JobHandler when a job was found.
	 */
	public static function reserve($queue)
	{
		$payload = Resque::pop($queue);
		if (!is_array($payload)) {
			return false;
		}

		return new JobHandler($queue, $payload);
	}

	/**
  * Find the next available job from the specified queues using blocking list pop
  * and return an instance of JobHandler for it.
  *
  * @param int               $timeout
  * @return false|object Null when there aren't any waiting jobs, instance of Resque\JobHandler when a job was found.
  */
 public static function reserveBlocking(array $queues, $timeout = null)
	{
		$item = Resque::blpop($queues, $timeout);

		if (!is_array($item)) {
			return false;
		}

		return new JobHandler($item['queue'], $item['payload']);
	}

	/**
	 * Update the status of the current job.
	 *
	 * @param int $status Status constant from Resque\Job\Status indicating the current status of a job.
	 */
	public function updateStatus($status, $result = null)
	{
		if (empty($this->payload['id'])) {
			return;
		}

		$statusInstance = new Status($this->payload['id'], $this->getPrefix());
		$statusInstance->update($status, $result);
	}

	/**
	 * Return the status of the current job.
	 *
	 * @return int|null The status of the job as one of the Resque\Job\Status constants
	 *                  or null if job is not being tracked.
	 */
	public function getStatus()
	{
		if (empty($this->payload['id'])) {
			return null;
		}

		$status = new Status($this->payload['id'], $this->getPrefix());
		return $status->get();
	}

	/**
	 * Get the arguments supplied to this job.
	 *
	 * @return array Array of arguments.
	 */
	public function getArguments()
	{
		if (!isset($this->payload['args'])) {
			return [];
		}

		return $this->payload['args'][0];
	}

	/**
	 * Get the instantiated object for this job that will be performing work.
	 * @return \Resque\Job\JobInterface Instance of the object that this job belongs to.
	 * @throws \Resque\Exceptions\ResqueException
	 */
	public function getInstance()
	{
		if (!is_null($this->instance)) {
			return $this->instance;
		}

		$this->instance = $this->getJobFactory()->create($this->payload['class'], $this->getArguments(), $this->queue);
		$this->instance->job = $this;
		return $this->instance;
	}

	/**
	 * Actually execute a job by calling the perform method on the class
	 * associated with the job with the supplied arguments.
	 *
	 * @return bool
	 * @throws Resque\Exceptions\ResqueException When the job's class could not be found
	 * 											 or it does not contain a perform method.
	 */
	public function perform()
	{
		$result = true;
		try {
			Event::trigger('beforePerform', $this);

			$instance = $this->getInstance();
			if (is_callable([$instance, 'setUp'])) {
				$instance->setUp();
			}

			$result = $instance->perform();

			if (is_callable([$instance, 'tearDown'])) {
				$instance->tearDown();
			}

			Event::trigger('afterPerform', $this);
		} catch (DoNotPerformException) {
			// beforePerform/setUp have said don't perform this job. Return.
			$result = false;
		}

		return $result;
	}

	/**
	 * Mark the current job as having failed.
	 *
	 * @param $exception
	 */
	public function fail($exception)
	{
		Event::trigger('onFailure', ['exception' => $exception, 'job' => $this]);

		$this->updateStatus(Status::STATUS_FAILED);
		if ($exception instanceof Error) {
			FailureHandler::createFromError(
				$this->payload,
				$exception,
				$this->worker,
				$this->queue
			);
		} else {
			FailureHandler::create(
				$this->payload,
				$exception,
				$this->worker,
				$this->queue
			);
		}

		if (!empty($this->payload['id'])) {
			PID::del($this->payload['id']);
		}

		Stat::incr('failed');
		Stat::incr('failed:' . $this->worker);
	}

	/**
	 * Re-queue the current job.
	 * @return string
	 */
	public function recreate()
	{
		$monitor = false;
		if (!empty($this->payload['id'])) {
			$status = new Status($this->payload['id'], $this->getPrefix());
			if ($status->isTracking()) {
				$monitor = true;
			}
		}

		return self::create(
			$this->queue,
			$this->payload['class'],
			$this->getArguments(),
			$monitor,
			null,
			$this->getPrefix()
		);
	}

	/**
	 * Generate a string representation used to describe the current job.
	 *
	 * @return string The string representation of the job.
	 */
	public function __toString(): string
	{
		$name = ['Job{' . $this->queue . '}'];
		if (!empty($this->payload['id'])) {
			$name[] = 'ID: ' . $this->payload['id'];
		}
		$name[] = $this->payload['class'];
		if (!empty($this->payload['args'])) {
			$name[] = json_encode($this->payload['args']);
		}
		return '(' . implode(' | ', $name) . ')';
	}

	/**
  * @return Resque\JobHandler
  */
 public function setJobFactory(FactoryInterface $jobFactory)
	{
		$this->jobFactory = $jobFactory;

		return $this;
	}

	/**
	 * @return Resque\Job\FactoryInterface
	 */
	public function getJobFactory()
	{
		if ($this->jobFactory === null) {
			$this->jobFactory = new Factory();
		}
		return $this->jobFactory;
	}

	/**
	 * @return string
	 */
	private function getPrefix()
	{
		return $this->payload['prefix'] ?? '';
	}
}
