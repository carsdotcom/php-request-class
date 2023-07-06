<?php

namespace Tests\Feature\Testing;

use Carsdotcom\ApiRequest\Testing\Helpers;
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;
use Tests\BaseTestCase;

class HelpersTest extends BaseTestCase
{
    public function testGetDataFileExists(): void
    {
        $file = Helpers::getDataFile('/status/success.json');
        self::assertSame('{"success": true}', $file);
    }

    public function testGetDataFileDoesNotExist(): void
    {
        self::expectExceptionObject(new FileNotFoundException("nope.json"));
        Helpers::getDataFile('nope.json');
    }

    public function testGetJsonDataFile(): void
    {
        $file = Helpers::getJsonDataFile('/status/success.json');
        self::assertSame(['success' => true], $file);
    }
}