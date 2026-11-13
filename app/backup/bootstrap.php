<?php

// Loads the backup / restore system. It needs only .env and the backup folder,
// never the database connection, so it still works when the database is down.

require_once __DIR__ . '/BackupConfig.php';
require_once __DIR__ . '/ProcessRunner.php';
require_once __DIR__ . '/BackupStore.php';
require_once __DIR__ . '/OperationLock.php';
require_once __DIR__ . '/RemoteStorage.php';
require_once __DIR__ . '/FolderRemote.php';
require_once __DIR__ . '/SftpRemote.php';
require_once __DIR__ . '/BackupManager.php';
require_once __DIR__ . '/RestoreManager.php';

BackupConfig::applyTimezone();
