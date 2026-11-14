<?php

declare(strict_types=1);

namespace ConfigDirector\Tests;

use PHPUnit\Framework\TestCase;

final class SourceOfTheSdkTest extends TestCase
{
    private const array EXTENSIONS_EVERY_PHP_HAS = ['Core', 'standard', 'date', 'hash', 'pcre', 'random', 'SPL'];

    public function testTheSdkCallsAFunctionOfAnExtensionItDoesNotRequireOnlyWhereItChecksThatTheFunctionExists(): void
    {
        $packageFolder = dirname(__DIR__);
        $extensionsRequired = [...self::EXTENSIONS_EVERY_PHP_HAS, ...self::extensionsRequiredIn($packageFolder)];

        $uncheckedCalls = [];
        foreach (self::phpFilesOfTheSdkIn($packageFolder) as $file) {
            $source = (string) file_get_contents($file);
            foreach (self::functionsCalledIn($source) as $function) {
                $extension = function_exists($function)
                    ? (new \ReflectionFunction($function))->getExtensionName()
                    : false;
                $isOfARequiredExtension = is_string($extension) && in_array($extension, $extensionsRequired, true);
                if (!$isOfARequiredExtension && !str_contains($source, "function_exists('{$function}')")) {
                    $uncheckedCalls[] = substr($file, strlen($packageFolder) + 1) . " calls {$function}()";
                }
            }
        }

        self::assertSame([], $uncheckedCalls);
    }

    public function testThePackageRequiresA64BitPhp(): void
    {
        $composerJson = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        self::assertIsArray($composerJson);
        self::assertIsArray($composerJson['require']);
        self::assertArrayHasKey('php-64bit', $composerJson['require']);
    }

    public function testTheSourceNeverUnserializesEvaluatesOrIncludesAnything(): void
    {
        $packageFolder = dirname(__DIR__);

        $forbiddenUses = [];
        foreach (self::phpFilesOfTheSourceIn($packageFolder) as $file) {
            $source = (string) file_get_contents($file);
            $usesOfTheFile = [
                ...array_intersect(self::functionsCalledIn($source), ['unserialize', 'assert', 'extract']),
                ...self::constructsThatRunCodeIn($source),
            ];
            foreach ($usesOfTheFile as $use) {
                $forbiddenUses[] = substr($file, strlen($packageFolder) + 1) . " uses {$use}";
            }
        }

        self::assertSame([], $forbiddenUses);
    }

    /**
     * @return list<string>
     */
    private static function constructsThatRunCodeIn(string $source): array
    {
        $constructs = [];
        foreach (\PhpToken::tokenize($source) as $token) {
            if ($token->is([T_EVAL, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE])) {
                $constructs[] = strtolower($token->text);
            }
        }

        return array_values(array_unique($constructs));
    }

    /**
     * @return list<string>
     */
    private static function extensionsRequiredIn(string $packageFolder): array
    {
        $composerJson = json_decode((string) file_get_contents($packageFolder . '/composer.json'), true);
        $requirements = is_array($composerJson) && is_array($composerJson['require'] ?? null)
            ? array_keys($composerJson['require'])
            : [];

        $extensions = [];
        foreach ($requirements as $requirement) {
            if (is_string($requirement) && str_starts_with($requirement, 'ext-')) {
                $extensions[] = substr($requirement, strlen('ext-'));
            }
        }

        return $extensions;
    }

    /**
     * @return list<string>
     */
    private static function phpFilesOfTheSdkIn(string $packageFolder): array
    {
        return [$packageFolder . '/bin/configdirector-stream', ...self::phpFilesOfTheSourceIn($packageFolder)];
    }

    /**
     * @return list<string>
     */
    private static function phpFilesOfTheSourceIn(string $packageFolder): array
    {
        $files = [];
        $sourceFiles = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($packageFolder . '/src'));
        foreach ($sourceFiles as $sourceFile) {
            if ($sourceFile instanceof \SplFileInfo && $sourceFile->getExtension() === 'php') {
                $files[] = $sourceFile->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @return list<string>
     */
    private static function functionsCalledIn(string $source): array
    {
        $tokens = array_values(array_filter(
            \PhpToken::tokenize($source),
            static fn(\PhpToken $token): bool => !$token->isIgnorable(),
        ));

        $functions = [];
        foreach ($tokens as $position => $token) {
            $isAName = $token->is([T_STRING, T_NAME_FULLY_QUALIFIED]);
            $isCalled = ($tokens[$position + 1] ?? null)?->text === '(';
            $isAMethodOrADeclaration = ($tokens[$position - 1] ?? null)?->is(
                [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW],
            ) ?? false;
            if ($isAName && $isCalled && !$isAMethodOrADeclaration) {
                $functions[] = ltrim($token->text, '\\');
            }
        }

        return array_values(array_unique($functions));
    }
}
