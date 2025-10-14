<?php

// Создаем необходимые папки если их нет
$dirs = [
    storage_path('app'),
    storage_path('app/public'),
    storage_path('framework'),
    storage_path('framework/cache'),
    storage_path('framework/sessions'),
    storage_path('framework/views'),
    storage_path('logs'),
    base_path('storage/database'),
];

foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// Создаем файл базы данных SQLite если его нет
if (!file_exists(base_path('storage/database/database.sqlite'))) {
    touch(base_path('storage/database/database.sqlite'));
}