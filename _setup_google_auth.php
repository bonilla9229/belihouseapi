<?php
// One-off setup script — run once then delete
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$sqls = [
    "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS google_id VARCHAR(100) NULL DEFAULT NULL AFTER email",
    "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS status ENUM('pendiente','aprobado','rechazado') NOT NULL DEFAULT 'aprobado' AFTER activo",
];

foreach ($sqls as $sql) {
    try {
        DB::statement($sql);
        echo "OK: $sql\n";
    } catch (\Exception $e) {
        echo "ERR: " . $e->getMessage() . "\n";
    }
}

// Add unique index only if not exists (MariaDB doesn't support IF NOT EXISTS for indexes in older versions)
try {
    $indexes = DB::select("SHOW INDEX FROM usuarios WHERE Key_name = 'usuarios_google_id_unique'");
    if (empty($indexes)) {
        DB::statement("ALTER TABLE usuarios ADD UNIQUE INDEX usuarios_google_id_unique (google_id)");
        echo "OK: added unique index on google_id\n";
    } else {
        echo "SKIP: index already exists\n";
    }
} catch (\Exception $e) {
    echo "ERR index: " . $e->getMessage() . "\n";
}

echo "Done.\n";
