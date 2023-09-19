<?php

namespace Detail\Laravel\Console;

use Detail\Laravel\Models\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Jenssegers\Mongodb\Collection;
use Jenssegers\Mongodb\Schema\Blueprint;
use MongoDB\Collection as MongoCollection;
use MongoDB\Driver\Cursor;
use ReflectionClass;
use stdClass;
use function array_filter;
use function array_intersect;
use function assert;
use function base_path;
use function count;
use function explode;
use function implode;
use function is_array;
use function is_string;
use function sprintf;

class CreateIndexes extends Command
{
    protected $signature =
        'db:create-indexes'
        . ' {--models= : Optional, comma separated list of models (complete namespace) to process, if none provided all are processed}'
        . ' {--renew-all : Optional, drop current indexes and rebuild from scratch (DANGEROUS!)}';
    protected $description = 'Create DB indexes';

    public function handleCommand(): bool
    {
        $this->info('Create DB indexes ...', log: false);

        $models = $this->getModels();
        $optionModels = $this->option('models') ?? [];
        $removeFirst = (bool) $this->option('renew-all');

        if (is_string($optionModels)) {
            $optionModels = array_filter(explode(',', $optionModels));
        }

        assert(is_array($optionModels));

        if (count($optionModels) > 0) {
            if (count(array_intersect($models, $optionModels)) !== count($optionModels)) {
                $this->error(sprintf('Provided models "%s" do not exist!', implode('", "', $optionModels)));
                $this->warn('Models with empty collections are reported as non-existent!');
                $this->info(sprintf('Select any of: "%s".', implode('", "', $models)));

                return false;
            }

            $models = $optionModels;
        }

        foreach ($models as $modelClass) {
            /** @var Model $model */
            $model = new $modelClass();
            $collectionName = $model->getTable();

            $this->info(sprintf('Processing collection "%s" (%s) ...', $collectionName, $modelClass));

            if ($removeFirst) {
                /** @var Cursor $currentIndexes */
                $currentIndexes = DB::connection('mongodb')->table($collectionName)->raw(
                    static function (Collection $collection) {
                        /** @var MongoCollection $collection */

                        return $collection->aggregate( // @phpstan-ignore-line Ignoring at present
                            [
                                ['$indexStats' => new stdClass()],
                            ],
                            ['allowDiskUse' => true]
                        );
                    }
                );

                Schema::connection('mongodb')
                    ->table(
                        $collectionName,
                        function (Blueprint $collection) use ($collectionName, $currentIndexes): void {
                            foreach ($currentIndexes->toArray() as $index) {
                                if ($index->name === '_id_') {
                                    continue; // Index '_id' can't be dropped
                                }

                                $collection->dropIndex($index->name);

                                $this->info(sprintf('Dropped index "%s.%s"', $collectionName, $index->name));
                            }
                        }
                    );
            }

            $this->outputLog = true;
            $model->ensureIndexes();
            $this->outputLog = false;
        }

        $this->info('DB indexes processed!', log: false);

        return true;
    }

    /**
     * @return class-string[]
     */
    function getModels(): array
    {
        return collect(File::allFiles(base_path('src/Models')))
            ->map(function ($item) {
                $path = $item->getRelativePathName();

                return sprintf('\App\Models\%s',
                    strtr(substr($path, 0, strrpos($path, '.') ?: null), '/', '\\'));
            })
            ->filter(function ($class) {
                if (class_exists($class)) {
                    $reflection = new ReflectionClass($class);

                    if ($reflection->isSubclassOf(Model::class) && !$reflection->isAbstract()) {
                        /** @var Model $model */
                        $model = new $class();

                        return $model->exists(); // Only models that have a real persisted collection
                        // @todo If a collection is empty, does not exists
                    }
                }

                return false;
            })->toArray();
    }
}
