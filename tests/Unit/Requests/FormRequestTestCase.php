<?php

namespace Tests\Unit\Requests;

use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;

abstract class FormRequestTestCase extends TestCase
{
    /**
     * Build a validator instance for the provided data and rules.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $rules
     */
    protected function validate(array $data, array $rules): array
    {
        $translator = new Translator(new ArrayLoader(), 'en');
        $factory = new Factory($translator);

        return $factory->make($data, $rules)->errors()->toArray();
    }
}
