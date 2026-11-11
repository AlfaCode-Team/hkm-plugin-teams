<?php

declare(strict_types=1);

namespace Tests\Plugins\Teams\Unit;

require_once __DIR__ . '/../Support/translator_stub.php';

use PHPUnit\Framework\TestCase;
use Plugins\Teams\Application\Exceptions\TeamException;
use Tests\Plugins\Teams\Support\FakeTranslator;

final class TeamExceptionTest extends TestCase
{
    protected function tearDown(): void
    {
        FakeTranslator::$lines = [];
    }

    public function test_the_envelope_code_is_the_specific_code_and_the_message_falls_back_to_it(): void
    {
        $e = TeamException::notFound('abc');

        self::assertSame('team.not_found', $e->layer, 'the kernel publishes `layer` as error.code');
        self::assertSame('team.not_found', $e->getMessage(), 'no translation loaded: the code itself');
        self::assertSame(404, $e->httpStatus());
    }

    public function test_the_message_is_the_translated_sentence_when_a_catalogue_has_one(): void
    {
        FakeTranslator::$lines['teams::errors.team.forbidden'] = 'Pas autorise.';

        $e = TeamException::forbidden('update');

        self::assertSame('Pas autorise.', $e->getMessage());
        self::assertSame('team.forbidden', $e->errorCode);
    }

    public function test_every_code_the_code_base_throws_has_a_sentence_in_both_languages(): void
    {
        $root = \dirname(__DIR__, 2);
        $codes = [];

        foreach (['Domain', 'Application'] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$root}/{$dir}", \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (preg_match_all("/['\"](team\.[a-z_.]+)['\"]/", (string) file_get_contents($file->getPathname()), $m)) {
                    array_push($codes, ...$m[1]);
                }
            }
        }
        $codes = array_unique($codes);
        // Failure fallbacks (`team.create.failed`) are ServiceException messages, not envelope codes.
        $codes = array_filter($codes, static fn (string $c): bool => !str_ends_with($c, '.failed'));

        foreach (['en', 'fr'] as $locale) {
            $lang = require "{$root}/resources/lang/{$locale}/teams.php";
            foreach ($codes as $code) {
                self::assertArrayHasKey($code, $lang['errors'], "{$locale}: no sentence for [{$code}]");
            }
        }

        self::assertGreaterThan(15, \count($codes), 'the scan found the codes');
    }
}
