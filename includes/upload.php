<?php
// includes/upload.php - Image upload handling

function uploadImage($file, $targetDir = '../uploads/listings/') {
    // Create directory if not exists
    if (!file_exists($targetDir)) {
        mkdir($targetDir, 0777, true);
    }
    
    $fileName = time() . '_' . uniqid() . '_' . basename($file['name']);
    $targetFile = $targetDir . $fileName;
    $imageFileType = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));
    
    // Check if image file is actual image
    $check = getimagesize($file['tmp_name']);
    if ($check === false) {
        return ['success' => false, 'error' => 'File is not an image'];
    }
    
    // Check file size (5MB max)
    if ($file['size'] > 5000000) {
        return ['success' => false, 'error' => 'File is too large (max 5MB)'];
    }
    
    // Allow certain file formats
    $allowedFormats = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($imageFileType, $allowedFormats)) {
        return ['success' => false, 'error' => 'Only JPG, JPEG, PNG, GIF & WEBP files are allowed'];
    }
    
    // Upload file
    if (move_uploaded_file($file['tmp_name'], $targetFile)) {
        return ['success' => true, 'filename' => $fileName, 'path' => $targetFile];
    } else {
        return ['success' => false, 'error' => 'Failed to upload image'];
    }
}

function deleteImage($filename, $targetDir = '../uploads/listings/') {
    $filePath = $targetDir . $filename;
    if (file_exists($filePath)) {
        unlink($filePath);
        return true;
    }
    return false;
}
?>