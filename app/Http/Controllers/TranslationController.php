<?php 
public function translateSite(Request $request)
{
    $targetLocale = $request->input('locale');
    
    try {
        // 1. Переводим статические строки
        Artisan::call('translate:generate', [
            'to' => $targetLocale
        ]);
        
        // 2. Переводим контент
        Artisan::call('translate:content', [
            '--to' => $targetLocale
        ]);
        
        // 3. Переводим глобальные наборы
        $this->translateGlobals($targetLocale);
        
        return response()->json([
            'success' => true,
            'message' => "Site translated to {$targetLocale} successfully!"
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}