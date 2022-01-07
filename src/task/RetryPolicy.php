<?php

namespace phpboot\task;

use phpboot\common\traits\MapAbleTrait;

/**
 * Task 类重试策略
 */
final class RetryPolicy
{
    use MapAbleTrait;

    /**
     * 当前失败次数
     *
     * @var int
     */
    private $failTimes = 0;

    /**
     * 最大重试次数
     *
     * @var int
     */
    private $retryAttempts = 3;

    /**
     * 重试间隔（秒）
     *
     * @var int
     */
    private $retryInterval = 60;

    private function __construct(?array $data = null)
    {
        if (empty($data)) {
            return;
        }

        $this->fromMap($data);
    }

    public static function create(int $failTimes = 0, int $retryAttempts = 3, int $retryInterval = 60): self
    {
        return new self(compact('failTimes', 'retryAttempts', 'retryInterval'));
    }

    public function toArray(): array
    {
        return [
            'failTimes' => $this->failTimes,
            'retryAttempts' => $this->retryAttempts,
            'retryInterval' => $this->retryInterval,
        ];
    }

    public function getFailTimes(): int
    {
        return $this->failTimes;
    }

    public function getRetryAttempts(): int
    {
        return $this->retryAttempts;
    }

    public function getRetryInterval(): int
    {
        return $this->retryInterval;
    }
}
