<?php
/**
* 目标：一条命令生成 migration,数据表,model,nova,factory
*
*
*
*
*
*
*/

namespace Yousheng\LaravelDevHelper\Console;
use Illuminate\Console\GeneratorCommand;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Database\Schema\ForeignKeyDefinition;
use Illuminate\Database\Schema\IndexDefinition;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputOption;


/**
 * 一条命令同时生成模型与（可选）Nova 资源（yousheng:one）。
 *
 * 与 ModelsFromDatabaseCommand 不同，本命令是自包含实现：直接把
 * getTables/getEloquentRules/generateEloquentModel 等方法内联在类里，
 * 不依赖 CreateModelTrait。适用于想一次性基于现有库产出模型的场景。
 */
class OneCommand extends GeneratorCommand
{
    protected $name = 'yousheng:one';
    // php artisan db:models --only=a --only=b --only=users 这种不方便，不用
    // php artisan db:models --only=users,posts 下面这种方式写起来方便
    protected $description = '一条命令生成migration,数据表,model,nova,factory等';
    protected static $ignoreTabels = ['migrations'];
    public function handle()
    {
        //================================================ 1: 读取所有要处理的数据表
        $tables=$this->getTables();

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

        //================================================ 5: 生成一个数组，key为model名，值包括 fields 中的配置
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
    public function getTables() {
        $this->info("\n从数据库读取表...");
        $tables = collect(app('db.schema')->getTables())->map(function($table){
            return $table['name'];
        })->all();
        $onlyTables=[];
        $ignoreTables=[];
        $optionIgnore = $this->option('ignore');
        $optionOnly = $this->option('only');
        if($optionIgnore && $optionOnly){
            $this->info("ignore 和 only 二选一，默认为only");
            $optionIgnore=null;
        }
        if($optionOnly){
            $onlyTables =  explode(',', $optionOnly);
        }
        if(!empty($onlyTables)){
            $tables = array_intersect($tables,$onlyTables);
            $optionIgnore=null;
        }
        if($optionIgnore){
            $ignoreTables =  explode(',', (string) $optionIgnore);
        }
        if(!empty($ignoreTables)){
            $tables = array_diff($tables,$ignoreTables);
        }
        $this->info("待处理的表有".count($tables)."个，分别是:\n    ".implode("\n    ",$tables));
        return $tables;
    }

    /**
     * 格式如下
     * "users" => [
     *      "hasMany" => []
     *      "hasOne" => []
     *      "belongsTo" => []
     *      "belongsToMany" => []
     *      "fillable" => [
     *              0 => "'id'"
     *              1 => "'name'"
     *              2 => "'email'"
     *              3 => "'email_verified_at'"
     *              4 => "'password'"
     *              5 => "'remember_token'"
     *      ]
     * ]
     */
    private function generateEloquentModels( $eloquentRules)
    {
        //0. set namespace
        //self::$namespace = $this->getNamespace();

        foreach ($eloquentRules as $table => $rules) {
            $this->info("开始生成表".$table."\n");
            try {
                $this->generateEloquentModel( $table, $rules);
            } catch(\Throwable $e) {
                $this->error( $table."生成模型时失败，原因是".$e);
                return;
            }
        }
    }
    private function generateNovaResources( $novaRules)
    {
        //0. set namespace
        //self::$namespace = $this->getNamespace();

        foreach ($novaRules as $table => $rules) {

            try {
                $this->generateNovaResource( $table, $rules);
            } catch(\Throwable $e) {
                $this->error( $table."生成模型时失败，原因是".$e);
                return;
            }
        }
    }

    private function generateEloquentModel( $table, $rules) {

        //1. Determine path where the file should be generated
        //$destinationFolder = $this->getPath();
        $destinationFolder = app_path('Models');
        $modelName = $this->generateModelNameFromTableName($table);
        $filePathToGenerate = $destinationFolder . '/'.$modelName.'.php';

        $canContinue = $this->canGenerate($filePathToGenerate, $table);
        if(!$canContinue) {
            return;
        }

        //2.  generate relationship functions and fillable array
        $hasMany = $rules['hasMany'];
        $hasOne = $rules['hasOne'];
        $belongsTo = $rules['belongsTo'];
        $belongsToMany = $rules['belongsToMany'];


        $fillable = implode(', ', $rules['fillable']);

        $belongsToFunctions = $this->generateBelongsToFunctions($belongsTo);

        $belongsToManyFunctions = $this->generateBelongsToManyFunctions($belongsToMany);

        $hasManyFunctions = $this->generateHasManyFunctions($hasMany);

        $hasOneFunctions = $this->generateHasOneFunctions($hasOne);

        $functions = $this->generateFunctions([
            $belongsToFunctions,
            $belongsToManyFunctions,
            $hasManyFunctions,
            $hasOneFunctions,
        ]);

        //3. prepare template data
        $templateData = array(
           // 'NAMESPACE' => self::$namespace,
            '{{NAMESPACE}}' => 'App\\Models',
            '{{NAME}}' => $modelName,
            '{{TABLENAME}}' => $table,
            '{{FILLABLE}}' => $fillable,
            '{{FUNCTIONS}}' => $functions
        );

        $templatePath = $this->getTemplatePath();
        $this->info("表 ".$table."'s 模型路径是: ".$filePathToGenerate);
        // 文件的owner是root
        file_put_contents($filePathToGenerate,str_replace(array_keys($templateData),array_values($templateData),file_get_contents($templatePath)));
        //run Jeffrey's generator
       /* $this->generator->make(
            $templatePath,
            $templateData,
            $filePathToGenerate
        );*/
        $this->info("Generated model for table $table");
    }
    private function generateNovaResource( $table, $tablerules) {

        //1. Nova 资源存放路径
        $destinationFolder = app_path('Nova');
        $table = $this->resolveTableName($table);
        $modelName = ucfirst( Str::camel(Str::singular($table)));
        $resourceName=$modelName;

        $this->line("\n<fg=yellow>开始生成Nova资源: $resourceName </fg=yellow>");
        $filePathToGenerate = $destinationFolder . '/'.$resourceName.'.php';
        $canContinue = $this->canGenerate($filePathToGenerate, $table);
        if(!$canContinue) {
            return;
        }

        //2.  生成fields
//        $hasMany = $rules['hasMany'];
//        $hasOne = $rules['hasOne'];
//        $belongsTo = $rules['belongsTo'];
//        $belongsToMany = $rules['belongsToMany'];
//
//
//        $fillable = implode(', ', $rules['fillable']);
//
//        $belongsToFunctions = $this->generateBelongsToFunctions($belongsTo);
//
//        $belongsToManyFunctions = $this->generateBelongsToManyFunctions($belongsToMany);
//
//        $hasManyFunctions = $this->generateHasManyFunctions($hasMany);
//
//        $hasOneFunctions = $this->generateHasOneFunctions($hasOne);
//
//        $functions = $this->generateFunctions([
//            $belongsToFunctions,
//            $belongsToManyFunctions,
//            $hasManyFunctions,
//            $hasOneFunctions,
//        ]);


        $fields=implode("\n",$tablerules["fields"]);
        $uses=implode("\n",$tablerules["uses"]);
        //3. prepare template data
        $templateData = array(
            // 'NAMESPACE' => self::$namespace,
            '{{NAMESPACE}}' => 'App\\Nova',
            '{{MODELNAME}}' => 'App\\Models\\'.$modelName,
            '{{RESOURCENAME}}' => $resourceName,
            '{{FIELDS}}' => $fields,
            '{{USES}}'=>$uses
        );

        $templatePath = $this->getResouceStubPath();
        $this->line("<fg=yellow>".$modelName."对应的Nova 资源是: ".$filePathToGenerate."</fg=yellow>");
        if(!is_dir($destinationFolder)) {
            mkdir($destinationFolder, 0755, true);
        }
        // 文件的owner是root
        file_put_contents($filePathToGenerate,str_replace(array_keys($templateData),array_values($templateData),file_get_contents($templatePath)));
        //run Jeffrey's generator
        /* $this->generator->make(
             $templatePath,
             $templateData,
             $filePathToGenerate
         );*/
        $this->line("<fg=yellow>Nova资源".$resourceName." 创建成功</fg=yellow>");
    }
    private function canGenerate($filePathToGenerate, $table) {
        $canOverWrite = $this->option('overwrite');
        if(file_exists($filePathToGenerate)) {
            $this->error(str_replace(base_path(),'',$filePathToGenerate)."已存在，为了安全暂不支持覆盖功能，请先手工删除后再执行db:models");
            if($canOverWrite) {
                $deleted = unlink($filePathToGenerate);
                if(!$deleted) {
                    $this->warn("Failed to delete existing model $filePathToGenerate");
                    return false;
                }
            } else {
                $this->warn("Skipped model generation, file already exists. (force using --overwrite) $table -> $filePathToGenerate");
                return false;
            }
        }

        return true;
    }


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

    private function generateHasManyFunctions($rulesContainer)
    {
        $functions = '';
        foreach ($rulesContainer as $rules) {
            $hasManyModel = $this->generateModelNameFromTableName($rules[0]);
            $key1 = $rules[1];
            $key2 = $rules[2];

            $hasManyFunctionName = $this->getPluralFunctionName($hasManyModel);

            $function = "
    public function $hasManyFunctionName() {".'
        return $this->hasMany'."(\\App\\Models\\$hasManyModel::class, '$key1', '$key2');
    }
";
            $functions .= $function;
        }

        return $functions;
    }

    private function generateHasOneFunctions($rulesContainer)
    {
        $functions = '';
        foreach ($rulesContainer as $rules) {
            $hasOneModel = $this->generateModelNameFromTableName($rules[0]);
            $key1 = $rules[1];
            $key2 = $rules[2];

            $hasOneFunctionName = $this->getSingularFunctionName($hasOneModel);

            $function = "
    public function $hasOneFunctionName() {".'
        return $this->hasOne'."(\\App\\Models\\$hasOneModel::class, '$key1', '$key2');
    }
";
            $functions .= $function;
        }

        return $functions;
    }

    private function generateBelongsToFunctions($rulesContainer)
    {
        $functions = '';
        foreach ($rulesContainer as $rules) {
            $belongsToModel = $this->generateModelNameFromTableName($rules[0]);
            $key1 = $rules[1];
            $key2 = is_array($rules[2])?$rules[2][0]:$rules[2];
            $belongsToFunctionName = $this->getSingularFunctionName($belongsToModel);
            $function = "
    public function $belongsToFunctionName() {".'
        return $this->belongsTo'."(\\App\\Models\\$belongsToModel::class, '$key1', '$key2');
    }
";
            $functions .= $function;
        }

        return $functions;
    }

    private function generateBelongsToManyFunctions($rulesContainer)
    {
        $functions = '';
        foreach ($rulesContainer as $rules) {
            $belongsToManyModel = $this->generateModelNameFromTableName($rules[0]);
            $through = $rules[1];
            $key1 = $rules[2];
            $key2 = $rules[3];

            $belongsToManyFunctionName = $this->getPluralFunctionName($belongsToManyModel);

            $function = "
    public function $belongsToManyFunctionName() {".'
        return $this->belongsToMany'."(\\App\\Models\\$belongsToManyModel::class, '$through', '$key1', '$key2');
    }
";
            $functions .= $function;
        }

        return $functions;
    }

    private function getPluralFunctionName($modelName)
    {
        $modelName = lcfirst($modelName);
        return Str::plural($modelName);
    }

    private function getSingularFunctionName($modelName)
    {
        $modelName = lcfirst($modelName);
        return Str::singular($modelName);
    }

    private function generateModelNameFromTableName($table)
    {
        $table = $this->resolveTableName($table);

        return ucfirst( Str::camel(Str::singular($table)));
    }

    /**
     * Laravel 13 中 Expression::getValue() 需要 Grammar 参数，且不再实现 __toString()，
     * 因此这里统一把 Expression 还原成表名字符串。
     */
    private function resolveTableName($value)
    {
        if ($value instanceof Expression) {
            return $value->getValue(app('db')->connection()->query()->getGrammar());
        }

        return $value;
    }


    private function getColumnsPrimaryAndForeignKeysPerTable($tables)
    {
        return collect(array_flip($tables))->map(function ($key,$table)  {
            $schema=app('db.schema');
            $_columns = collect($schema->getColumns($table))->map(fn ($column) => new ColumnDefinition([
                'name' => $column['name'],
                'type' => $column['type_name'],
                'full_type_definition' => $column['type'],
                'nullable' => $column['nullable'],
                'default' => is_null($column['default']) ? null : new Expression(Str::wrap($column['default'], '(', ')')),
                'autoIncrement' => $column['auto_increment'],
                'collation' => $column['collation'],
                'comment' => $column['comment'],
                'virtualAs' => ! is_null($column['generation']) && $column['generation']['type'] === 'virtual'
                    ? $column['generation']['expression'] : null,
                'storedAs' => ! is_null($column['generation']) && $column['generation']['type'] === 'stored'
                    ? $column['generation']['expression'] : null,
            ]))->all();

            [$primary, $indexes] = collect($schema->getIndexes($table))->map(fn ($index) => new IndexDefinition([
                'name' => match (true) {
                    $index['primary'] => 'primary',
                    $index['unique'] => 'unique',
                    default => 'index',
                },
                'index' => $index['name'],
                'columns' => $index['columns'],
            ]))->partition(fn ($index) => $index->name === 'primary');

            $indexes = $indexes->all();
            $_primaryKey = $primary->first();


            $_foreignKeys = collect($schema->getForeignKeys($table))->map(fn ($foreignKey) => new ForeignKeyDefinition([
                'columns' => $foreignKey['columns'],
                'on' => new Expression($foreignKey['foreign_table']),
                'references' => $foreignKey['foreign_columns'],
                'onUpdate' => $foreignKey['on_update'],
                'onDelete' => $foreignKey['on_delete'],
            ]))->all();
            return [
                'foreign' => $_foreignKeys,
                'primary' => $_primaryKey,
                'columns' => $_columns
            ];
        })->all();




//
//
//
//
//        return collect(array_flip($tables))->map(function ($key,$table)  {
//            $schema=app('db.schema');
//            $foreignKeys=collect($schema->getForeignKeys($table))->map(fn ($foreignKey) => new ForeignKeyDefinition([
//                'columns' => $foreignKey['columns'],
//                'on' => new Expression($foreignKey['foreign_table']),
//                'references' => $foreignKey['foreign_columns'],
//                'onUpdate' => $foreignKey['on_update'],
//                'onDelete' => $foreignKey['on_delete'],
//            ]))->all();
//
//            //上面代码来自 framework/src/Illuminate/Database/Schema/BlueprintState.php
//
//           $_foreignKeys=collect($schema->getForeignKeys($table))
//               ->map(function($ForeignKeyConstraint) use ($table){
//                   return [
//                       'name'       => $ForeignKeyConstraint->getName(),               // 外键名字，如 user_meta_fk
//                       'field'      => $ForeignKeyConstraint->getLocalColumns()[0],   // user_id
//                       'references' => $ForeignKeyConstraint->getForeignColumns()[0], // id
//                       'on'         => $ForeignKeyConstraint->getForeignTableName(), // users
//                       'onUpdate'   => $ForeignKeyConstraint->hasOption('onUpdate') ? $ForeignKeyConstraint->getOption('onUpdate') : 'RESTRICT',
//                       'onDelete'   => $ForeignKeyConstraint->hasOption('onDelete') ? $ForeignKeyConstraint->getOption('onDelete') : 'RESTRICT',
//                   ];
//               });
//
//
//
//
//
//            $pks=$this->doctrineSchemaManager->listTableDetails($table)->getPrimaryKey();
//            $_primaryKeys=$pks?$pks->getColumns():[];
//
//           // get columns lists
//           $_columns = array_keys($this->doctrineSchemaManager->listTableColumns($table));
//            return [
//                'foreign' => $_foreignKeys,
//                'primary' => $_primaryKeys,
//                'columns' => $_columns
//            ];
//        })->all();
//
//
//        //------------无误后删除下面的代码
//        $prep = [];
//        foreach ($tables as $table) {
//            $foreignKeys = [];
//            $fks = $this->doctrineSchemaManager->listTableForeignKeys($table);
//                foreach ($fks as $foreignKey) {
//                    $foreignKeys[] = [
//                        'name'       => $foreignKey->getName(),               // 外键名字，如 user_meta_fk
//                        'field'      => $foreignKey->getLocalColumns()[0],   // user_id
//                        'references' => $foreignKey->getForeignColumns()[0], // id
//                        'on'         => $foreignKey->getForeignTableName(), // users
//                        'onUpdate'   => $foreignKey->hasOption('onUpdate') ? $foreignKey->getOption('onUpdate') : 'RESTRICT',
//                        'onDelete'   => $foreignKey->hasOption('onDelete') ? $foreignKey->getOption('onDelete') : 'RESTRICT',
//                    ];
//                }
//
//            //get primary keys 获取主键
//            //$primaryKeys = $this->schemaGenerator->getPrimaryKeys($table);
//
//            $primary_key_index = $this->doctrineSchemaManager->listTableDetails($table)->getPrimaryKey();
//            $primaryKeys= $primary_key_index ? $primary_key_index->getColumns() : [];
//
//
//            // get columns lists
//            $__columns = $this->doctrineSchemaManager->listTableColumns($table);
//            $columns = [];
//            foreach($__columns as $col) {
//                $columns[] = $col->toArray()['name'];
//            }
//
//            $prep[$table] = [
//                'foreign' => $foreignKeys,
//                'primary' => $primaryKeys,
//                'columns' => $columns,
//            ];
//        }
//
//
//        return $prep;
    }

    // todo 加上 morph
    private function getEloquentRules($tables, $prep)
    {
        $rules = [];

        //first create empty ruleset for each table
        foreach ($prep as $table => $properties) {
            $rules[$table] = [
                'hasMany' => [],
                'hasOne' => [],
                'belongsTo' => [],
                'belongsToMany' => [],
                'fillable' => [],
            ];
        }

        foreach ($prep as $table => $properties) {
            $foreign = $properties['foreign'];
            $primary = $properties['primary'];
            $columns = $properties['columns'];

            // 所有都设为 fillable
            $this->setFillableProperties($table, $rules, $columns);

            $isManyToMany = $this->detectManyToMany($prep, $table);

            if ($isManyToMany === true) {
                $this->addManyToManyRules($tables, $table, $prep, $rules);
            }

            //the below used to be in an ELSE clause but we should be as verbose as possible
            //when we detect a many-to-many table, we still want to set relations on it
            //else
            {
                foreach ($foreign as $fk) {
                    $isOneToOne = $this->detectOneToOne($fk, $primary);

                    if ($isOneToOne) {
                        $this->addOneToOneRules($tables, $table, $rules, $fk);
                    } else {
                        $this->addOneToManyRules($tables, $table, $rules, $fk);
                    }
                }
            }
        }

        return $rules;
    }
    private function getNovaRules($tables, $prep)
    {
        $rules = [];


        //first create empty ruleset for each table
        foreach ($prep as $table => $properties) {
            $rules[$table] = [
                'fields' => [],
                'uses'=>[]
            ];
        }

        foreach ($prep as $table => $properties) {
            $columns = $properties['columns'];
            $fields=[];
            $uses=[];
            foreach ($columns as $columnDefinition){
                $name=$columnDefinition->get('name');
                $type=$columnDefinition->get('type');
                if($type==="enum"){
                    // full_type_definition 可以读取枚举的值，但在nova中好象用不到
                    $fields[] = "Select::make('$name'),";
                    $uses[] = "use Laravel\Nova\Fields\Select;";
                }
                if(in_array($type,['varchar','char','text'])){
                    $fields[] = "Text::make('$name'),";
                    $uses[] = "use Laravel\Nova\Fields\Text;";
                }
                // 其它类型再补充
            }
            $fields=array_unique($fields);
            $uses=array_unique($uses);
            $rules[$table]['fields'] = $fields;
            $rules[$table]['uses'] = $uses;


            /**
             * 11 => Illuminate\Database\Schema\ColumnDefinition^ {#1773
      3 => Illuminate\Database\Schema\ColumnDefinition^ {#1780
        #attributes: array:10 [
          "name" => "stars5"
          "type" => "enum"
          "full_type_definition" => "enum('1','2','3','4','5')"
          "nullable" => false
          "default" => null
          "autoIncrement" => false
          "collation" => "utf8mb4_unicode_ci"
          "comment" => "1为最简单,5最难",
          "virtualAs" => null
          "storedAs" => null
        ]
      }
             */
//            $foreign = $properties['foreign'];
//            $primary = $properties['primary'];
//            $columns = $properties['columns'];
//
//            // 所有都设为 fillable
//            $this->setFillableProperties($table, $rules, $columns);
//
//            $isManyToMany = $this->detectManyToMany($prep, $table);
//
//            if ($isManyToMany === true) {
//                $this->addManyToManyRules($tables, $table, $prep, $rules);
//            }
//
//            //the below used to be in an ELSE clause but we should be as verbose as possible
//            //when we detect a many-to-many table, we still want to set relations on it
//            //else
//            {
//                foreach ($foreign as $fk) {
//                    $isOneToOne = $this->detectOneToOne($fk, $primary);
//
//                    if ($isOneToOne) {
//                        $this->addOneToOneRules($tables, $table, $rules, $fk);
//                    } else {
//                        $this->addOneToManyRules($tables, $table, $rules, $fk);
//                    }
//                }
//            }
        }

        return $rules;
    }
    private function setFillableProperties($table, &$rules, $columns)
    {
        $fillable = [];
        foreach ($columns as $column_name) {
            $_name=$column_name['name'];
            if ($_name !== 'created_at' && $_name !== 'updated_at') {
                $fillable[] = "'$_name'";
            }
        }
        $rules[$table]['fillable'] = $fillable;
    }

    private function addOneToManyRules($tables, $table, &$rules, $fk)
    {
        //$table belongs to $FK
        //FK hasMany $table

        $fkTable = $this->resolveTableName($fk->get('on'));
        $field = head((array) $fk->get('columns', []));
        $references = head((array) $fk->get('references', []));
        if($fkTable !== null && in_array($fkTable, $tables, true)) {
            $rules[$fkTable]['hasMany'][] = [$table, $field, $references];
        }
        if(in_array($table, $tables, true)) {
            $rules[$table]['belongsTo'][] = [$fkTable, $field, $references];
        }
    }

    private function addOneToOneRules($tables, $table, &$rules, $fk)
    {
        //$table belongsTo $FK
        //$FK hasOne $table

        $fkTable = $this->resolveTableName($fk->get('on'));
        $field = head((array) $fk->get('columns', []));
        $references = head((array) $fk->get('references', []));
        if($fkTable !== null && in_array($fkTable, $tables, true)) {
            $rules[$fkTable]['hasOne'][] = [$table, $field, $references];
        }
        if(in_array($table, $tables, true)) {
            $rules[$table]['belongsTo'][] = [$fkTable, $field, $references];
        }
    }

    private function addManyToManyRules($tables, $table, $prep, &$rules)
    {

        //$FK1 belongsToMany $FK2
        //$FK2 belongsToMany $FK1

        $foreign = $prep[$table]['foreign'];

        $fk1 = $foreign[0];
        $fk1Table = $this->resolveTableName($fk1->get('on'));
        $fk1Field = head((array) $fk1->get('columns', []));
        //$fk1References = $fk1['references'];

        $fk2 = $foreign[1];
        $fk2Table = $this->resolveTableName($fk2->get('on'));
        $fk2Field = head((array) $fk2->get('columns', []));
        //$fk2References = $fk2['references'];

        //User belongstomany groups user_group, user_id, group_id
        if($fk1Table !== null && in_array($fk1Table, $tables, true)) {
            $rules[$fk1Table]['belongsToMany'][] = [$fk2Table, $table, $fk1Field, $fk2Field];
        }
        if($fk2Table !== null && in_array($fk2Table, $tables, true)) {
            $rules[$fk2Table]['belongsToMany'][] = [$fk1Table, $table, $fk2Field, $fk1Field];
        }
    }

    //if FK is also a primary key, and there is only one primary key, we know this will be a one to one relationship
    private function detectOneToOne($fk, $primary)
    {
        if ($primary === null) {
            return false;
        }

        $primaryColumns = (array) $primary->get('columns', []);
        $foreignColumns = (array) $fk->get('columns', []);

        return count($primaryColumns) === 1
            && count($foreignColumns) === 1
            && $primaryColumns[0] === $foreignColumns[0];
    }

    //does this table have exactly two foreign keys that are also NOT primary,
    //and no tables in the database refer to this table?
    private function detectManyToMany($prep, $table)
    {
        $properties = $prep[$table];
        $foreignKeys = $properties['foreign'];
        $primary = $properties['primary'];
        $primaryColumns = $primary !== null ? (array) $primary->get('columns', []) : [];

        //ensure we only have two foreign keys
        // 只有两个外键
        if (count($foreignKeys) === 2) {
            // certificates
            // contents_comment
            // user_channel_video

            //ensure our foreign keys are not also defined as primary keys
            // 确保一个外键同时不是主键
            $primaryKeyCountThatAreAlsoForeignKeys = 0;
            foreach ($foreignKeys as $foreign) {
                foreach ((array) $foreign->get('columns', []) as $foreignColumn) {
                    if (in_array($foreignColumn, $primaryColumns, true)) {
                        ++$primaryKeyCountThatAreAlsoForeignKeys;
                    }
                }
            }

            if ($primaryKeyCountThatAreAlsoForeignKeys === 1) {
                //one of the keys foreign keys was also a primary key
                //this is not a many to many. (many to many is only possible when both or none of the foreign keys are also primary)
                return false;
            }

            //ensure no other tables refer to this one
            foreach ($prep as $compareTable => $compareProperties) {
                if ($table !== $compareTable) {
                    foreach ($compareProperties['foreign'] as $prop) {
                        if ($this->resolveTableName($prop->get('on')) === $table) {
                            return false;
                        }
                    }
                }
            }
            //this is a many to many table! 我理解为中间表
            return true;
        }

        return false;
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
