<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature and Action tests run against real Postgres (see phpunit.xml) — never SQLite,
| per docs/01-architecture.md.
|
| Unit tests get no TestCase and no database on purpose: the money primitives in M1 are
| pure integer functions and must stay fast enough to run on every keystroke.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');
