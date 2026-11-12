<?php

/**
 * Runs an external program safely.
 *
 * The command is an ARRAY (program + separate arguments), so nothing is passed
 * through a shell and nothing a user types can become a command. Output goes to
 * temporary files (pipes cannot be polled on Windows), and a timeout stops
 * programs that hang.
 */
final class ProcessRunner
{
    /**
     * @param string[]              $command    program and arguments
     * @param array<string,string>  $extraEnv   extra environment variables (e.g. PGPASSWORD)
     * @return array{exit:int,stdout:string,stderr:string,timed_out:bool}
     */
    public static function run(array $command, array $extraEnv = [], int $timeoutSeconds = 300): array
    {
        $outFile = tempnam(sys_get_temp_dir(), 'fio');
        $errFile = tempnam(sys_get_temp_dir(), 'fie');

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $outFile, 'w'],
            2 => ['file', $errFile, 'w'],
        ];

        $env = array_merge(
            getenv() ?: [],
            ['LC_ALL' => 'C', 'LANGUAGE' => 'en', 'PGCONNECT_TIMEOUT' => '15'],
            $extraEnv
        );

        $pipes = [];
        $process = @proc_open($command, $descriptors, $pipes, null, $env);

        if (!is_resource($process)) {
            @unlink($outFile);
            @unlink($errFile);
            return ['exit' => -1, 'stdout' => '', 'stderr' => 'The program could not be started.', 'timed_out' => false];
        }

        fclose($pipes[0]);

        $started = time();
        $exit = null;
        $timedOut = false;

        while (true) {
            $status = proc_get_status($process);

            if (!$status['running']) {
                $exit = (int) $status['exitcode'];
                break;
            }

            if (time() - $started > $timeoutSeconds) {
                proc_terminate($process);
                $timedOut = true;
                usleep(300000);
                break;
            }

            usleep(100000);
        }

        $closeCode = proc_close($process);
        if ($exit === null || $exit < 0) {
            $exit = $timedOut ? -1 : (int) $closeCode;
        }

        $stdout = (string) @file_get_contents($outFile, false, null, 0, 200000);
        $stderr = (string) @file_get_contents($errFile, false, null, 0, 200000);
        @unlink($outFile);
        @unlink($errFile);

        return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr, 'timed_out' => $timedOut];
    }

    /** Remove secrets from text before it is logged or shown. */
    public static function redact(string $text, array $secrets): string
    {
        foreach ($secrets as $secret) {
            if (is_string($secret) && strlen($secret) >= 3) {
                $text = str_replace($secret, '***', $text);
            }
        }

        // Keep logs short and on one line each.
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        return strlen($text) > 600 ? substr($text, 0, 600) . '...' : $text;
    }
}
