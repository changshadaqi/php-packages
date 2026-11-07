<?php
namespace Yousheng\LaravelDevHelper\Console;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\ForeignKeyDefinition;
use Illuminate\Database\Schema\IndexDefinition;
use Illuminate\Support\Str;

/**
 * 数据库表结构读取 trait。
 *
 * 基于 Laravel Schema 反射当前连接，一次性把每张表的列、索引、
 * 主键、外键缓存到静态属性，供模型/Nova 生成器复用。默认会过滤掉
 * Laravel/Nova 内置表（见 $defualtIgnoreTables）。
 */
trait ColumnsTrait
{
    // 下列静态属性以表名为键缓存 schema，init() 后全局复用，避免重复查库。
    private static $clumns=[];
    private static $foreignKeys=[];
    private static $primaryKeys=[];
    private static $indexes=[];
    private static $tables=[];

    public $initialized=false;

    // 默认忽略的框架内置表（无需为其生成模型/迁移）。
    private static $defualtIgnoreTables=[
        'nova_field_attachments',
        'nova_notifications',
        'nova_pending_field_attachments',
        'password_reset_tokens',
        'action_events',
        'cache',
        'cache_locks',
        'failed_jobs',
        'jobs',
        'job_batches',
        'migrations',
        'sessions',
        'users'
    ];


    // 读取所有要处理的表，已过滤掉laravel内置的表
//    private function getTables() {
//        if(!static::$tables){
//            $tables = collect(app('db.schema')->getTables())->map(function($table){
//                return $table['name'];
//            })->all();
//            $onlyTables=[];
//            $optionIgnore = $this->option('ignore');
//            $optionOnly = $this->option('only');
//            if($optionIgnore && $optionOnly){
//                $this->info("ignore 和 only 二选一，默认为only");
//                $optionIgnore=null;
//            }
//            if($optionOnly){
//                $onlyTables =  explode(',', $optionOnly);
//            }
//            if(!empty($onlyTables)){
//                $tables = array_intersect($tables,$onlyTables);
//                $optionIgnore=null;
//            }
//            if($optionIgnore){
//                $ignoreTables =  explode(',', $optionIgnore[0]);
//            }
//            if(!empty($ignoreTables)){
//                $tables = array_diff($tables,$ignoreTables);
//            }
//            $this->info("待处理的表有".count($tables)."个，分别是:\n    ".implode("\n    ",$tables));
//            // action_events,cache,cache_locks,failed_jobs,jobs,job_batches,migrations,sessions,users
//            static::$tables = collect($tables)->diff(static::$defualtIgnoreTables)->values()->all();
//        }
//        // 初始化所有表的 columns,indexes,primary key ,foreign keys
//        $this->init(static::$tables);
//        return static::$tables;
//    }

    public function getTables()
    {
        $this->init();
        return static::$tables;
    }

    // 所有表并附带有主键,外键，索引等信息
    public function getTablesWithKeys()
    {
        $this->init();
        return collect(static::$tables)->mapWithKeys(function($item,$key){
            return [$item=>[
                "indexes"=>static::$indexes[$item],
                "foreignkeys"=>static::$foreignKeys[$item],
                "primarykeys"=>static::$primaryKeys[$item],
            ]];
        });
    }
    public function getAllPrimaryKeys()
    {
        $this->init();
        return static::$primaryKeys;
    }
    public function getAllPrimaryKes($table)
    {
        return $this->getAllPrimaryKeys()[$table];
    }
    public function getAllForeignKeys()
    {
        $this->init();
        return static::$foreignKeys;
    }
    public function getAllForeignKey($table)
    {
        return $this->getAllForeignKeys()[$table];
    }
    public function getAllColumns()
    {
        $this->init();
        return static::$clumns;
    }
    public function getAllColumn($table)
    {
        return $this->getAllColumns()[$table];
    }
    public function getAllIndexes()
    {
        $this->init();
        return static::$indexes;
    }
    public function getAllIndex($table)
    {
        return $this->getAllIndexes()[$table];
    }
    /**
     * 懒加载初始化：首次调用时读取并缓存所有表及其列/索引/主键/外键。
     * 已初始化则直接返回，保证幂等。
     *
     * @return bool
     */
    private function init()
    {
        if($this->initialized){
            return true;
        }
        $schema = app('db.schema');
        // 1.获取所有表
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
        // action_events,cache,cache_locks,failed_jobs,jobs,job_batches,migrations,sessions,users
        static::$tables = collect($tables)->diff(static::$defualtIgnoreTables)->values()->all();
        $this->info("待处理的表有".count(static::$tables)."个，分别是:\n    ".implode("\n    ",static::$tables));

        // 2：遍历所有表，读取columns,索引，外键，主键等信息
        collect(array_flip(static::$tables))->map(function ($key,$table){
            $schema=app('db.schema');
            static::$clumns[$table] = collect($schema->getColumns($table))->map(fn ($column) => new ColumnDefinition([
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

            static::$indexes[$table] = $indexes->all();
            static::$primaryKeys[$table] = $primary->first();

            static::$foreignKeys[$table]=collect($schema->getForeignKeys($table))->map(fn ($foreignKey) => new ForeignKeyDefinition([
                'columns' => $foreignKey['columns'],
                'on' => new Expression($foreignKey['foreign_table']),
                'references' => $foreignKey['foreign_columns'],
                'onUpdate' => $foreignKey['on_update'],
                'onDelete' => $foreignKey['on_delete'],
            ]))->all();

        });
        return $this->initialized=true;
    }




    // ================================= 下面删除
    private function getColumnsPrimaryAndForeignKeysPerTable($tables)
    {

        $all= collect(array_flip($tables))->map(function ($key,$table)  {
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
                'index' => $indexes,
                'foreign' => $_foreignKeys,
                'primary' => $_primaryKey,
                'columns' => $_columns
            ];
        })->all();
        return $all;




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




}