<?php

namespace Detail\Laravel\Http\Traits;

use Detail\Laravel\Models\Model;
use Illuminate\Support\Facades\Log;
use Throwable;
use function array_diff_key;
use function array_filter;
use function array_flip;
use function class_basename;
use function in_array;
use function sprintf;
use function substr;

trait ModelLogging
{
    /**
     * @param Model|class-string $model
     */
    protected function logPersisted(string $action, $model, array $data): void
    {
        Log::info(
            sprintf('%s %s', class_basename($model), $this->simplePastForm($action)),
            array_filter(
                [
                    'input' => $this->clearInputData($data, $model),
                    'model' => $model instanceof Model ? $model->onlySortedFields() : [], // Sorted fields are those we care about
                    //'model' => $model instanceof Model ? $model->toArray() : []
                ],
                'count' // Filter out empty arrays
            )
        );
    }

    private function simplePastForm(string $infinitive): string
    {
        // Can be done excessively: https://www.codeproject.com/Tips/5293151/English-Simple-Past-Past-Participle-PHP-Auto-GenerCould
        // But we can do best effort ... handling 'add','register' and 'refresh'
        return $infinitive . (in_array(substr($infinitive, -1), ['d', 'r', 'h']) ? 'ed' : 'd');
    }

    /**
     * @param Model|class-string $model
     */
    private function clearInputData(array $data, $model): array
    {
        if (!$model instanceof Model) {
            return $data;
        }

        return array_diff_key($data, array_flip($model->getGuarded())); // Strip out guarded fields like passwords
    }

    /**
     * @param Model|class-string $model
     */
    protected function logError(string $action, Throwable $e, $model, array $data): void
    {
        $message = sprintf('Failed to %s %s: %s', $action, class_basename($model), $e->getMessage());
        $context = [
            'input' => $this->clearInputData($data, $model),
            'previous' => [],
        ];

        while (($e = $e->getPrevious()) !== null) {
            $context['previous'][] = $e->getMessage();
        }

        Log::error($message, array_filter($context, 'count')); // Filter out empty arrays
    }
}
