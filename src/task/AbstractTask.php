<?php

namespace phpboot\task;

use phpboot\logging\LogAbleTrait;
use phpboot\common\util\JsonUtils;

abstract class AbstractTask
{
    use LogAbleTrait;

    /**
     * @var array
     */
    private $params = [];

    public function __construct(array $params = [])
    {
        $this->setParams($params);
    }

    public function getParams(): array
    {
        return $this->params;
    }

    public function setParams(array $params): void
    {
        $this->params = array_merge($this->params, $params);
    }

    public function toJson(): string
    {
        $taskClass = get_class($this);
        $taskClass = str_replace("\\", '/', $taskClass);
        $taskParams = $this->params;
        return JsonUtils::toJson(compact('taskClass', 'taskParams'));
    }
}
