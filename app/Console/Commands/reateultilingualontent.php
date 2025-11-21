<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

class CreateMultilingualContent extends Command
{
    protected $signature = 'content:create-multilingual';
    protected $description = 'Create test content for all languages';

    public function handle()
    {
        $sites = [
            'default' => [
                'home' => ['title' => 'Главная страница', 'content' => 'Добро пожаловать на наш сайт'],
                'about' => ['title' => 'О нас', 'content' => 'Информация о нашей компании']
            ],
            'english' => [
                'home' => ['title' => 'Home Page', 'content' => 'Welcome to our website'],
                'about' => ['title' => 'About Us', 'content' => 'Information about our company']
            ],
            'ukrainian' => [
                'home' => ['title' => 'Головна сторінка', 'content' => 'Ласкаво просимо на наш сайт'],
                'about' => ['title' => 'Про нас', 'content' => 'Інформація про нашу компанію']
            ]
        ];

        foreach ($sites as $siteHandle => $pages) {
            foreach ($pages as $slug => $data) {
                $entry = Entry::query()
                    ->where('site', $siteHandle)
                    ->where('slug', $slug)
                    ->first();

                if (!$entry) {
                    try {
                        $entry = Entry::make()
                            ->collection('pages')
                            ->slug($slug)
                            ->locale($siteHandle)
                            ->data($data);
                        
                        $entry->save();
                        $this->info("✓ Создана страница: {$siteHandle}/{$slug}");
                    } catch (\Exception $e) {
                        $this->error("❌ Ошибка при создании {$siteHandle}/{$slug}: {$e->getMessage()}");
                    }
                } else {
                    $this->line("➡ Страница уже существует: {$siteHandle}/{$slug}");
                }
            }
        }

        $this->info("Тестовый контент создан!");
    }
}
