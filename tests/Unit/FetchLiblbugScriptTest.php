<?php

declare(strict_types=1);

namespace Ladybug\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class FetchLiblbugScriptTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private const MOCK_COMMAND = <<<'SH_WRAP'
        #!/bin/sh
        case "${0##*/}" in
            uname)
                case "$1" in
                    -s) printf 'Linux\n' ;;
                    -m) printf 'x86_64\n' ;;
                    *) exit 2 ;;
                esac
                ;;
            curl)
                while [ "$#" -gt 0 ]; do
                    if [ "$1" = '-o' ]; then
                        : > "$2"
                        exit 0
                    fi
                    shift
                done
                exit 2
                ;;
            sha256sum)
                printf '%s  %s\n' "$LIBLBUG_SHA256" "$1"
                ;;
            tar)
                case "$1" in
                    tzf) printf 'liblbug.so\n' ;;
                    xzf) : > "$4/liblbug.so" ;;
                    *) exit 2 ;;
                esac
                ;;
            *) exit 2 ;;
        esac
        SH_WRAP;

    public function testReadingFromStdinUnpacksIntoTheCurrentDirectory(): void
    {
        $testDirectory = sys_get_temp_dir() . '/ladybug-fetch-' . bin2hex(random_bytes(6));
        $mockBin = $testDirectory . '/bin';
        $workingDirectory = $testDirectory . '/work';

        try {
            if (!mkdir($mockBin, 0o777, true) || !mkdir($workingDirectory)) {
                self::fail("Could not create temporary test directory {$testDirectory}.");
            }

            foreach (['curl', 'sha256sum', 'tar', 'uname'] as $command) {
                $path = $mockBin . '/' . $command;
                if (file_put_contents($path, self::MOCK_COMMAND) === false || !chmod($path, 0o755)) {
                    self::fail("Could not create the mocked {$command} command.");
                }
            }

            $environment = getenv();
            if (!\is_array($environment)) {
                self::fail('Could not read environment variables for the test process.');
            }

            $environment['PATH'] = $mockBin . PATH_SEPARATOR . ($environment['PATH'] ?? '');
            $environment['PWD'] = $workingDirectory;
            $environment['LIBLBUG_SHA256'] = 'mocked-sha256';
            unset($environment['BASH_ENV']);

            $script = file_get_contents(self::ROOT . '/tools/fetch-liblbug.sh');
            self::assertIsString($script);

            [$exitCode, $stdout, $stderr] = $this->runFromStdin($script, $workingDirectory, $environment);

            self::assertSame(0, $exitCode, "stdout:\n{$stdout}\nstderr:\n{$stderr}");
            self::assertFileExists($workingDirectory . '/lib/liblbug.so');
            self::assertDirectoryDoesNotExist($testDirectory . '/lib');
            self::assertStringContainsString('Unpacked into ' . $workingDirectory . '/lib:', $stdout);
        } finally {
            $this->removeDirectory($testDirectory);
        }
    }

    /**
     * @param array<string, string> $environment
     * @return array{int, string, string}
     */
    private function runFromStdin(string $script, string $workingDirectory, array $environment): array
    {
        $pipes = [];
        $process = proc_open(
            ['bash', '-s', '0.19.1', '--static'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $workingDirectory,
            $environment,
        );
        if (!\is_resource($process)) {
            self::fail('Could not start Bash for the stdin execution test.');
        }

        try {
            $bytesWritten = fwrite($pipes[0], $script);
            fclose($pipes[0]);
            unset($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            unset($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            unset($pipes[2]);
            $exitCode = proc_close($process);
            $process = false;

            self::assertSame(\strlen($script), $bytesWritten);
            self::assertIsString($stdout);
            self::assertIsString($stderr);

            return [$exitCode, $stdout, $stderr];
        } finally {
            foreach ($pipes as $pipe) {
                if (\is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            if (\is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($directory);
    }
}
