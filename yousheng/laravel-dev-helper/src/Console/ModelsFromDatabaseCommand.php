<?php
namespace Yousheng\LaravelDevHelper\Console;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * 从已有 MySQL 数据库生成 Eloquent 模型（yousheng:model）。
 *
 * 流程：读取表→提取列/主键/外键→推导 Eloquent 关系→渲染模型。
 * 传入 --nova 时额外生成对应的 Nova 资源。
 */
class ModelsFromDatabaseCommand extends GeneratorCommand
{
    use CreateModelTrait;
    use CreateNovaTrait;
    use ColumnsTrait;
    use UtilsTrait;

    protected $name = 'yousheng:model';
    // php artisan db:models --only=a --only=b --only=users 这种不方便，不用
    // php artisan db:models --only=users,posts 下面这种方式写起来方便
    protected $description = '根据已有MYSQL数据库生成对应模型';
    protected static $ignoreTabels = ['migrations'];
    public function handle()
    {
        //================================================ 1: 读取所有要处理的数据表
        $tables=$this->getTables();
        $modelsConfig=$this->getModelsConfig($this->getTablesWithKeys());


        //================================================ 2: 提取所有表的column,主键，外键
        $this->info('开始提取表 columns, primary keys, foreign keys');
        $prep = $this->getColumnsPrimaryAndForeignKeysPerTable($tables);
        $this->info('所有表的列，主键，外键提取成功');

        //================================================ 3: 生成一个数组，由Eloquent models规则组成
        $this->info('生成 Eloquent rules');
        $eloquentRules = $this->getEloquentRules($tables, $prep);
        //================================================ 4: 生成 Eloquent Models
        $this->info("开始生成所有Eloquent models\n");
        $this->generateEloquentModels( $eloquentRules);
        $this->info("\n所有model生成完毕!");

        //================================================ 4: 生成 Nova Resource
        if($this->option('nova')){
            $this->line("<fg=yellow>生成 Nova rules</fg=yellow>\n");
            $NovaRules = $this->getNovaRules($tables, $prep);

            $this->generateNovaResources( $NovaRules);
            $this->line("\n<fg=yellow>所有Nova资源生成完毕!</fg=yellow>");

        }

        //================================================ 5: 生成一个数组，key为model名，值包括 fields中的配置
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
     * @param  string  $stub
     * @return string
     */
    protected function resolveStubPath($stub)
    {
        return file_exists($customPath = $this->laravel->basePath(trim($stub, '/')))
            ? $customPath
            : __DIR__.$stub;
    }




    // todo: 增加only和excep选项







    protected function getNamespace_del() {
        $ns = $this->option('namespace');
        if(empty($ns)) {
            $ns = env('APP_NAME','App\Models');
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
            'NAMESPACE' => env('APP_NAME','App\Models'),
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

        if(!is_dir($path)) {
            $this->warn('Path is not a directory, creating ' . $path);
            mkdir($path);
        }

        return $path;
    }

    /**
     * Get the path to the generator template.
     *
     * @return mixed
     */
    protected function getTemplatePath()
    {
        return  __DIR__.'/stubs/model.txt';
    }
    protected function getResouceStubPath()
    {
        return  __DIR__.'/stubs/resource.stub';
    }

    protected function getNovaResourcestb()
    {
        return  __DIR__.'/stubs/resource.stub';
    }
    protected function getOptions()
    {
        return [
            ['nova', null, InputOption::VALUE_NONE, '同时生成nova资源'],
            ['overwrite', null, InputOption::VALUE_NONE, '如果模型存在，强制覆盖'],
            ['only', null, InputOption::VALUE_OPTIONAL , '指定表'],
            ['ignore', null, InputOption::VALUE_OPTIONAL , '忽略表'],
   ];
    }
}
