<?php

$source = __DIR__ . '/../database/schema/pgsql-schema.sql';
$target = __DIR__ . '/../storage/framework/cache/phpstan-schema/schema.sql';

$types = [
    '/^uuid$/'                                  => 'char(36)',
    '/^character varying(\(\d+\))?$/'           => 'varchar$1',
    '/^character(\(\d+\))?$/'                   => 'char$1',
    '/^timestamp(\(\d+\))?( with(out)? time zone)?$/' => 'timestamp',
    '/^time(\(\d+\))?( with(out)? time zone)?$/'      => 'time',
    '/^date$/'                                  => 'date',
    '/^numeric(\(\d+,\s*\d+\))?$/'              => 'varchar(40)',
    '/^(integer|int)$/'                         => 'int',
    '/^smallint$/'                              => 'smallint',
    '/^bigint$/'                                => 'bigint',
    '/^boolean$/'                               => 'boolean',
    '/^(jsonb|json|text|inet)$/'                => 'text',
    '/^double precision$/'                      => 'double',
];

$sql = file_get_contents($source);

if ($sql === false || $sql === '') {
    fwrite(STDERR, "No se pudo leer el esquema.\n");
    exit(1);
}

preg_match_all('/CREATE TABLE public\.(\w+) \((.*?)\n\);/s', $sql, $tables, PREG_SET_ORDER);

$out = [];

foreach ($tables as [, $name, $body]) {
    $columns = [];

    foreach (explode("\n", trim($body)) as $line) {
        $line = rtrim(trim($line), ',');

        if (!preg_match('/^"?(\w+)"? (.+?)( DEFAULT .*?)?( NOT NULL)?$/', $line, $m) || str_starts_with($line, 'CONSTRAINT')) {
            continue;
        }

        $pgType = preg_replace('/ (DEFAULT|NOT NULL|GENERATED|COLLATE).*$/', '', $m[2]);
        $pgType = preg_replace('/\[\]$/', '', $pgType, -1, $isArray);
        $mapped = 'text';

        if (!$isArray) {
            foreach ($types as $pattern => $replacement) {
                if (preg_match($pattern, $pgType)) {
                    $mapped = preg_replace($pattern, $replacement, $pgType);
                    break;
                }
            }
        }

        $columns[] = '  `' . $m[1] . '` ' . $mapped . (str_contains($line, 'NOT NULL') ? ' NOT NULL' : ' NULL');
    }

    $out[] = 'CREATE TABLE `' . $name . "` (\n" . implode(",\n", $columns) . "\n);";
}

if (!is_dir(dirname($target))) {
    mkdir(dirname($target), 0777, true);
}

file_put_contents($target, implode("\n\n", $out) . "\n");

echo count($out) . " tablas para PHPStan\n";
