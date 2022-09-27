<?php

namespace Detail\Laravel\Http;

use Carbon\Carbon;
use DateTimeInterface;
use function request;

class DateRange
{
    public const QUERY_PARAM_FROM = 'from_date';
    public const QUERY_PARAM_TO = 'to_date';

    public function __construct(
        public Carbon $from,
        public Carbon $to
    ) {
    }

    public static function fromRequest(
        string|DateTimeInterface|Carbon $defaultFrom = '-1 week',
        string|DateTimeInterface|Carbon $defaultTo = 'today',
        string|DateTimeInterface|Carbon $minDate = '-1 year',
        string|DateTimeInterface|Carbon $maxDate = 'today'
    ): self {
        $minDate = $minDate instanceof Carbon ? $minDate : Carbon::parse($minDate);
        $maxDate = $maxDate instanceof Carbon ? $maxDate : Carbon::parse($maxDate);

        // Set defaults
        /** @var array<string, Carbon> $range */
        $range = [
            self::QUERY_PARAM_FROM => $defaultFrom instanceof Carbon ? $defaultFrom : Carbon::parse($defaultFrom),
            self::QUERY_PARAM_TO => $defaultTo instanceof Carbon ? $defaultTo : Carbon::parse($defaultTo),
        ];

        // Get values from request
        foreach ($range as $key => &$value) {
            if (request()->{$key}) {
                $value = Carbon::parse(request()->{$key});
            }
        }

        // Enforce limits
        foreach ($range as &$value) {
            $value = $value->isBefore($minDate) ? $minDate : $value;
            $value = $value->isAfter($maxDate) ? $maxDate : $value;
        }

        // Swap dates if necessary
        if ($range[self::QUERY_PARAM_TO]->isBefore($range[self::QUERY_PARAM_FROM])) {
            [$range[self::QUERY_PARAM_FROM], $range[self::QUERY_PARAM_TO]] = [$range[self::QUERY_PARAM_TO], $range[self::QUERY_PARAM_FROM]];
        }

        // Create object enforcing start/end of day
        return new self($range[self::QUERY_PARAM_FROM]->startOfDay(), $range[self::QUERY_PARAM_TO]->endOfDay());
    }
}
