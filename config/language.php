<?php
/**
 * Multi-language Configuration and Translation System
 * Farmer Marketplace Platform
 */

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

class Language {
    private static $instance = null;
    private $currentLanguage = 'en';
    private $translations = [];
    private $supportedLanguages = [
        'en' => ['name' => 'English', 'flag' => '🇺🇸', 'rtl' => false],
        'hi' => ['name' => 'हिंदी', 'flag' => '🇮🇳', 'rtl' => false],
        'mr' => ['name' => 'मराठी', 'flag' => '🇮🇳', 'rtl' => false],
        'gu' => ['name' => 'ગુજરાતી', 'flag' => '🇮🇳', 'rtl' => false],
        'ta' => ['name' => 'தமிழ்', 'flag' => '🇮🇳', 'rtl' => false],
        'te' => ['name' => 'తెలుగు', 'flag' => '🇮🇳', 'rtl' => false],
        'kn' => ['name' => 'ಕನ್ನಡ', 'flag' => '🇮🇳', 'rtl' => false],
        'bn' => ['name' => 'বাংলা', 'flag' => '🇮🇳', 'rtl' => false]
    ];

    private function __construct() {
        $this->initializeLanguage();
        $this->loadTranslations();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function initializeLanguage() {
        // Priority: URL parameter > Session > Cookie > Browser > Default
        if (isset($_GET['lang']) && $this->isValidLanguage($_GET['lang'])) {
            $this->currentLanguage = $_GET['lang'];
            $_SESSION['language'] = $this->currentLanguage;
            setcookie('preferred_language', $this->currentLanguage, time() + (86400 * 365), '/');
        } elseif (isset($_SESSION['language']) && $this->isValidLanguage($_SESSION['language'])) {
            $this->currentLanguage = $_SESSION['language'];
        } elseif (isset($_COOKIE['preferred_language']) && $this->isValidLanguage($_COOKIE['preferred_language'])) {
            $this->currentLanguage = $_COOKIE['preferred_language'];
            $_SESSION['language'] = $this->currentLanguage;
        } else {
            // Detect from browser
            $browserLang = $this->detectBrowserLanguage();
            if ($browserLang) {
                $this->currentLanguage = $browserLang;
                $_SESSION['language'] = $this->currentLanguage;
                setcookie('preferred_language', $this->currentLanguage, time() + (86400 * 365), '/');
            }
        }
    }

    private function detectBrowserLanguage() {
        if (!isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            return null;
        }

        $acceptedLanguages = explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE']);
        foreach ($acceptedLanguages as $lang) {
            $lang = strtolower(trim(explode(';', $lang)[0]));
            
            // Direct match
            if ($this->isValidLanguage($lang)) {
                return $lang;
            }
            
            // Language code only (e.g., 'en' from 'en-US')
            $langCode = explode('-', $lang)[0];
            if ($this->isValidLanguage($langCode)) {
                return $langCode;
            }
        }
        
        return null;
    }

    private function isValidLanguage($lang) {
        return array_key_exists($lang, $this->supportedLanguages);
    }

    private function loadTranslations() {
        $translationFile = __DIR__ . "/translations/{$this->currentLanguage}.php";
        
        if (file_exists($translationFile)) {
            $this->translations = include $translationFile;
        } else {
            // Fallback to English
            $fallbackFile = __DIR__ . "/translations/en.php";
            if (file_exists($fallbackFile)) {
                $this->translations = include $fallbackFile;
            }
        }
    }

    public function get($key, $params = []) {
        $translation = $this->getNestedValue($this->translations, $key);
        
        if ($translation === null) {
            // Fallback to English if current language doesn't have the key
            if ($this->currentLanguage !== 'en') {
                $englishFile = __DIR__ . "/translations/en.php";
                if (file_exists($englishFile)) {
                    $englishTranslations = include $englishFile;
                    $translation = $this->getNestedValue($englishTranslations, $key);
                }
            }
            
            // If still not found, return the key itself
            if ($translation === null) {
                return $key;
            }
        }

        // Replace parameters
        if (!empty($params)) {
            foreach ($params as $param => $value) {
                $translation = str_replace(':' . $param, $value, $translation);
            }
        }

        return $translation;
    }

    private function getNestedValue($array, $key) {
        $keys = explode('.', $key);
        $value = $array;

        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return null;
            }
            $value = $value[$k];
        }

        return $value;
    }

    public function getCurrentLanguage() {
        return $this->currentLanguage;
    }

    public function getSupportedLanguages() {
        return $this->supportedLanguages;
    }

    public function isRTL() {
        return $this->supportedLanguages[$this->currentLanguage]['rtl'] ?? false;
    }

    public function getLanguageName($lang = null) {
        $lang = $lang ?? $this->currentLanguage;
        return $this->supportedLanguages[$lang]['name'] ?? $lang;
    }

    public function getLanguageFlag($lang = null) {
        $lang = $lang ?? $this->currentLanguage;
        return $this->supportedLanguages[$lang]['flag'] ?? '🌐';
    }

    public function getLanguageDirection() {
        return $this->isRTL() ? 'rtl' : 'ltr';
    }

    public function switchLanguage($lang) {
        if ($this->isValidLanguage($lang)) {
            $this->currentLanguage = $lang;
            $_SESSION['language'] = $lang;
            setcookie('preferred_language', $lang, time() + (86400 * 365), '/');
            $this->loadTranslations();
            return true;
        }
        return false;
    }

    public function generateLanguageSelector($currentUrl = null) {
        if ($currentUrl === null) {
            $currentUrl = $_SERVER['REQUEST_URI'];
        }

        $html = '<div class="language-selector">';
        $html .= '<button class="lang-toggle" onclick="toggleLanguageDropdown()">';
        $html .= $this->getLanguageFlag() . ' ' . $this->getLanguageName();
        $html .= ' <i class="fas fa-chevron-down"></i></button>';
        $html .= '<div class="lang-dropdown" id="langDropdown">';

        foreach ($this->supportedLanguages as $code => $info) {
            $url = $this->addLanguageToUrl($currentUrl, $code);
            $active = $code === $this->currentLanguage ? 'active' : '';
            $html .= "<a href='$url' class='lang-option $active'>";
            $html .= $info['flag'] . ' ' . $info['name'];
            $html .= '</a>';
        }

        $html .= '</div></div>';
        return $html;
    }

    private function addLanguageToUrl($url, $lang) {
        $parsedUrl = parse_url($url);
        $query = [];
        
        if (isset($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $query);
        }
        
        $query['lang'] = $lang;
        
        $newUrl = $parsedUrl['path'] ?? '';
        if (!empty($query)) {
            $newUrl .= '?' . http_build_query($query);
        }
        
        return $newUrl;
    }
}

// Global translation function
function __($key, $params = []) {
    return Language::getInstance()->get($key, $params);
}

// Alias for shorter syntax
function t($key, $params = []) {
    return __($key, $params);
}

// Get current language
function getCurrentLanguage() {
    return Language::getInstance()->getCurrentLanguage();
}

// Get language direction
function getLanguageDirection() {
    return Language::getInstance()->getLanguageDirection();
}

// Check if RTL
function isRTL() {
    return Language::getInstance()->isRTL();
}

// Generate language selector
function languageSelector($currentUrl = null) {
    return Language::getInstance()->generateLanguageSelector($currentUrl);
}

// Initialize the language system
$language = Language::getInstance();
?>

<script>
function toggleLanguageDropdown() {
    const dropdown = document.getElementById('langDropdown');
    dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    const selector = document.querySelector('.language-selector');
    if (selector && !selector.contains(event.target)) {
        const dropdown = document.getElementById('langDropdown');
        if (dropdown) {
            dropdown.style.display = 'none';
        }
    }
});
</script>

<style>
.language-selector {
    position: relative;
    display: inline-block;
}

.lang-toggle {
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    color: white;
    padding: 8px 15px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
}

.lang-toggle:hover {
    background: rgba(255, 255, 255, 0.2);
}

.lang-dropdown {
    display: none;
    position: absolute;
    top: 100%;
    right: 0;
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    z-index: 1000;
    min-width: 150px;
    margin-top: 5px;
}

.lang-option {
    display: block;
    padding: 10px 15px;
    text-decoration: none;
    color: #333;
    font-size: 14px;
    transition: background-color 0.3s ease;
    border-bottom: 1px solid #f0f0f0;
}

.lang-option:last-child {
    border-bottom: none;
}

.lang-option:hover {
    background-color: #f8f9fa;
}

.lang-option.active {
    background-color: #2a9d8f;
    color: white;
}

.lang-option.active:hover {
    background-color: #219f8b;
}

/* RTL Support */
[dir="rtl"] .lang-dropdown {
    right: auto;
    left: 0;
}

[dir="rtl"] .lang-toggle {
    flex-direction: row-reverse;
}
</style>
