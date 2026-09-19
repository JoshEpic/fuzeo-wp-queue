<?php

declare(strict_types=1);

use Fuzeo\Queue\Drivers\MySql\MySqlDriver;
use Fuzeo\Queue\Drivers\ReserveRequest;
use Fuzeo\Queue\Persistence\PdoConnection;
use Fuzeo\Queue\Runtime\Coordinator;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$dsn = getenv('FUZEO_QUEUE_TEST_DSN');
if (!is_string($dsn) || $dsn === '') {
    fwrite(STDERR, "missing DSN\n");
    exit(1);
}
$user = getenv('FUZEO_QUEUE_TEST_DB_USER') ?: 'root';
$password = getenv('FUZEO_QUEUE_TEST_DB_PASS');
if ($password === false) {
    $password = 'root';
}
$worker = $argv[1] ?? 'worker';
$sleep = (int) ($argv[2] ?? 0);

$connection = PdoConnection::fromDsn($dsn, $user, $password, 'wp_');
Coordinator::bootForTesting(['driver' => 'mysql'], connection: $connection);
$driver = Coordinator::get()->driver();
if (!$driver instanceof MySqlDriver) {
    fwrite(STDERR, "expected mysql driver\n");
    exit(1);
}
$reserved = $driver->reserve(new ReserveRequest('default', $worker, 15));
if ($reserved === null) {
    echo "none\n";
    exit(0);
}
echo $reserved->envelope->jobId . ' ' . $reserved->token->value . PHP_EOL;
if ($sleep > 0) {
    sleep($sleep);
}
