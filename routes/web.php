<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\Entry;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Statamic\Http\Controllers\GlideController;


use Statamic\Facades\Collection;

Route::get('/img/{path}', [GlideController::class, 'generateByPath'])
    ->where('path', '.*')
    ->name('statamic.glide.generateByPath');

// API для создания объектов с дополнительными изображениями
Route::post('/api/telegram-property', function (Request $request) {
    try {
        Log::info('📨 Получен запрос от Telegram бота');
        
        // Проверка аутентификации
        $token = $request->bearerToken();
        $expectedToken = env('TELEGRAM_API_TOKEN');
        
        if (!$token || $token !== $expectedToken) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
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
        
        // ===== ЗАГРУЗКА ГЛАВНЫХ ИЗОБРАЖЕНИЙ =====
        $savedImages = [];

        if (!empty($validated['images'])) {
            foreach ($validated['images'] as $index => $imageUrl) {
                try {
                    Log::info("🖼️ Загрузка главного изображения {$index}: {$imageUrl}");
                    
                    $imageData = downloadImage($imageUrl);
                    if (!$imageData) {
                        Log::warning("❌ Ошибка загрузки главного изображения");
                        continue;
                    }
                    
                    $filename = 'property-main-' . time() . '-' . $index . '.jpg';
                    $savedPath = saveImageToAssets($imageData, $filename, 'properties');
                    
                    if ($savedPath) {
                        $savedImages[] = $savedPath;
                        Log::info("✅ Главное изображение сохранено: {$savedPath}");
                    }
                    
                } catch (\Exception $e) {
                    Log::error("❌ Ошибка загрузки главного изображения: " . $e->getMessage());
                    continue;
                }
            }
        }
        
        // ===== ЗАГРУЗКА ДОПОЛНИТЕЛЬНЫХ ИЗОБРАЖЕНИЙ =====
        $savedAssetsArray = [];

        if (!empty($validated['assets_array'])) {
            foreach ($validated['assets_array'] as $index => $imageUrl) {
                try {
                    Log::info("🖼️ Загрузка дополнительного изображения {$index}: {$imageUrl}");
                    
                    $imageData = downloadImage($imageUrl);
                    if (!$imageData) {
                        Log::warning("❌ Ошибка загрузки дополнительного изображения");
                        continue;
                    }
                    
                    $filename = 'property-asset-' . time() . '-' . $index . '.jpg';
                    $savedPath = saveImageToAssets($imageData, $filename, 'properties');
                    
                    if ($savedPath) {
                        $savedAssetsArray[] = $savedPath;
                        Log::info("✅ Дополнительное изображение сохранено: {$savedPath}");
                    }
                    
                } catch (\Exception $e) {
                    Log::error("❌ Ошибка загрузки дополнительного изображения: " . $e->getMessage());
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
                'images' => $savedImages,
                'assets_array' => $savedAssetsArray,
                'published' => true
            ]);

        // Сохраняем запись
        $entry->save();
        
        $entryId = $entry->id();
        Log::info('💾 Запись сохранена', [
            'id' => $entryId,
            'main_images_count' => count($savedImages),
            'assets_images_count' => count($savedAssetsArray)
        ]);
        
        return response()->json([
            'success' => true, 
            'message' => 'Объект успешно добавлен с ' . count($savedImages) . ' основными и ' . count($savedAssetsArray) . ' дополнительными изображениями!',
            'entry_id' => $entryId,
            'images_saved' => count($savedImages),
            'assets_array_saved' => count($savedAssetsArray)
        ]);
        
    } catch (\Exception $e) {
        Log::error('🔥 Ошибка: ' . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
        
        return response()->json([
            'success' => false,
            'message' => 'Ошибка: ' . $e->getMessage()
        ], 500);
    }
})->withoutMiddleware(['web', 'verify.csrf']);

// Вспомогательные функции для загрузки изображений (СТАРЫЕ РАБОЧИЕ ФУНКЦИИ)
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

function uploadToSUPABASEStorage($imageUrl, $fileName) {
    try {
        $supabaseUrl = env('SUPABASE_URL');
        $supabaseKey = env('SUPABASE_SERVICE_KEY');
        
        Log::info("🔄 Начало загрузки в Supabase", [
            'image_url' => $imageUrl,
            'file_name' => $fileName,
            'supabase_url' => $supabaseUrl,
            'key_length' => $supabaseKey ? strlen($supabaseKey) : 0
        ]);
        
        if (!$supabaseUrl || !$supabaseKey) {
            Log::error('❌ Supabase credentials not configured');
            throw new Exception('Supabase credentials not configured');
        }
        
        // Скачиваем изображение с таймаутом
        $client = new \GuzzleHttp\Client();
        $response = $client->get($imageUrl, [
            'timeout' => 30,
            'connect_timeout' => 10,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Accept' => 'image/*'
            ],
            'verify' => false // На время разработки отключаем SSL verification
        ]);
        
        $imageData = $response->getBody()->getContents();
        
        if (empty($imageData)) {
            Log::error('❌ Failed to download image - empty response');
            throw new Exception('Failed to download image - empty response');
        }
        
        Log::info("📥 Изображение скачано", [
            'size' => strlen($imageData) . " bytes",
            'content_type' => $response->getHeader('Content-Type')[0] ?? 'unknown'
        ]);

        // Загружаем в Supabase Storage
        $uploadUrl = "{$supabaseUrl}/storage/v1/object/properties/{$fileName}";
        
        Log::info("📤 Загрузка в Supabase", ['upload_url' => $uploadUrl]);
        
        $uploadResponse = Http::withHeaders([
            'Authorization' => 'Bearer ' . $supabaseKey,
            'Content-Type' => 'image/jpeg'
        ])
        ->withOptions([
            'timeout' => 60,
            'connect_timeout' => 30,
            'verify' => false
        ])
        ->put($uploadUrl, $imageData);

        $statusCode = $uploadResponse->status();
        Log::info("📤 Ответ от Supabase", [
            'status' => $statusCode,
            'success' => $uploadResponse->successful()
        ]);
        
        if ($uploadResponse->successful()) {
            $publicUrl = "{$supabaseUrl}/storage/v1/object/public/properties/{$fileName}";
            Log::info("✅ Изображение загружено в Supabase", [
                'public_url' => $publicUrl,
                'file_name' => $fileName
            ]);
            return $publicUrl;
        } else {
            $errorBody = $uploadResponse->body();
            Log::error("❌ Ошибка загрузки в Supabase", [
                'status' => $statusCode,
                'error' => $errorBody,
                'headers' => $uploadResponse->headers()
            ]);
            throw new Exception("Supabase upload failed: HTTP {$statusCode} - {$errorBody}");
        }

    } catch (\GuzzleHttp\Exception\RequestException $e) {
        Log::error('❌ Ошибка скачивания изображения: ' . $e->getMessage());
        return null;
    } catch (\Exception $e) {
        Log::error('❌ Ошибка в uploadToLocalStorage: ' . $e->getMessage());
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
// Проверка конфигурации

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
        $result = uploadToLocalStorage($imageUrl, $fileName);
        
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


Route::get('/api/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toISOString(),
        'environment' => app()->environment()
    ]);
});
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

Route::get('/api/debug-config', function() {
    return response()->json([
        'supabase_url' => env('SUPABASE_URL') ? '✅ Установлен' : '❌ Отсутствует',
        'supabase_service_key' => env('SUPABASE_SERVICE_KEY') ? '✅ Установлен' : '❌ Отсутствует',
        'supabase_anon_key' => env('SUPABASE_ANON_KEY') ? '✅ Установлен' : '❌ Отсутствует',
        'app_env' => env('APP_ENV'),
        'app_debug' => env('APP_DEBUG')
    ]);
});

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
        $result = uploadToLocalStorage($imageUrl, $fileName);
        
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
Route::get('/api/check-storage', function() {
    $storagePath = public_path('storage');
    $exists = file_exists($storagePath);
    $isLink = is_link($storagePath);
    
    return response()->json([
        'storage_exists' => $exists,
        'is_symlink' => $isLink,
        'storage_path' => $storagePath,
        'public_path' => public_path()
    ]);
});

// Остальные маршруты...
Route::statamic('/', 'home.index');
Route::statamic('catalog', 'catalog.index');
Route::statamic('about', 'about.index');
Route::statamic('services', 'services.index');
Route::statamic('open_bisnes', 'open_bisnes.index');
Route::statamic('consalt', 'consalt.index');
Route::statamic('contact', 'contact.index');
Route::statamic('blog', 'contact.show');

// Главный маршрут для настройки мультиязычности
Route::get('/setup-multilingual', function() {
    $output = "<h1>Настройка мультиязычного сайта</h1>";
    
    try {
        // Принудительно устанавливаем сайт по умолчанию
        \Statamic\Facades\Site::setCurrent('default');
        
        $output .= "<p>✓ Текущий сайт установлен: default</p>";
        
        // 1. Создаем коллекцию если её нет
        $collection = \Statamic\Facades\Collection::find('pages');
        if (!$collection) {
            $collection = \Statamic\Facades\Collection::make('pages')
                ->title('Pages')
                ->routes('{slug}')
                ->sites(['default', 'english', 'ukrainian']);
            
            $collection->save();
            $output .= "<p>✓ Коллекция 'pages' создана</p>";
        } else {
            $output .= "<p>✓ Коллекция 'pages' уже существует</p>";
        }

        // 2. Создаем тестовый контент только для default сайта сначала
        $pages = [
            'home' => ['title' => 'Главная страница', 'content' => 'Добро пожаловать на наш сайт'],
            'about' => ['title' => 'О нас', 'content' => 'Информация о нашей компании']
        ];

        foreach ($pages as $slug => $data) {
            $entry = \Statamic\Facades\Entry::query()
                ->where('site', 'default')
                ->where('slug', $slug)
                ->first();

            if (!$entry) {
                $entry = \Statamic\Facades\Entry::make()
                    ->collection('pages')
                    ->slug($slug)
                    ->locale('default')
                    ->data($data);
                
                $entry->save();
                $output .= "<p>✓ Создана страница: default/{$slug}</p>";
            } else {
                $output .= "<p>➡ Страница уже существует: default/{$slug}</p>";
            }
        }

        $output .= "<h2>Настройка завершена для русского сайта!</h2>";
        $output .= "<p>Проверьте ваш сайт: <a href='/'>Русская версия</a></p>";
        $output .= "<p><a href='/debug-sites'>Проверить диагностику сайтов</a></p>";

    } catch (\Exception $e) {
        $output .= "<p style='color: red'>Произошла ошибка: " . $e->getMessage() . "</p>";
        $output .= "<p><a href='/debug-sites'>Перейти к диагностике</a></p>";
    }

    return $output;
});

// Маршрут для проверки контента
Route::get('/check-config', function() {
    echo "<h1>Проверка конфигурации</h1>";
    
    // Проверяем существует ли файл конфигурации
    $configPath = config_path('statamic/sites.php');
    $configExists = file_exists($configPath);
    
    echo "<p>Путь к конфигурации: {$configPath}</p>";
    echo "<p>Файл существует: " . ($configExists ? '✅ Да' : '❌ Нет') . "</p>";
    
    if ($configExists) {
        $configContent = file_get_contents($configPath);
        echo "<h3>Содержимое файла:</h3>";
        echo "<pre>" . htmlspecialchars($configContent) . "</pre>";
    }
    
    // Проверяем загруженную конфигурацию
    $sitesConfig = config('statamic.sites.sites');
    echo "<h3>Загруженная конфигурация:</h3>";
    if ($sitesConfig) {
        echo "<pre>" . print_r($sitesConfig, true) . "</pre>";
    } else {
        echo "<p style='color: red'>Конфигурация не загружена!</p>";
    }
    
    return "<p><a href='/fix-languages'>Перейти к созданию контента</a></p>";
});

// Простой тестовый маршрут
Route::get('/test', function() {
    return "
        <h1>Тестовая страница</h1>
        <p>Если вы видите эту страницу, маршруты работают</p>
        <p><a href='/setup-multilingual'>Настроить мультиязычность</a></p>
        <p><a href='/check-content'>Проверить контент</a></p>
    ";
});

Route::get('/fix-languages', function() {
    echo "<h1>Исправление мультиязычности (безопасная версия)</h1>";
    
    // Получаем конфигурацию напрямую из config
    $sites = config('statamic.sites.sites');
    
    if (!$sites) {
        echo "<p style='color: red'>Конфигурация сайтов не найдена!</p>";
        echo "<p>Проверьте файл config/statamic/sites.php</p>";
        return;
    }
    
    echo "<h2>Конфигурация сайтов из config:</h2>";
    echo "<pre>" . print_r($sites, true) . "</pre>";
    
    // Создаем коллекцию если её нет
    try {
        $collection = \Statamic\Facades\Collection::find('pages');
        if (!$collection) {
            $collection = \Statamic\Facades\Collection::make('pages')
                ->title('Pages')
                ->routes('{slug}')
                ->sites(array_keys($sites)); // Используем handles из конфигурации
            
            $collection->save();
            echo "<p>✓ Коллекция 'pages' создана</p>";
        } else {
            echo "<p>✓ Коллекция 'pages' уже существует</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red'>Ошибка создания коллекции: " . $e->getMessage() . "</p>";
    }
    
    // Создаем контент для каждого сайта
    $pagesContent = [
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
    
    foreach ($sites as $siteHandle => $siteConfig) {
        echo "<h3>Обрабатываем сайт: {$siteHandle}</h3>";
        
        if (!isset($pagesContent[$siteHandle])) {
            echo "<p style='color: orange'>Нет контента для сайта {$siteHandle}</p>";
            continue;
        }
        
        foreach ($pagesContent[$siteHandle] as $slug => $data) {
            try {
                $entry = \Statamic\Facades\Entry::query()
                    ->where('site', $siteHandle)
                    ->where('slug', $slug)
                    ->first();
                    
                if (!$entry) {
                    $entry = \Statamic\Facades\Entry::make()
                        ->collection('pages')
                        ->slug($slug)
                        ->locale($siteHandle)
                        ->data($data);
                    $entry->save();
                    echo "<p>✓ Создана страница: {$siteHandle}/{$slug}</p>";
                } else {
                    echo "<p>✓ Уже существует: {$siteHandle}/{$slug}</p>";
                }
            } catch (Exception $e) {
                echo "<p style='color: red'>Ошибка создания {$siteHandle}/{$slug}: " . $e->getMessage() . "</p>";
            }
        }
    }
    
    echo "<h2>Проверка завершена!</h2>";
    echo "<p>Теперь проверьте:</p>";
    echo "<ul>";
    echo "<li><a href='/'>Русская версия</a></li>";
    echo "<li><a href='/en'>Английская версия</a></li>";
    echo "<li><a href='/uk'>Украинская версия</a></li>";
    echo "</ul>";
});
Route::get('/debug-current', function() {
    echo "<h1>Диагностика текущего состояния</h1>";
    
    // Проверка текущего сайта
    try {
        $currentSite = \Statamic\Facades\Site::current();
        echo "<h2>Текущий сайт:</h2>";
        if ($currentSite) {
            echo "<ul>";
            echo "<li>Handle: " . $currentSite->handle() . "</li>";
            echo "<li>Name: " . $currentSite->name() . "</li>";
            echo "<li>Locale: " . $currentSite->locale() . "</li>";
            echo "<li>URL: " . $currentSite->url() . "</li>";
            echo "<li>Lang: " . $currentSite->lang() . "</li>";
            echo "</ul>";
        } else {
            echo "<p style='color: red'>Текущий сайт не определен!</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red'>Ошибка: " . $e->getMessage() . "</p>";
    }
    
    // Проверка что видит Antlers
    echo "<h2>Что видит шаблонизатор:</h2>";
    echo "<ul>";
    echo "<li>site:locale: '" . (\Statamic\View\Antlers::parse('{{ site:locale }}') ?: 'empty') . "'</li>";
    echo "<li>site:handle: '" . (\Statamic\View\Antlers::parse('{{ site:handle }}') ?: 'empty') . "'</li>";
    echo "<li>site:name: '" . (\Statamic\View\Antlers::parse('{{ site:name }}') ?: 'empty') . "'</li>";
    echo "<li>site:url: '" . (\Statamic\View\Antlers::parse('{{ site:url }}') ?: 'empty') . "'</li>";
    echo "</ul>";
    
    // Проверка доступных сайтов
    echo "<h2>Доступные сайты:</h2>";
    $sites = \Statamic\Facades\Site::all();
    foreach ($sites as $site) {
        echo "<p><strong>{$site->handle()}:</strong> {$site->name()} (locale: {$site->locale()}, url: {$site->url()})</p>";
    }
    
    return "<p><a href='/'>Вернуться на сайт</a></p>";
});

Route::get('/check-existing-content', function() {
    echo "<h1>Проверка существующего контента</h1>";
    
    $sites = ['default', 'english', 'ukrainian'];
    $slugs = ['home', 'about'];
    
    foreach ($sites as $site) {
        echo "<h2>Сайт: {$site}</h2>";
        foreach ($slugs as $slug) {
            $entry = \Statamic\Facades\Entry::query()
                ->where('site', $site)
                ->where('slug', $slug)
                ->first();
                
            if ($entry) {
                $title = $entry->get('title') ?? 'Без названия';
                echo "<p>✅ {$slug}: {$title}</p>";
            } else {
                echo "<p>❌ {$slug}: не существует</p>";
            }
        }
    }
    
    return "<p><a href='/fix-languages'>Создать недостающий контент</a></p>";
});

Route::get('/create-all-languages-content-fixed', function() {
    echo "<h1>Создание контента для всех языков (исправленная версия)</h1>";
    
    // Принудительно устанавливаем конфигурацию сайтов
    \Statamic\Facades\Site::setConfig([
        'default' => [
            'name' => 'Русский',
            'locale' => 'ru_RU',
            'url' => '/',
        ],
        'english' => [
            'name' => 'English', 
            'locale' => 'en_US',
            'url' => '/en/',
        ],
        'ukrainian' => [
            'name' => 'Українська',
            'locale' => 'uk_UA',
            'url' => '/uk/',
        ],
    ]);
    
    // Контент для каждого языка
    $languages = [
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
    
    foreach ($languages as $siteHandle => $pages) {
        echo "<h2>Создаем контент для: {$siteHandle}</h2>";
        
        // Принудительно устанавливаем текущий сайт
        \Statamic\Facades\Site::setCurrent($siteHandle);
        
        foreach ($pages as $slug => $data) {
            try {
                // Проверяем существует ли запись
                $existingEntry = \Statamic\Facades\Entry::query()
                    ->where('site', $siteHandle)
                    ->where('slug', $slug)
                    ->first();
                
                if ($existingEntry) {
                    echo "<p>✅ Уже существует: {$siteHandle}/{$slug} - '{$data['title']}'</p>";
                } else {
                    // Создаем новую запись с явным указанием сайта
                    $entry = \Statamic\Facades\Entry::make()
                        ->collection('pages')
                        ->slug($slug)
                        ->locale($siteHandle)
                        ->data($data);
                    
                    $entry->save();
                    echo "<p>✅ Создана: {$siteHandle}/{$slug} - '{$data['title']}'</p>";
                }
            } catch (Exception $e) {
                echo "<p style='color: red'>❌ Ошибка при создании {$siteHandle}/{$slug}: {$e->getMessage()}</p>";
                
                // Показываем более детальную информацию об ошибке
                if (strpos($e->getMessage(), 'lang() on null') !== false) {
                    echo "<p style='color: orange'>⚠️ Проблема с определением сайта. Пробуем альтернативный метод...</p>";
                    
                    // Альтернативный метод создания
                    try {
                        $entry = \Statamic\Facades\Entry::make()
                            ->collection('pages')
                            ->slug($slug)
                            ->data($data);
                        
                        // Пытаемся сохранить без явного указания сайта
                        $entry->save();
                        echo "<p>✅ Создана (альтернативным методом): {$siteHandle}/{$slug}</p>";
                    } catch (Exception $e2) {
                        echo "<p style='color: red'>❌ Альтернативный метод тоже не сработал: {$e2->getMessage()}</p>";
                    }
                }
            }
        }
    }
    
    echo "<h2>Процесс завершен!</h2>";
    echo "<p><a href='/check-existing-content'>Проверить созданный контент</a></p>";
    echo "<p><a href='/'>Проверить главную страницу</a></p>";
});

Route::get('/create-content-via-files', function() {
    echo "<h1>Создание контента через файлы</h1>";
    
    $contentStructure = [
        'default' => [
            'home' => "id: home\ntitle: 'Главная страница'\ncontent: 'Добро пожаловать на наш сайт'\ntemplate: home\n",
            'about' => "id: about\ntitle: 'О нас'\ncontent: 'Информация о нашей компании'\ntemplate: default\n"
        ],
        'english' => [
            'home' => "id: home\ntitle: 'Home Page'\ncontent: 'Welcome to our website'\ntemplate: home\n", 
            'about' => "id: about\ntitle: 'About Us'\ncontent: 'Information about our company'\ntemplate: default\n"
        ],
        'ukrainian' => [
            'home' => "id: home\ntitle: 'Головна сторінка'\ncontent: 'Ласкаво просимо на наш сайт'\ntemplate: home\n",
            'about' => "id: about\ntitle: 'Про нас'\ncontent: 'Інформація про нашу компанію'\ntemplate: default\n"
        ]
    ];
    
    $basePath = base_path('content/collections/pages');
    
    foreach ($contentStructure as $siteHandle => $pages) {
        $sitePath = "{$basePath}/{$siteHandle}";
        
        // Создаем директорию если не существует
        if (!file_exists($sitePath)) {
            mkdir($sitePath, 0755, true);
            echo "<p>✅ Создана директория: {$sitePath}</p>";
        }
        
        foreach ($pages as $slug => $content) {
            $filePath = "{$sitePath}/{$slug}.md";
            
            if (file_exists($filePath)) {
                echo "<p>✅ Уже существует: {$siteHandle}/{$slug}.md</p>";
            } else {
                file_put_contents($filePath, $content);
                echo "<p>✅ Создан файл: {$siteHandle}/{$slug}.md</p>";
            }
        }
    }
    
    echo "<h2>Файлы созданы! Очистите кеш:</h2>";
    echo "<p>Теперь выполните в терминале:</p>";
    echo "<pre>php artisan statamic:stache:clear</pre>";
    echo "<pre>php artisan statamic:stache:refresh</pre>";
    
    echo "<p><a href='/check-existing-content'>Проверить контент после очистки кеша</a></p>";
});

Route::get('/clear-cache', function() {
    echo "<h1>Очистка кеша</h1>";
    
    try {
        \Artisan::call('config:clear');
        echo "<p>✅ Конфигурационный кеш очищен</p>";
        
        \Artisan::call('cache:clear');
        echo "<p>✅ Кеш приложения очищен</p>";
        
        \Artisan::call('statamic:stache:clear');
        echo "<p>✅ Stache кеш очищен</p>";
        
        \Artisan::call('statamic:stache:refresh');
        echo "<p>✅ Stache кеш обновлен</p>";
        
        echo "<h2>Все кеши очищены!</h2>";
        echo "<p><a href='/check-existing-content'>Проверить контент</a></p>";
        echo "<p><a href='/'>Проверить сайт</a></p>";
        
    } catch (Exception $e) {
        echo "<p style='color: red'>❌ Ошибка при очистке кеша: {$e->getMessage()}</p>";
    }
});

Route::get('/check-files-content', function() {
    echo "<h1>Проверка содержимого файлов</h1>";
    
    $basePath = base_path('content/collections/pages');
    $sites = ['default', 'english', 'ukrainian'];
    $slugs = ['home', 'about'];
    
    foreach ($sites as $site) {
        echo "<h2>Сайт: {$site}</h2>";
        foreach ($slugs as $slug) {
            $filePath = "{$basePath}/{$site}/{$slug}.md";
            if (file_exists($filePath)) {
                $content = file_get_contents($filePath);
                echo "<h3>Файл: {$site}/{$slug}.md</h3>";
                echo "<pre>" . htmlspecialchars($content) . "</pre>";
                echo "<hr>";
            } else {
                echo "<p style='color: red'>Файл не существует: {$filePath}</p>";
            }
        }
    }
    
    return "<p><a href='/fix-files-content'>Исправить содержимое файлов</a></p>";
});
Route::get('/fix-files-content', function() {
    echo "<h1>Исправление содержимого файлов</h1>";
    
    $basePath = base_path('content/collections/pages');
    
    $correctContent = [
        'default' => [
            'home' => "id: home\ntitle: 'Главная страница'\ncontent: 'Добро пожаловать на наш сайт'\ntemplate: home\n",
            'about' => "id: about\ntitle: 'О нас'\ncontent: 'Информация о нашей компании'\ntemplate: default\n"
        ],
        'english' => [
            'home' => "id: home\ntitle: 'Home Page'\ncontent: 'Welcome to our website'\ntemplate: home\n",
            'about' => "id: about\ntitle: 'About Us'\ncontent: 'Information about our company'\ntemplate: default\n"
        ],
        'ukrainian' => [
            'home' => "id: home\ntitle: 'Головна сторінка'\ncontent: 'Ласкаво просимо на наш сайт'\ntemplate: home\n",
            'about' => "id: about\ntitle: 'Про нас'\ncontent: 'Інформація про нашу компанію'\ntemplate: default\n"
        ]
    ];
    
    foreach ($correctContent as $site => $pages) {
        echo "<h2>Сайт: {$site}</h2>";
        foreach ($pages as $slug => $content) {
            $filePath = "{$basePath}/{$site}/{$slug}.md";
            file_put_contents($filePath, $content);
            echo "<p>✅ Исправлен файл: {$site}/{$slug}.md</p>";
        }
    }
    
    echo "<h2>Файлы исправлены! Теперь выполните:</h2>";
    echo "<p><a href='/clear-cache-deep'>Глубокая очистка кеша</a></p>";
});
Route::get('/clear-cache-deep', function() {
    echo "<h1>Глубокая очистка кеша</h1>";
    
    try {
        \Artisan::call('config:clear');
        echo "<p>✅ Конфигурационный кеш очищен</p>";
        
        \Artisan::call('cache:clear');
        echo "<p>✅ Кеш приложения очищен</p>";
        
        \Artisan::call('view:clear');
        echo "<p>✅ Кеш шаблонов очищен</p>";
        
        \Artisan::call('route:clear');
        echo "<p>✅ Кеш маршрутов очищен</p>";
        
        \Artisan::call('statamic:stache:clear');
        echo "<p>✅ Stache кеш очищен</p>";
        
        // Дадим Statamic время на переиндексацию
        sleep(2);
        
        \Artisan::call('statamic:stache:refresh');
        echo "<p>✅ Stache кеш обновлен</p>";
        
        echo "<h2>Все кеши очищены! Подождите 5 секунд...</h2>";
        echo "<script>setTimeout(() => { window.location.href = '/check-existing-content'; }, 5000);</script>";
        echo "<p><a href='/check-existing-content'>Проверить контент (если не сработает авто-редирект)</a></p>";
        
    } catch (Exception $e) {
        echo "<p style='color: red'>❌ Ошибка при очистке кеша: {$e->getMessage()}</p>";
    }
});
Route::get('/fix-collection', function() {
    echo "<h1>Исправление коллекции</h1>";
    
    try {
        $collection = \Statamic\Facades\Collection::find('pages');
        
        if (!$collection) {
            echo "<p>Коллекция 'pages' не найдена. Создаем новую...</p>";
            $collection = \Statamic\Facades\Collection::make('pages');
        }
        
        // Настраиваем коллекцию для всех сайтов
        $collection
            ->title('Pages')
            ->routes(['default' => '{slug}', 'english' => '{slug}', 'ukrainian' => '{slug}'])
            ->sites(['default', 'english', 'ukrainian'])
            ->save();
            
        echo "<p>✅ Коллекция 'pages' настроена для всех сайтов</p>";
        echo "<p><a href='/clear-cache-deep'>Очистить кеш и проверить</a></p>";
        
    } catch (Exception $e) {
        echo "<p style='color: red'>❌ Ошибка при настройке коллекции: {$e->getMessage()}</p>";
    }
});
Route::get('/direct-file-check', function() {
    echo "<h1>Прямая проверка файлов</h1>";
    
    $basePath = base_path('content/collections/pages');
    
    function scanDirectory($path, $depth = 0) {
        $result = "";
        $items = scandir($path);
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            
            $fullPath = $path . '/' . $item;
            $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $depth);
            
            if (is_dir($fullPath)) {
                $result .= "<div>{$indent}📁 {$item}</div>";
                $result .= scanDirectory($fullPath, $depth + 1);
            } else {
                $size = filesize($fullPath);
                $result .= "<div>{$indent}📄 {$item} ({$size} bytes)</div>";
            }
        }
        
        return $result;
    }
    
    echo "<h2>Структура папки content/collections/pages:</h2>";
    echo scanDirectory($basePath);
    
    echo "<h2>Проверка прав доступа:</h2>";
    echo "<p>base_path(): " . base_path() . "</p>";
    echo "<p>Директория существует: " . (file_exists($basePath) ? '✅ Да' : '❌ Нет') . "</p>";
    echo "<p>Директория доступна для чтения: " . (is_readable($basePath) ? '✅ Да' : '❌ Нет') . "</p>";
    echo "<p>Директория доступна для записи: " . (is_writable($basePath) ? '✅ Да' : '❌ Нет') . "</p>";
});

Route::get('/diagnose-structure', function() {
    echo "<h1>Диагностика структуры сайта</h1>";
    
    // Проверим существующие записи
    echo "<h2>Существующие записи в коллекции pages:</h2>";
    $entries = \Statamic\Facades\Entry::all();
    foreach ($entries as $entry) {
        echo "<p>📍 {$entry->id()} - Сайт: {$entry->site()->handle()} - Шаблон: {$entry->template()}</p>";
    }
    
    // Проверим маршруты
    echo "<h2>Зарегистрированные маршруты Statamic:</h2>";
    $routes = [
        '/', 'catalog', 'about', 'services', 'open_bisnes', 'consalt', 'contact', 'blog'
    ];
    
    foreach ($routes as $route) {
        echo "<p>🛣️ {$route} -> " . (\Route::has($route) ? '✅ существует' : '❌ не найден') . "</p>";
    }
    
    return "<p><a href='/'>Вернуться на сайт</a></p>";
});
Route::get('/create-proper-content', function() {
    echo "<h1>Создание контента для вашей структуры</h1>";
    
    $sites = ['default', 'english', 'ukrainian'];
    
    // Основные страницы с правильными шаблонами
    $pages = [
        'home' => [
            'template' => 'home.index',
            'default' => ['title' => 'Главная', 'hero_slides' => []],
            'english' => ['title' => 'Home Page', 'hero_slides' => []],
            'ukrainian' => ['title' => 'Головна', 'hero_slides' => []]
        ],
        'catalog' => [
            'template' => 'catalog.index', 
            'default' => ['title' => 'Каталог'],
            'english' => ['title' => 'Catalog'],
            'ukrainian' => ['title' => 'Каталог']
        ],
        'about' => [
            'template' => 'about.index',
            'default' => ['title' => 'О нас'],
            'english' => ['title' => 'About Us'], 
            'ukrainian' => ['title' => 'Про нас']
        ],
        'services' => [
            'template' => 'services.index',
            'default' => ['title' => 'Услуги'],
            'english' => ['title' => 'Services'],
            'ukrainian' => ['title' => 'Послуги']
        ],
        'open_bisnes' => [
            'template' => 'open_bisnes.index',
            'default' => ['title' => 'Открытие бизнеса'],
            'english' => ['title' => 'Business Opening'],
            'ukrainian' => ['title' => 'Відкриття бізнесу']
        ],
        'consalt' => [
            'template' => 'consalt.index',
            'default' => ['title' => 'Консультация'],
            'english' => ['title' => 'Consultation'],
            'ukrainian' => ['title' => 'Консультація']
        ],
        'contact' => [
            'template' => 'contact.index', 
            'default' => ['title' => 'Контакты'],
            'english' => ['title' => 'Contact'],
            'ukrainian' => ['title' => 'Контакти']
        ],
        'blog' => [
            'template' => 'blog.index',
            'default' => ['title' => 'Блог'],
            'english' => ['title' => 'Blog'],
            'ukrainian' => ['title' => 'Блог']
        ]
    ];
    
    foreach ($sites as $site) {
        echo "<h2>Сайт: {$site}</h2>";
        
        foreach ($pages as $slug => $pageData) {
            try {
                // Проверяем существует ли запись
                $existing = \Statamic\Facades\Entry::query()
                    ->where('site', $site)
                    ->where('slug', $slug)
                    ->first();
                
                if ($existing) {
                    echo "<p>✅ Уже существует: {$site}/{$slug}</p>";
                } else {
                    // Создаем запись
                    $entry = \Statamic\Facades\Entry::make()
                        ->collection('pages')
                        ->slug($slug)
                        ->locale($site)
                        ->data(array_merge(
                            $pageData[$site] ?? $pageData['default'],
                            ['template' => $pageData['template']]
                        ));
                    
                    $entry->save();
                    echo "<p>✅ Создана: {$site}/{$slug} (шаблон: {$pageData['template']})</p>";
                }
            } catch (Exception $e) {
                echo "<p style='color: red'>❌ Ошибка: {$site}/{$slug} - {$e->getMessage()}</p>";
            }
        }
    }
    
    echo "<h2>Контент создан! Теперь проверьте:</h2>";
    echo "<p><a href='/clear-cache-deep'>Очистить кеш</a></p>";
    echo "<p>После очистки кеша проверьте:</p>";
    echo "<ul>";
    echo "<li><a href='/en'>Английская версия</a></li>";
    echo "<li><a href='/uk'>Украинская версия</a></li>";
    echo "</ul>";
});
Route::get('/test-routes', function() {
    echo "<h1>Тестирование маршрутов для всех языков</h1>";
    
    $routes = ['/', '/catalog', '/about', '/services', '/open_bisnes', '/consalt', '/contact', '/blog'];
    $sites = ['', '/en', '/uk'];
    
    foreach ($sites as $site) {
        echo "<h2>Сайт: " . ($site ?: 'default (/)') . "</h2>";
        foreach ($routes as $route) {
            $fullRoute = $site . $route;
            $status = $fullRoute === '//' ? '/' : $fullRoute;
            echo "<p><a href='{$status}' target='_blank'>{$status}</a></p>";
        }
    }
    
    return "<p>Проверьте каждый маршрут в новой вкладке</p>";
});