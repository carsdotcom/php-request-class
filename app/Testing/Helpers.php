<?php

namespace Carsdotcom\ApiRequest\Testing;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;

class Helpers
{
    /**
     * Given a path relative to the disk specified in config,
     * return the contents as a string.
     * @param string $relativeFilename
     * @return string    File contents. Note, does not make an effort to decode e.g. JSON
     *    Unlike native file_get_contents will never return false, give you up, let you down
     */
    public static function getDataFile(string $relativeFilename): string
    {
        $disk = Storage::disk(config('api-request.tapper_data_storage_disk_name'));
        $file = $disk->get(Str::start($relativeFilename, '/'));
        if (!$file) {
            throw new FileNotFoundException("{$relativeFilename}");
        }
        return $file;
    }

    /**
     * Assuming a unit test data file contains JSON, decode it before returning
     * @param string $relativeFilename
     * @return mixed
     */
    public static function getJsonDataFile(string $relativeFilename)
    {
        return json_decode(self::getDataFile($relativeFilename), true, 512, JSON_THROW_ON_ERROR);
    }
}