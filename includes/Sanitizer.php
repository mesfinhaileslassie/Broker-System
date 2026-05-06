<?php
// includes/Sanitizer.php - Reusable sanitization trait

trait Sanitizer {
    
    /**
     * Sanitize all inputs
     */
    public function sanitizeInput($data) {
        if (is_array($data)) {
            return array_map([$this, 'sanitizeInput'], $data);
        }
        return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Get sanitized POST data
     */
    public function getPost($key, $default = null) {
        if (!isset($_POST[$key])) {
            return $default;
        }
        return $this->sanitizeInput($_POST[$key]);
    }
    
    /**
     * Get sanitized GET data
     */
    public function getQuery($key, $default = null) {
        if (!isset($_GET[$key])) {
            return $default;
        }
        return $this->sanitizeInput($_GET[$key]);
    }
    
    /**
     * Get sanitized integer from POST
     */
    public function getPostInt($key, $default = 0) {
        return sanitizeInt($this->getPost($key, $default));
    }
    
    /**
     * Get sanitized float from POST
     */
    public function getPostFloat($key, $default = 0.00) {
        return sanitizeFloat($this->getPost($key, $default));
    }
}