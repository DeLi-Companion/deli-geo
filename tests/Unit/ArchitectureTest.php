<?php

declare(strict_types=1);

arch('library code declares strict types')
    ->expect('DeLiCompanion\Geo')
    ->toUseStrictTypes();

arch('library classes are final')
    ->expect('DeLiCompanion\Geo')
    ->classes()
    ->toBeFinal();

arch('the library depends on no framework')
    ->expect('DeLiCompanion\Geo')
    ->not->toUse(['Illuminate', 'Laravel', 'Symfony']);
