<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\Entry;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Statamic\Http\Controllers\GlideController;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

Route::get('/img/{path}', [GlideController::class, 'generateByPath'])
    ->where('path', '.*')
    ->name('statamic.glide.generateByPath');




// API для добавления объектов недвижимости через Telegram бота
Route::post('/api/telegram-property', function (Request $request) {
    try {
        Log::info('📨 Получен запрос от Telegram бота');
        
        // Валидация данных с учетом assets_array
        $validated = $request->validate([
            'title' => 'required|string',
            'type' => 'required|in:rent,buy',
            'price' => 'required|integer',
            'address' => 'required|string',
            'district' => 'required|in:Mamaia,Constanta,Navodari,Ovidiu,Lumina',
            'floor' => 'required|integer',
            'rooms' => 'required|integer',
            'has_lift' => 'required|boolean',
            'has_balcony' => 'required|boolean',
            'bathroom' => 'required|integer|min:1',
            'type_home' => 'required|in:квартира,дом,вилла',
            'nearbu' => 'required|string',
            'date_use' => 'required|string',
            'apartment_area' => 'required|integer',
            'description' => 'required|string',
            'images' => 'sometimes|array',
            'images.*' => 'sometimes|url',
            'assets_array' => 'sometimes|array',
            'assets_array.*' => 'sometimes|url'
        ]);
        
        Log::info('✅ Данные валидны', $validated);
        
        // ===== ЗАГРУЗКА ГЛАВНЫХ ИЗОБРАЖЕНИЙ В SUPABASE =====
        $uploadedImages = [];

        if (!empty($validated['images'])) {
            foreach ($validated['images'] as $index => $imageUrl) {
                try {
                    Log::info("🖼️ Загрузка главного изображения {$index} в Supabase: {$imageUrl}");
                    
                    $fileName = 'main_' . time() . '_' . $index . '.jpg';
                    $uploadedPath = uploadToSupabaseStorage($imageUrl, $fileName);
                    
                    if ($uploadedPath) {
                        $uploadedImages[] = $uploadedPath;
                        Log::info("✅ Главное изображение загружено в Supabase: {$uploadedPath}");
                    } else {
                        Log::warning("❌ Ошибка загрузки главного изображения в Supabase");
                    }
                    
                } catch (\Exception $e) {
                    Log::error("❌ Ошибка загрузки главного изображения в Supabase: " . $e->getMessage());
                    continue;
                }
            }
        }
        
        // ===== ЗАГРУЗКА ДОПОЛНИТЕЛЬНЫХ ИЗОБРАЖЕНИЙ В SUPABASE =====
        $uploadedAssetsArray = [];

        if (!empty($validated['assets_array'])) {
            foreach ($validated['assets_array'] as $index => $imageUrl) {
                try {
                    Log::info("🖼️ Загрузка дополнительного изображения {$index} в Supabase: {$imageUrl}");
                    
                    $fileName = 'asset_' . time() . '_' . $index . '.jpg';
                    $uploadedPath = uploadToSupabaseStorage($imageUrl, $fileName);
                    
                    if ($uploadedPath) {
                        $uploadedAssetsArray[] = $uploadedPath;
                        Log::info("✅ Дополнительное изображение загружено в Supabase: {$uploadedPath}");
                    } else {
                        Log::warning("❌ Ошибка загрузки дополнительного изображения в Supabase");
                    }
                    
                } catch (\Exception $e) {
                    Log::error("❌ Ошибка загрузки дополнительного изображения в Supabase: " . $e->getMessage());
                    continue;
                }
            }
        }
        
        // СОХРАНЕНИЕ В STATAMIC
        $entry = Entry::make()
            ->collection('properties')
            ->data([
                'title' => $validated['title'],
                'type' => $validated['type'],
                'price' => $validated['price'],
                'address' => $validated['address'],
                'district' => $validated['district'],
                'floor' => $validated['floor'],
                'rooms' => $validated['rooms'],
                'has_lift' => $validated['has_lift'],
                'has_balcony' => $validated['has_balcony'],
                'bathroom' => $validated['bathroom'],
                'type_home' => $validated['type_home'],
                'nearbu' => $validated['nearbu'],
                'date_use' => $validated['date_use'],
                'apartment_area' => $validated['apartment_area'],
                'description' => $validated['description'],
                'images' => $uploadedImages,
                'assets_array' => $uploadedAssetsArray,
                'published' => true
            ]);

        // Сохраняем запись
        $entry->save();
        
        $entryId = $entry->id();
        Log::info('💾 Запись сохранена в Statamic', [
            'id' => $entryId,
            'main_images_count' => count($uploadedImages),
            'assets_images_count' => count($uploadedAssetsArray)
        ]);
        
        return response()->json([
            'success' => true, 
            'message' => 'Объект успешно добавлен с ' . count($uploadedImages) . ' основными и ' . count($uploadedAssetsArray) . ' дополнительными изображениями!',
            'entry_id' => $entryId,
            'images_saved' => count($uploadedImages),
            'assets_array_saved' => count($uploadedAssetsArray)
        ]);
        
    } catch (\Exception $e) {
        Log::error('🔥 Ошибка при создании объекта: ' . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
        
        return response()->json([
            'success' => false,
            'message' => 'Ошибка: ' . $e->getMessage()
        ], 500);
    }
})->withoutMiddleware(['web', 'verify.csrf']);

/**
 * Функция для загрузки изображения в Supabase Storage
 */
function uploadToSupabaseStorage($imageUrl, $fileName) {
    try {
        $supabaseUrl = env('SUPABASE_URL');
        $supabaseKey = env('SUPABASE_SERVICE_KEY');
        
        if (!$supabaseUrl || !$supabaseKey) {
            throw new Exception('Supabase credentials not configured');
        }
        
        Log::info("🔗 Подключение к Supabase: {$supabaseUrl}");

        // Скачиваем изображение
        $client = new \GuzzleHttp\Client();
        $response = $client->get($imageUrl, [
            'timeout' => 30,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ]
        ]);
        
        $imageData = $response->getBody()->getContents();
        
        if (empty($imageData)) {
            throw new Exception('Failed to download image - empty response');
        }
        
        Log::info("📥 Изображение скачано, размер: " . strlen($imageData) . " bytes");

        // Загружаем в Supabase Storage
        $uploadResponse = Http::withHeaders([
            'Authorization' => 'Bearer ' . $supabaseKey,
            'Content-Type' => 'image/jpeg'
        ])
        ->withOptions([
            'timeout' => 60,
            'verify' => false // Отключаем SSL verification если нужно
        ])
        ->put("{$supabaseUrl}/storage/v1/object/properties/{$fileName}", $imageData);

        Log::info("📤 Ответ от Supabase: " . $uploadResponse->status());
        
        if ($uploadResponse->successful()) {
            $publicUrl = "{$supabaseUrl}/storage/v1/object/public/properties/{$fileName}";
            Log::info("✅ Изображение загружено: {$publicUrl}");
            return $publicUrl;
        } else {
            $error = $uploadResponse->body();
            Log::error("❌ Ошибка загрузки в Supabase: " . $error);
            throw new Exception('Supabase upload failed: ' . $error);
        }

    } catch (\Exception $e) {
        Log::error('❌ Ошибка в uploadToSupabaseStorage: ' . $e->getMessage());
        return null;
    }
}

/**
 * Вспомогательные endpoint для проверки и отладки
 */

// Проверка Supabase подключения
Route::get('/api/supabase-test', function() {
    try {
        $supabaseUrl = env('SUPABASE_URL');
        $supabaseKey = env('SUPABASE_SERVICE_KEY');
        
        if (!$supabaseUrl || !$supabaseKey) {
            return response()->json([
                'status' => 'error',
                'message' => 'Supabase credentials missing'
            ], 500);
        }
        
        // Пробуем получить список файлов в бакете
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $supabaseKey,
            'Content-Type' => 'application/json'
        ])->get("{$supabaseUrl}/storage/v1/object/list/properties");
        
        return response()->json([
            'status' => 'success',
            'supabase_connected' => true,
            'bucket_exists' => !$response->failed(),
            'files_count' => count($response->json() ?? []),
            'supabase_url' => $supabaseUrl,
            'service_key_length' => strlen($supabaseKey)
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage()
        ], 500);
    }
});

// Проверка конфигурации
Route::get('/api/debug-config', function() {
    return response()->json([
        'supabase_url' => env('SUPABASE_URL') ? '✅ Установлен' : '❌ Отсутствует',
        'supabase_service_key' => env('SUPABASE_SERVICE_KEY') ? '✅ Установлен' : '❌ Отсутствует',
        'supabase_anon_key' => env('SUPABASE_ANON_KEY') ? '✅ Установлен' : '❌ Отсутствует',
        'app_env' => env('APP_ENV'),
        'app_debug' => env('APP_DEBUG')
    ]);
});

// Тестовый endpoint для загрузки изображения
Route::post('/api/test-upload', function(Request $request) {
    try {
        $imageUrl = $request->input('image_url');
        
        if (!$imageUrl) {
            return response()->json([
                'success' => false,
                'message' => 'image_url parameter required'
            ], 400);
        }
        
        $fileName = 'test_' . time() . '.jpg';
        $result = uploadToSupabaseStorage($imageUrl, $fileName);
        
        if ($result) {
            return response()->json([
                'success' => true,
                'message' => 'Image uploaded successfully',
                'url' => $result
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload image'
            ], 500);
        }
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage()
        ], 500);
    }
});



// Вспомогательные функции для загрузки изображений
function downloadImage($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $imageData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || !$imageData) {
        Log::warning("❌ Ошибка загрузки: HTTP {$httpCode}");
        return null;
    }
    
    return $imageData;
}

function saveImageToAssets($imageData, $filename, $folder = 'properties') {
    $assetsPath = public_path('assets/' . $folder);
    if (!file_exists($assetsPath)) {
        mkdir($assetsPath, 0755, true);
    }
    
    $fullPath = $assetsPath . '/' . $filename;
    
    if (file_put_contents($fullPath, $imageData) === false) {
        Log::warning("❌ Не удалось сохранить файл");
        return null;
    }
    
    return $folder . '/' . $filename;
}
// API для удаления по заголовку
Route::delete('/api/telegram-property/delete-by-title', function (Request $request) {
    try {
        Log::info('🗑️ Получен запрос на удаление по заголовку');
        
        // Проверка аутентификации
        $token = $request->bearerToken();
        $expectedToken = env('TELEGRAM_API_TOKEN');
        
        if (!$token || $token !== $expectedToken) {
            Log::warning('❌ Неавторизованный запрос на удаление по заголовку');
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        // Валидация
        $validated = $request->validate([
            'title' => 'required|string'
        ]);
        
        $title = $validated['title'];
        Log::info("🔍 Поиск записей с заголовком: {$title}");
        
        // Получаем все записи и фильтруем их
        $allEntries = Entry::whereCollection('properties')->all();
        $filteredEntries = [];
        
        foreach ($allEntries as $entry) {
            if (stripos($entry->get('title'), $title) !== false) {
                $filteredEntries[] = $entry;
            }
        }
        
        Log::info("📊 Найдено записей: " . count($filteredEntries));
        
        if (count($filteredEntries) === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Записей с таким заголовком не найдено.'
            ], 404);
        }
        
        $count = 0;
        foreach ($filteredEntries as $entry) {
            try {
                $entry->delete();
                $count++;
                Log::info("✅ Удалена запись: " . $entry->id());
            } catch (\Exception $e) {
                Log::error("❌ Ошибка при удалении записи {$entry->id()}: " . $e->getMessage());
            }
        }
        
        Log::info("🗑️ Удалено записей: {$count}");
        
        return response()->json([
            'success' => true, 
            'message' => "Удалено $count записей с заголовком '{$title}'."
        ]);
        
    } catch (\Illuminate\Validation\ValidationException $e) {
        Log::error('Ошибка валидации при удалении по заголовку: ' . json_encode($e->errors()));
        return response()->json([
            'success' => false,
            'message' => 'Ошибка валидации: ' . json_encode($e->errors())
        ], 422);
    } catch (\Exception $e) {
        Log::error('Ошибка при удалении по заголовку: ' . $e->getMessage() . ' в файле ' . $e->getFile() . ' на строке ' . $e->getLine());
        
        return response()->json([
            'success' => false,
            'message' => 'Внутренняя ошибка сервера: ' . $e->getMessage()
        ], 500);
    }
})->withoutMiddleware(['web', 'verify.csrf']);
// API для получения списка записей
Route::get('/api/telegram-property/list', function (Request $request) {
    try {
        // Проверка аутентификации
        $token = $request->bearerToken();
        $expectedToken = env('TELEGRAM_API_TOKEN');
        
        if (!$token || $token !== $expectedToken) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $entries = Entry::whereCollection('properties')
            ->sortBy('date')
            ->map(function ($entry) {
                return [
                    'id' => $entry->id(),
                    'title' => $entry->get('title'),
                    'price' => $entry->get('price'),
                    'date' => $entry->date() ? $entry->date()->timestamp : null,
                    'published' => $entry->published()
                ];
            })
            ->values()
            ->all();
        
        return response()->json([
            'success' => true, 
            'entries' => $entries
        ]);
        
    } catch (\Exception $e) {
        Log::error('Ошибка при получении списка: ' . $e->getMessage());
        
        return response()->json([
            'success' => false,
            'message' => 'Ошибка при получении списка: ' . $e->getMessage()
        ], 500);
    }
})->withoutMiddleware(['web', 'verify.csrf']);

// API для удаления всех записей
Route::delete('/api/telegram-property/delete/all', function (Request $request) {
    try {
        // Проверка аутентификации
        $token = $request->bearerToken();
        $expectedToken = env('TELEGRAM_API_TOKEN');
        
        if (!$token || $token !== $expectedToken) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $entries = Entry::whereCollection('properties')->get();
        $count = 0;
        
        foreach ($entries as $entry) {
            $entry->delete();
            $count++;
        }
        
        return response()->json([
            'success' => true, 
            'message' => "Удалено $count записей."
        ]);
        
    } catch (\Exception $e) {
        Log::error('Ошибка при удалении: ' . $e->getMessage());
        
        return response()->json([
            'success' => false,
            'message' => 'Ошибка при удалении: ' . $e->getMessage()
        ], 500);
    }
})->withoutMiddleware(['web', 'verify.csrf']);

// API для удаления записей по ID
Route::delete('/api/telegram-property/delete/{id}', function ($id, Request $request) {
    try {
        // Проверка аутентификации
        $token = $request->bearerToken();
        $expectedToken = env('TELEGRAM_API_TOKEN');
        
        if (!$token || $token !== $expectedToken) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $entry = Entry::find($id);
        
        if (!$entry) {
            return response()->json([
                'success' => false,
                'message' => 'Запись не найдена.'
            ], 404);
        }
        
        $entry->delete();
        
        return response()->json([
            'success' => true, 
            'message' => 'Запись успешно удалена.'
        ]);
        
    } catch (\Exception $e) {
        Log::error('Ошибка при удалении: ' . $e->getMessage());
        
        return response()->json([
            'success' => false,
            'message' => 'Ошибка при удалении: ' . $e->getMessage()
        ], 500);
    }
})->withoutMiddleware(['web', 'verify.csrf']);

// API для удаления черновиков
Route::delete('/api/telegram-property/delete/drafts', function (Request $request) {
    try {
        // Проверка аутентификации
        $token = $request->bearerToken();
        $expectedToken = env('TELEGRAM_API_TOKEN');
        
        if (!$token || $token !== $expectedToken) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $entries = Entry::whereCollection('properties')
                        ->where('published', false)
                        ->get();
        
        $count = 0;
        foreach ($entries as $entry) {
            $entry->delete();
            $count++;
        }
        
        return response()->json([
            'success' => true, 
            'message' => "Удалено $count черновиков."
        ]);
        
    } catch (\Exception $e) {
        Log::error('Ошибка при удалении черновиков: ' . $e->getMessage());
        
        return response()->json([
            'success' => false,
            'message' => 'Ошибка при удалении черновиков: ' . $e->getMessage()
        ], 500);
    }
})->withoutMiddleware(['web', 'verify.csrf']);

// API для удаления старых записей (старше 30 дней)
Route::delete('/api/telegram-property/delete/old', function (Request $request) {
    try {
        // Проверка аутентификации
        $token = $request->bearerToken();
        $expectedToken = env('TELEGRAM_API_TOKEN');
        
        if (!$token || $token !== $expectedToken) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $thirtyDaysAgo = now()->subDays(30)->timestamp;
        
        $entries = Entry::whereCollection('properties')
                        ->where('date', '<', $thirtyDaysAgo)
                        ->get();
        
        $count = 0;
        foreach ($entries as $entry) {
            $entry->delete();
            $count++;
        }
        
        return response()->json([
            'success' => true, 
            'message' => "Удалено $count старых записей (старше 30 дней)."
        ]);
        
    } catch (\Exception $e) {
        Log::error('Ошибка при удалении старых записей: ' . $e->getMessage());
        
        return response()->json([
            'success' => false,
            'message' => 'Ошибка при удалении старых записей: ' . $e->getMessage()
        ], 500);
    }
})->withoutMiddleware(['web', 'verify.csrf']);

// API для обработки заявок с сайта
Route::post('/submit-application', function (Request $request) {
    \Log::info('=== DEBUG APPLICATION SUBMISSION ===');
    \Log::info('Headers:', $request->headers->all());
    \Log::info('All data:', $request->all());
    \Log::info('JSON:', [$request->getContent()]);
    
    try {
        Log::info('📋 Получена новая заявка с сайта', $request->all());
        
        // Валидация данных
        $validated = $request->validate([
            'full_name' => 'required|string|max:255',
            'telegram' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'email' => 'required|email|max:255',
            'budget' => 'nullable|string|max:100',
            'consultation_type' => 'nullable|string|in:покупка,аренда',
            'checkin_date' => 'nullable|date',
            'period' => 'nullable|string|max:50',
            'people_count' => 'nullable|integer|min:1',
            'message' => 'nullable|string',
            'property_id' => 'nullable|string',
            'property_title' => 'nullable|string|max:255',
            'property_price' => 'nullable|string|max:100',
            'property_type' => 'nullable|string|in:rent,buy'
        ]);
        
        Log::info('✅ Данные валидны', $validated);
        
        // Используем config() вместо env() для production
        $botToken = config('services.telegram.bot_token');
        $adminChatId = config('services.telegram.admin_chat_id');
        
        Log::info('Проверка конфигурации Telegram:', [
            'bot_token' => $botToken ? 'установлен' : 'не установлен',
            'admin_chat_id' => $adminChatId ? 'установлен' : 'не установлен'
        ]);
        
        // Если переменные настроены, отправляем в Telegram
        if ($botToken && $adminChatId) {
            // Формируем сообщение для администратора
            $telegramMessage = "📋 *Новая заявка с сайта:*\n\n";
            
            // Добавляем информацию об объекте, если есть
            if (!empty($validated['property_title'])) {
                $propertyType = $validated['property_type'] == 'rent' ? 'Аренда' : 'Покупка';
                $telegramMessage .= "🏠 *Объект:* {$validated['property_title']}\n";
                $telegramMessage .= "💰 *Цена:* {$validated['property_price']} €\n";
                $telegramMessage .= "🔖 *Тип:* {$propertyType}\n";
                if (!empty($validated['property_id'])) {
                    $telegramMessage .= "🆔 *ID объекта:* {$validated['property_id']}\n";
                }
                $telegramMessage .= "\n";
            }
            
            $telegramMessage .= "👤 *ФИО:* " . $validated['full_name'] . "\n";
            $telegramMessage .= "📱 *Telegram:* " . $validated['telegram'] . "\n";
            $telegramMessage .= "📞 *Телефон:* " . $validated['phone'] . "\n";
            $telegramMessage .= "📧 *Email:* " . $validated['email'] . "\n";
            
            // Добавляем бюджет и тип консультации, если не привязаны к объекту
            if (empty($validated['property_title'])) {
                if (!empty($validated['budget'])) {
                    $telegramMessage .= "💰 *Бюджет:* " . $validated['budget'] . "\n";
                }
                
                if (!empty($validated['consultation_type'])) {
                    $telegramMessage .= "🏠 *Тип консультации:* " . $validated['consultation_type'] . "\n";
                }
            }
            
            // Добавляем поля аренды, если они заполнены
            if (!empty($validated['checkin_date'])) {
                $telegramMessage .= "📅 *Дата заселения:* " . $validated['checkin_date'] . "\n";
            }
            
            if (!empty($validated['period'])) {
                $telegramMessage .= "⏱️ *Период заселения:* " . $validated['period'] . "\n";
            }
            
            if (!empty($validated['people_count'])) {
                $telegramMessage .= "👥 *Количество человек:* " . $validated['people_count'] . "\n";
            }
            
            if (!empty($validated['message'])) {
                $telegramMessage .= "📝 *Дополнительная информация:* " . $validated['message'] . "\n";
            }
            
            $telegramMessage .= "\n🕒 *Время отправки:* " . now()->format('d.m.Y H:i');
            
 // Отправляем сообщение администратору в Telegram
            $response = Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                'chat_id' => $adminChatId,
                'text' => $telegramMessage,
                'parse_mode' => 'Markdown'
            ]);
            
            if ($response->successful()) {
                Log::info('✅ Заявка отправлена администратору в Telegram');
            } else {
                Log::error('❌ Ошибка отправки в Telegram: ' . $response->body());
            }
        } else {
            Log::warning('⚠️ Переменные Telegram не настроены в конфиге, пропускаем отправку');
        }
        
        return response()->json(['success' => true, 'message' => 'Заявка отправлена!']);
        
    } catch (\Exception $e) {
        \Log::error('Application error details:', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
        
        return response()->json([
            'success' => false,
            'message' => 'Server error: ' . $e->getMessage(),
            'debug' => [
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]
        ], 500);
    }
})->withoutMiddleware(['web', 'verify.csrf']);

// API для создания новостей/блог-постов
Route::post('/api/telegram-blok', function (Request $request) {
    try {
        Log::info('📰 Получен запрос на создание новости от Telegram бота');
        
        // Проверка аутентификации
        $token = $request->bearerToken();
        $expectedToken = env('TELEGRAM_API_TOKEN');
        
        if (!$token || $token !== $expectedToken) {
            Log::warning('❌ Неавторизованный доступ к API новостей');
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        Log::info('📨 Данные новости:', $request->all());
        
        // Валидация данных для новости
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'blog_text' => 'required|string',
            'logo_blog' => 'sometimes|array',
            'logo_blog.*' => 'sometimes|url'
        ]);
        
        Log::info('✅ Данные новости валидны', $validated);
        
        // ===== ЗАГРУЗКА И СОХРАНЕНИЕ ИЗОБРАЖЕНИЙ =====
        $savedImages = [];

        if (!empty($validated['logo_blog'])) {
            foreach ($validated['logo_blog'] as $index => $imageUrl) {
                try {
                    Log::info("🖼️ Загрузка изображения новости {$index}: {$imageUrl}");
                    
                    // Используем cURL для загрузки
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $imageUrl);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
                    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    
                    $imageData = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    
                    if ($httpCode !== 200 || !$imageData) {
                        Log::warning("❌ Ошибка загрузки: HTTP {$httpCode}");
                        continue;
                    }
                    
                    // Сохраняем в public/assets/blok
                    $assetsPath = public_path('assets/blok');
                    if (!file_exists($assetsPath)) {
                        mkdir($assetsPath, 0755, true);
                    }
                    
                    // Генерируем имя файла
                    $filename = 'blok-' . time() . '-' . $index . '.jpg';
                    $fullPath = $assetsPath . '/' . $filename;
                    
                    // Сохраняем файл
                    if (file_put_contents($fullPath, $imageData) === false) {
                        Log::warning("❌ Не удалось сохранить файл");
                        continue;
                    }
                    
                    // Формируем путь для Statamic
                    $savedImages[] = 'blok/' . $filename;
                    
                    Log::info("✅ Изображение новости сохранено в assets: blok/{$filename}");
                    
                } catch (\Exception $e) {
                    Log::error("❌ Ошибка загрузки изображения новости: " . $e->getMessage());
                    continue;
                }
            }
        }
        // ===== КОНЕЦ ЗАГРУЗКИ ИЗОБРАЖЕНИЙ =====
        
        // СОХРАНЕНИЕ В STATAMIC (коллекция blok)
        $entry = Entry::make()
            ->collection('catalog') // Используем правильное имя коллекции
            ->data([
                'title' => $validated['title'],
                'blog_text' => $validated['blog_text'],
                'logo_blog' => $savedImages,
                'published' => true
                // slug и date будут автоматически сгенерированы Statamic
            ]);

        // Сохраняем запись
        $entry->save();
        
        $entryId = $entry->id();
        Log::info('💾 Новость сохранена в коллекцию blok', [
            'id' => $entryId,
            'title' => $validated['title']
        ]);
        
        return response()->json([
            'success' => true, 
            'message' => 'Новость успешно добавлена!',
            'entry_id' => $entryId,
            'images_saved' => count($savedImages)
        ]);
        
    } catch (\Illuminate\Validation\ValidationException $e) {
        Log::error('❌ Ошибка валидации новости: ' . json_encode($e->errors()));
        return response()->json([
            'success' => false,
            'message' => 'Ошибка валидации: ' . implode(', ', array_flatten($e->errors()))
        ], 422);
    } catch (\Exception $e) {
        Log::error('🔥 Ошибка при создании новости: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Ошибка: ' . $e->getMessage()
        ], 500);
    }
})->withoutMiddleware(['web', 'verify.csrf']);
// Endpoint для проверки Supabase (замените старый)
Route::get('/api/supabase-test', function() {
    try {
        $supabaseUrl = env('SUPABASE_URL');
        $supabaseKey = env('SUPABASE_SERVICE_KEY');
        
        if (!$supabaseUrl || !$supabaseKey) {
            return response()->json([
                'status' => 'error',
                'message' => 'Supabase credentials missing'
            ], 500);
        }
        
        // Пробуем получить список файлов в бакете properties
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $supabaseKey,
            'Content-Type' => 'application/json'
        ])->get("{$supabaseUrl}/storage/v1/object/list/properties");
        
        $files = [];
        if ($response->successful()) {
            $files = $response->json() ?? [];
        }
        
        return response()->json([
            'status' => 'success',
            'supabase_connected' => true,
            'bucket_exists' => $response->successful(),
            'files_count' => count($files),
            'supabase_url' => $supabaseUrl,
            'service_key_length' => strlen($supabaseKey),
            'files_sample' => array_slice($files, 0, 3) // первые 3 файла для примера
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage(),
            'supabase_connected' => false
        ], 500);
    }
})->withoutMiddleware(['web', 'verify.csrf']);

// Endpoint для тестовой загрузки изображения (исправленный)
Route::post('/api/test-upload', function(Request $request) {
    try {
        Log::info('🧪 Тестовая загрузка изображения');
        
        $imageUrl = $request->input('image_url');
        
        if (!$imageUrl) {
            return response()->json([
                'success' => false,
                'message' => 'image_url parameter required'
            ], 400);
        }
        
        $fileName = 'test_' . time() . '.jpg';
        $result = uploadToSupabaseStorage($imageUrl, $fileName);
        
        if ($result) {
            return response()->json([
                'success' => true,
                'message' => 'Image uploaded successfully',
                'url' => $result,
                'file_name' => $fileName
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload image'
            ], 500);
        }
        
    } catch (\Exception $e) {
        Log::error('❌ Ошибка тестовой загрузки: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => $e->getMessage()
        ], 500);
    }
})->withoutMiddleware(['web', 'verify.csrf']); // ⬅️ ВАЖНО: добавить withoutMiddleware

// Endpoint для проверки конфигурации
Route::get('/api/debug-config', function() {
    return response()->json([
        'supabase_url' => env('SUPABASE_URL') ? '✅ Установлен' : '❌ Отсутствует',
        'supabase_service_key' => env('SUPABASE_SERVICE_KEY') ? '✅ Установлен (' . strlen(env('SUPABASE_SERVICE_KEY')) . ' chars)' : '❌ Отсутствует',
        'supabase_anon_key' => env('SUPABASE_ANON_KEY') ? '✅ Установлен' : '❌ Отсутствует',
        'app_env' => env('APP_ENV'),
        'app_debug' => env('APP_DEBUG'),
        'app_url' => env('APP_URL')
    ]);
})->withoutMiddleware(['web', 'verify.csrf']);

// Остальные маршруты...
Route::statamic('/', 'home.index');
Route::statamic('catalog', 'catalog.index');
Route::statamic('about', 'about.index');
Route::statamic('services', 'services.index');
Route::statamic('open_bisnes', 'open_bisnes.index');
Route::statamic('consalt', 'consalt.index');
Route::statamic('contact', 'contact.index');
Route::statamic('blog', 'contact.show');
