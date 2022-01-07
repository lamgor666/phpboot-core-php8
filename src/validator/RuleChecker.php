<?php

namespace phpboot\validator;

interface RuleChecker
{
    public function getRuleName(): string;

    public function check(string $value, string $checkValue = ''): bool;
}
