<?php
session_start();

// ==============================================================================
// Configuration & Helper Functions
// ==============================================================================

// --- Shared Configuration ---
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

/**
 * A helper function to execute a cURL request.
 */
function executeCurl(string $url, array $options, bool $returnHeaders = false): array {
    $ch = curl_init($url);
    $headerArray = [];
    if ($returnHeaders) {
        $options[CURLOPT_HEADERFUNCTION] = function($curl, $header) use (&$headerArray) {
            $len = strlen($header);
            $header = explode(':', $header, 2);
            if (count($header) < 2) return $len;
            $headerArray[strtolower(trim($header[0]))][] = trim($header[1]);
            return $len;
        };
    }
    curl_setopt_array($ch, $options);
    $responseBody = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    return ['body' => $responseBody, 'headers' => $headerArray, 'httpCode' => $httpCode, 'error' => $error];
}

// ==============================================================================
// Main Logic: Decide between API and HTML response
// ==============================================================================

if (isset($_POST['is_ajax'])) {
    // --- API LOGIC (Handles AJAX requests) ---
    header('Content-Type: application/json');
    $response = ['success' => false, 'error' => 'An unknown error occurred.', 'responseText' => null];

    // --- Get and validate data from form ---
    $apiKey = $_POST['apiKey'] ?? '';
    $_SESSION['apiKey'] = $apiKey;

    $submittedModel = $_POST['model'] ?? 'gemini-1.5-flash';
    if (array_key_exists($submittedModel, $allowedModels)) {
        $selectedModel = $submittedModel;
        $_SESSION['selectedModel'] = $selectedModel;
    } else {
        $selectedModel = 'gemini-1.5-flash'; // Default
    }

    if (empty($apiKey)) {
        $response['error'] = 'API Key Error: Please provide your Google AI API key.';
        echo json_encode($response);
        exit();
    }

    if (!isset($_FILES['videoFile']) || $_FILES['videoFile']['error'] !== UPLOAD_ERR_OK) {
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
        $response['error'] = 'File Upload Error: ' . ($errors[$uploadError] ?? 'Unknown error');
        echo json_encode($response);
        exit();
    }

    $videoFilePath = $_FILES['videoFile']['tmp_name'];
    $prompt = $_POST['prompt'] ?? 'Describe this video.';
    $fileSizeBytes = $_FILES['videoFile']['size'];
    $mimeType = $_FILES['videoFile']['type'];

    $tenMB = 10 * 1024 * 1024;
    $uploadMethod = ($fileSizeBytes < $tenMB) ? 'inline' : 'resumable';

    $fileDataPayload = null;

    // --- Upload Logic ---
    if ($uploadMethod === 'resumable') {
        $startUrl = "https://generativelanguage.googleapis.com/upload/v1beta/files?key={$apiKey}";
        $startHeaders = ['X-Goog-Upload-Protocol: resumable', 'X-Goog-Upload-Command: start', "X-Goog-Upload-Header-Content-Length: {$fileSizeBytes}", "X-Goog-Upload-Header-Content-Type: {$mimeType}", 'Content-Type: application/json'];
        $startPayload = json_encode(['file' => ['display_name' => basename($_FILES['videoFile']['name'])]]);
        $startResult = executeCurl($startUrl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $startPayload, CURLOPT_HTTPHEADER => $startHeaders, CURLOPT_RETURNTRANSFER => true], true);

        if ($startResult['httpCode'] !== 200 || empty($startResult['headers']['x-goog-upload-url'][0])) {
            $response['error'] = "Could not get resumable upload URL. Response: " . htmlspecialchars($startResult['body']);
        } else {
            $uploadUrl = $startResult['headers']['x-goog-upload-url'][0];
            $uploadHeaders = ["Content-Type: {$mimeType}", "Content-Length: {$fileSizeBytes}", 'X-Goog-Upload-Offset: 0', 'X-Goog-Upload-Command: upload, finalize'];
            $uploadResult = executeCurl($uploadUrl, [CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_POSTFIELDS => file_get_contents($videoFilePath), CURLOPT_HTTPHEADER => $uploadHeaders, CURLOPT_RETURNTRANSFER => true]);

            if ($uploadResult['httpCode'] !== 200) {
                $response['error'] = "Failed to upload video data. Response: " . htmlspecialchars($uploadResult['body']);
            } else {
                $uploadResponse = json_decode($uploadResult['body']);
                if (!isset($uploadResponse->file->uri)) {
                    $response['error'] = 'File URI not found in the final upload response.';
                } else {
                    $fileDataPayload = ['fileData' => ['mimeType' => $mimeType, 'fileUri' => $uploadResponse->file->uri]];
                }
            }
        }
    } else { // Inline upload
        $base64Video = base64_encode(file_get_contents($videoFilePath));
        $fileDataPayload = ['inlineData' => ['mimeType' => $mimeType, 'data' => $base64Video]];
    }

    // --- Content Generation ---
    if ($fileDataPayload) {
        $modelUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$selectedModel}:generateContent?key={$apiKey}";
        $modelPayload = json_encode(['contents' => [['parts' => [['text' => $prompt], $fileDataPayload]]]]);
        $modelResult = executeCurl($modelUrl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $modelPayload, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);

        if ($modelResult['httpCode'] !== 200) {
            $response['error'] = "Failed to get a response from the model. Response: " . htmlspecialchars($modelResult['body']);
        } else {
            $modelResponse = json_decode($modelResult['body']);
            if (isset($modelResponse->candidates[0]->content->parts[0]->text)) {
                $response['success'] = true;
                $response['responseText'] = $modelResponse->candidates[0]->content->parts[0]->text;
                $response['error'] = null;
            } else {
                $response['error'] = "Could not find generated text in the model's response. Raw response: " . htmlspecialchars(print_r($modelResponse, true));
            }
        }
    }

    echo json_encode($response);
    exit();

} else {
    // --- HTML PAGE LOGIC (Handles normal browser requests) ---
    $apiKey = $_SESSION['apiKey'] ?? '';
    $selectedModel = $_SESSION['selectedModel'] ?? 'gemini-1.5-flash';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gemini Video Analyzer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-LN+7fdVzj6u52u30Kp6M/trliBMCMKTyK833zpbD+pXdCLuTusPj697FH4R/5mcr" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://rsms.me/inter/inter.css">
    <style>
        :root { font-family: 'Inter', sans-serif; }
        .form-label { font-weight: 500; }
    </style>
</head>
<body class="bg-light-subtle">
    <div class="toast-container position-fixed bottom-0 end-0 p-3">
        <!-- Toasts will be appended here -->
    </div>

    <div class="container py-5" style="max-width: 800px;">
        <div class="position-absolute top-0 end-0 p-3">
            <button class="btn btn-outline-secondary" id="theme-toggle" type="button">
                <i class="bi bi-sun-fill"></i>
            </button>
        </div>
        <div class="card shadow-sm">
            <div class="card-header text-center bg-primary text-white">
                <h1 class="h3 mb-0">Analyze Video with Gemini</h1>
            </div>
            <div class="card-body p-4">
                <form id="videoForm" action="" method="post" enctype="multipart/form-data">
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

                    <div class="d-grid gap-2">
                        <button type="submit" id="submitBtn" class="btn btn-primary py-2 fw-bold">
                            <span id="btn-text">Analyze Video</span>
                            <span id="btn-spinner" class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                        </button>
                        <button type="button" id="downloadBtn" class="btn btn-success py-2 fw-bold d-none">
                            <i class="bi bi-download me-2"></i> Download .txt file
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // --- Theme Toggler Logic ---
        const themeToggle = document.getElementById('theme-toggle');
        const themeIcon = themeToggle.querySelector('i');

        const getPreferredTheme = () => {
            if (localStorage.getItem('theme')) {
                return localStorage.getItem('theme');
            }
            return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        };

        const setTheme = (theme) => {
            if (theme === 'dark') {
                document.documentElement.setAttribute('data-bs-theme', 'dark');
                themeIcon.classList.replace('bi-sun-fill', 'bi-moon-fill');
            } else {
                document.documentElement.setAttribute('data-bs-theme', 'light');
                themeIcon.classList.replace('bi-moon-fill', 'bi-sun-fill');
            }
            localStorage.setItem('theme', theme);
        };

        themeToggle.addEventListener('click', () => {
            const currentTheme = document.documentElement.getAttribute('data-bs-theme');
            setTheme(currentTheme === 'dark' ? 'light' : 'dark');
        });

        // Set initial theme on page load
        setTheme(getPreferredTheme());

        // --- Form Submission Logic ---
        const form = document.getElementById('videoForm');
        const submitBtn = document.getElementById('submitBtn');
        const downloadBtn = document.getElementById('downloadBtn');
        const btnText = document.getElementById('btn-text');
        const btnSpinner = document.getElementById('btn-spinner');
        const toastContainer = document.querySelector('.toast-container');
        let lastResponseText = '';

        function showToast(message, type = 'info') {
            const toastId = 'toast-' + Date.now();
            const toastHtml = `
                <div id="${toastId}" class="toast" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="5000">
                    <div class="toast-header">
                        <span class="p-2 border border-light rounded-circle me-2 bg-${type}"></span>
                        <strong class="me-auto">${type.charAt(0).toUpperCase() + type.slice(1)}</strong>
                        <small>Just now</small>
                        <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
                    </div>
                    <div class="toast-body">
                        ${message}
                    </div>
                </div>
            `;
            toastContainer.insertAdjacentHTML('beforeend', toastHtml);

            const toastElement = document.getElementById(toastId);
            const toast = new bootstrap.Toast(toastElement);

            toastElement.addEventListener('hidden.bs.toast', () => {
                toastElement.remove();
            });

            toast.show();
        }

        form.addEventListener('submit', async function(e) {
            e.preventDefault();

            // --- 1. Set loading state ---
            lastResponseText = '';
            downloadBtn.classList.add('d-none');
            submitBtn.classList.remove('d-none');
            btnText.textContent = 'Analyzing...';
            btnSpinner.classList.remove('d-none');
            submitBtn.disabled = true;

            showToast('Processing your video, please wait...', 'info');

            // --- 2. Prepare form data ---
            const formData = new FormData(form);
            formData.append('is_ajax', '1');

            let success = false;
            try {
                // --- 3. Fetch results ---
                const response = await fetch('index.php', {
                    method: 'POST',
                    body: formData
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! Status: ${response.status}`);
                }

                const result = await response.json();

                // --- 4. Handle response ---
                if (result.success && result.responseText) {
                    showToast('Analysis successful!', 'success');
                    lastResponseText = result.responseText;
                    success = true;
                } else {
                    throw new Error(result.error || 'An unknown error occurred.');
                }

            } catch (error) {
                showToast(`<strong>Error:</strong> ${error.message}`, 'danger');
            } finally {
                // --- 5. Reset button state ---
                if (success) {
                    submitBtn.classList.add('d-none');
                    downloadBtn.classList.remove('d-none');
                }
                btnText.textContent = 'Analyze Video';
                btnSpinner.classList.add('d-none');
                submitBtn.disabled = false;
            }
        });

        downloadBtn.addEventListener('click', function() {
            if (!lastResponseText) {
                showToast('No response text to download.', 'warning');
                return;
            }

            const blob = new Blob([lastResponseText], { type: 'text/plain' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'gemini-response.txt';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
            showToast('Download started.', 'info');
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js" integrity="sha384-ndDqU0Gzau9qJ1lfW4pNLlhNTkCfHzAVBReH9diLvGRem5+R9g2FzA8ZGN954O5Q" crossorigin="anonymous"></script>
</body>
</html>
<?php
} // End of HTML page logic
?>
