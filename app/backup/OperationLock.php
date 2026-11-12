<?php

/**
 * Only ONE backup or restore may run at a time. The lock is released by the
 * operating system if the process dies, so a crash can never block backups forever.
 */
final class OperationLock
{
    /** @var resource */
    private $handle;
    private string $infoPath;

    private function __construct($handle, string $infoPath)
    {
        $this->handle = $handle;
        $this->infoPath = $infoPath;
    }

    public static function acquire(string $operation, ?string $runId = null): ?self
    {
        $dir = BackupConfig::dir('logs');
        $handle = @fopen($dir . '/operation.lock', 'c');

        if ($handle === false) {
            return null;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }

        $infoPath = $dir . '/operation.json';
        @file_put_contents($infoPath, json_encode([
            'operation' => $operation,
            'run_id' => $runId,
            'pid' => getmypid(),
            'started_at' => date('c'),
        ]));

        return new self($handle, $infoPath);
    }

    public function release(): void
    {
        @unlink($this->infoPath);
        @flock($this->handle, LOCK_UN);
        @fclose($this->handle);
    }

    /** Details of the running operation, or null when nothing is running. */
    public static function running(): ?array
    {
        $dir = BackupConfig::dir('logs');
        $handle = @fopen($dir . '/operation.lock', 'c');

        if ($handle === false) {
            return null;
        }

        if (flock($handle, LOCK_EX | LOCK_NB)) {
            // Nobody holds it: any leftover info file is from a crashed run.
            flock($handle, LOCK_UN);
            fclose($handle);
            @unlink($dir . '/operation.json');
            return null;
        }

        fclose($handle);
        $info = json_decode((string) @file_get_contents($dir . '/operation.json'), true);

        return is_array($info) ? $info : ['operation' => 'unknown'];
    }
}
