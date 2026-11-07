<?php

namespace Yousheng\LaravelDevHelper\Console;

use Illuminate\Console\GeneratorCommand;

/**
 * 从已有 MySQL 数据库生成 Nova 资源（yousheng:nova）。
 *
 * 流程与 yousheng:model 类似，但只输出 Nova 资源：
 * 读取表→提取列/主键/外键→推导字段→渲染 resource.stub。
 */
class NovaResourceFromDatabaseCommand extends GeneratorCommand
{
    use CreateNovaTrait;
    use ColumnsTrait;
    use UtilsTrait;
    protected $description = '根据已有MYSQL数据库生成 Nova Resource';
    protected $signature = 'yousheng:nova {--only= : 只包括的表}
                {--ignore= : 排除哪些表} {--overwrite : 是否强制覆盖}' ;

    /**
     * The console command description.
     *
     * @var string
     */
    public function handle()
    {
        //================================================ 1: 读取所有要处理的数据表
        $tables = $this->getTables();
        //================================================ 2: 提取所有表的column,主键，外键
        $this->info('开始提取表 columns, primary keys, foreign keys');
        $prep = $this->getColumnsPrimaryAndForeignKeysPerTable($tables);
        $this->info('所有表的列，主键，外键提取成功');
        //================================================ 3: 生成 Nova Resource
        $this->line("<fg=yellow>生成 Nova rules</fg=yellow>\n");
        $NovaRules = $this->getNovaRules($tables, $prep);
        $this->generateNovaResources($NovaRules);
        $this->line("\n<fg=yellow>所有Nova资源生成完毕!</fg=yellow>");
    }


    /**
     * Get the stub file for the generator.
     *
     * @return string
     */
    protected function getStub()
    {
        return $this->option('pivot')
            ? $this->resolveStubPath('/stubs/model.pivot.stub')
            : $this->resolveStubPath('/stubs/model.stub');
    }

    /**
     * Resolve the fully-qualified path to the stub.
     *
     * @param string $stub
     * @return string
     */
    protected function resolveStubPath($stub)
    {
        return file_exists($customPath = $this->laravel->basePath(trim($stub, '/')))
            ? $customPath
            : __DIR__ . $stub;
    }


    // todo: 增加only和excep选项


    protected function getNamespace_del()
    {
        $ns = $this->option('namespace');
        if (empty($ns)) {
            $ns = env('APP_NAME', 'App\Models');
        }
        //convert forward slashes in the namespace to backslashes
        $ns = str_replace('/', '\\', $ns);
        return $ns;

    }

    private function generateFunctions($functionsContainer)
    {
        $f = '';
        foreach ($functionsContainer as $functions) {
            $f .= $functions;
        }
        return $f;
    }


    /**
     * Fetch the template data.
     *
     * @return array
     */
    protected function getTemplateData()
    {
        return [
            'NAME' => ucwords($this->argument('modelName')),
            'NAMESPACE' => env('APP_NAME', 'App\Models'),
        ];
    }

    /**
     * The path to where the file will be created.
     *
     * @return mixed
     */
    protected function getFileGenerationPath()
    {
        // $path = $this->getPathByOptionOrConfig('path', 'model_target_path');
        $path = app_path('Models');

        if (!is_dir($path)) {
            $this->warn('Path is not a directory, creating ' . $path);
            mkdir($path);
        }

        return $path;
    }



    protected function getResouceStubPath()
    {
        return __DIR__ . '/stubs/resource.stub';
    }


}
