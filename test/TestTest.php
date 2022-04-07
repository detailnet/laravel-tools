<?php

declare(strict_types=1);

namespace DetailTest\Laravel;

use PHPUnit\Framework\TestCase;

class TestTest extends TestCase
{
    private string $dummy;

    protected function setUp(): void
    {
        $this->dummy = 'dummy';
    }

    public function testTestDoSetup(): void
    {
        $this->assertTrue($this->dummy === 'dummy');
    }
}
