<?php
/**
 * Asset Optimization Script for Grading System
 * Converts images to WebP and creates optimized CSS/JS bundles
 */

// Set memory limit and execution time
ini_set('memory_limit', '512M');
set_time_limit(300);

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

class AssetOptimizer {
    private $baseDir;
    private $uploadsDir;
    private $optimizedDir;
    
    public function __construct() {
        $this->baseDir = __DIR__;
        $this->uploadsDir = $this->baseDir . '/uploads';
        $this->optimizedDir = $this->baseDir . '/uploads/optimized';
        
        // Create optimized directory if it doesn't exist
        if (!is_dir($this->optimizedDir)) {
            mkdir($this->optimizedDir, 0755, true);
        }
    }
    
    /**
     * Convert images to WebP format
     */
    public function convertToWebP() {
        echo "Starting WebP conversion...\n";
        
        $imageTypes = ['jpg', 'jpeg', 'png', 'gif'];
        $converted = 0;
        $totalSizeBefore = 0;
        $totalSizeAfter = 0;
        
        foreach ($imageTypes as $type) {
            $files = glob($this->uploadsDir . '/*.' . $type);
            foreach ($files as $file) {
                $pathInfo = pathinfo($file);
                $webpPath = $this->optimizedDir . '/' . $pathInfo['filename'] . '.webp';
                
                // Skip if WebP already exists and is newer
                if (file_exists($webpPath) && filemtime($webpPath) >= filemtime($file)) {
                    continue;
                }
                
                // Get original file size
                $originalSize = filesize($file);
                $totalSizeBefore += $originalSize;
                
                try {
                    $image = null;
                    
                    // Create image resource based on file type
                    switch (strtolower($pathInfo['extension'])) {
                        case 'jpg':
                        case 'jpeg':
                            $image = imagecreatefromjpeg($file);
                            break;
                        case 'png':
                            $image = imagecreatefrompng($file);
                            break;
                        case 'gif':
                            $image = imagecreatefromgif($file);
                            break;
                    }
                    
                    if ($image) {
                        // Convert to WebP with 85% quality (good balance)
                        if (imagewebp($image, $webpPath, 85)) {
                            $webpSize = filesize($webpPath);
                            $totalSizeAfter += $webpSize;
                            $converted++;
                            
                            $savings = (($originalSize - $webpSize) / $originalSize) * 100;
                            echo "Converted: {$pathInfo['basename']} -> WebP (Size reduction: " . 
                                 number_format($savings, 1) . "%)\n";
                        }
                        
                        imagedestroy($image);
                    }
                } catch (Exception $e) {
                    echo "Error converting {$file}: " . $e->getMessage() . "\n";
                }
            }
        }
        
        $totalSavings = $totalSizeBefore > 0 ? (($totalSizeBefore - $totalSizeAfter) / $totalSizeBefore) * 100 : 0;
        echo "\nWebP Conversion Summary:\n";
        echo "Files converted: $converted\n";
        echo "Total size before: " . $this->formatBytes($totalSizeBefore) . "\n";
        echo "Total size after: " . $this->formatBytes($totalSizeAfter) . "\n";
        echo "Total savings: " . number_format($totalSavings, 1) . "%\n\n";
    }
    
    /**
     * Create optimized CSS bundle
     */
    public function createOptimizedCSS() {
        echo "Creating optimized CSS bundle...\n";
        
        $cssFiles = [
            'css/bootstrap.min.css',
            'css/sidebar.css',
            'css/button.css'
        ];
        
        $bundleContent = '';
        $totalSize = 0;
        
        foreach ($cssFiles as $cssFile) {
            $filePath = $this->baseDir . '/' . $cssFile;
            if (file_exists($filePath)) {
                $content = file_get_contents($filePath);
                $bundleContent .= $this->minifyCSS($content);
                $totalSize += filesize($filePath);
                echo "Added: $cssFile\n";
            }
        }
        
        $bundlePath = $this->baseDir . '/css/bundle.min.css';
        file_put_contents($bundlePath, $bundleContent);
        
        $bundleSize = strlen($bundleContent);
        $savings = $totalSize > 0 ? (($totalSize - $bundleSize) / $totalSize) * 100 : 0;
        
        echo "CSS Bundle created: bundle.min.css\n";
        echo "Original size: " . $this->formatBytes($totalSize) . "\n";
        echo "Bundle size: " . $this->formatBytes($bundleSize) . "\n";
        echo "CSS savings: " . number_format($savings, 1) . "%\n\n";
    }
    
    /**
     * Create optimized JS bundle
     */
    public function createOptimizedJS() {
        echo "Creating optimized JS bundle...\n";
        
        $jsFiles = [
            'js/jquery-3.6.0.min.js',
            'js/popper.min.js',
            'js/bootstrap.min.js',
            'js/script.js',
            'js/sidebar.js'
        ];
        
        $bundleContent = '';
        $totalSize = 0;
        
        foreach ($jsFiles as $jsFile) {
            $filePath = $this->baseDir . '/' . $jsFile;
            if (file_exists($filePath)) {
                $content = file_get_contents($filePath);
                // Skip minification for already minified files
                if (strpos($jsFile, '.min.') !== false) {
                    $bundleContent .= $content . ";\n";
                } else {
                    $bundleContent .= $this->minifyJS($content) . ";\n";
                }
                $totalSize += filesize($filePath);
                echo "Added: $jsFile\n";
            }
        }
        
        $bundlePath = $this->baseDir . '/js/bundle.min.js';
        file_put_contents($bundlePath, $bundleContent);
        
        $bundleSize = strlen($bundleContent);
        $savings = $totalSize > 0 ? (($totalSize - $bundleSize) / $totalSize) * 100 : 0;
        
        echo "JS Bundle created: bundle.min.js\n";
        echo "Original size: " . $this->formatBytes($totalSize) . "\n";
        echo "Bundle size: " . $this->formatBytes($bundleSize) . "\n";
        echo "JS savings: " . number_format($savings, 1) . "%\n\n";
    }
    
    /**
     * Minify CSS by removing whitespace and comments
     */
    private function minifyCSS($css) {
        // Remove comments
        $css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);
        // Remove whitespace
        $css = str_replace(["\r\n", "\r", "\n", "\t", '  ', '    ', '     '], '', $css);
        // Remove unnecessary semicolons
        $css = preg_replace('/;(\s*})/', '$1', $css);
        return $css;
    }
    
    /**
     * Minify JavaScript by removing whitespace and comments
     */
    private function minifyJS($js) {
        // Remove single line comments
        $js = preg_replace('/\/\/.*$/m', '', $js);
        // Remove multi-line comments
        $js = preg_replace('/\/\*.*?\*\//s', '', $js);
        // Remove whitespace
        $js = preg_replace('/\s+/', ' ', $js);
        $js = trim($js);
        return $js;
    }
    
    /**
     * Format bytes to human readable format
     */
    private function formatBytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }
    
    /**
     * Run all optimizations
     */
    public function optimizeAll() {
        echo "=== Grading System Asset Optimization ===\n\n";
        
        // Check if GD is available for WebP conversion
        if (!extension_loaded('gd')) {
            echo "Warning: GD extension not available. WebP conversion skipped.\n";
        } else {
            $this->convertToWebP();
        }
        
        $this->createOptimizedCSS();
        $this->createOptimizedJS();
        
        echo "=== Optimization Complete ===\n";
        echo "Next steps:\n";
        echo "1. Update your HTML to use the new bundle files\n";
        echo "2. Update image references to use WebP format\n";
        echo "3. Test the application to ensure everything works\n";
    }
}

// Run the optimization
if (php_sapi_name() === 'cli') {
    $optimizer = new AssetOptimizer();
    $optimizer->optimizeAll();
} else {
    echo "<pre>";
    $optimizer = new AssetOptimizer();
    $optimizer->optimizeAll();
    echo "</pre>";
}
?>
