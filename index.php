<?php
session_start();

// ==============================================================================
// Configuration
// ==============================================================================

// 1. Manage API Key
$apiKey = '';
// If a new key is submitted via POST, update it in the session.
if (!empty($_POST['apiKey'])) {
    $_SESSION['apiKey'] = $_POST['apiKey'];
}
// Set the apiKey for the current request from the session.
if (!empty($_SESSION['apiKey'])) {
    $apiKey = $_SESSION['apiKey'];
}

// This will hold the HTML output for the results.
$resultOutput = '';

// 2. Define available models and get selection from session
$allowedModels = [
    'gemini-1.5-flash' => 'Gemini 1.5 Flash (Default)',
    'gemini-1.5-pro' => 'Gemini 1.5 Pro',
    'gemini-1.5-flash-latest' => 'Gemini 1.5 Flash (Latest)',
    'gemini-2.5-flash' => 'Gemini 2.5 Flash',
    'gemini-2.5-flash-lite' => 'Gemini 2.5 Flash Lite',
    'gemini-2.5-pro' => 'Gemini 2.5 Pro',
    'gemini-2.0-flash' => 'Gemini 2.0 Flash',
    'gemini-2.0-flash-lite' => 'Gemini 2.0 Flash Lite'
];
$selectedModel = $_SESSION['selectedModel'] ?? 'gemini-1.5-flash';

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

    // Validate the model submitted from the form
    $submittedModel = $_POST['model'] ?? 'gemini-1.5-flash';
    if (array_key_exists($submittedModel, $allowedModels)) {
        $selectedModel = $submittedModel;
        $_SESSION['selectedModel'] = $selectedModel;
    }

    $fileSizeBytes = $_FILES['videoFile']['size'];
    $mimeType = $_FILES['videoFile']['type'];

    // --- Automatically determine upload method based on file size (10MB threshold) ---
    $tenMB = 10 * 1024 * 1024;
    $uploadMethod = ($fileSizeBytes < $tenMB) ? 'inline' : 'resumable';
    $fileSizeMB = $fileSizeBytes / (1024 * 1024);

    $resultOutput .= "<h5>Processing Details:</h5>";
    $resultOutput .= "<ul class='list-group mb-3'>";
    $resultOutput .= "<li class='list-group-item'><b>Original File:</b> {$originalFileName}</li>";
    $resultOutput .= "<li class='list-group-item'><b>File Size:</b> " . number_format($fileSizeMB, 2) . " MB</li>";
    $resultOutput .= "<li class='list-group-item'><b>MIME Type:</b> {$mimeType}</li>";
    $resultOutput .= "<li class='list-group-item'><b>Upload Method:</b> <span class='badge bg-secondary'>{$uploadMethod}</span></li>";
    $resultOutput .= "</ul>";

    $fileDataPayload = null;
    $errorOccurred = false;

    // --- Main Logic: Choose Upload Path ---
    if ($uploadMethod === 'resumable') {
        // --- Method 1: Resumable Upload ---
        $resultOutput .= "<div class='alert alert-info'>Step 1.1: Initializing resumable upload...</div>";
        
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
            $resultOutput .= "<div class='alert alert-danger'><strong>Error:</strong> Could not get the resumable upload URL. Response: " . htmlspecialchars($startResult['body']) . "</div>";
            $errorOccurred = true;
        } else {
            $uploadUrl = $startResult['headers']['x-goog-upload-url'][0];
            $resultOutput .= "<div class='alert alert-info'>Step 1.2: Uploading video data...</div>";

            $uploadHeaders = [
                "Content-Type: {$mimeType}",
                "Content-Length: {$fileSizeBytes}",
                'X-Goog-Upload-Offset: 0',
                'X-Goog-Upload-Command: upload, finalize'
            ];
            $uploadResult = executeCurl($uploadUrl, [CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_POSTFIELDS => file_get_contents($videoFilePath), CURLOPT_HTTPHEADER => $uploadHeaders, CURLOPT_RETURNTRANSFER => true]);

            if ($uploadResult['httpCode'] !== 200) {
                $resultOutput .= "<div class='alert alert-danger'><strong>Error:</strong> Failed to upload video data. Response: " . htmlspecialchars($uploadResult['body']) . "</div>";
                $errorOccurred = true;
            } else {
                $uploadResponse = json_decode($uploadResult['body']);
                if (!isset($uploadResponse->file->uri)) {
                    $resultOutput .= "<div class='alert alert-danger'><strong>Error:</strong> File URI not found in the final upload response.</div>";
                    $errorOccurred = true;
                } else {
                    $fileUri = $uploadResponse->file->uri;
                    $resultOutput .= "<div class='alert alert-success'><strong>Success!</strong> File uploaded. URI: {$fileUri}</div>";
                    $fileDataPayload = ['fileData' => ['mimeType' => $mimeType, 'fileUri' => $fileUri]];
                }
            }
        }
    } else {
        // --- Method 2: Inline Data Upload ---
        $resultOutput .= "<div class='alert alert-info'>Step 1: Encoding video file to Base64...</div>";
        $base64Video = base64_encode(file_get_contents($videoFilePath));
        $fileDataPayload = ['inlineData' => ['mimeType' => $mimeType, 'data' => $base64Video]];
        $resultOutput .= "<div class='alert alert-success'><strong>Success!</strong> Video encoded.</div>";
    }

    // --- Step 2: Generate Content from Video ---
    if (!$errorOccurred) {
        $resultOutput .= "<div class='alert alert-info'>Step 2: Sending prompt and video to the <b>{$selectedModel}</b> model...</div>";

        $modelUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$selectedModel}:generateContent?key={$apiKey}";
        $modelPayload = json_encode(['contents' => [['parts' => [['text' => $prompt], $fileDataPayload]]]]);
        $modelResult = executeCurl($modelUrl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $modelPayload, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);

        if ($modelResult['httpCode'] !== 200) {
            $resultOutput .= "<div class='alert alert-danger'><strong>Error:</strong> Failed to get a response from the model. Response: " . htmlspecialchars($modelResult['body']) . "</div>";
        } else {
            $modelResponse = json_decode($modelResult['body']);
            if (isset($modelResponse->candidates[0]->content->parts[0]->text)) {
                $responseText = $modelResponse->candidates[0]->content->parts[0]->text;
                $resultOutput .= "<h5 class='mt-4'>Gemini Model Response:</h5>";
                $resultOutput .= "<pre class='bg-dark text-white p-3 rounded'>" . htmlspecialchars($responseText) . "</pre>";
            } else {
                $resultOutput .= "<div class='alert alert-warning'><strong>Warning:</strong> Could not find the generated text in the model's response.</div>";
                $resultOutput .= "<pre class='bg-secondary text-white p-3 rounded'>" . htmlspecialchars(print_r($modelResponse, true)) . "</pre>";
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
    $resultOutput = "<div class='alert alert-danger'><strong>File Upload Error:</strong> " . ($errors[$uploadError] ?? 'Unknown error') . "</div>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gemini Video Analyzer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-LN+7fdVzj6u52u30Kp6M/trliBMCMKTyK833zpbD+pXdCLuTusPj697FH4R/5mcr" crossorigin="anonymous">
</head>
<body class="bg-light-subtle">
    <div class="container py-5" style="max-width: 800px;">
        <div class="card shadow-sm">
            <div class="card-header text-center bg-primary text-white">
                <h1 class="h3 mb-0">Analyze Video with Gemini</h1>
            </div>
            <div class="card-body p-4">
                <form action="" method="post" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label for="apiKey" class="form-label">1. Your Google AI API Key:</label>
                        <input type="password" name="apiKey" id="apiKey" class="form-control" value="<?php echo htmlspecialchars($apiKey); ?>" required>
                    </div>

                    <div class="mb-3">
                        <label for="videoFile" class="form-label">2. Choose Video File:</label>
                        <input type="file" name="videoFile" id="videoFile" class="form-control" accept="video/*" required>
                    </div>

                    <div class="mb-3">
                        <label for="model" class="form-label">3. Select Gemini Model:</label>
                        <select name="model" id="model" class="form-select">
                            <?php foreach ($allowedModels as $modelValue => $modelName): ?>
                                <option value="<?php echo $modelValue; ?>" <?php if ($modelValue === $selectedModel) echo 'selected'; ?>>
                                    <?php echo $modelName; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Choose the model to use for video analysis.</div>
                    </div>

                    <div class="mb-3">
                        <label for="prompt" class="form-label">4. Enter Your Prompt:</label>
                        <textarea name="prompt" id="prompt" class="form-control" rows="4" required>Summarize this video. Then create a quiz with an answer key based on the information in this video.</textarea>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 py-2 fw-bold">Analyze Video</button>
                </form>
            </div>
        </div>

        <?php if (!empty($resultOutput)): ?>
            <div class="card mt-4 shadow-sm">
                <div class="card-body">
                    <?php echo $resultOutput; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
