<?php

namespace Carsdotcom\ApiRequest;

class Helpers
{
    /**
     * Given an object, or a full class name and namespace (e.g. thing::class or ModelNotFoundException->getModel())
     * strip off the name space, and turn the camel case into separated words.
     * @param  string|object $class
     * @return string
     */
    public static function friendlyClassName(string|object $class): string
    {
        $class = is_object($class) ? get_class($class) : $class;

        if (str_contains($class, '@anonymous')) {
            $parent = get_parent_class($class);
            if (!$parent || $parent === 'stdClass') {
                return 'Anonymous Class';
            }
            return 'Anonymous Descendent of ' . self::friendlyClassName($parent);
        }

        // Get just the class name (strip namespace)
        $className = class_basename($class);

        // Replace punctuation with spaces (especially _ )
        $className = preg_replace('/[^a-z]+/i', ' ', $className);

        // Insert spaces in camel case names
        $className = preg_replace('/([a-z])([A-Z])/', '$1 $2', $className);

        return $className;
    }
}