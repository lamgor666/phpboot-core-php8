<?php

namespace phpboot\task;

interface CronTask
{
    public function run(): void;
}
