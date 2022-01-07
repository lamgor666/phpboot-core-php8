<?php

namespace phpboot\task;

interface Task
{
    public function process(): bool;

    public function getParams(): array;

    public function setParams(array $params): void;

    public function toJson(): string;
}
