<?php

// ==============================================================================
// Configuration
// ==============================================================================

// 1. IMPORTANT: Replace with your actual Google AI API key.
$apiKey = 'API_KEY_HERE';

// This will hold the HTML output for the results.
$resultOutput = '';

// ==============================================================================
// Helper Function
// ==============================================================================

/**
 * A helper function to execute a cURL request.
 *
 * @param string $url The URL to send the request to.
 * @param array $options An array of cURL options.
 * @param bool $returnHeaders Whether to return response headers.
 * @return array An array containing 'body', 'headers', 'httpCode', and 'error'.
 */
function executeCurl(string $url, array $options, bool $returnHeaders = false): array {
    $ch = curl_init($url);
    
    $headerArray = [];
    if ($returnHeaders) {
        $options[CURLOPT_HEADERFUNCTION] = function($curl, $header) use (&$headerArray) {
            $len = strlen($header);
            $header = explode(':', $header, 2);
            if (count($header) < 2) { // ignore invalid headers
                return $len;
            }
            $headerArray[strtolower(trim($header[0]))][] = trim($header[1]);
            return $len;
        };
    }

    curl_setopt_array($ch, $options);

    $responseBody = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'body' => $responseBody,
        'headers' => $headerArray,
        'httpCode' => $httpCode,
        'error' => $error
    ];
}

// ==============================================================================
// Main Logic: Handle Form Submission
// ==============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['videoFile']) && $_FILES['videoFile']['error'] === UPLOAD_ERR_OK) {
    
    // --- Get data from form ---
    $videoFilePath = $_FILES['videoFile']['tmp_name'];
    $originalFileName = basename($_FILES['videoFile']['name']);
    $prompt = $_POST['prompt'] ?? 'Describe this video.';
    $uploadMethod = $_POST['uploadMethod'] ?? 'resumable';
    $fileSizeBytes = $_FILES['videoFile']['size'];
    $mimeType = $_FILES['videoFile']['type'];
    $fileSizeMB = $fileSizeBytes / (1024 * 1024);

    $resultOutput .= "<h3>Processing Request...</h3>";
    $resultOutput .= "<ul>";
    $resultOutput .= "<li>Original File: {$originalFileName}</li>";
    $resultOutput .= "<li>File Size: " . number_format($fileSizeMB, 2) . " MB</li>";
    $resultOutput .= "<li>MIME Type: {$mimeType}</li>";
    $resultOutput .= "<li>Upload Method: {$uploadMethod}</li>";
    $resultOutput .= "</ul><hr>";

    $fileDataPayload = null;
    $errorOccurred = false;

    // --- Main Logic: Choose Upload Path ---
    if ($uploadMethod === 'resumable') {
        // --- Method 1: Resumable Upload ---
        $resultOutput .= "<p><strong>Step 1.1:</strong> Initializing resumable upload...</p>";
        
        $startUrl = "https://generativelanguage.googleapis.com/upload/v1beta/files?key={$apiKey}";
        $startHeaders = [
            'X-Goog-Upload-Protocol: resumable',
            'X-Goog-Upload-Command: start',
            "X-Goog-Upload-Header-Content-Length: {$fileSizeBytes}",
            "X-Goog-Upload-Header-Content-Type: {$mimeType}",
            'Content-Type: application/json'
        ];
        $startPayload = json_encode(['file' => ['display_name' => $originalFileName]]);
        
        $startResult = executeCurl($startUrl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $startPayload, CURLOPT_HTTPHEADER => $startHeaders, CURLOPT_RETURNTRANSFER => true], true);

        if ($startResult['httpCode'] !== 200 || empty($startResult['headers']['x-goog-upload-url'][0])) {
            $resultOutput .= "<p class='error'><strong>Error:</strong> Could not get the resumable upload URL. Response: " . htmlspecialchars($startResult['body']) . "</p>";
            $errorOccurred = true;
        } else {
            $uploadUrl = $startResult['headers']['x-goog-upload-url'][0];
            $resultOutput .= "<p><strong>Step 1.2:</strong> Uploading video data...</p>";

            $uploadHeaders = [
                "Content-Type: {$mimeType}",
                "Content-Length: {$fileSizeBytes}",
                'X-Goog-Upload-Offset: 0',
                'X-Goog-Upload-Command: upload, finalize'
            ];
            $uploadResult = executeCurl($uploadUrl, [CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_POSTFIELDS => file_get_contents($videoFilePath), CURLOPT_HTTPHEADER => $uploadHeaders, CURLOPT_RETURNTRANSFER => true]);

            if ($uploadResult['httpCode'] !== 200) {
                $resultOutput .= "<p class='error'><strong>Error:</strong> Failed to upload video data. Response: " . htmlspecialchars($uploadResult['body']) . "</p>";
                $errorOccurred = true;
            } else {
                $uploadResponse = json_decode($uploadResult['body']);
                if (!isset($uploadResponse->file->uri)) {
                    $resultOutput .= "<p class='error'><strong>Error:</strong> File URI not found in the final upload response.</p>";
                    $errorOccurred = true;
                } else {
                    $fileUri = $uploadResponse->file->uri;
                    $resultOutput .= "<p><strong>Success!</strong> File uploaded. URI: {$fileUri}</p>";
                    $fileDataPayload = ['fileData' => ['mimeType' => $mimeType, 'fileUri' => $fileUri]];
                }
            }
        }
    } else {
        // --- Method 2: Inline Data Upload ---
        $resultOutput .= "<p><strong>Step 1:</strong> Encoding video file to Base64...</p>";
        $base64Video = base64_encode(file_get_contents($videoFilePath));
        $fileDataPayload = ['inlineData' => ['mimeType' => $mimeType, 'data' => $base64Video]];
        $resultOutput .= "<p><strong>Success!</strong> Video encoded.</p>";
    }

    // --- Step 2: Generate Content from Video ---
    if (!$errorOccurred) {
        $resultOutput .= "<p><strong>Step 2:</strong> Sending prompt and video to the Gemini model...</p>";

        $modelUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key={$apiKey}";
        $modelPayload = json_encode(['contents' => [['parts' => [['text' => $prompt], $fileDataPayload]]]]);
        $modelResult = executeCurl($modelUrl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $modelPayload, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);

        if ($modelResult['httpCode'] !== 200) {
            $resultOutput .= "<p class='error'><strong>Error:</strong> Failed to get a response from the model. Response: " . htmlspecialchars($modelResult['body']) . "</p>";
        } else {
            $modelResponse = json_decode($modelResult['body']);
            if (isset($modelResponse->candidates[0]->content->parts[0]->text)) {
                $responseText = $modelResponse->candidates[0]->content->parts[0]->text;
                $resultOutput .= "<h3>Gemini Model Response:</h3>";
                $resultOutput .= "<pre>" . htmlspecialchars($responseText) . "</pre>";
            } else {
                $resultOutput .= "<p class='error'><strong>Error:</strong> Could not find the generated text in the model's response.</p>";
                $resultOutput .= "<pre>" . htmlspecialchars(print_r($modelResponse, true)) . "</pre>";
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle upload errors
    $uploadError = $_FILES['videoFile']['error'] ?? UPLOAD_ERR_NO_FILE;
    $errors = [
        UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
        UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.',
        UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded.',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
    ];
    $resultOutput = "<p class='error'><strong>File Upload Error:</strong> " . ($errors[$uploadError] ?? 'Unknown error') . "</p>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gemini Video Analyzer</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; line-height: 1.6; color: #333; max-width: 800px; margin: 20px auto; padding: 0 20px; background-color: #f4f7f9; }
        .container { background-color: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        h1, h3 { color: #1a73e8; }
        h1 { text-align: center; border-bottom: 2px solid #e0e0e0; padding-bottom: 15px; margin-bottom: 25px; }
        form { display: flex; flex-direction: column; gap: 20px; }
        label { font-weight: bold; color: #555; }
        input[type="file"], textarea { padding: 10px; border: 1px solid #ccc; border-radius: 5px; font-size: 16px; }
        textarea { min-height: 80px; resize: vertical; }
        .radio-group { display: flex; align-items: center; gap: 15px; }
        .radio-group label { font-weight: normal; }
        button { background-color: #1a73e8; color: white; padding: 12px 20px; border: none; border-radius: 5px; font-size: 16px; font-weight: bold; cursor: pointer; transition: background-color 0.3s; }
        button:hover { background-color: #1558b3; }
        .results { margin-top: 30px; padding: 20px; border: 1px solid #e0e0e0; border-radius: 8px; background-color: #fafafa; }
        .error { color: #d93025; font-weight: bold; }
        pre { background-color: #e8eaed; padding: 15px; border-radius: 5px; white-space: pre-wrap; word-wrap: break-word; font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace; }
        hr { border: 0; height: 1px; background-color: #e0e0e0; margin: 20px 0; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Analyze Video with Gemini</h1>
        <form action="" method="post" enctype="multipart/form-data">
            <div>
                <label for="videoFile">1. Choose Video File:</label>
                <input type="file" name="videoFile" id="videoFile" accept="video/*" required>
            </div>
            
            <div>
                <label for="prompt">2. Enter Your Prompt:</label>
                <textarea name="prompt" id="prompt" required>Summarize this video. Then create a quiz with an answer key based on the information in this video.</textarea>
            </div>

            <div>
                <label>3. Select Upload Method:</label>
                <div class="radio-group">
                    <input type="radio" name="uploadMethod" value="inline" id="inline"> 
                    <label for="inline">Inline (&lt; 20MB)</label>
                    
                    <input type="radio" name="uploadMethod" value="resumable" id="resumable" checked> 
                    <label for="resumable">Resumable (Recommended)</label>
                </div>
            </div>

            <button type="submit">Analyze Video</button>
        </form>

        <?php if (!empty($resultOutput)): ?>
            <div class="results">
                <?php echo $resultOutput; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
