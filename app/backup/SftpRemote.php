<?php

/**
 * Remote copy to a Linux server over SSH, using the OpenSSH "ssh" and "scp"
 * programs (built into Windows 10/11). Login must work without a password
 * prompt, i.e. with an SSH key.
 *
 * Recommended server setup: a dedicated account that can only write into its own
 * backup folder, with retention done on the server (see docs/BACKUP_GUIDE.md), so
 * that someone who takes over this computer cannot delete the remote copies.
 */
final class SftpRemote implements RemoteStorage
{
    private string $host;
    private int $port;
    private string $user;
    private string $dir;
    private string $keyFile;
    private string $knownHosts;

    public function __construct(string $host, int $port, string $user, string $dir, string $keyFile = '', string $knownHosts = '')
    {
        // Everything below ends up in a command on the server, so keep it strictly plain.
        if (!preg_match('/\A[A-Za-z0-9.-]{1,253}\z/', $host)) {
            throw new RuntimeException('REMOTE_SFTP_HOST is not a valid host name.');
        }
        if (!preg_match('/\A[A-Za-z0-9._-]{1,32}\z/', $user)) {
            throw new RuntimeException('REMOTE_SFTP_USER is not valid.');
        }
        if (!preg_match('/\A[A-Za-z0-9._\/-]{1,150}\z/', $dir) || strpos($dir, '..') !== false) {
            throw new RuntimeException('REMOTE_SFTP_DIR may only contain letters, digits, . _ - and /.');
        }
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('REMOTE_SFTP_PORT is not valid.');
        }
        if ($keyFile !== '' && !is_file($keyFile)) {
            throw new RuntimeException('REMOTE_SFTP_KEY was not found.');
        }

        $this->host = $host;
        $this->port = $port;
        $this->user = $user;
        $this->dir = rtrim($dir, '/');
        $this->keyFile = $keyFile;
        $this->knownHosts = $knownHosts;
    }

    public static function fromEnv(): self
    {
        $host = BackupConfig::env('REMOTE_SFTP_HOST');
        $user = BackupConfig::env('REMOTE_SFTP_USER');

        if ($host === '' || $user === '') {
            throw new RuntimeException('REMOTE_SFTP_HOST and REMOTE_SFTP_USER must be set in .env.');
        }

        return new self(
            $host,
            BackupConfig::int('REMOTE_SFTP_PORT', 22, 1, 65535),
            $user,
            BackupConfig::env('REMOTE_SFTP_DIR', 'findit-backups'),
            BackupConfig::env('REMOTE_SFTP_KEY'),
            BackupConfig::env('REMOTE_SFTP_KNOWN_HOSTS')
        );
    }

    public function name(): string
    {
        return 'sftp';
    }

    public function describe(): string
    {
        return 'SFTP ' . $this->user . '@' . $this->host . ':' . $this->dir;
    }

    // ------------------------------------------------------------- plumbing

    private function options(string $portFlag): array
    {
        $opts = [
            $portFlag, (string) $this->port,
            '-o', 'BatchMode=yes',
            '-o', 'StrictHostKeyChecking=accept-new',
            '-o', 'ConnectTimeout=15',
            '-o', 'ServerAliveInterval=15',
            '-o', 'ServerAliveCountMax=3',
        ];

        if ($this->keyFile !== '') {
            array_push($opts, '-i', $this->keyFile, '-o', 'IdentitiesOnly=yes');
        }
        if ($this->knownHosts !== '') {
            array_push($opts, '-o', 'UserKnownHostsFile=' . $this->knownHosts);
        }

        return $opts;
    }

    /** Run one command on the server. Arguments are validated, then single-quoted. */
    private function ssh(string $remoteCommand, int $timeout = 120): array
    {
        $cmd = array_merge(
            [BackupConfig::tool('ssh')],
            $this->options('-p'),
            [$this->user . '@' . $this->host, $remoteCommand]
        );

        return ProcessRunner::run($cmd, [], $timeout);
    }

    private function scp(string $from, string $to, int $timeout = 900): array
    {
        $cmd = array_merge([BackupConfig::tool('scp')], $this->options('-P'), [$from, $to]);
        return ProcessRunner::run($cmd, [], $timeout);
    }

    private function remotePath(string $kind, string $name = ''): string
    {
        RemoteFactory::assertValid($name === '' ? 'findit_x.dump' : $name, $kind);
        return $this->dir . '/' . $kind . ($name !== '' ? '/' . $name : '');
    }

    private function fail(string $what, array $result): RuntimeException
    {
        BackupStore::log('backup', 'error', 'sftp_failed', [
            'step' => $what,
            'exit' => $result['exit'],
            'detail' => ProcessRunner::redact($result['stderr'], []),
        ]);

        return new RuntimeException('The SFTP step "' . $what . '" failed.');
    }

    // ----------------------------------------------------------- interface

    public function upload(string $localPath, string $remoteName, string $kind): void
    {
        $final = $this->remotePath($kind, $remoteName);
        $part = $this->dir . '/' . $kind . '/.' . $remoteName . '.part';

        $r = $this->ssh("mkdir -p -- '" . $this->dir . "/daily'");
        if ($r['exit'] !== 0) {
            throw $this->fail('prepare folders', $r);
        }

        $r = $this->scp($localPath, $this->user . '@' . $this->host . ':' . $part);
        if ($r['exit'] !== 0) {
            throw $this->fail('upload', $r);
        }

        $r = $this->ssh("mv -f -- '" . $part . "' '" . $final . "'");
        if ($r['exit'] !== 0) {
            throw $this->fail('finish upload', $r);
        }
    }

    public function stat(string $remoteName, string $kind): ?array
    {
        $path = $this->remotePath($kind, $remoteName);
        $r = $this->ssh("sha256sum -- '" . $path . "' && stat -c %s -- '" . $path . "'");

        if ($r['exit'] !== 0) {
            return null;
        }

        $lines = preg_split('/\R/', trim($r['stdout'])) ?: [];
        if (count($lines) < 2 || !preg_match('/\A([a-f0-9]{64})\s/', $lines[0], $m) || !ctype_digit(trim($lines[1]))) {
            return null;
        }

        return ['size' => (int) trim($lines[1]), 'sha256' => $m[1]];
    }

    public function listFiles(string $kind): array
    {
        $dir = $this->remotePath($kind);
        $r = $this->ssh("find '" . $dir . "' -maxdepth 1 -type f -name 'findit_*.dump' -printf '%f|%s|%T@\\n'");

        if ($r['exit'] !== 0) {
            throw $this->fail('list files', $r);
        }

        $files = [];
        foreach (preg_split('/\R/', trim($r['stdout'])) ?: [] as $line) {
            $parts = explode('|', $line);
            if (count($parts) === 3 && RemoteFactory::safeName($parts[0]) && ctype_digit($parts[1])) {
                $files[] = ['name' => $parts[0], 'size' => (int) $parts[1], 'mtime' => (int) $parts[2]];
            }
        }
        return $files;
    }

    public function download(string $remoteName, string $kind, string $localPath): void
    {
        $r = $this->scp($this->user . '@' . $this->host . ':' . $this->remotePath($kind, $remoteName), $localPath);
        if ($r['exit'] !== 0 || !is_file($localPath)) {
            throw $this->fail('download', $r);
        }
    }

    public function delete(string $remoteName, string $kind): void
    {
        $path = $this->remotePath($kind, $remoteName);
        $r = $this->ssh("rm -f -- '" . $path . "' '" . $path . ".sha256'");
        if ($r['exit'] !== 0) {
            throw $this->fail('delete', $r);
        }
    }

    public function test(): array
    {
        try {
            $r = $this->ssh("mkdir -p -- '" . $this->dir . "/daily' && echo ok", 40);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        if ($r['exit'] === 0 && trim($r['stdout']) === 'ok') {
            return ['ok' => true, 'message' => 'The server accepts the SSH key and the backup folder is writable.'];
        }

        $why = 'The SSH connection failed.';
        if (stripos($r['stderr'], 'permission denied') !== false) {
            $why = 'The server rejected the SSH key.';
        } elseif (stripos($r['stderr'], 'timed out') !== false || stripos($r['stderr'], 'refused') !== false) {
            $why = 'The server could not be reached.';
        } elseif (stripos($r['stderr'], 'host key') !== false) {
            $why = 'The server host key changed or is not trusted.';
        }

        return ['ok' => false, 'message' => $why];
    }
}
