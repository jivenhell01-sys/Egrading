<?php
/**
 * PHP Code Obfuscator for Grading System
 * This script will create an obfuscated version of the application
 */

class CodeObfuscator {
    private $mapping = [];
    private $counter = 0;
    private $preserved = [
        // Core PHP functions and classes
        'array', 'string', 'int', 'float', 'bool', 'true', 'false', 'null',
        'if', 'else', 'elseif', 'for', 'foreach', 'while', 'do', 'switch', 'case', 'default',
        'function', 'class', 'public', 'private', 'protected', 'static', 'const', 'return',
        'new', 'instanceof', 'extends', 'implements', 'interface', 'trait', 'namespace', 'use',
        'try', 'catch', 'throw', 'finally',
        // Common functions
        'isset', 'empty', 'unset', 'echo', 'print', 'print_r', 'var_dump', 'die', 'exit',
        // Database related
        'PDO', 'SQLITE3_ASSOC', 'SQLITE3_BOTH', 'SQLITE3_NUM',
        // Session related
        'session_start', 'session_destroy', 'session_unset', 'session_id', 'session_regenerate_id',
        // File operations
        'file_get_contents', 'file_put_contents', 'file_exists', 'is_file', 'is_dir',
        'fopen', 'fclose', 'fread', 'fwrite', 'fgets', 'feof',
        // Our custom preserved names
        'admin_id', 'student_id', 'class_id', 'subject_id', 'grade', 'marks', 'attendance',
        'user', 'password', 'username', 'email', 'firstname', 'lastname', 'middlename',
        'action', 'id', 'name', 'description', 'status', 'date_created', 'date_updated'
    ];

    public function obfuscateFile($source, $destination) {
        $code = file_get_contents($source);
        
        // Preserve HTML and PHP tags
        $tokens = token_get_all($source === 'index.php' ? $code : "<?php\n" . $code);
        $output = '';
        
        foreach ($tokens as $token) {
            if (is_string($token)) {
                $output .= $token;
            } else {
                list($id, $text) = $token;
                
                switch ($id) {
                    case T_WHITESPACE:
                        $output .= $text;
                        break;
                        
                    case T_STRING:
                    case T_VARIABLE:
                        if ($this->shouldObfuscate($text)) {
                            $output .= $this->getObfuscatedName($text);
                        } else {
                            $output .= $text;
                        }
                        break;
                        
                    default:
                        $output .= $text;
                }
            }
        }
        
        // If it's not the main file, remove the opening PHP tag we added
        if ($source !== 'index.php') {
            $output = substr($output, strpos($output, '<?php') + 5);
        }
        
        file_put_contents($destination, $output);
    }
    
    private function shouldObfuscate($name) {
        // Don't obfuscate preserved words
        if (in_array(strtolower($name), array_map('strtolower', $this->preserved))) {
            return false;
        }
        
        // Don't obfuscate superglobals
        if (in_array($name, ['$_GET', '$_POST', '$_REQUEST', '$_SERVER', '$_SESSION', '$_COOKIE', '$_FILES', '$_ENV'])) {
            return false;
        }
        
        // Don't obfuscate class names that are used in type hints
        if (class_exists($name) || interface_exists($name) || trait_exists($name)) {
            return false;
        }
        
        // Don't obfuscate function names that are called dynamically
        if (function_exists($name)) {
            return false;
        }
        
        return true;
    }
    
    private function getObfuscatedName($original) {
        if (!isset($this->mapping[$original])) {
            $this->mapping[$original] = $this->generateObfuscatedName();
        }
        return $this->mapping[$original];
    }
    
    private function generateObfuscatedName() {
        $prefix = '';
        $suffix = '';
        $length = 8;
        $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $result = '';
        
        // Generate a random string
        for ($i = 0; $i < $length; $i++) {
            $result .= $characters[rand(0, strlen($characters) - 1)];
        }
        
        return $prefix . $result . $suffix;
    }
    
    public function copyNonPhpFiles($source, $destination) {
        $dir = opendir($source);
        @mkdir($destination);
        
        while (($file = readdir($dir)) !== false) {
            if ($file != '.' && $file != '..') {
                $srcPath = $source . '/' . $file;
                $destPath = $destination . '/' . $file;
                
                if (is_dir($srcPath)) {
                    $this->copyNonPhpFiles($srcPath, $destPath);
                } elseif (pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
                    if (!is_dir(dirname($destPath))) {
                        mkdir(dirname($destPath), 0777, true);
                    }
                    copy($srcPath, $destPath);
                }
            }
        }
        
        closedir($dir);
    }
}

// Main execution
$sourceDir = __DIR__;
$destDir = dirname(__DIR__) . '/grading_system_obfuscated';

// Create obfuscator instance
$obfuscator = new CodeObfuscator();

// Copy all non-PHP files first
$obfuscator->copyNonPhpFiles($sourceDir, $destDir);

// Process PHP files
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($sourceDir),
    RecursiveIteratorIterator::LEAVES_ONLY
);

foreach ($files as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $relativePath = substr($file->getPathname(), strlen($sourceDir) + 1);
        $destPath = $destDir . '/' . $relativePath;
        
        // Create directory if it doesn't exist
        if (!is_dir(dirname($destPath))) {
            mkdir(dirname($destPath), 0777, true);
        }
        
        echo "Obfuscating: $relativePath\n";
        $obfuscator->obfuscateFile($file->getPathname(), $destPath);
    }
}

echo "\nObfuscation complete! Check the 'grading_system_obfuscated' directory.\n";
?>
