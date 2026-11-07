<?php

namespace Yousheng\LaravelDevHelper\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 从已有的 MySQL 数据库反向生成一个整体迁移文件（db:migrate）。
 *
 * 直接查 information_schema 读取列/默认值/注释/外键，把每张表拼成
 * Schema::create(...) 语句，汇总写入单个 migration 文件。表会按外键
 * 依赖排序，保证被引用的主表先创建。
 */
class MigrationsFromDatabaseCommand extends Command
{
    use TableHelpers;

    /**
     * The console command name.
     * example: php artisan convert:migrations --ignore="table1, table2"
     * example: php artisan migrate:database yousheng_0he1_com --only="option,sells"
     *
     * @var string
     */
    protected $signature = 'db:migrate {database} {--ignore=}  {--only=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'create migrations files from an existing MySQL database';

    protected static $ignoreTabels = ['migrations'];

    protected static $selects = array('column_name as Field', 'column_type as Type', 'is_nullable as Null', 'column_key as Key', 'column_default as Default', 'extra as Extra', 'data_type as Data_Type','column_comment as Column_Comment');//增加说明部分
    protected static $schema = [];
    protected static $type2method = [
        'PRI' => 'increments',
        "int:length" => "interger",
    ];
    protected static $sortedTables = [];

    // todo 增加表说明部分
    protected static $templateGetTableComment = "SELECT TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_NAME='%s' AND TABLE_SCHEMA='%s'";



    protected static $templateGetForeignTables = "SELECT TABLE_NAME,REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA='%s' AND REFERENCED_TABLE_SCHEMA='%s'";
    protected static $templateGetForeignConstraint = <<<EOT
SELECT
	a_.CONSTRAINT_NAME,
	a_.TABLE_NAME,
	a_.COLUMN_NAME,
	a_.REFERENCED_TABLE_NAME,
	a_.REFERENCED_COLUMN_NAME,
	b_.UPDATE_RULE,
	b_.DELETE_RULE
FROM
	information_schema.`KEY_COLUMN_USAGE` AS a_
INNER JOIN
	information_schema.REFERENTIAL_CONSTRAINTS AS b_
ON a_.CONSTRAINT_NAME = b_.CONSTRAINT_NAME AND a_.CONSTRAINT_SCHEMA=b_.CONSTRAINT_SCHEMA AND a_.TABLE_NAME = b_.TABLE_NAME
WHERE a_.CONSTRAINT_SCHEMA='%s' AND a_.TABLE_NAME = '%s' ;
EOT;

    protected static $templateSchema = <<<EOT
<?php

/**
 * ----------------------------------------------------------------------
 * 数据库迁移助手.功能升级中，bug请直接在加qq                                                                                  |
 * ----------------------------------------------------------------------
 * Author: daqi <2922800186@qq.com>
 * ----------------------------------------------------------------------
 * Date: 数据库迁移文件生成时间 %s
 * ----------------------------------------------------------------------
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class %s extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        %s
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        %s
    }
}

EOT;


    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * 执行命令：选表 → 读取列与外键 → 拼接迁移代码 → 写入单一迁移文件。
     *
     * @return mixed
     */
    public function handle()
    {
        // 数据库名和排除的表
        $databaseName = $this->argument('database');
        $optionIgnore = $this->option('ignore');
        $optionOnly = $this->option('only');  // 只提取某些表

        // 提取哪些表需要生成迁移文件
        $migrateTables = collect(DB::select(sprintf(static::$templateGetTables, $databaseName)))->pluck('TABLE_NAME')->all();   // 所有表

        if($optionOnly){
        	$onlyTables =  explode(',', str_replace(' ', '', $optionOnly));       //  only优先级高于ignore
            $onlyFilteredTables = collect($onlyTables)->filter(function($tableName){
                if(!\Illuminate\Support\Facades\Schema::hasTable($tableName)){    // 如果指定的表不存在，报错
                    $this->warn("table `$tableName` you selected doesn`t exists");
                }
                return \Illuminate\Support\Facades\Schema::hasTable($tableName);
            })->all();
            if(count($onlyFilteredTables)===0){
                $this->comment('No table migrated');
                return;
            }
            $migrateTables=$onlyFilteredTables;   // 只迁移指定的且存在的表
        	$optionIgnore = null;
        }

        if($optionIgnore){
        	$ignoreTables = array_merge(static::$ignoreTabels, explode(',', str_replace(' ', '', $optionIgnore)));           // 忽略的表
        	$migrateTables = array_diff($migrateTables, $ignoreTables);
        }


        // 排除migrations表
        $migrateTables = collect($migrateTables)->reject(function($table){
            return $table==='migrations';
        })->all();
        
        // 迁移表数量统计
        if ($countMigrate = count($migrateTables)) {
            $this->info("\n".count($migrateTables) . ' tables will be writed into migration file,include:');
            if($countMigrate>0){
	            foreach ($migrateTables as  $value) {
	            	$this->info("\t TABLE: '$value'");
	            }
            }
        }
        // 所有外键表
        $tableForeigns = collect(DB::select(sprintf(static::$templateGetForeignTables, $databaseName, $databaseName)))->mapWithKeys(function ($item)  {
			return [$item->TABLE_NAME => $item->REFERENCED_TABLE_NAME];
		// 2020-11-14 只需要当前迁移表的主表
        })->filter(function($item,$index) use($migrateTables){
			return in_array($index,$migrateTables);
        })->all();

        if ($countForeign = count($tableForeigns)>0) {
            $this->info($countForeign . ' FOREIGN TABLES  will be writed into migration file，include:');
            foreach ($tableForeigns as $table=>$foreign) {
            	$this->info("\t FOREIGN TABLE: ".$foreign."[ for '".$table."'']");
            }
        }

        // 所有表排序
        $migrateTables = $this->sortTable($migrateTables, $tableForeigns);

        if(count($migrateTables)===0){
            $this->comment('No table migrated');
            return;
        }

        foreach ($migrateTables as $table) {
            $downStack[] = $table;
            $down = "        Schema::dropIfExists('{$table}');";
            $up = "        Schema::create('{$table}', function(Blueprint \$table) {\n";
            $tableDescribes = DB::table('information_schema.columns')->where('table_schema', $databaseName)->where('table_name', $table)->get(static::$selects)->all();
            $tableDescribes = array_map(function ($value) {
                return (array)$value;
            }, $tableDescribes);


            // todo 增加表说明部分
            $tableComment = DB::table('information_schema.TABLES')->where('table_schema', $databaseName)->where('table_name', $table)->select('TABLE_COMMENT')->first();
            foreach ($tableDescribes as $values) {
                extract($values);
                // $Field $Type $Null $Key $Extra $Data_Type
                $method = "";
                $type = Str::before($Type, '('); // int(10),  smallint(5) unsigned
                $numbers = "";
                $nullable = $Null == "NO" ? "" : "->nullable()";
                // 2021-10-30 处理，默认时间值为CURRENT_TIMESTAMP的情况，将它改为
                if($Default==='CURRENT_TIMESTAMP'){
                    $default = "->useCurrent()";
                }elseif(is_null($Default)){
                    $default="";
                }elseif($type==='int' && (string)$Default==='0'){
                    $default = "->default({$Default})";
                }else{
                    $default = "->default(\"{$Default}\")";
                }
                $unsigned = strpos($Type, "unsigned") === false ? '' : '->unsigned()';
                $unique = $Key == 'UNI' ? "->unique()" : "";
                $comment = empty($Column_Comment) ? "" : "->comment(\"{$Column_Comment}\")";

                $choices = '';
                switch ($type) {
                    case 'enum':
                        $method = 'enum';
                        $choices = preg_replace('/enum/', 'array', $Type);
                        $choices = ", $choices";
                        break;
                    case 'int' :
                        $method = 'unsignedInteger';
                        break;
                    case 'bigint' :
                        $method = 'bigInteger';
                        break;
                    case 'mediumint' :
                        $method = 'mediumInteger';
                        break;
                    case 'smallint' :
                        $method = 'smallInteger';
                        break;
                    case 'tinyint' :
                        if ($Type == 'tinyint(1)') {
                            $method = 'boolean';
                        } else {
                            $method = 'tinyInteger';
                        }
                        break;
                    case 'char' :
                    case 'varchar' :
                        $numbers = ", " . substr($Type, strpos($Type, '(') + 1, -1);
                        $method = 'string';
                        break;
                    case 'float' :
                        $method = 'float';
                        break;
                    case 'decimal' :
                        $numbers = ", " . substr($Type, strpos($Type, '(') + 1, -1);
                        $method = 'decimal';
                        break;
                        // 2021-10-29加
                    case 'double' :
                        $numbers = ", " . substr($Type, strpos($Type, '(') + 1, -1);
                        $method = 'double';
                        break;    

                    case 'date':
                        $method = 'date';
                        break;
                    case 'timestamp' :
                        $method = 'timestamp';
                        break;
                    case 'datetime' :
                        $method = 'dateTime';
                        break;
                    case 'mediumtext' :
                        $method = 'mediumtext';
                        break;
                    case 'text' :
                        $method = 'text';
                        break;
                    case 'longtext' :
                        $method = 'longText';
                        break;
                }
                if ($Key == 'PRI') {
                    $method = 'increments';
                }
                $up .= "            $" . "table->{$method}('{$Field}'{$choices}{$numbers}){$nullable}{$default}{$unsigned}{$unique}{$comment};\n";
            }

            // 如果当前表有外键，获取相应外键信息
            if (array_key_exists($table, $tableForeigns)) {
                $foreignInfo = collect(DB::select(sprintf(static::$templateGetForeignConstraint, $databaseName, $table)))->all();
                foreach ($foreignInfo as $v) {
                    $foreign = $v->COLUMN_NAME;
                    $references = $v->REFERENCED_COLUMN_NAME;
                    $on = $v->REFERENCED_TABLE_NAME;
                    $onDelete = $v->DELETE_RULE;
                    $onUpdate = $v->UPDATE_RULE;
                    $up .= "            $" . "table->foreign('{$foreign}')->references('{$references}')->on('{$on}')";
                    $up .= $onDelete ? "->onDelete('" . $onDelete . "')" : '';
                    $up .= $onUpdate ? "->onUpdate('" . $onUpdate . "');" : ';';
                }

            }

            $up .= "        });\n\n";

            // table comment
            if ($tableComment && $tComment = $tableComment->TABLE_COMMENT) {
                $up .= "        DB::statement(\"ALTER TABLE `{$table}` comment = '{$tComment}'\");\n";
            }

            self::$schema[$table] = array(
                'up' => $up,
                'down' => $down
            );


        }

        $schema = static::compileSchema($databaseName);
        $filename = date('Y_m_d_His') . "_create_" . str_replace(['_', '.'], '', Str::snake($databaseName)) . "_database.php";
        //$path = app()->databasePath() . '/migrations/';
        app('files')->ensureDirectoryExists($path = app()->databasePath() . '/migrations/');
        file_put_contents($path . $filename, $schema);
        $this->comment('Migration Created Successfully');
        $this->info("\tMigration file is  ".$path . $filename);
    }


    /**
     * 把缓存的逐表 schema 拼接成完整的迁移类代码（基于 $templateSchema）。
     */
    private static function compileSchema($databaseName)
    {
        static $number = 1;
        $upSchema = "";
        $downSchema = "";
        $newSchema = "";
        $tab=$number===1?"":"        ";
        foreach (self::$schema as $name => $values) {
            $upSchema .=  $tab."// --------------------------------{$number}------------------{$name}\n{$values['up']}";
            if ($values['down'] !== "") {
                $downSchema .= "\n{$values['down']}";
            }
            $number++;
        }
        $_className = "Create" . str_replace(['_', '.'], '', Str::title($databaseName)) . "Database";
        $_createDate = date("Y-m-d H_i_s");
        $_up = $upSchema;
        $_down = $downSchema;
        return sprintf(static::$templateSchema, $_createDate, $_className, $_up, $_down);
    }

    /**
     * 按外键依赖排序：无外键依赖的表在前，被引用表（主表）放在引用它的子表之前。
     *
     * @param $original 原始数组
     * @param $associate 关联数组，键依赖于值，所以键得放在值后面
     * @return array
     */
    private function sortTable($original, $associate)
    {
        $one=array_diff($original,array_keys($associate));
        $two =[];
        foreach ($associate as $key=>$value){
            if(in_array($value,$one)) continue;
            if(in_array($value,$two)) {
                $two[]=$key;
            }else{
                $two[]=$value;
                $two[]=$key;
            }
        }
        return array_merge($one,$two);
    }
}
