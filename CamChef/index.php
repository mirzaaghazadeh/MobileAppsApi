<?php
/**
 * CamChef REST API
 * Handles image uploads and sends them to OpenAI API for analysis
 * Returns JSON responses only
 */

// Set JSON content type and CORS headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow POST requests for the API
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed. Only POST requests are accepted.',
        'allowed_methods' => ['POST']
    ]);
    exit();
}

// Function to load environment variables from .env file
function loadEnv($path) {
    if (!file_exists($path)) {
        throw new Exception('.env file not found');
    }
    
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue; // Skip comments
        }
        
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        
        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// Function to create a temporary public URL for uploaded image
function createTempImageUrl($uploadedFile) {
    $uploadDir = __DIR__ . '/temp_uploads/';
    
    // Debug: Log the upload directory path
    error_log("Upload directory: " . $uploadDir);
    
    // Create directory if it doesn't exist
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            error_log("Failed to create directory: " . $uploadDir);
            throw new Exception('Failed to create temp_uploads directory. Check permissions.');
        }
        error_log("Created directory: " . $uploadDir);
    }
    
    // Check if directory is writable
    if (!is_writable($uploadDir)) {
        error_log("Directory not writable: " . $uploadDir);
        throw new Exception('temp_uploads directory is not writable. Check permissions.');
    }
    
    // Validate uploaded file
    if (!isset($uploadedFile['tmp_name']) || !is_uploaded_file($uploadedFile['tmp_name'])) {
        error_log("Invalid uploaded file");
        throw new Exception('Invalid uploaded file.');
    }
    
    // Generate unique filename
    $fileExtension = pathinfo($uploadedFile['name'], PATHINFO_EXTENSION);
    $fileName = uniqid('img_', true) . '.' . $fileExtension;
    $filePath = $uploadDir . $fileName;
    
    // Debug: Log file paths
    error_log("Source file: " . $uploadedFile['tmp_name']);
    error_log("Destination file: " . $filePath);
    
    // Move uploaded file
    if (move_uploaded_file($uploadedFile['tmp_name'], $filePath)) {
        error_log("File moved successfully to: " . $filePath);
        
        // Verify file exists after move
        if (file_exists($filePath)) {
            error_log("File verified to exist at: " . $filePath);
        } else {
            error_log("ERROR: File does not exist after move at: " . $filePath);
        }
        
        // Return the public URL that can be accessed by OpenAI
        $baseUrl = 'https://navid.tr';
        $url = $baseUrl . '/MobileAppsAPi/CamChef/temp_uploads/' . $fileName;
        error_log("Generated URL: " . $url);
        return $url;
    } else {
        error_log("Failed to move uploaded file from " . $uploadedFile['tmp_name'] . " to " . $filePath);
        throw new Exception('Failed to move uploaded file. Error: ' . error_get_last()['message'] ?? 'Unknown error');
    }
}

// Function to delete temporary file
function deleteTempFile($url) {
    $parsedUrl = parse_url($url);
    $filePath = ltrim($parsedUrl['path'], '/');
    
    // Extract just the filename part after the last slash
    $pathParts = explode('/', $filePath);
    $fileName = end($pathParts);
    $localPath = __DIR__ . '/temp_uploads/' . $fileName;
    
    if (file_exists($localPath)) {
        unlink($localPath);
    }
}



// Function to send request to OpenAI API
function sendToOpenAI($imageUrl, $apiKey, $prompt = null) {
    $url = 'https://api.openai.com/v1/responses';
    
    $data = [
        'model' => 'gpt-5',
        'prompt' => [
            'id' => 'pmpt_6898f03dbdbc8197a54a35fcc707a91f01e7adb5cb7bd1e3'
        ],
        'input' => [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'input_text',
                        'text' => $prompt ?: 'What is in this image?'
                    ],
                    [
                        'type' => 'input_image',
                        'image_url' => $imageUrl
                    ]
                ]
            ]
        ]
    ];
    
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_error($ch)) {
        throw new Exception('Curl error: ' . curl_error($ch));
    }
    
    curl_close($ch);
    
    if ($httpCode !== 200) {
        throw new Exception('OpenAI API error: HTTP ' . $httpCode . ' - ' . $response);
    }
    
    return json_decode($response, true);
}

// Main API execution
try {
    // Load environment variables
    loadEnv('.env');
    
    // Get API key from environment
    $apiKey = getenv('OPENAI_API_KEY');
    if (empty($apiKey) || $apiKey === 'your_openai_api_key_here') {
        throw new Exception('OpenAI API key not found or not set in .env file');
    }
    
    // Check if image was uploaded
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No image uploaded or upload error occurred. Please send image via form-data with key "image"');
    }
    
    // Validate image type
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($_FILES['image']['type'], $allowedTypes)) {
        throw new Exception('Invalid image type. Only JPEG, PNG, GIF, and WebP are allowed.');
    }
    
    // Validate file size (max 10MB)
    $maxSize = 10 * 1024 * 1024; // 10MB
    if ($_FILES['image']['size'] > $maxSize) {
        throw new Exception('File too large. Maximum size is 10MB.');
    }
    
    // Get custom prompt if provided
    $customPrompt = isset($_POST['prompt']) ? trim($_POST['prompt']) : null;
    
    // Create temporary public URL for the image
    $imageUrl = createTempImageUrl($_FILES['image']);
    
    // Debug: Check if file was actually saved
    error_log("Image URL created: " . $imageUrl);
    
    try {
        // Send to OpenAI API
        $response = sendToOpenAI($imageUrl, $apiKey, $customPrompt);
        
        // Clean up temporary file ONLY after successful OpenAI response
        deleteTempFile($imageUrl);
        
        // Extract the AI response text
        $aiResponse = '';
        
        // Debug: Log the full response to understand the structure
        error_log("OpenAI Response: " . json_encode($response));
        
        // Handle the new responses API format
        if (isset($response['output']) && !empty($response['output'])) {
            // New responses format - content is in output[0]['content'][0]['text']
            $output = $response['output'][0];
            if (isset($output['content']) && !empty($output['content'])) {
                $content = $output['content'][0];
                if (isset($content['text'])) {
                    $textContent = $content['text'];
                    
                    // Try to parse the text as JSON (since it contains recipe data)
                    $decodedContent = json_decode($textContent, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        // If it's valid JSON, use the parsed object
                        $aiResponse = $decodedContent;
                    } else {
                        // If it's not JSON, use the text as is
                        $aiResponse = $textContent;
                    }
                }
            }
        } elseif (isset($response['choices']) && !empty($response['choices'])) {
            // Fallback for chat completions format
            $content = $response['choices'][0]['message']['content'];
            $aiResponse = $content;
        } else {
            // Fallback - return the entire response for debugging
            $aiResponse = $response;
        }
    
        
        // Return successful response
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Image analyzed successfully',
            'ai_response' => $aiResponse,
            'usage' => isset($response['usage']) ? $response['usage'] : null,
            'model' => 'gpt-5',
            'timestamp' => date('c')
        ]);
        
    } catch (Exception $e) {
        // DON'T clean up temporary file on error - keep it for debugging
        // if (isset($imageUrl)) {
        //     deleteTempFile($imageUrl);
        // }
        throw $e;
    }
    
} catch (Exception $e) {
    // Handle errors and return JSON error response
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('c')
    ]);
}
?>