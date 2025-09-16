<?php
/**
 * OpenAI Image Chat PHP Script
 * Handles image uploads and sends them to OpenAI API with a specific prompt
 */

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
    $uploadDir = 'temp_uploads/';
    
    // Create directory if it doesn't exist
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Generate unique filename
    $fileExtension = pathinfo($uploadedFile['name'], PATHINFO_EXTENSION);
    $fileName = uniqid('img_', true) . '.' . $fileExtension;
    $filePath = $uploadDir . $fileName;
    
    // Move uploaded file
    if (move_uploaded_file($uploadedFile['tmp_name'], $filePath)) {
        // Return the public URL (adjust this based on your server configuration)
        $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
        $currentDir = dirname($_SERVER['REQUEST_URI']);
        return $baseUrl . $currentDir . '/' . $filePath;
    }
    
    throw new Exception('Failed to upload file');
}

// Function to delete temporary file
function deleteTempFile($url) {
    $parsedUrl = parse_url($url);
    $filePath = ltrim($parsedUrl['path'], '/');
    
    // Extract just the filename part after the last slash
    $pathParts = explode('/', $filePath);
    $fileName = end($pathParts);
    $localPath = 'temp_uploads/' . $fileName;
    
    if (file_exists($localPath)) {
        unlink($localPath);
    }
}

// Function to send request to OpenAI API
function sendToOpenAI($imageUrl, $apiKey, $promptId) {
    $url = 'https://api.openai.com/v1/chat/completions';
    
    $data = [
        'model' => 'gpt-4-vision-preview',
        'messages' => [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'What is in this image?'
                    ],
                    [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => $imageUrl
                        ]
                    ]
                ]
            ]
        ],
        'max_tokens' => 300
    ];
    
    // If prompt ID is provided, add it to the request
    if (!empty($promptId)) {
        $data['prompt'] = [
            'id' => $promptId
        ];
    }
    
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

// Main execution
try {
    // Load environment variables
    loadEnv('.env');
    
    // Get API key from environment
    $apiKey = getenv('OPENAI_API_KEY');
    if (empty($apiKey) || $apiKey === 'your_openai_api_key_here') {
        throw new Exception('OpenAI API key not found or not set in .env file');
    }
    
    // Set the prompt ID as specified
    $promptId = 'pmpt_6898f03dbdbc8197a54a35fcc707a91f01e7adb5cb7bd1e3';
    
    // Handle different request methods
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Check if image was uploaded
        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('No image uploaded or upload error occurred');
        }
        
        // Validate image type
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($_FILES['image']['type'], $allowedTypes)) {
            throw new Exception('Invalid image type. Only JPEG, PNG, GIF, and WebP are allowed.');
        }
        
        // Create temporary public URL for the image
        $imageUrl = createTempImageUrl($_FILES['image']);
        
        try {
            // Send to OpenAI API
            $response = sendToOpenAI($imageUrl, $apiKey, $promptId);
            
            // Clean up temporary file
            deleteTempFile($imageUrl);
            
            // Return response as JSON
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'response' => $response,
                'message' => 'Image processed successfully'
            ]);
            
        } catch (Exception $e) {
            // Clean up temporary file even if there's an error
            deleteTempFile($imageUrl);
            throw $e;
        }
        
    } else {
        // Display upload form for GET requests
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>OpenAI Image Chat</title>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    max-width: 600px;
                    margin: 50px auto;
                    padding: 20px;
                    background-color: #f5f5f5;
                }
                .container {
                    background: white;
                    padding: 30px;
                    border-radius: 10px;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                }
                h1 {
                    color: #333;
                    text-align: center;
                }
                .upload-form {
                    margin: 20px 0;
                }
                input[type="file"] {
                    width: 100%;
                    padding: 10px;
                    margin: 10px 0;
                    border: 2px dashed #ddd;
                    border-radius: 5px;
                }
                button {
                    background-color: #007cba;
                    color: white;
                    padding: 12px 24px;
                    border: none;
                    border-radius: 5px;
                    cursor: pointer;
                    font-size: 16px;
                    width: 100%;
                }
                button:hover {
                    background-color: #005a87;
                }
                .response {
                    margin-top: 20px;
                    padding: 15px;
                    background-color: #f8f9fa;
                    border-radius: 5px;
                    display: none;
                }
                .error {
                    color: #dc3545;
                    background-color: #f8d7da;
                    border: 1px solid #f5c6cb;
                }
                .success {
                    color: #155724;
                    background-color: #d4edda;
                    border: 1px solid #c3e6cb;
                }
            </style>
        </head>
        <body>
            <div class="container">
                <h1>OpenAI Image Chat</h1>
                <p>Upload an image to get AI analysis using ChatGPT vision capabilities.</p>
                
                <form class="upload-form" id="uploadForm" enctype="multipart/form-data">
                    <input type="file" name="image" accept="image/*" required>
                    <button type="submit">Analyze Image</button>
                </form>
                
                <div id="response" class="response"></div>
            </div>
            
            <script>
                document.getElementById('uploadForm').addEventListener('submit', async function(e) {
                    e.preventDefault();
                    
                    const formData = new FormData(this);
                    const responseDiv = document.getElementById('response');
                    const button = this.querySelector('button');
                    
                    // Show loading state
                    button.textContent = 'Analyzing...';
                    button.disabled = true;
                    responseDiv.style.display = 'none';
                    
                    try {
                        const response = await fetch('', {
                            method: 'POST',
                            body: formData
                        });
                        
                        const result = await response.json();
                        
                        responseDiv.style.display = 'block';
                        
                        if (result.success) {
                            responseDiv.className = 'response success';
                            responseDiv.innerHTML = '<h3>AI Response:</h3><p>' + 
                                (result.response.choices && result.response.choices[0] ? 
                                 result.response.choices[0].message.content : 
                                 JSON.stringify(result.response, null, 2)) + '</p>';
                        } else {
                            responseDiv.className = 'response error';
                            responseDiv.innerHTML = '<h3>Error:</h3><p>' + (result.error || 'Unknown error occurred') + '</p>';
                        }
                        
                    } catch (error) {
                        responseDiv.style.display = 'block';
                        responseDiv.className = 'response error';
                        responseDiv.innerHTML = '<h3>Error:</h3><p>' + error.message + '</p>';
                    }
                    
                    // Reset button
                    button.textContent = 'Analyze Image';
                    button.disabled = false;
                });
            </script>
        </body>
        </html>
        <?php
    }
    
} catch (Exception $e) {
    // Handle errors
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    } else {
        echo '<h1>Error</h1><p>' . htmlspecialchars($e->getMessage()) . '</p>';
    }
}
?>