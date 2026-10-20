<?php
/** Read-only, binary-safe database snapshot. Does not load WordPress or run cron. */

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
[$host, $port] = array_pad(explode(':', getenv('WORDPRESS_DB_HOST'), 2), 2, '3306');
$database = getenv('EHRMAN_SNAPSHOT_DATABASE') ?: getenv('WORDPRESS_DB_NAME');
$db = new mysqli($host, getenv('WORDPRESS_DB_USER'), getenv('WORDPRESS_DB_PASSWORD'), $database, (int) $port);
$db->set_charset('utf8mb4');
$db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$db->query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
$snapshot = ['version' => $db->query('SELECT VERSION()')->fetch_row()[0], 'tables' => []];
$tables = $db->query('SHOW TABLE STATUS')->fetch_all(MYSQLI_ASSOC);
foreach ($tables as $table) {
    if ($table['Engine'] !== 'InnoDB') {
        throw new RuntimeException('Expected an InnoDB base table: ' . $table['Name']);
    }
    $name = '`' . str_replace('`', '``', $table['Name']) . '`';
    $columns = $db->query('SHOW FULL COLUMNS FROM ' . $name)->fetch_all(MYSQLI_ASSOC);
    foreach ($columns as &$column) {
        // MariaDB displays integer widths; MySQL 8 omits them.
        $column['Type'] = preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\(\d+\)/i', '$1', $column['Type']);
        unset($column['Privileges']);
    }
    unset($column);
    $indexes = $db->query('SHOW INDEX FROM ' . $name)->fetch_all(MYSQLI_ASSOC);
    $keys = [];
    foreach ($indexes as $index) {
        $keys[] = array_intersect_key($index, array_flip(['Key_name', 'Non_unique', 'Seq_in_index', 'Column_name', 'Collation', 'Sub_part', 'Index_type']));
    }
    usort($keys, static fn($a, $b) => [$a['Key_name'], (int) $a['Seq_in_index']] <=> [$b['Key_name'], (int) $b['Seq_in_index']]);
    $result = $db->query('SELECT * FROM ' . $name, MYSQLI_USE_RESULT);
    $fields = $result->fetch_fields();
    $rowHashes = [];
    $binaryBytes = 0;
    while ($row = $result->fetch_row()) {
        $hash = hash_init('sha256');
        foreach ($row as $i => $value) {
            if ($value === null) {
                hash_update($hash, 'N;');
                continue;
            }
            if ($fields[$i]->type === MYSQLI_TYPE_DOUBLE) {
                $value = pack('E', (float) $value);
            } elseif ($fields[$i]->type === MYSQLI_TYPE_FLOAT) {
                $value = pack('G', (float) $value);
            }
            if ($fields[$i]->name === 'embedding') {
                $binaryBytes += strlen($value);
            }
            hash_update($hash, strlen($value) . ':' . $value . ';');
        }
        $rowHashes[] = hash_final($hash);
    }
    $result->free();
    sort($rowHashes, SORT_STRING);
    $snapshot['tables'][$table['Name']] = [
        'engine' => $table['Engine'],
        'collation' => $table['Collation'],
        'auto_increment' => $table['Auto_increment'],
        'columns' => $columns,
        'indexes' => $keys,
        'rows' => count($rowHashes),
        'sha256' => hash('sha256', implode('', $rowHashes)),
        'embedding_bytes' => $binaryBytes,
    ];
}
$db->rollback();
ksort($snapshot['tables']);
echo json_encode($snapshot, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
