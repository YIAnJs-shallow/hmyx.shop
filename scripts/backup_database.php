<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../lib/shop.php';
$destination = $argv[1] ?? '';
if (!$destination || is_file($destination)) exit("Supply a new backup file path outside the web root.\n");
$file = fopen($destination,'x');
if (!$file) throw new RuntimeException('Cannot open backup destination');
fwrite($file,"SET NAMES utf8mb4;\nSET time_zone='+08:00';\nSET FOREIGN_KEY_CHECKS=0;\n");
$conn->begin_transaction(MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
try {
    foreach(rows('SHOW TABLES') as $row) {
        $table=array_values($row)[0]; $name='`'.str_replace('`','``',$table).'`';
        $ddl=one('SHOW CREATE TABLE '.$name)['Create Table'];
        fwrite($file,'DROP TABLE IF EXISTS '.$name.";\n".$ddl.";\n");
        foreach(rows('SELECT * FROM '.$name) as $record) {
            $values=[]; foreach($record as $value) $values[]=$value===null?'NULL':"'".$conn->real_escape_string($value)."'";
            fwrite($file,'INSERT INTO '.$name.' VALUES('.implode(',',$values).");\n");
        }
    }
    $conn->commit(); fwrite($file,"SET FOREIGN_KEY_CHECKS=1;\n"); fclose($file);
    echo "Database backup saved.\n";
} catch(Throwable $e) { $conn->rollback(); fclose($file); throw $e; }
