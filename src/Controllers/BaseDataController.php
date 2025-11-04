<?php
namespace SweetDelights\Mayie\Controllers;

/**
 * A base controller that provides data-saving helper methods.
 */
class BaseDataController {

    /**
     * Helper function to write data to a PHP file.
     * @param string $path The file path to write to.
     * @param array $data The data to save.
     */
    protected function saveData(string $path, array $data)
    {
        // Re-index the array if keys are not sequential
        $data = array_values($data);
        
        // Format the data as a PHP array string
        $content = "<?php\n\nreturn " . var_export($data, true) . ";\n";
        
        // Atomically write the file
        file_put_contents($path, $content, LOCK_EX);
    }
}