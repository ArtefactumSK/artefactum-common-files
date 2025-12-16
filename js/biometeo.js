document.addEventListener('DOMContentLoaded', () => {
    const input = document.getElementById('cityInput');
    const savedCity = sessionStorage.getItem('selectedCity') || 'Bratislava';

    // Načítame počasie pre uložené mesto
    loadWeather(savedCity);

    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            const city = input.value.trim();
            if (city !== '') {
                sessionStorage.setItem('selectedCity', city);
                loadWeather(city);
            }
        }
    });
});

async function loadWeather(city) {
    const weatherResult = document.getElementById('weatherResult');
    const input = document.getElementById('cityInput');

    // Zobrazíme loading správu
    weatherResult.innerHTML = '<p>Načítavam údaje...</p>';

    try {
        // Získame súradnice mesta pomocou Open-Meteo Geocoding API
        const geocodingUrl = `https://geocoding-api.open-meteo.com/v1/search?name=${encodeURIComponent(city)}&count=1&language=en&format=json`;
        const geocodingResponse = await fetch(geocodingUrl);
        if (!geocodingResponse.ok) {
            throw new Error(`HTTP ${geocodingResponse.status}: ${geocodingResponse.statusText}`);
        }
        const geocodingData = await geocodingResponse.json();

        if (!geocodingData.results || geocodingData.results.length === 0) {
            throw new Error('Mesto nebolo nájdené');
        }

        const { latitude, longitude, name, country_code } = geocodingData.results[0];

        // Získame údaje o počasí
        const weatherUrl = `https://api.open-meteo.com/v1/forecast?latitude=${latitude}&longitude=${longitude}&current=temperature_2m,apparent_temperature,relative_humidity_2m,pressure_msl,wind_speed_10m,weather_code&daily=sunrise,sunset,uv_index_max&timezone=auto&forecast_days=1`;
        const weatherResponse = await fetch(weatherUrl);
        if (!weatherResponse.ok) {
            throw new Error(`HTTP ${weatherResponse.status}: ${weatherResponse.statusText}`);
        }
        const weatherData = await weatherResponse.json();

        console.log('Weather data received:', weatherData);

        // Extrahujeme údaje
        const current = weatherData.current;
        const daily = weatherData.daily;

        // Prevod weather_code na popis a ikonu
        const { description, icon } = translateWeatherCode(current.weather_code);
        
        const sunrise = new Date(daily.sunrise[0]).toLocaleTimeString('sk-SK', { hour: '2-digit', minute: '2-digit' });
        const sunset = new Date(daily.sunset[0]).toLocaleTimeString('sk-SK', { hour: '2-digit', minute: '2-digit' });
        const pressureIcon = current.pressure_msl > 1013 ? '/arte-content/themes/senior/images/pressure-high.svg' : '/arte-content/themes/senior/images/pressure-low.svg';
        const uvIcon = daily.uv_index_max[0] > 6 ? '/arte-content/themes/senior/images/sun-high.svg' : '/arte-content/themes/senior/images/sun.svg';

        // Vytvoríme HTML výstup s ikonou pred popisom počasia
        const weatherHtml = `
            <h5>${name}, ${country_code}</h5>
            <p><img class="weatherbiticon" src="${icon}" alt="${description}" style="width: 64px; vertical-align: middle;"><span style="vertical-align: middle;">${description}</span></p>
            <p>
                <span><img class="biometeoicon weathericon" src="/arte-content/themes/senior/images/sunrise.svg"> <strong>${sunrise}</strong></span>
                <span><img class="biometeoicon weathericon" src="/arte-content/themes/senior/images/sunset.svg"> <strong>${sunset}</strong></span>
                <span class="span100"><img class="biometeoicon" src="/arte-content/themes/senior/images/temperature.svg"> Teplota: <strong>${current.temperature_2m}</strong>°C, pocitovo <strong>${current.apparent_temperature}</strong>°C</span>
                <span class="span100"><img class="biometeoicon humidity" src="/arte-content/themes/senior/images/humidity.svg"> Vlhkosť: <strong>${current.relative_humidity_2m}</strong>%</span>
                <span class="span100"><img class="biometeoicon wind" src="/arte-content/themes/senior/images/wind.svg"> Vietor: <strong>${current.wind_speed_10m.toFixed(1)}</strong> m/s</span>
                <span class="span100"><img class="biometeoicon apressure" src="${pressureIcon}"> Tlak: <strong>${current.pressure_msl}</strong> hPa</span>
                <span class="span100"><img class="biometeoicon sun" src="${uvIcon}"> UV index: <strong>${daily.uv_index_max[0]}</strong> – <strong>${getBioLevel(daily.uv_index_max[0])}</strong></span>
            </p>
        `;

        weatherResult.innerHTML = weatherHtml;

        // Zobrazíme input pole a vyčistíme ho
        input.classList.remove('hidden');
        input.value = '';
        input.placeholder = 'Zadajte mesto a stlačte enter ↵';
    } catch (err) {
        console.error('Chyba:', err);

        // Detailnejšie chybové hlásenie
        let errorMessage = 'Chyba pri načítaní údajov o počasí';
        if (err.message.includes('HTTP 401')) {
            errorMessage = 'Neplatný API kľúč';
        } else if (err.message.includes('HTTP 404') || err.message.includes('Mesto nebolo nájdené')) {
            errorMessage = 'Zadaná lokalita nebola nájdená';
        } else if (err.message.includes('HTTP 429')) {
            errorMessage = 'Prekročený limit requestov API';
        } else if (err.message.includes('Failed to fetch')) {
            errorMessage = 'Problém s pripojením k internetu alebo CORS';
        }

        weatherResult.innerHTML = `<p class="error">${errorMessage}</p>`;
        // Zabezpečíme, že input zostane viditeľný a funkčný
        input.classList.remove('hidden');
        input.value = '';
        input.placeholder = 'Zadajte iné mesto a stlačte enter ↵';
    }
}

// Funkcia na prevod weather_code na popis a ikonu
function translateWeatherCode(code) {
    const weatherCodes = {
        0: { description: 'Jasná obloha', icon: '/arte-content/themes/senior/images/day.svg' },
        1: { description: 'Prevažne jasno', icon: '/arte-content/themes/senior/images/cloudy-day-1.svg' },
        2: { description: 'Mierne oblačno', icon: '/arte-content/themes/senior/images/cloudy-day-3.svg' },
        3: { description: 'Zamračené', icon: '/arte-content/themes/senior/images/cloudy.svg' },
        45: { description: 'Hmla', icon: '/arte-content/themes/senior/images/fog.svg' },
        48: { description: 'Hmla', icon: '/arte-content/themes/senior/images/fog.svg' },
        51: { description: 'Mrholenie', icon: '/arte-content/themes/senior/images/rainy-7.svg' },
        53: { description: 'Mrholenie', icon: '/arte-content/themes/senior/images/rainy-7.svg' },
        55: { description: 'Mrholenie', icon: '/arte-content/themes/senior/images/rainy-7.svg' },
        61: { description: 'Slabý dážď', icon: '/arte-content/themes/senior/images/rainy-4.svg' },
        63: { description: 'Mierny dážď', icon: '/arte-content/themes/senior/images/rainy-5.svg' },
        65: { description: 'Silný dážď', icon: '/arte-content/themes/senior/images/rainy-6.svg' },
        71: { description: 'Slabé sneženie', icon: '/arte-content/themes/senior/images/snowy-4.svg' },
        73: { description: 'Mierne sneženie', icon: '/arte-content/themes/senior/images/snowy-5.svg' },
        75: { description: 'Silné sneženie', icon: '/arte-content/themes/senior/images/snowy-6.svg' },
        80: { description: 'Dážď', icon: '/arte-content/themes/senior/images/rainy-2.svg' },
        81: { description: 'Silný dážď', icon: '/arte-content/themes/senior/images/rainy-3.svg' },
        82: { description: 'Prívalový dážď', icon: '/arte-content/themes/senior/images/rainy-7.svg' },
        95: { description: 'Búrka', icon: '/arte-content/themes/senior/images/thunder.svg' },
        96: { description: 'Búrka so snehom', icon: '/arte-content/themes/senior/images/snowy-6.svg' },
        99: { description: 'Búrka s krupobitím', icon: '/arte-content/themes/senior/images/thunder.svg' }
    };
    return weatherCodes[code] || { description: 'Neznámy stav', icon: '/arte-content/themes/senior/images/unknown.svg' };
}

// Funkcie getBioLevel a getAqiDesc zostávajú rovnaké
function getBioLevel(uv) {
    if (uv < 3) return 'nízky';
    if (uv < 6) return 'stredný';
    if (uv < 8) return 'vysoký';
    if (uv < 11) return 'veľmi vysoký';
    return 'extrémny';
}

function getAqiDesc(aqi) {
    if (aqi <= 50) return 'výborné';
    if (aqi <= 100) return 'dobré';
    if (aqi <= 150) return 'mierne znečistené';
    if (aqi <= 200) return 'nezdravé pre citlivé skupiny';
    if (aqi <= 300) return 'nezdravé';
    return 'veľmi nezdravé';
}    