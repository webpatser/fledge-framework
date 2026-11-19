<?php

namespace Illuminate\JsonSchema\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class DistinctArrayItems implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * Laravel's "distinct" rule does not compare array items, so this rule compares them by content.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    #[\Override]
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $seen = [];

        foreach ((array) $value as $item) {
            if (! is_array($item)) {
                continue;
            }

            if (in_array($item, $seen)) {
                $fail('validation.distinct')->translate();

                return;
            }

            $seen[] = $item;
        }
    }
}
