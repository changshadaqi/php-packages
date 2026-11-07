<?php
namespace Yousheng\LaravelDevHelper\Console;

use DB;
use Illuminate\Support\Str;

/**
 * 早期版本的“数据库→迁移”转换工具（链式调用风格）。
 *
 * 与 MigrationsFromDatabaseCommand 功能重叠，属于遗留实现：通过
 * convert()->ignore()->write() 这样链式 API 把库表转为迁移代码。
 * 目前命令层已不再调用此类，仅保留供参考。
 */
class SqlMigrations
{
    private static $ignore = array('migrations');  // ignored tables
    private static $database = "";
    private static $migrations = false;
    private static $schema = array();
    // 从 information_schema.columns 读取的列属性字段。
    private static $selects = array('column_name as Field', 'column_type as Type', 'is_nullable as Null', 'column_key as Key', 'column_default as Default', 'extra as Extra', 'data_type as Data_Type');
    private static $instance;
    private static $up = "";
    private static $down = "";







    // 读取单张表的列描述（来自 information_schema.columns）。
    private static function getTableDescribes($table)
    {
        return DB::table('information_schema.columns')
                ->where('table_schema', '=', self::$database)
                ->where('table_name', '=', $table)
                ->get(self::$selects);
    }
 
    // 查询库中所有“作为外键引用别表”的表名（用于后续补充约束）。
    private static function getForeignTables()
    {
        return DB::table('information_schema.KEY_COLUMN_USAGE')
                ->where('CONSTRAINT_SCHEMA', '=', self::$database)
                ->where('REFERENCED_TABLE_SCHEMA', '=', self::$database)
                ->select('TABLE_NAME')->distinct()
                ->get();
    }
 
    // 读取指定表的全部外键定义（列、引用表、引用列）。
    private static function getForeigns($table)
    {
        return DB::table('information_schema.KEY_COLUMN_USAGE')
                ->where('CONSTRAINT_SCHEMA', '=', self::$database)
                ->where('REFERENCED_TABLE_SCHEMA', '=', self::$database)
                ->where('TABLE_NAME', '=', $table)
                ->select('COLUMN_NAME', 'REFERENCED_TABLE_NAME', 'REFERENCED_COLUMN_NAME')
                ->get();
    }
 
    // 把逐表缓存的 up/down 拼接成完整的迁移类源码。
    private static function compileSchema()
    {
        $upSchema = "";
        $downSchema = "";
        $newSchema = "";
        foreach (self::$schema as $name => $values) {
            if (in_array($name, self::$ignore)) {
                continue;
            }
            $upSchema .= "
//
// NOTE -- {$name}
// --------------------------------------------------
 
{$values['up']}";
            if ( $values['down'] !== "" ) {
            $downSchema .= "
{$values['down']}";
            }
        }
 
        $schema = "<?php
 
//
// NOTE Migration Created: " . date("Y-m-d H:i:s") . "
// --------------------------------------------------
 
class Create" . str_replace('_', '', Str::title(self::$database)) . "Database {
//
// NOTE - Make changes to the database.
// --------------------------------------------------
 
public function up()
{
" . $upSchema . "
" . self::$up . "
}
 
//
// NOTE - Revert the changes to the database.
// --------------------------------------------------
 
public function down()
{
" . $downSchema . "
" . self::$down . "
}
}";
 
        return $schema;
    }
 
    public function up($up)
    {
        self::$up = $up;
        return self::$instance;
    }
 
    public function down($down)
    {
        self::$down = $down;
        return self::$instance;
    }
    public function ignore($tables)
    {
        self::$ignore = array_merge($tables, self::$ignore);
        return self::$instance;
    }
 
    public function migrations()
    {
        self::$migrations = true;
        return self::$instance;
    }
 
    public function write()
    {
        $schema = self::compileSchema();
        $filename = date('Y_m_d_His') . "_create_" . self::$database . "_database.php";
        $path = app()->databasePath().'/migrations/';
        file_put_contents($path.$filename, $schema);
    }
 
    public function get()
    {
        return self::compileSchema();
    }
 
    /**
     * 转换入口：逐表生成 Schema::create 语句并缓存，随后补充外键约束。
     *
     * 注意：下方 foreach ($tables ...) 依赖的 $tables 在本版本中未赋值，
     * 属于遗留代码；如需启用，应先补充“读取库中所有表”的逻辑。
     */
    public function convert($database)
    {
        $downStack = array();
        self::$instance = new self();
        self::$database = $database;
        // desc tablename;
        $table_headers = array('Field', 'Type', 'Null', 'Key', 'Default', 'Extra');

        foreach ($tables as $key => $value) {
            if (in_array($value->TABLE_NAME, self::$ignore)) {
                continue;
            }
 
            $downStack[] = $value->TABLE_NAME;
            $down = "Schema::dropIfExists('{$value->TABLE_NAME}');";
            $up = "Schema::create('{$value->TABLE_NAME}', function(Blueprint \$table) {\n";
            $tableDescribes = self::getTableDescribes($value->TABLE_NAME);
            foreach ($tableDescribes as $values) {
                $method = "";
                $para = strpos($values->Type, '(');
                $type = $para > -1 ? substr($values->Type, 0, $para) : $values->Type;
                $numbers = "";
                $nullable = $values->Null == "NO" ? "" : "->nullable()";
                $default = empty($values->Default) ? "" : "->default(\"{$values->Default}\")";
                $unsigned = strpos($values->Type, "unsigned") === false ? '' : '->unsigned()';
                $unique = $values->Key == 'UNI' ? "->unique()" : "";
                $choices = '';
                switch ($type) {
                    case 'enum':
                        $method = 'enum';
                        $choices = preg_replace('/enum/', 'array', $values->Type);
                        $choices = ", $choices";
                        break;
                    case 'int' :
                        $method = 'unsignedInteger';
                        break;
                    case 'bigint' :
                        $method = 'bigInteger';
                        break;
                    case 'samllint' :
                        $method = 'smallInteger';
                        break;
                    case 'char' :
                    case 'varchar' :
                        $para = strpos($values->Type, '(');
                        $numbers = ", " . substr($values->Type, $para + 1, -1);
                        $method = 'string';
                        break;
                    case 'float' :
                        $method = 'float';
                        break;
                    case 'decimal' :
                        $para = strpos($values->Type, '(');
                        $numbers = ", " . substr($values->Type, $para + 1, -1);
                        $method = 'decimal';
                        break;
                    case 'tinyint' :
                        if ($values->Type == 'tinyint(1)') {
                            $method = 'boolean';
                        } else {
                            $method = 'tinyInteger';
                        }
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
                }
                if ($values->Key == 'PRI') {
                    $method = 'increments';
                }
                $up .= " $" . "table->{$method}('{$values->Field}'{$choices}{$numbers}){$nullable}{$default}{$unsigned}{$unique};\n";
            }
 
            $up .= " });\n\n";
            self::$schema[$value->TABLE_NAME] = array(
                'up' => $up,
                'down' => $down
            );
        }

 
        // add foreign constraints, if any
        $tableForeigns = self::getForeignTables();
        if (sizeof($tableForeigns) !== 0) {
            foreach ($tableForeigns as $key => $value) {
                $up = "Schema::table('{$value->TABLE_NAME}', function($" . "table) {\n";
                $foreign = self::getForeigns($value->TABLE_NAME);
                foreach ($foreign as $k => $v) {
                    $up .= " $" . "table->foreign('{$v->COLUMN_NAME}')->references('{$v->REFERENCED_COLUMN_NAME}')->on('{$v->REFERENCED_TABLE_NAME}');\n";
                }
                $up .= " });\n\n";
                self::$schema[$value->TABLE_NAME . '_foreign'] = array(
                    'up' => $up,
                    'down' => ( ! in_array($value->TABLE_NAME, $downStack) ) ? $down : "",
                );
            }
        }
 
        return self::$instance;
    }
}
