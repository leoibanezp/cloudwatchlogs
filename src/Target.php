<?php
namespace leoibanezp\cloudwatchlogs;

use yii\log\Target as BaseTarget;
use yii\base\InvalidConfigException;
use Aws\CloudWatchLogs\CloudWatchLogsClient;
use yii\log\Logger;
use \yii\helpers\VarDumper;

class Target extends BaseTarget
{
    /**
     * @var string The name of the log group.
     */
    public $logGroup;

    /**
     * @var string The AWS region to use e.g. eu-west-1.
     */
    public $region;

    /**
     * @var string Your AWS access key.
     */
    public $key;

    /**
     * @var string The name of the log stream. When not set, we try to get the ID of your EC2 instance.
     */
    public $logStream;

    /**
     * @var string Your AWS secret.
     */
    public $secret;

    /**
     * @var boolean Specifies whether to start or not launch.
     */
    public $launch;    

    /**
     * @var CloudWatchLogsClient
     */
    private $client;

    /**
     * @var string
     */
    private $sequenceToken;

    /**
     * @inheritdoc
     */
    public function init() {
        if (empty($this->launch)) {
            return;
        }
        if (empty($this->logGroup)) {
            throw new InvalidConfigException("A log group must be set.");
        }

        if (empty($this->region)) {
            throw new InvalidConfigException("The AWS region must be set.");
        }
        if (empty($this->key)) {
            throw new InvalidConfigException("Cannot identify instance ID and no log stream name is set.");
        }
        if (empty($this->logStream)) {
            $this->logStream = date('Y-m-d');
        }

        $params = [
            'region' => $this->region,
            'version' => 'latest',
        ];

        if (!empty($this->key) && !empty($this->secret)) {
            $params['credentials'] = [
                'key' => $this->key,
                'secret' => $this->secret,
            ];
        }

        $this->client = new CloudWatchLogsClient($params);
    }

    /**
     * @inheritdoc
     */
    public function export()
    {
        if (empty($this->launch)) {
            return;
        }        
        $this->ensureLogGroupExists();

        $this->refreshSequenceToken();

        $data = [
            'logEvents' => array_map([$this, 'formatMessage'], $this->messages),
            'logGroupName' => $this->logGroup,
            'logStreamName' => $this->logStream,
        ];

        if (!empty($this->sequenceToken)) {
            $data['sequenceToken'] = $this->sequenceToken;
        }

        $response = $this->client->putLogEvents($data);

        $this->sequenceToken = $response->get('nextSequenceToken');
    }

    /**
     * @inheritdoc
     */
    public function formatMessage($message)
    {
        list($text, $level, $category, $timestamp) = $message;
        $level = Logger::getLevelName($level);
        if (!is_string($text)) {
            // exceptions may not be serializable if in the call stack somewhere is a Closure
            if ($text instanceof \Throwable || $text instanceof \Exception) {
                $text = (string) $text;
            } else {
                $text = VarDumper::export($text);
            }
        }
        $traces = [];
        if (isset($message[4])) {
            foreach ($message[4] as $trace) {
                $traces[] = "in {$trace['file']}:{$trace['line']}";
            }
        }

        $prefix = $this->getMessagePrefix($message);

        return [
            'timestamp' => round(microtime(true) * 1000),
            'message' => "{$prefix}[$level][$category] $text" . (empty($traces) ? '' : "\n    " . implode("\n    ", $traces))
        ];
    }

    /**
     * Get the sequence token for the selected log stream.
     *
     * @return void
     */
    private function refreshSequenceToken()
    {
        if (empty($this->launch)) {
            return;
        }          
        $existingStreams = $this->client->describeLogStreams([
            'logGroupName' => $this->logGroup,
            'logStreamNamePrefix' => $this->logStream,
        ])->get('logStreams');

        $exists = false;

        foreach($existingStreams as $stream) {
            if ($stream['logStreamName'] === $this->logStream) {
                $exists = true;
                if (isset($stream['uploadSequenceToken'])) {
                    $this->sequenceToken = $stream['uploadSequenceToken'];
                }
            }
        }

        if (!$exists) {
            $this->client->createLogStream([
                'logGroupName' => $this->logGroup,
                'logStreamName' => $this->logStream,
            ]);
        }
    }

    /**
     * Ensures that the selected log group exists or create it
     *
     * @return void
     */
    private function ensureLogGroupExists()
    {
        if (empty($this->launch)) {
            return;
        }        
        $existingGroups = $this->client->describeLogGroups([
            'logGroupNamePrefix' => $this->logGroup,
        ])->get('logGroups');

        $exists = false;

        foreach ($existingGroups as $group) {
            if ($group['logGroupName'] === $this->logGroup) {
                $exists = true;
            }
        }

        if (!$exists) {
            $this->client->createLogGroup([
                'logGroupName' => $this->logGroup,
            ]);
        }
    }
}
