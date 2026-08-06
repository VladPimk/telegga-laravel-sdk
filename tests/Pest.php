<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Telegga\Laravel\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');
uses(DatabaseMigrations::class)->in('Feature');
