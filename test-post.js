require('dotenv').config();
const axios = require('axios');

async function testPostAPI() {
    try {
        console.log('🧪 Тестируем POST запрос к API...');
        
        const response = await axios.post(
            'http://rent0.2.test/api/telegram-property',
            {
                title: "Test Property from Bot",
                type: "rent",
                price: 1000,
                address: "Test Address 123",
                district: "Mamaia",
                floor: 2,
                rooms: 3,
                lift: "есть",
                balcony: "есть",
                bathroom: 1,
                type_home: "квартира",
                images: ["https://picsum.photos/200/300"]
            },
            {
                headers: {
                    'Authorization': `Bearer ${process.env.API_KEY}`,
                    'Content-Type': 'application/json'
                }
            }
        );
        
        console.log('✅ УСПЕХ! Ответ от API:');
        console.log(JSON.stringify(response.data, null, 2));
        
    } catch (error) {
        console.log('❌ Ошибка:');
        if (error.response) {
            console.log('Status:', error.response.status);
            console.log('Data:', error.response.data);
        } else {
            console.log('Message:', error.message);
        }
    }
}

testPostAPI();