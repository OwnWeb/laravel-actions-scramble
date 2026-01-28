<?php

namespace Tommica\LaravelActionsScramble;

use Dedoc\Scramble\Configuration\ParametersExtractors;
use Dedoc\Scramble\Scramble;
use Illuminate\Support\ServiceProvider;

class LaravelActionsScrambleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Scramble::configure()
            ->withParametersExtractors(function (ParametersExtractors $extractors) {
                $extractors->prepend([
                    LaravelActionsParameterExtractor::class,
                ]);
            });
    }

    public function register(): void
    {
        //
    }
}
