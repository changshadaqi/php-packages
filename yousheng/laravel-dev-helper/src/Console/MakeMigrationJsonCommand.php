<?php

namespace Yousheng\LaravelDevHelper\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * 从一个 JSON 文件批量生成迁移、模型、工厂与种子。
 */
class MakeMigrationJsonCommand extends Command
{
    /** @var string */
    protected $signature = 'make:migration:json
        {--file= : JSON schema 文件路径}
        {--only= : 仅生成指定的表/迁移名，逗号分隔}
        {--m|model : 生成 Model}
        {--f|factory : 生成 Factory}
        {--s|seeder : 生成 Seeder}
        {--validate : 只校验 JSON 与字段定义，不生成文件}
        {--undo : 删除上一次生成的文件}
        {--disableundo : 不生成 undo 文件}';

    /** @var string */
    protected $description = 'Create migrations, models, factories and seeders from a JSON schema';

    /** @var MakeMigrationJson */
    protected $parser;

    /** @var Filesystem */
    protected $filesystem;

    /** @var string */
    protected $undoFilePath;

    public function __construct(MakeMigrationJson $parser, Filesystem $filesystem)
    {
        parent::__construct();

        $this->parser = $parser;
        $this->filesystem = $filesystem;
    }

    public function handle()
    {
        $file = $this->option('file');

        if (! $file) {
            $this->call('help', ['command_name' => $this->getName()]);

            return 0;
        }

        $this->undoFilePath = $file.'.undo.json';

        if ($this->option('undo')) {
            return $this->undo();
        }

        if ($this->option('validate')) {
            return $this->validate($file);
        }

        return $this->makeJsonMigration($file);
    }

    protected function makeJsonMigration($file)
    {
        $data = $this->parser->jsonFileToArray($file);

        if (! is_array($data) || empty($data)) {
            $this->error('Invalid JSON/JSON5 detected or no data found in file: '.$file);

            return 1;
        }

        $only = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('only'))), 'strlen'));

        try {
            $tables = $this->parser->parseTables($data, $only);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return 1;
        }

        if (empty($tables)) {
            $this->error('No tables matched the --only option.');

            return 1;
        }

        $seeder = (bool) $this->option('seeder');
        $factory = $seeder || (bool) $this->option('factory');
        $model = $factory || (bool) $this->option('model');

        $generatedFiles = $this->generator()->generateTables($tables, $model, $factory, $seeder);

        if (empty($generatedFiles)) {
            $this->error('No generated files created');

            return 1;
        }

        $this->info('The following files have been created:');
        foreach ($generatedFiles as $generatedFile) {
            $this->info("  {$generatedFile}");
        }

        if (! $this->option('disableundo')) {
            $this->createUndoFile($generatedFiles);
        }

        return 0;
    }

    protected function createUndoFile(array $generatedFiles)
    {
        $directory = dirname($this->undoFilePath);

        if (! $this->filesystem->isWritable($directory)) {
            $this->error('Could not create undo file, not enough permissions perhaps?: '.$this->undoFilePath);

            return;
        }

        $this->filesystem->put(
            $this->undoFilePath,
            json_encode(array_values($generatedFiles), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    protected function undo()
    {
        if (! $this->filesystem->exists($this->undoFilePath)) {
            $this->error('Undo file not found: '.$this->undoFilePath);

            return 1;
        }

        $files = json_decode($this->filesystem->get($this->undoFilePath), true);
        if (! is_array($files)) {
            $this->error('Invalid undo file: '.$this->undoFilePath);

            return 1;
        }

        $deleteCompleted = true;
        $this->info('Deleting files:');

        foreach ($files as $file) {
            if (! is_string($file)) {
                continue;
            }

            $path = $this->absolutePath($file);
            if (! $this->filesystem->exists($path)) {
                continue;
            }
            if (! $this->filesystem->isWritable($path)) {
                $deleteCompleted = false;
                $this->error('Could not delete: '.$path);
                continue;
            }

            $this->filesystem->delete($path);
            $this->info("  Deleted: {$path}");
        }

        if ($deleteCompleted) {
            $this->filesystem->delete($this->undoFilePath);
        } else {
            $this->error('Undo file kept because some files could not be deleted.');
        }

        return $deleteCompleted ? 0 : 1;
    }

    /**
     * undo 文件里记录的是相对路径，删除时相对应用根目录解析。
     */
    protected function absolutePath($path)
    {
        if (preg_match('/^([a-zA-Z]:[\\\\\\/]|\\/)/', $path)) {
            return $path;
        }

        $basePath = $this->laravel && method_exists($this->laravel, 'basePath')
            ? $this->laravel->basePath()
            : getcwd();

        return rtrim($basePath, '/\\').'/'.ltrim($path, '/\\');
    }

    protected function validate($file)
    {
        $data = $this->parser->jsonFileToArray($file);

        if ($data === null) {
            $this->error('Invalid JSON/JSON5 detected: Check that your file does not contain invalid syntax: '.$file);

            return 1;
        }

        if (empty($data)) {
            $this->error('No data found in file: It seems you have no data in: '.$file);

            return 1;
        }

        $errors = $this->parser->validateSchema($data);

        if (empty($errors)) {
            $this->info('No validation errors were found, congrats!');

            return 0;
        }

        foreach ($errors as $tableName => $fields) {
            foreach ($fields as $fieldName => $properties) {
                foreach ($properties as $property) {
                    $this->error($property);
                    $this->error('In section: '.json_encode([
                        $tableName => [
                            $fieldName => isset($data[$tableName][$fieldName]) ? $data[$tableName][$fieldName] : null,
                        ],
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                }
            }
        }

        return 1;
    }

    protected function generator()
    {
        $basePath = $this->laravel && method_exists($this->laravel, 'basePath')
            ? $this->laravel->basePath()
            : getcwd();

        return new JsonMigrationGenerator($this->filesystem, $basePath);
    }
}
