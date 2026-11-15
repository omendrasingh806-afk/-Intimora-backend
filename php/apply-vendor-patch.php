<?php
/**
 * Re-applies the MadelineProto 8 patch that lets the backend run on shared
 * hosting where proc_open / stream_socket_server / posix_mkfifo are disabled
 * (e.g. InfinityFree). Run it once after `composer install`:
 *
 *     php php/apply-vendor-patch.php
 *
 * It only edits vendor/danog/madelineproto/src/MTProto.php and is safe to
 * re-run (skips when the patch is already applied).
 */
$file = __DIR__ . '/vendor/danog/madelineproto/src/MTProto.php';
if (!is_file($file)) {
    fwrite(STDERR, "vendor not installed - run composer install first\n");
    exit(1);
}
$src = file_get_contents($file);
if (strpos($src, 'MT_IPC_DISABLED') !== false) {
    echo "already patched\n";
    exit(0);
}

$from1 = "        \$this->serializeLoop->start();\n        \$this->configLoop->start();\n        try {\n            \$this->ipcServer->start();";
$to1   = "        \$this->serializeLoop->start();\n        \$this->configLoop->start();\n        // Intimora patch: allow running fully in-process on shared hosts where\n        // proc_open / stream_socket_server / posix_mkfifo are disabled.\n        if (\defined('MT_IPC_DISABLED') && \MT_IPC_DISABLED) {\n            return;\n        }\n        try {\n            \$this->ipcServer->start();";

$from2 = "        // Start IPC server\n        if (!\$this->ipcServer) {\n            \$this->ipcServer = new Server(\$this);\n            \$this->ipcServer->setIpcPath(\$this->wrapper->getSession());\n        }\n        \$this->ipcServer->start();";
$to2   = "        // Start IPC server (skipped when MT_IPC_DISABLED is defined, see startLoops)\n        if (!\defined('MT_IPC_DISABLED') || !\MT_IPC_DISABLED) {\n            if (!\$this->ipcServer) {\n                \$this->ipcServer = new Server(\$this);\n                \$this->ipcServer->setIpcPath(\$this->wrapper->getSession());\n            }\n            \$this->ipcServer->start();\n        }";

$patched = str_replace([$from1, $from2], [$to1, $to2], $src, $count);
if ($count < 2) {
    fwrite(STDERR, "patch failed (matched $count/2 hunks) - vendor version changed\n");
    exit(1);
}
file_put_contents($file, $patched);
echo "patched ($count hunks)\n";
