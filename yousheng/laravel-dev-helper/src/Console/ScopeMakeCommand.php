<?php

namespace Yousheng\LaravelDevHelper\Console;

use Illuminate\Console\GeneratorCommand;


/**
 * 生成查询作用域类（make:scope）。
 *
 * 基于 scope.stub 在 App\Scopes 命名空间下生成一个 Eloquent 查询作用域类。
 * 例：php artisan make:scope PostActiveScope
 */
class ScopeMakeCommand extends GeneratorCommand
{

    /**
     * The console command name.
     * example: php artisan make:scope PostActiveScope
     *
     * @var string
     */
    protected $signature = 'make:scope {name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new query scope class';

    /**
     * The type of class being generated
     * @var string
     */
    protected $type = 'Scope';

    /**
     * Get the stub file for the generator.
     *
     * @return string
     */
    protected function getStub()
    {
        return __DIR__.'/stubs/scope.stub';
    }

    /**
     * Get the default namespace for the class.
     *
     * @param string $rootNamespace
     *
     * @return string
     */
    protected function getDefaultNamespace($rootNamespace)
    {
        return $rootNamespace.'\Scopes';
    }
}
