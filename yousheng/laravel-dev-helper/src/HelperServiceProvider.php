<?php

namespace Yousheng\LaravelDevHelper;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\ServiceProvider;
use Yousheng\LaravelDevHelper\Console\MakeMigrationJsonCommand;
use Yousheng\LaravelDevHelper\Console\MigrationsFromDatabaseCommand;
use Yousheng\LaravelDevHelper\Console\ModelsFromDatabaseCommand;
use Yousheng\LaravelDevHelper\Console\NovaResourceFromDatabaseCommand;
use Yousheng\LaravelDevHelper\Console\OneCommand;
use Yousheng\LaravelDevHelper\Console\ScopeMakeCommand;
use Yousheng\LaravelDevHelper\Http\Middleware\First;
use Yousheng\LaravelDevHelper\Http\Middleware\Last;

class HelperServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('helper', function ($app) {
            return new Helper($app);
        });

        // Laravel 11+ 推荐在 Http Kernel 解析完成后再注册全局中间件。
        // 使用 afterResolving 可以保证 withMiddleware() 里的配置先生效，
        // 同时避免在 register() 阶段提前实例化 HTTP Kernel。
        $this->app->afterResolving(HttpKernel::class, function ($kernel) {
            if (method_exists($kernel, 'prependMiddleware')) {
                $kernel->prependMiddleware(First::class);
            }

            if (method_exists($kernel, 'pushMiddleware')) {
                $kernel->pushMiddleware(Last::class);
            }
        });
    }

    public function boot(): void
    {
        $this->commands([
            MigrationsFromDatabaseCommand::class,
            ModelsFromDatabaseCommand::class,
            NovaResourceFromDatabaseCommand::class,
            ScopeMakeCommand::class,
            OneCommand::class,
            MakeMigrationJsonCommand::class,
        ]);
    }
}
