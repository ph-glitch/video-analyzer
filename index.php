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
$allowedVoices = [
    'Zephyr' => 'Zephyr (Bright)',
    'Puck' => 'Puck (Upbeat)',
    'Charon' => 'Charon (Informative)',
    'Kore' => 'Kore (Firm)',
    'Fenrir' => 'Fenrir (Excitable)',
    'Leda' => 'Leda (Youthful)',
    'Orus' => 'Orus (Firm)',
    'Aoede' => 'Aoede (Breezy)',
    'Callirrhoe' => 'Callirrhoe (Easy-going)',
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
    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'error' => 'An unknown error occurred.'];

    // --- Common validation ---
    $apiKey = $_POST['apiKey'] ?? '';
    if (empty($apiKey)) {
        $response['error'] = 'API Key Error: Please provide your Google AI API key.';
        echo json_encode($response);
        exit();
    }
    $_SESSION['apiKey'] = $apiKey;


    switch ($action) {
        case 'upload':
            if (!isset($_FILES['videoFile']) || $_FILES['videoFile']['error'] !== UPLOAD_ERR_OK) {
                 $uploadError = $_FILES['videoFile']['error'] ?? UPLOAD_ERR_NO_FILE;
                 $errors = [ /* ... error mapping ... */ ];
                 $response['error'] = 'File Upload Error: ' . ($errors[$uploadError] ?? 'Unknown error');
            } else {
                $videoFilePath = $_FILES['videoFile']['tmp_name'];
                $fileSizeBytes = $_FILES['videoFile']['size'];
                $mimeType = $_FILES['videoFile']['type'];

                // For this workflow, we will only support resumable uploads as they are required for polling.
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
                        if (!isset($uploadResponse->file->name)) {
                            $response['error'] = 'File Name identifier not found in the final upload response.';
                        } else {
                            $response['success'] = true;
                            $response['fileUri'] = $uploadResponse->file->name; // Use the 'name' not the 'uri'
                            $response['error'] = null;
                        }
                    }
                }
            }
            break;

        case 'check_status':
            $fileUri = $_POST['fileUri'] ?? '';
            if (empty($fileUri)) {
                $response['error'] = 'File URI not provided for status check.';
            } else {
                $statusUrl = "https://generativelanguage.googleapis.com/v1beta/{$fileUri}?key={$apiKey}";
                $statusResult = executeCurl($statusUrl, [CURLOPT_HTTPGET => true, CURLOPT_RETURNTRANSFER => true]);
                $statusResponse = json_decode($statusResult['body']);

                if (isset($statusResponse->state)) {
                    $response['success'] = true;
                    $response['state'] = $statusResponse->state;
                    $response['error'] = null;
                } elseif (isset($statusResponse->error->message)) {
                    $response['error'] = 'Google API Error: ' . htmlspecialchars($statusResponse->error->message);
                } else {
                    $response['error'] = 'Could not retrieve file processing status. The response from Google was unexpected.';
                }
            }
            break;

        case 'get_result':
            $fileUri = $_POST['fileUri'] ?? '';
            $prompt = $_POST['prompt'] ?? '';
            $mimeType = $_POST['mimeType'] ?? '';

            $submittedModel = $_POST['model'] ?? 'gemini-1.5-flash';
             if (array_key_exists($submittedModel, $allowedModels)) {
                $selectedModel = $submittedModel;
                $_SESSION['selectedModel'] = $selectedModel;
            } else {
                $selectedModel = 'gemini-1.5-flash';
            }

            if (empty($fileUri) || empty($prompt) || empty($mimeType)) {
                $response['error'] = 'Missing required data for generating content.';
            } else {
                // The `generateContent` call requires the full resource URI.
                $fullFileUrl = "https://generativelanguage.googleapis.com/v1beta/{$fileUri}";
                $fileDataPayload = ['fileData' => ['mimeType' => $mimeType, 'fileUri' => $fullFileUrl]];

                $modelUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$selectedModel}:generateContent?key={$apiKey}";
                $modelPayload = json_encode(['contents' => [['parts' => [['text' => $prompt], $fileDataPayload]]]]);
                $modelResult = executeCurl($modelUrl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $modelPayload, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);

                if ($modelResult['httpCode'] !== 200) {
                    $response['error'] = "Failed to get a response from the model. Response: " . htmlspecialchars($modelResult['body']);
                } else {
                    $modelResponse = json_decode($modelResult['body']);
                    if (!empty($modelResponse->candidates[0]->content->parts[0]->text)) {
                        $response['success'] = true;
                        $response['responseText'] = $modelResponse->candidates[0]->content->parts[0]->text;
                        $response['error'] = null;
                    } elseif (empty($modelResponse->candidates[0]->content->parts)) {
                        $response['error'] = "The model processed the request but did not return any text. This can happen due to the model's safety filters or if the video content is invalid or unsupported.";
                    } else {
                        $response['error'] = "Could not find generated text in the model's response. Raw response: " . htmlspecialchars(print_r($modelResponse, true));
                    }
                }
            }
            break;

        case 'convert_to_audio':
            $textToConvert = $_POST['textToConvert'] ?? '';
            $voice = $_POST['voice'] ?? 'Kore'; // Default voice
            $ttsModel = 'gemini-2.5-flash-preview-tts';

            if (empty($textToConvert)) {
                $response['error'] = 'No text provided for audio conversion.';
            } else {
                $ttsUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$ttsModel}:generateContent?key={$apiKey}";
                $ttsPayload = json_encode([
                    'model' => $ttsModel,
                    'contents' => [['parts' => [['text' => $textToConvert]]]],
                    'generationConfig' => [
                        'responseModalities' => ['AUDIO'],
                        'speechConfig' => [
                            'voiceConfig' => [
                                'prebuiltVoiceConfig' => ['voiceName' => $voice]
                            ]
                        ]
                    ]
                ]);

                $ttsResult = executeCurl($ttsUrl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $ttsPayload, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
                $ttsResponse = json_decode($ttsResult['body']);

                if ($ttsResult['httpCode'] !== 200) {
                    $response['error'] = "Failed to get a response from the TTS model. Response: " . htmlspecialchars($ttsResult['body']);
                } elseif (!empty($ttsResponse->candidates[0]->content->parts[0]->inlineData->data)) {
                    $response['success'] = true;
                    $response['audioData'] = $ttsResponse->candidates[0]->content->parts[0]->inlineData->data;
                    $response['error'] = null;
                } else {
                    $response['error'] = "Could not find audio data in the model's response. Raw response: " . htmlspecialchars(print_r($ttsResponse, true));
                }
            }
            break;

        default:
            $response['error'] = 'Invalid action specified.';
            break;
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
                        <div id="video-duration-display" class="form-text mt-1"></div>
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
                        <textarea name="prompt" id="prompt" class="form-control" rows="8" required>You are a professional narrator. Your task is to create a detailed, continuous narration script for the provided video.

The most important rule is that the length of your spoken narration must be timed to perfectly match the video's total duration. When read aloud at a natural, unhurried pace, the narration should start at the beginning of the video and end exactly when the video ends.

Describe the key actions, the setting, and the overall mood as they happen on screen. Do not include any introductory or concluding text. Do not write "Here is the script," "Certainly," or any other conversational phrases. Your entire response must consist ONLY of the narration script, starting directly with the first timestamp. The video length is (HH:MM:SS)</textarea>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" id="submitBtn" class="btn btn-primary py-2 fw-bold">
                            <span id="btn-text">Analyze Video</span>
                            <span id="btn-spinner" class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                        </button>
                        <button type="button" id="downloadBtn" class="btn btn-success py-2 fw-bold d-none">
                            <i class="bi bi-download me-2"></i> Download .txt file
                        </button>

                        <div id="audioConversionWrapper" class="d-none">
                            <div class="mb-3">
                                <label for="voice" class="form-label">5. Select a Voice for the Audio:</label>
                                <select name="voice" id="voice" class="form-select">
                                    <?php foreach ($allowedVoices as $voiceValue => $voiceName): ?>
                                        <option value="<?php echo $voiceValue; ?>">
                                            <?php echo $voiceName; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="button" id="audioBtn" class="btn btn-info py-2 fw-bold w-100">
                                <span id="audio-btn-text"><i class="bi bi-sound-wave me-2"></i>Convert to Audio File</span>
                                <span id="audio-btn-spinner" class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                            </button>
                        </div>
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

        // --- Video Duration Logic ---
        const videoFileInput = document.getElementById('videoFile');
        const durationDisplay = document.getElementById('video-duration-display');
        const promptTextarea = document.getElementById('prompt');
        const originalPrompt = promptTextarea.value; // Store the template

        function formatDuration(seconds) {
            const h = Math.floor(seconds / 3600).toString().padStart(2, '0');
            const m = Math.floor((seconds % 3600) / 60).toString().padStart(2, '0');
            const s = Math.floor(seconds % 60).toString().padStart(2, '0');
            return `${h}:${m}:${s}`;
        }

        videoFileInput.addEventListener('change', function(event) {
            const file = event.target.files[0];
            promptTextarea.value = originalPrompt; // Reset to template
            durationDisplay.textContent = '';

            if (file) {
                const videoElement = document.createElement('video');
                const objectUrl = URL.createObjectURL(file);

                videoElement.src = objectUrl;
                videoElement.addEventListener('loadedmetadata', function() {
                    const duration = videoElement.duration;
                    const formattedDuration = formatDuration(duration);

                    durationDisplay.textContent = `Video Duration: ${formattedDuration}`;
                    promptTextarea.value = originalPrompt.replace('(HH:MM:SS)', formattedDuration);

                    URL.revokeObjectURL(objectUrl); // Clean up memory
                });

                videoElement.addEventListener('error', function() {
                    durationDisplay.textContent = 'Could not determine video duration.';
                    URL.revokeObjectURL(objectUrl);
                });
            }
        });

        // --- Form Submission Logic ---
        const form = document.getElementById('videoForm');
        const submitBtn = document.getElementById('submitBtn');
        const downloadBtn = document.getElementById('downloadBtn');
        const btnText = document.getElementById('btn-text');
        const btnSpinner = document.getElementById('btn-spinner');
        const toastContainer = document.querySelector('.toast-container');
        let lastResponseText = '';
        let pollingInterval;

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

        function resetUI() {
            btnText.textContent = 'Analyze Video';
            btnSpinner.classList.add('d-none');
            submitBtn.disabled = false;
            submitBtn.classList.remove('d-none');
            downloadBtn.classList.add('d-none');
            document.getElementById('audioConversionWrapper').classList.add('d-none');
            if(pollingInterval) clearInterval(pollingInterval);
        }

        async function checkStatus(fileUri, apiKey, mimeType, prompt, model) {
            let retries = 0;
            const maxRetries = 30; // ~2.5 minutes

            pollingInterval = setInterval(async () => {
                retries++;
                if (retries > maxRetries) {
                    clearInterval(pollingInterval);
                    showToast('Error: File processing timed out.', 'danger');
                    resetUI();
                    return;
                }

                const formData = new FormData();
                formData.append('is_ajax', '1');
                formData.append('action', 'check_status');
                formData.append('fileUri', fileUri);
                formData.append('apiKey', apiKey);

                try {
                    const response = await fetch('index.php', { method: 'POST', body: formData });
                    const result = await response.json();

                    if (result.success) {
                        if (result.state === 'ACTIVE') {
                            clearInterval(pollingInterval);
                            showToast('Video processing complete! Generating narration...', 'success');
                            getResult(fileUri, apiKey, mimeType, prompt, model);
                        } else if (result.state === 'FAILED') {
                            clearInterval(pollingInterval);
                            showToast('Error: Video processing failed on the server.', 'danger');
                            resetUI();
                        }
                        // If still PROCESSING, the interval will just continue
                    } else {
                        throw new Error(result.error);
                    }
                } catch (error) {
                    clearInterval(pollingInterval);
                    showToast(`Error checking status: ${error.message}`, 'danger');
                    resetUI();
                }
            }, 5000);
        }

        async function getResult(fileUri, apiKey, mimeType, prompt, model) {
             const formData = new FormData();
            formData.append('is_ajax', '1');
            formData.append('action', 'get_result');
            formData.append('fileUri', fileUri);
            formData.append('apiKey', apiKey);
            formData.append('mimeType', mimeType);
            formData.append('prompt', prompt);
            formData.append('model', model);

            try {
                const response = await fetch('index.php', { method: 'POST', body: formData });
                const result = await response.json();
                if (result.success && result.responseText) {
                    lastResponseText = result.responseText;
                    // On success, hide the submit button and show the download and audio buttons
                    submitBtn.classList.add('d-none');
                    downloadBtn.classList.remove('d-none');
                    document.getElementById('audioConversionWrapper').classList.remove('d-none');
                    // And reset the state of the (now hidden) submit button
                    btnText.textContent = 'Analyze Video';
                    btnSpinner.classList.add('d-none');
                    submitBtn.disabled = false;
                } else {
                    throw new Error(result.error || 'Failed to get result.');
                }
            } catch (error) {
                showToast(`Error generating result: ${error.message}`, 'danger');
                resetUI(); // Only reset the entire UI on failure
            }
        }


        form.addEventListener('submit', async function(e) {
            e.preventDefault();

            // --- 1. Set loading state & get initial data ---
            resetUI();
            btnText.textContent = 'Uploading...';
            btnSpinner.classList.remove('d-none');
            submitBtn.disabled = true;

            const formData = new FormData(form);
            formData.append('is_ajax', '1');
            formData.append('action', 'upload');

            const apiKey = form.querySelector('#apiKey').value;
            const mimeType = form.querySelector('#videoFile').files[0]?.type;
            const prompt = form.querySelector('#prompt').value;
            const model = form.querySelector('#model').value;

            try {
                showToast('Uploading video file...', 'info');
                const response = await fetch('index.php', { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success && result.fileUri) {
                    showToast('File uploaded successfully. Video is now processing...', 'info');
                    btnText.textContent = 'Processing...'; // Update button text
                    checkStatus(result.fileUri, apiKey, mimeType, prompt, model);
                } else {
                    throw new Error(result.error || 'Upload failed.');
                }
            } catch (error) {
                showToast(`Upload Error: ${error.message}`, 'danger');
                resetUI();
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

        const audioBtn = document.getElementById('audioBtn');
        const audioBtnText = document.getElementById('audio-btn-text');
        const audioBtnSpinner = document.getElementById('audio-btn-spinner');

        function createWaveFile(base64String) {
            try {
                const binaryString = window.atob(base64String);
                const len = binaryString.length;
                const bytes = new Uint8Array(len);
                for (let i = 0; i < len; i++) {
                    bytes[i] = binaryString.charCodeAt(i);
                }

                const sampleRate = 24000;
                const numChannels = 1;
                const bitsPerSample = 16;
                const dataSize = len;
                const blockAlign = (numChannels * bitsPerSample) / 8;
                const byteRate = sampleRate * blockAlign;

                const buffer = new ArrayBuffer(44 + dataSize);
                const view = new DataView(buffer);

                // RIFF header
                writeString(view, 0, 'RIFF');
                view.setUint32(4, 36 + dataSize, true);
                writeString(view, 8, 'WAVE');
                // fmt chunk
                writeString(view, 12, 'fmt ');
                view.setUint32(16, 16, true); // Subchunk1Size
                view.setUint16(20, 1, true); // AudioFormat (PCM)
                view.setUint16(22, numChannels, true);
                view.setUint32(24, sampleRate, true);
                view.setUint32(28, byteRate, true);
                view.setUint16(32, blockAlign, true);
                view.setUint16(34, bitsPerSample, true);
                // data chunk
                writeString(view, 36, 'data');
                view.setUint32(40, dataSize, true);

                // Write PCM data
                new Uint8Array(buffer, 44).set(bytes);

                return new Blob([view], { type: 'audio/wav' });
            } catch (e) {
                console.error("Error creating WAV file:", e);
                showToast(`Error creating audio file: ${e.message}`, 'danger');
                return null;
            }
        }

        function writeString(view, offset, string) {
            for (let i = 0; i < string.length; i++) {
                view.setUint8(offset + i, string.charCodeAt(i));
            }
        }


        audioBtn.addEventListener('click', async function() {
            if (!lastResponseText) {
                showToast('No generated text available to convert.', 'warning');
                return;
            }

            audioBtn.disabled = true;
            audioBtnSpinner.classList.remove('d-none');
            audioBtnText.textContent = 'Converting...';

            const formData = new FormData();
            formData.append('is_ajax', '1');
            formData.append('action', 'convert_to_audio');
            formData.append('textToConvert', lastResponseText);
            formData.append('apiKey', document.getElementById('apiKey').value);
            formData.append('voice', document.getElementById('voice').value);

            try {
                showToast('Sending text to Speech API...', 'info');
                const response = await fetch('index.php', { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success && result.audioData) {
                    showToast('Audio received, creating WAV file...', 'success');
                    const waveBlob = createWaveFile(result.audioData);
                    if (waveBlob) {
                        const url = URL.createObjectURL(waveBlob);
                        const a = document.createElement('a');
                        a.href = url;
                        a.download = 'gemini-narration.wav';
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                        URL.revokeObjectURL(url);
                        showToast('Audio download started.', 'info');
                    }
                } else {
                    throw new Error(result.error || 'Audio conversion failed.');
                }
            } catch (error) {
                showToast(`Audio Conversion Error: ${error.message}`, 'danger');
            } finally {
                audioBtn.disabled = false;
                audioBtnSpinner.classList.add('d-none');
                audioBtnText.innerHTML = '<i class="bi bi-sound-wave me-2"></i>Convert to Audio File';
            }
        });

    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js" integrity="sha384-ndDqU0Gzau9qJ1lfW4pNLlhNTkCfHzAVBReH9diLvGRem5+R9g2FzA8ZGN954O5Q" crossorigin="anonymous"></script>
</body>
</html>
<?php
} // End of HTML page logic
?>
