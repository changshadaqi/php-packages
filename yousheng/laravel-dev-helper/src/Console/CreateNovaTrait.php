<?php

namespace Yousheng\LaravelDevHelper\Console;

use Illuminate\Support\Str;

/**
 * 根据数据库表结构生成 Laravel Nova 资源 trait。
 *
 * 把列类型映射为 Nova 字段（enum→Select、tinyint(1)→Boolean、
 * varchar/char/text→Text、url 类→URL），并渲染到 resource.stub。
 */
trait CreateNovaTrait
{
    /**
     * 为每张表推导 Nova 字段与需要的 use 语句。
     *
     * @param  array $tables 表名列表
     * @param  array $prep   列/主键/外键结构
     * @return array 以表名为键，含 fields 与 uses
     */
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
                $typeName=$columnDefinition->get('full_type_definition');
                if($type==="enum"){
                    // full_type_definition 可以读取枚举的值，但在nova中好象用不到
                    $fields[] = "Select::make('$name'),";
                    $uses[] = "use Laravel\\Nova\\Fields\\Select;";
                }
                if($typeName==='tinyint(1)'){
                    $fields[] = "Boolean::make('$name'),";
                    $uses[] = "use Laravel\\Nova\\Fields\\Boolean;";
                }
                if(in_array($name,['website','url','website_url'])){
                    $fields[] = "URL::make('$name'),";
                    $uses[] = "use Laravel\\Nova\\Fields\\URL;";
                }else{
                    if(in_array($type,['varchar','char','text'])){
                        $fields[] = "Text::make('$name'),";
                        $uses[] = "use Laravel\\Nova\\Fields\\Text;";
                    }
                }

                // 其它类型再补充
            }
            $fields=array_unique($fields);
            $uses=array_unique($uses);
            $rules[$table]['fields'] = $fields;
            $rules[$table]['uses'] = $uses;
        }

        return $rules;
    }

    /**
     * 渲染单个 Nova 资源：填充字段与 use 到 stub 并写入 app/Nova。
     */
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
        $fields=implode("\n\t\t\t",$tablerules["fields"]);
        $uses=implode("\n",$tablerules["uses"]);
        //3. prepare template data
        $templateData = array(
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
        $this->line("<fg=yellow>Nova资源".$resourceName." 创建成功</fg=yellow>");
    }

    private function generateNovaResources( $novaRules)
    {
        foreach ($novaRules as $table => $rules) {
            try {
                $this->generateNovaResource( $table, $rules);
            } catch(\Throwable $e) {
                $this->error( $table."生成模型时失败，原因是".$e);
                return;
            }
        }
    }
}
