<?php

namespace Forumhouse\LaravelAmqp\Queue;

use Forumhouse\LaravelAmqp\Exception\AMQPException;
use Forumhouse\LaravelAmqp\Jobs\AMQPJob;
use Forumhouse\LaravelAmqp\Utility\ArrayUtil;
use Illuminate\Queue\Queue;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPConnection;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Class representing AMQP Queue
 *
 * @package Forumhouse\LaravelAmqp\Queue
 */
class AMQPQueue extends Queue
{
    const EXCHANGE_TYPE_DIRECT = 'direct';

    const EXCHANGE_TYPE_TOPIC = 'topic';

    const EXCHANGE_TYPE_HEADERS = 'headers';

    const EXCHANGE_TYPE_FANOUT = 'fanout';

    /**
     * @var AMQPConnection Connection to amqp compatible server
     */
    protected $connection;

    /**
     * @var AMQPChannel Channel, that is used for communication
     */
    protected $channel;

    /**
     * @var string Default queue name to be used when passed queue = null
     */
    protected $defaultQueueName;

    /**
     * @var string Exchange name, if used
     */
    protected $exchangeName;

    /**
     * @var string Default channel id if needed
     */
    private $defaultChannelId;
    /**
     * @var array
     */
    private $queueFlags;
    /**
     * @var array
     */
    private $messageProperties;

    /**
     * @param AMQPConnection $connection
     * @param string         $defaultQueueName  Default queue name
     * @param array          $queueFlags        Queue flags See a list of parameters to
     *                                          \PhpAmqpLib\Channel\AMQPChannel::queue_declare. Parameters should be
     *                                          passed like for call_user_func_array in this parameter
     * @param array          $messageProperties This is passed as a second parameter to \PhpAmqpLib\Message\AMQPMessage
     *                                          constructor
     * @param string         $defaultChannelId  Default channel id
     * @param string         $exchangeName      Exchange name
     * @param mixed          $exchangeType      Exchange type
     * @param mixed          $exchangeFlags     Exchange flags
     */
    public function __construct(
        AMQPConnection $connection,
        $defaultQueueName = null,
        $queueFlags = [],
        $messageProperties = [],
        $defaultChannelId = null,
        $exchangeName = '',
        $exchangeType = null,
        $exchangeFlags = []
    ) {
        $this->connection = $connection;
        $this->defaultQueueName = $defaultQueueName ?: 'default';
        $this->queueFlags = $queueFlags;
        $this->messageProperties = $messageProperties;
        $this->defaultChannelId = $defaultChannelId;
        $this->exchangeName = $exchangeName;
        $this->channel = $connection->channel($this->defaultChannelId);

        if ($exchangeName !== null) {
            $this->declareExchange($exchangeName, $exchangeType, $exchangeFlags);
        }
    }

    /**
     * Push a new job onto the queue.
     *
     * @param  string $job   Job implementation class name
     * @param  mixed  $data  Job custom data. Usually array
     * @param  string $queue Queue name, if different from the default one
     *
     * @throws AMQPException
     * @return bool Always true
     */
    public function push($job, $data = '', $queue = null)
    {
        $queue = $this->getQueueName($queue);
        $this->declareQueue($queue);
        $payload = new AMQPMessage($this->createPayload($job, $data), $this->messageProperties);
        $this->channel->basic_publish($payload, $this->exchangeName, $this->getRoutingKey($queue));
        return true;
    }

    /**
     * Push a raw payload onto the queue.
     *
     * @param  string $payload Job payload
     * @param  string $queue   Queue name, if different from the default one
     * @param  array  $options Currently unused
     *
     * @return bool Always true
     */
    public function pushRaw($payload, $queue = null, array $options = [])
    {
        $queue = $this->getQueueName($queue);
        $this->declareQueue($queue);
        $payload = new AMQPMessage($payload, $this->messageProperties);
        $this->channel->basic_publish($payload, $this->exchangeName, $queue);
        return true;
    }

    /**
     * Push a new job onto the queue after a delay.
     *
     * @param  \DateTime|int $delay Delay
     * @param  string        $job   Job implementation class name
     * @param  mixed         $data  Job custom data. Usually array
     * @param  string        $queue Queue name, if different from the default one
     *
     * @return bool Always true
     */
    public function later($delay, $job, $data = '', $queue = null)
    {
        if ($delay instanceof \DateTime) {
            $delay = $delay->getTimestamp() - time();
        }

        $queue = $this->getQueueName($queue);
        $this->declareQueue($queue);
        $delayedQueueName = $this->declareDelayedQueue($queue, $delay);

        $payload = new AMQPMessage($this->createPayload($job, $data), $this->messageProperties);
        $this->channel->basic_publish($payload, $this->exchangeName, $delayedQueueName);
        return true;
    }

    /**
     * Pop the next job off of the queue.
     *
     * @param string|null $queue Queue name if different from the default one
     *
     * @return \Illuminate\Queue\Jobs\Job|null Job instance or null if no unhandled jobs available
     */
    public function pop($queue = null)
    {
        $queue = $this->getQueueName($queue);
        $this->declareQueue($queue);
        $envelope = $this->channel->basic_get($queue);

        if ($envelope instanceof AMQPMessage) {
            return new AMQPJob($this->container, $queue, $this->channel, $envelope);
        }

        return null;
    }

    /**
     * @param string $exchangeName  The name of the exchange. For example, 'logs'
     * @param string $exchangeType  The type of the exchange. See EXCHANGE_TYPE_* constants for details
     * @param array  $exchangeFlags The flags of the exchange. See \PhpAmqpLib\Channel\AMQPChannel::exchange_declare
     *                              (from third parameter onwards). Must be an assoc array. Default flags can be omitted
     *
     * @see \PhpAmqpLib\Channel\AMQPChannel::exchange_declare
     * @return void
     */
    protected function declareExchange($exchangeName, $exchangeType, array $exchangeFlags = [])
    {
        $flags = array_replace([
            'exchange' => $exchangeName,
            'type' => $exchangeType,
            'passive' => false,
            'durable' => false,
            'auto_delete' => true,
            'internal' => false,
            'nowait' => false,
            'arguments' => null,
            'ticket' => null,
        ], $exchangeFlags);

        call_user_func_array([$this->channel, 'exchange_declare'], $flags);
    }

    /**
     * Declares a queue to the AMQP library
     *
     * @param string $name The name of the queue to declare
     *
     * @return void
     */
    public function declareQueue($name)
    {
        $queue = $this->getQueueName($name);
        $flags = array_replace_recursive([
            'queue' => $queue,
            'passive' => false,
            'durable' => false,
            'exclusive' => false,
            'auto_delete' => true,
            'nowait' => false,
            'arguments' => null,
            'ticket' => null,
        ], $this->getQueueFlags($name));

        call_user_func_array([$this->channel, 'queue_declare'], $flags);
    }

    /**
     * Declares delayed queue to the AMQP library
     *
     * @param string $destinationQueueName Queue destination
     * @param int    $delay                Queue delay in seconds
     *
     * @return string Deferred queue name for the specified delay
     */
    public function declareDelayedQueue($destinationQueueName, $delay)
    {
        $destinationQueueName = $this->getQueueName($destinationQueueName);
        $deferredQueueName = $destinationQueueName . '_deferred_' . $delay;

        $flags = array_replace_recursive([
            'queue' => '',
            'passive' => false,
            'durable' => false,
            'exclusive' => false,
            'auto_delete' => true,
            'nowait' => false,
            'arguments' => null,
            'ticket' => null,
        ], $this->getQueueFlags($destinationQueueName, $deferredQueueName, $delay), [
            'queue' => $deferredQueueName,
            'durable' => true,
            'arguments' => [
                'x-dead-letter-exchange' => ['S', ''],
                'x-dead-letter-routing-key' => ['S', $destinationQueueName],
                'x-message-ttl' => ['I', $delay * 1000],
            ],
        ]);

        call_user_func_array([$this->channel, 'queue_declare'], $flags);
        return $deferredQueueName;
    }

    /**
     * Helper to return a default queue name in case passed param is empty
     *
     * @param string|null $name Queue name. If null, default will be used
     *
     * @throws AMQPException
     * @return string Queue name to be used in AMQP calls
     */
    protected function getQueueName($name)
    {
        $name = $name ?: $this->defaultQueueName;
        if ($name === null) {
            throw new AMQPException('Default nor specific queue names given');
        }
        return $name;
    }

    /**
     *  Get routing key from config or use default one (queue name)
     *
     * @param $queue string
     * @return string Routing key name
     */
    protected function getRoutingKey($queue)
    {
        return empty($this->queueFlags['routing_key']) ? $queue : $this->queueFlags['routing_key'];
    }

    /**
     * @param string      $queueName
     * @param null|string $deferredQueueName
     * @param null|int    $deferredQueueDelay
     *
     * @return array
     */
    protected function getQueueFlags($queueName, $deferredQueueName = null, $deferredQueueDelay = null)
    {
        $args = func_get_args();
        $result = ArrayUtil::arrayMapRecursive(function ($value) use($args) {
            return is_callable($value) ? call_user_func_array($value, $args) : $value;
        }, $this->queueFlags);

        $result = ArrayUtil::removeNullsRecursive($result);

        return $result;
    }
}
