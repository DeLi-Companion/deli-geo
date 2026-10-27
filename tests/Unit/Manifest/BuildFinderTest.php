<?php

declare(strict_types=1);

use DeLiCompanion\Geo\Manifest\BuildFinder;
use DeLiCompanion\Geo\Manifest\FormatVersion;
use DeLiCompanion\Geo\Tests\Support\FixtureBuild;

beforeEach(function (): void {
    $this->buildsDirectory = FixtureBuild::temporaryDirectory();
});

afterEach(function (): void {
    FixtureBuild::removeDirectory($this->buildsDirectory);
});

it('returns the newest readable build', function (): void {
    FixtureBuild::create($this->buildsDirectory, '2026-09-01', ['{}'], ['{}']);
    FixtureBuild::create($this->buildsDirectory, '2026-10-01', ['{}'], ['{}']);

    expect((new BuildFinder)->newest($this->buildsDirectory)?->buildId)->toBe('2026-10-01');
});

it('skips newer builds it cannot read', function (): void {
    FixtureBuild::create($this->buildsDirectory, '2026-09-01', ['{}'], ['{}']);
    FixtureBuild::create($this->buildsDirectory, '2026-10-01', ['{}'], ['{}'], ['format_version' => FormatVersion::CURRENT + 1]);
    mkdir($this->buildsDirectory.'/2026-10-15');

    expect((new BuildFinder)->newest($this->buildsDirectory)?->buildId)->toBe('2026-09-01');
});

it('ignores partial and unrelated entries', function (): void {
    FixtureBuild::create($this->buildsDirectory, '2026-09-01', ['{}'], ['{}']);
    mkdir($this->buildsDirectory.'/.2026-10-01.partial');
    touch($this->buildsDirectory.'/2026-10-02');

    expect((new BuildFinder)->newest($this->buildsDirectory)?->buildId)->toBe('2026-09-01');
});

it('returns null when there is no usable build', function (): void {
    expect((new BuildFinder)->newest($this->buildsDirectory))->toBeNull()
        ->and((new BuildFinder)->newest($this->buildsDirectory.'/missing'))->toBeNull();
});
