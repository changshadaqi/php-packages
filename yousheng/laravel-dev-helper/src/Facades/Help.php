<?php

namespace Yousheng\LaravelDevHelper\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void print2file($item, $append = false, $dirname = '0000', $file = 'index.html', $maxLevel = null)
 * @method static void dump($item, $append = false, $file = 'index.html')
 * @method static string getAllPublicMethods($class)
 * @method static array getProperties($obj, $level = 1, $proName = 'all', $exludes = [])
 * @method static array getProperty($object, $propertyName)
 * @method static bool isYoushengDebug()
 *
 * @see \Yousheng\LaravelDevHelper\Helper
 */
class Help extends Facade
{
    public static function getFacadeAccessor()
    {
        return 'helper';
    }
}
