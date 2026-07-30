<?php

namespace Grav\Plugin\Form\Captcha;

use Grav\Common\Grav;

class BasicCaptcha
{
    /**
     * Field types that share the 'type' key with the captcha type setting
     */
    protected const FIELD_TYPES = ['basic-captcha', 'captcha'];

    protected $session = null;
    protected $key = 'basic_captcha_value';
    protected $typeKey = 'basic_captcha_type';
    protected $config = null;

    public function __construct($fieldConfig = null)
    {
        $this->session = Grav::instance()['session'];

        // Load global configuration
        $globalConfig = Grav::instance()['config']->get('plugins.form.basic_captcha', []);

        // Merge field-specific config with global config
        if ($fieldConfig && is_array($fieldConfig)) {
            $this->config = array_replace_recursive($globalConfig, $fieldConfig);
        } else {
            $this->config = $globalConfig;
        }
    }

    /**
     * Resolve the configured captcha type.
     *
     * 'captcha_type' is checked first because this config can have a form field merged
     * into it, and the field's own 'type' ('basic-captcha' or 'captcha') would otherwise
     * mask the configured type. When that has happened, fall back to the global setting,
     * which no field can overwrite.
     */
    protected function getCaptchaType(): string
    {
        foreach ([$this->config['captcha_type'] ?? null, $this->config['type'] ?? null] as $type) {
            if ($type && !in_array($type, static::FIELD_TYPES, true)) {
                return $type;
            }
        }

        $global = Grav::instance()['config']->get('plugins.form.basic_captcha', []);
        $type = $global['captcha_type'] ?? $global['type'] ?? 'characters';

        return in_array($type, static::FIELD_TYPES, true) ? 'characters' : $type;
    }

    public function getCaptchaCode($length = null): string
    {
        $type = $this->getCaptchaType();

        // Store the captcha type in session for validation
        $this->setSession($this->typeKey, $type);

        switch ($type) {
            case 'dotcount':
                return $this->getDotCountCaptcha($this->config);
            case 'position':
                return $this->getPositionCaptcha($this->config);
            case 'math':
                return $this->getMathCaptcha($this->config);
            case 'characters':
            default:
                return $this->getCharactersCaptcha($this->config, $length);
        }
    }

    /**
     * Dot colors available to the dot counting captcha
     */
    protected function getDotColors(): array
    {
        return [
            'red' => [255, 0, 0],
            'blue' => [0, 0, 255],
            'green' => [0, 128, 0],
            'yellow' => [255, 255, 0],
            'purple' => [128, 0, 128],
            'orange' => [255, 165, 0]
        ];
    }

    /**
     * Resolve the dot grid layout and the range of target dots that will fit in it.
     * Shared by the generator and the renderer so the expected answer can never be
     * larger than the number of dots the image is able to draw.
     */
    protected function getDotGrid($config): array
    {
        $rows = max(1, (int) ($config['dots']['rows'] ?? 2));
        $cols = max(1, (int) ($config['dots']['cols'] ?? 4));
        $capacity = $rows * $cols;

        // Always leave at least one cell for a different colored dot, otherwise every
        // dot is the target color and the answer is just "count all of them".
        $max = min((int) ($config['dots']['max'] ?? 5), $capacity - 1);
        $max = max(1, $max);
        $min = max(1, min((int) ($config['dots']['min'] ?? 2), $max));

        return [
            'rows' => $rows,
            'cols' => $cols,
            'capacity' => $capacity,
            'min' => $min,
            'max' => $max,
        ];
    }

    /**
     * Creates a dot counting captcha - user has to count dots of a specific color
     */
    protected function getDotCountCaptcha($config): string
    {
        $colors = $this->getDotColors();

        // Pick a random color to count
        $colorNames = array_keys($colors);
        $targetColorName = $colorNames[array_rand($colorNames)];
        $targetColor = $colors[$targetColorName];

        // Keep the count within what the grid can actually display
        $grid = $this->getDotGrid($config);
        $targetCount = random_int($grid['min'], $grid['max']);

        // Store the expected answer
        $this->setSession($this->key, (string) $targetCount);

        // Carry the count in the code so the renderer draws exactly this many dots
        return "count_dots|{$targetColorName}|".implode(',', $targetColor)."|{$targetCount}";
    }

    /**
     * Creates a position-based captcha - user has to identify position of a symbol
     */
    protected function getPositionCaptcha($config): string
    {
        // Define possible symbols - using simple ASCII characters
        $symbols = ['*', '+', '$', '#', '@', '!', '?', '%', '&', '='];

        // Define positions - simpler options
        $positions = ['top', 'bottom', 'left', 'right', 'center'];

        // Pick a random symbol and position
        $targetSymbol = $symbols[array_rand($symbols)];
        $targetPosition = $positions[array_rand($positions)];

        // Store the expected answer
        $this->setSession($this->key, $targetPosition);

        // Return the instruction and symbol
        return "position|{$targetSymbol}|{$targetPosition}";
    }

    /**
     * Creates a math-based captcha
     */
    protected function getMathCaptcha($config): string
    {
        $min = $config['math']['min'] ?? 1;
        $max = $config['math']['max'] ?? 12;
        $operators = $config['math']['operators'] ?? ['+', '-', '*'];

        // Fall back to addition if the configured operators are all unsupported
        $operators = array_values(array_intersect((array) $operators, ['+', '-', '*', '/']));
        if (!$operators) {
            $operators = ['+'];
        }

        $first_num = random_int($min, $max);
        $second_num = random_int($min, $max);
        $operator = $operators[array_rand($operators)];

        // Calculator
        if ($operator === '-') {
            if ($first_num < $second_num) {
                $result = "$second_num - $first_num";
                $captcha_code = $second_num - $first_num;
            } else {
                $result = "$first_num - $second_num";
                $captcha_code = $first_num - $second_num;
            }
        } elseif ($operator === '*') {
            $result = "{$first_num} x {$second_num}";
            $captcha_code = $first_num * $second_num;
        } elseif ($operator === '/') {
            // Multiply back up from the divisor so the answer is always a whole number
            $divisor = max(1, $second_num);
            $result = ($first_num * $divisor)." / {$divisor}";
            $captcha_code = $first_num;
        } else {
            $result = "$first_num + $second_num";
            $captcha_code = $first_num + $second_num;
        }

        $this->setSession($this->key, (string) $captcha_code);
        return "math|{$result}";
    }

    /**
     * Creates a character-based captcha
     */
    protected function getCharactersCaptcha($config, $length = null): string
    {
        if ($length === null) {
            $length = $config['chars']['length'] ?? 6;
        }

        // Use more complex character set with mixed case and exclude similar-looking characters
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $captcha_code = '';

        // Generate random characters
        for ($i = 0; $i < $length; $i++) {
            $captcha_code .= $chars[random_int(0, strlen($chars) - 1)];
        }

        $this->setSession($this->key, $captcha_code);
        return $captcha_code;
    }

    public function setSession($key, $value): void
    {
        $this->session->$key = $value;
    }

    public function getSession($key = null): ?string
    {
        if ($key === null) {
            $key = $this->key;
        }
        return $this->session->$key ?? null;
    }

    /**
     * Work out which kind of captcha a code represents.
     *
     * Codes are prefixed with their type, so anything without a prefix is a plain
     * character code. The bare math expression is still recognised for older callers
     * that generated a code before the prefix was added.
     */
    protected function getCaptchaKind($captcha_code): string
    {
        $captcha_code = (string) $captcha_code;

        if (strpos($captcha_code, '|') !== false) {
            $type = explode('|', $captcha_code)[0];
            if (in_array($type, ['count_dots', 'position', 'math'], true)) {
                return $type;
            }
        }

        // Legacy: math used to be returned as a bare "3 + 4" expression. Anchor the
        // match so a character code that happens to contain an "x" isn't caught.
        if (preg_match('#^\s*\d+\s*[+\-x/]\s*\d+\s*$#', $captcha_code)) {
            return 'math';
        }

        return 'characters';
    }

    /**
     * Create captcha image based on the type
     */
    public function createCaptchaImage($captcha_code)
    {
        // Determine image dimensions based on type
        $kind = $this->getCaptchaKind($captcha_code);
        $isCharacterCaptcha = $kind === 'characters';

        // Use box_width/box_height for character captchas if specified, otherwise use default image dimensions
        if ($isCharacterCaptcha && isset($this->config['chars']['box_width'])) {
            $width = $this->config['chars']['box_width'];
        } else {
            $width = $this->config['image']['width'] ?? 135;
        }

        if ($isCharacterCaptcha && isset($this->config['chars']['box_height'])) {
            $height = $this->config['chars']['box_height'];
        } else {
            $height = $this->config['image']['height'] ?? 40;
        }

        // Create a blank image
        $image = imagecreatetruecolor($width, $height);

        // Set background color (support both image.bg and chars.bg for character captchas)
        $bgColor = '#ffffff';
        if ($isCharacterCaptcha && isset($this->config['chars']['bg'])) {
            $bgColor = $this->config['chars']['bg'];
        } elseif (isset($this->config['image']['bg'])) {
            $bgColor = $this->config['image']['bg'];
        }

        $bg = $this->hexToRgb($bgColor);
        $backgroundColor = imagecolorallocate($image, $bg[0], $bg[1], $bg[2]);
        imagefill($image, 0, 0, $backgroundColor);

        // Render according to the resolved type
        $parts = explode('|', (string) $captcha_code);

        switch ($kind) {
            case 'count_dots':
                return $this->createDotCountImage($image, $parts, $this->config);
            case 'position':
                return $this->createPositionImage($image, $parts, $this->config);
            case 'math':
                // Strip the type prefix, if the code carries one
                $expression = count($parts) > 1 ? $parts[1] : $parts[0];
                return $this->createMathImage($image, $expression, $this->config);
            default:
                return $this->createCharacterImage($image, $captcha_code, $this->config);
        }
    }

    /**
     * Create image for dot counting captcha
     */
    protected function createDotCountImage($image, $parts, $config)
    {
        $colorName = $parts[1];
        $targetColorRGB = explode(',', (string) $parts[2]);

        $width = imagesx($image);
        $height = imagesy($image);

        // Allocate target color
        $targetColor = imagecolorallocate($image, $targetColorRGB[0], $targetColorRGB[1], $targetColorRGB[2]);

        // Create other distraction colors
        $distractionColors = [];

        foreach ($this->getDotColors() as $rgb) {
            if ($rgb[0] != $targetColorRGB[0] || $rgb[1] != $targetColorRGB[1] || $rgb[2] != $targetColorRGB[2]) {
                $distractionColors[] = imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
            }
        }

        // Prefer the count carried in the code, falling back to the session for
        // anything still calling this with an older two-part code
        $grid = $this->getDotGrid($config);
        $targetCount = (int) ($parts[3] ?? $this->getSession());
        $targetCount = max(1, min($targetCount, $grid['capacity']));

        // Draw instruction text
        $fontPath = __DIR__.'/../../fonts/'.($config['chars']['font'] ?? 'zxx-xed.ttf');
        $black = imagecolorallocate($image, 0, 0, 0);
        imagettftext($image, 10, 0, 5, 15, $black, $fontPath, "Count {$colorName}:");

        // Simplified approach to prevent overlapping
        // Divide the image into a grid and place one dot per cell
        $gridCells = [];
        $gridRows = $grid['rows'];
        $gridCols = $grid['cols'];

        // Build available grid cells
        for ($y = 0; $y < $gridRows; $y++) {
            for ($x = 0; $x < $gridCols; $x++) {
                $gridCells[] = [$x, $y];
            }
        }

        // Shuffle grid cells for random placement
        shuffle($gridCells);

        // Calculate cell dimensions
        $cellWidth = ($width - 20) / $gridCols;
        $cellHeight = ($height - 20) / $gridRows;

        // Dot size for better visibility
        $dotSize = 8;

        // Draw target dots first (taking the first N cells)
        for ($i = 0; $i < $targetCount && $i < count($gridCells); $i++) {
            $cell = $gridCells[$i];
            $gridX = $cell[0];
            $gridY = $cell[1];

            // Calculate center position of cell with small random offset
            $x = 10 + ($gridX + 0.5) * $cellWidth + mt_rand(-2, 2);
            $y = 20 + ($gridY + 0.5) * $cellHeight + mt_rand(-2, 2);

            // Draw the dot
            imagefilledellipse($image, $x, $y, $dotSize, $dotSize, $targetColor);

            // Add a small border for better contrast
            imageellipse($image, $x, $y, $dotSize + 2, $dotSize + 2, $black);
        }

        // Fill every remaining cell with a distraction dot, so the target dots can't be
        // picked out by looking for the occupied cells
        $distractionCount = max(0, count($gridCells) - $targetCount);

        for ($i = 0; $i < $distractionCount; $i++) {
            // Get the next available cell
            $cellIndex = $targetCount + $i;

            if ($cellIndex >= count($gridCells)) {
                break; // No more cells available
            }

            $cell = $gridCells[$cellIndex];
            $gridX = $cell[0];
            $gridY = $cell[1];

            // Calculate center position of cell with small random offset
            $x = 10 + ($gridX + 0.5) * $cellWidth + mt_rand(-2, 2);
            $y = 20 + ($gridY + 0.5) * $cellHeight + mt_rand(-2, 2);

            // Draw the dot with a random distraction color
            $color = $distractionColors[array_rand($distractionColors)];
            imagefilledellipse($image, $x, $y, $dotSize, $dotSize, $color);
        }

        // Add subtle grid lines to help with counting
        $lightGray = imagecolorallocate($image, 230, 230, 230);
        for ($i = 1; $i < $gridCols; $i++) {
            imageline($image, 10 + $i * $cellWidth, 20, 10 + $i * $cellWidth, $height - 5, $lightGray);
        }
        for ($i = 1; $i < $gridRows; $i++) {
            imageline($image, 10, 20 + $i * $cellHeight, $width - 10, 20 + $i * $cellHeight, $lightGray);
        }

        // Add minimal noise
        $this->addImageNoise($image, 15);

        return $image;
    }

    /**
     * Create image for position captcha
     */
    protected function createPositionImage($image, $parts, $config)
    {
        $symbol = $parts[1];
        $position = $parts[2];

        $width = imagesx($image);
        $height = imagesy($image);

        // Allocate colors
        $black = imagecolorallocate($image, 0, 0, 0);
        $red = imagecolorallocate($image, 255, 0, 0);

        // Draw instruction text
        $fontPath = __DIR__.'/../../fonts/'.($config['chars']['font'] ?? 'zxx-xed.ttf');
        imagettftext($image, 9, 0, 5, 15, $black, $fontPath, "Position of symbol?");

        // Determine symbol position based on the target position
        $symbolX = $width / 2;
        $symbolY = $height / 2;

        switch ($position) {
            case 'top':
                $symbolX = $width / 2;
                $symbolY = 20;
                break;
            case 'bottom':
                $symbolX = $width / 2;
                $symbolY = $height - 10;
                break;
            case 'left':
                $symbolX = 20;
                $symbolY = $height / 2;
                break;
            case 'right':
                $symbolX = $width - 20;
                $symbolY = $height / 2;
                break;
            case 'center':
                $symbolX = $width / 2;
                $symbolY = $height / 2;
                break;
        }

        // Draw the symbol - make it larger and in red for visibility
        imagettftext($image, 20, 0, $symbolX - 8, $symbolY + 8, $red, $fontPath, (string) $symbol);

        // Draw a grid to make positions clearer
        $gray = imagecolorallocate($image, 200, 200, 200);
        imageline($image, $width / 2, 15, $width / 2, $height - 5, $gray);
        imageline($image, 5, $height / 2, $width - 5, $height / 2, $gray);

        // Add minimal noise
        $this->addImageNoise($image, 10);

        return $image;
    }

    /**
     * Create image for math captcha
     */
    protected function createMathImage($image, $mathExpression, $config)
    {
        $width = imagesx($image);
        $height = imagesy($image);

        // Get font and colors
        $fontPath = __DIR__.'/../../fonts/'.($config['chars']['font'] ?? 'zxx-xed.ttf');
        $textColor = imagecolorallocate($image, 0, 0, 0);

        // Draw the math expression
        $fontSize = 16;
        $textBox = imagettfbbox($fontSize, 0, $fontPath, (string) $mathExpression);
        $textWidth = $textBox[2] - $textBox[0];
        $textHeight = $textBox[1] - $textBox[7];
        $textX = ($width - $textWidth) / 2;
        $textY = ($height + $textHeight) / 2;

        imagettftext($image, $fontSize, 0, $textX, $textY, $textColor, $fontPath, (string) $mathExpression);

        // Add visual noise and distortions to prevent OCR
        $this->addImageNoise($image, 25);
        $this->addWaveDistortion($image);

        return $image;
    }

    /**
     * Create image for character captcha
     */
    protected function createCharacterImage($image, $captcha_code, $config)
    {
        $width = imagesx($image);
        $height = imagesy($image);

        // Get font settings with support for custom box dimensions, position, and colors
        $fontPath = __DIR__.'/../../fonts/'.($config['chars']['font'] ?? 'zxx-xed.ttf');
        $fontSize = $config['chars']['size'] ?? 16;

        // Support custom text color (defaults to black)
        $textColorHex = $config['chars']['text'] ?? '#000000';
        $textRgb = $this->hexToRgb($textColorHex);
        $textColor = imagecolorallocate($image, $textRgb[0], $textRgb[1], $textRgb[2]);

        // Support custom start position (useful for fine-tuning text placement)
        $startX = $config['chars']['start_x'] ?? ($width / (strlen((string) $captcha_code) + 2));
        $baseY = $config['chars']['start_y'] ?? ($height / 2 + 5);

        // Draw each character with random rotation and position
        $charWidth = $width / (strlen((string) $captcha_code) + 2);

        for ($i = 0; $i < strlen((string) $captcha_code); $i++) {
            $char = $captcha_code[$i];
            $angle = mt_rand(-15, 15); // Random rotation

            // Random vertical position with custom base Y
            $y = $baseY + mt_rand(-5, 5);

            imagettftext($image, $fontSize, $angle, $startX, $y, $textColor, $fontPath, (string) $char);

            // Move to next character position with some randomness
            $startX += $charWidth + mt_rand(-5, 5);
        }

        // Add visual noise and distortions
        $this->addImageNoise($image, 25);
        $this->addWaveDistortion($image);

        return $image;
    }

    /**
     * Add random noise to the image
     */
    protected function addImageNoise($image, $density = 100)
    {
        $width = imagesx($image);
        $height = imagesy($image);

        // For performance, reduce density
        $density = min($density, 30);

        // Add random dots
        for ($i = 0; $i < $density; $i++) {
            $x = mt_rand(0, $width - 1);
            $y = mt_rand(0, $height - 1);
            $shade = mt_rand(150, 200);
            $color = imagecolorallocate($image, $shade, $shade, $shade);
            imagesetpixel($image, $x, $y, $color);
        }

        // Add a few random lines
        $lineCount = min(3, mt_rand(2, 3));
        for ($i = 0; $i < $lineCount; $i++) {
            $x1 = mt_rand(0, $width / 4);
            $y1 = mt_rand(0, $height - 1);
            $x2 = mt_rand(3 * $width / 4, $width - 1);
            $y2 = mt_rand(0, $height - 1);
            $shade = mt_rand(150, 200);
            $color = imagecolorallocate($image, $shade, $shade, $shade);
            imageline($image, $x1, $y1, $x2, $y2, $color);
        }
    }

    /**
     * Add wave distortion to the image
     */
    protected function addWaveDistortion($image)
    {
        $width = imagesx($image);
        $height = imagesy($image);

        // Create temporary image
        $temp = imagecreatetruecolor($width, $height);
        $bg = imagecolorallocate($temp, 255, 255, 255);
        imagefill($temp, 0, 0, $bg);

        // Copy original to temp
        imagecopy($temp, $image, 0, 0, 0, 0, $width, $height);

        // Clear original image
        $bg = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $bg);

        // Apply simplified wave distortion
        $amplitude = mt_rand(1, 2);
        $period = mt_rand(10, 15);

        // Process only every 2nd pixel for better performance
        for ($x = 0; $x < $width; $x += 2) {
            $wave = sin($x / $period) * $amplitude;

            for ($y = 0; $y < $height; $y += 2) {
                $yp = $y + $wave;

                if ($yp >= 0 && $yp < $height) {
                    $color = imagecolorat($temp, $x, $yp);
                    imagesetpixel($image, $x, $y, $color);

                    // Fill adjacent pixel for better performance
                    if ($x + 1 < $width && $y + 1 < $height) {
                        imagesetpixel($image, $x + 1, $y, $color);
                    }
                }
            }
        }

        imagedestroy($temp);
    }

    public function renderCaptchaImage($imageData): void
    {
        header("Content-type: image/jpeg");
        imagejpeg($imageData);
    }

    public function validateCaptcha($formData): bool
    {
        $isValid = false;
        $capchaSessionData = $this->getSession();

        // With no challenge in the session there is nothing to match against, so an
        // empty answer must not be allowed to pass
        if ($capchaSessionData !== null && $capchaSessionData !== '') {
            // Make validation case-insensitive
            $isValid = strtolower($capchaSessionData) === strtolower(trim((string) $formData));
        }

        // Debug validation if enabled
        $grav = Grav::instance();
        if ($grav['config']->get('plugins.form.basic_captcha.debug', false)) {
            $grav['log']->debug("Captcha Validation - Expected: '{$capchaSessionData}', Got: '{$formData}', Result: ".
                ($isValid ? 'valid' : 'invalid'));
        }

        // Regenerate a new captcha after validation
        $this->setSession($this->key, null);

        return $isValid;
    }

    private function hexToRgb($hex): array
    {
        return sscanf($hex, "#%02x%02x%02x");
    }
}