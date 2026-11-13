<?php

/** Remote copy into another folder (USB drive, network share, synced cloud folder). */
final class FolderRemote implements RemoteStorage
{
    private string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/\\');

        // A "second copy" in the same folder as the first one protects nothing.
        $local = BackupConfig::baseDir();
        $norm = static fn($p) => strtolower(rtrim(str_replace('\\', '/', (string) $p), '/')) . '/';

        $localReal = realpath($local) ?: $local;
        $remoteReal = realpath($this->root) ?: $this->root;

        if (strpos($norm($remoteReal), $norm($localReal)) === 0 || strpos($norm($localReal), $norm($remoteReal)) === 0) {
            throw new RuntimeException('REMOTE_FOLDER_PATH must be a different place from BACKUP_DIR.');
        }
    }

    public function name(): string
    {
        return 'folder';
    }

    public function describe(): string
    {
        return 'Folder ' . $this->root;
    }

    private function dir(string $kind): string
    {
        if (!RemoteFactory::safeKind($kind)) {
            throw new InvalidArgumentException('Invalid backup kind.');
        }

        $dir = $this->root . '/' . $kind;
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('The remote folder could not be created.');
        }
        return $dir;
    }

    public function upload(string $localPath, string $remoteName, string $kind): void
    {
        RemoteFactory::assertValid($remoteName, $kind);
        $dir = $this->dir($kind);

        // Copy under a temporary name first, so a half-copied file is never mistaken for a backup.
        $tmp = $dir . '/.' . $remoteName . '.part';
        if (!@copy($localPath, $tmp)) {
            @unlink($tmp);
            throw new RuntimeException('Copying to the remote folder failed.');
        }

        $target = $dir . '/' . $remoteName;
        if (!@rename($tmp, $target)) {
            @unlink($target);
            if (!@rename($tmp, $target)) {
                @unlink($tmp);
                throw new RuntimeException('Finishing the remote copy failed.');
            }
        }
    }

    public function stat(string $remoteName, string $kind): ?array
    {
        RemoteFactory::assertValid($remoteName, $kind);
        $path = $this->dir($kind) . '/' . $remoteName;

        if (!is_file($path)) {
            return null;
        }

        return ['size' => (int) filesize($path), 'sha256' => (string) hash_file('sha256', $path)];
    }

    public function listFiles(string $kind): array
    {
        $files = [];
        foreach (glob($this->dir($kind) . '/findit_*.dump') ?: [] as $path) {
            $name = basename($path);
            if (RemoteFactory::safeName($name)) {
                $files[] = ['name' => $name, 'size' => (int) filesize($path), 'mtime' => (int) filemtime($path)];
            }
        }
        return $files;
    }

    public function download(string $remoteName, string $kind, string $localPath): void
    {
        RemoteFactory::assertValid($remoteName, $kind);
        $path = $this->dir($kind) . '/' . $remoteName;

        if (!is_file($path) || !@copy($path, $localPath)) {
            throw new RuntimeException('The remote file could not be copied.');
        }
    }

    public function delete(string $remoteName, string $kind): void
    {
        RemoteFactory::assertValid($remoteName, $kind);
        $dir = $this->dir($kind);
        @unlink($dir . '/' . $remoteName);
        @unlink($dir . '/' . $remoteName . '.sha256');
    }

    public function test(): array
    {
        try {
            $dir = $this->dir('daily');
            $probe = $dir . '/.write-test-' . bin2hex(random_bytes(3));

            if (@file_put_contents($probe, 'ok') === false) {
                return ['ok' => false, 'message' => 'The remote folder is not writable.'];
            }
            @unlink($probe);

            return ['ok' => true, 'message' => 'The remote folder is reachable and writable.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
