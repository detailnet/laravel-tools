<?php

namespace Detail\Laravel\Validators;

use Closure;
use Detail\Laravel\Models\Model;
use Illuminate\Contracts\Validation\InvokableRule; // Laravel 9.x
use function is_int;
use function is_string;
use function preg_match;
use function sprintf;

// use Illuminate\Contracts\Validation\ValidationRule; Laravel 10.x

class SortIndex implements InvokableRule
{
    public const DRAG_AND_DROP_POSITION_REGEX = '/^(?<position>after|before):(?<uuid>' . Model::UUID_V4_PATTERN . ')$/';
    public const DRAG_AND_DROP_RULE = ['string', 'regex:' . self::DRAG_AND_DROP_POSITION_REGEX]; // Legacy rule

    /**
     * @param  string  $attribute
     * @param  mixed  $value
     * @param  Closure  $fail
     * @return void
     */
    public function __invoke($attribute, $value, $fail) // Laravel 9.x
//  public function validate(string $attribute, mixed $value, Closure $fail): void // Laravel 10.x
    {
        if (!is_int($value)
            && !(is_string($value) && preg_match(self::DRAG_AND_DROP_POSITION_REGEX, $value) === 1)
        ) {
            $fail(
                sprintf(
                    'The :attribute must be a string following regex "%s" or an integer number.',
                    self::DRAG_AND_DROP_POSITION_REGEX
                )
            );
        }
    }
}
