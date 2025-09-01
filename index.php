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
                 $errors = [
                     UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
                     UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.',
                     UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded.',
                     UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
                     UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder for uploads.',
                     UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk. Check permissions for the temporary upload directory.',
                     UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.'
                 ];
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

            $submittedModel = $_POST['model'] ?? 'gemini-2.5-flash';
             if (array_key_exists($submittedModel, $allowedModels)) {
                $selectedModel = $submittedModel;
                $_SESSION['selectedModel'] = $selectedModel;
            } else {
                $selectedModel = 'gemini-2.5-flash';
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
            set_time_limit(0); // Allow script to run indefinitely for this task
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
                } elseif (isset($ttsResponse->candidates[0]->content->parts[0]->inlineData->data)) {
                    $response['success'] = true;
                    $response['audioData'] = $ttsResponse->candidates[0]->content->parts[0]->inlineData->data;
                    $response['error'] = null;
                } else {
                    if (is_null($ttsResponse)) {
                        $response['error'] = "The server received an invalid (non-JSON) response from the Google API.";
                    } else {
                        $response['error'] = "Could not find audio data in the model's response. Raw response: " . htmlspecialchars(print_r($ttsResponse, true));
                    }
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
    $selectedModel = $_SESSION['selectedModel'] ?? 'gemini-2.5-flash';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gemini Video Analyzer - AI-Powered Narration Scripts</title>
    <meta name="description" content="Effortlessly analyze video content and generate professional narration scripts with the Gemini API. Upload any video, provide a custom prompt, and receive a detailed, timestamped script ready for voiceover. You can also convert the generated text into a high-quality audio file.">
    <meta name="keywords" content="Gemini API, Video Analysis, AI Narration, Text to Speech, Google AI, Video Content, Script Generation, AI Voiceover, Video to Text">
    <link rel="canonical" href="https://gemini-video-analyzer.example.com/">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>✨</text></svg>">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://rsms.me/inter/inter.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                    },
                    animation: {
                        'fade-in': 'fadeIn 1s ease-in-out',
                        'background-pan': 'backgroundPan 15s linear infinite',
                    },
                    keyframes: {
                        fadeIn: {
                            '0%': { opacity: 0, transform: 'translateY(20px)' },
                            '100%': { opacity: 1, transform: 'translateY(0)' },
                        },
                        backgroundPan: {
                            '0%': { background-position: '0% 50%' },
                            '50%': { background-position: '100% 50%' },
                            '100%': { background-position: '0% 50%' },
                        }
                    }
                }
            }
        }
    </script>
    <style>
        body {
            background-color: #0c0a18;
            color: #e0e0e0;
        }
        .bg-pan-animated {
            background: linear-gradient(270deg, #8A2387, #E94057, #F27121, #4A00E0);
            background-size: 800% 800%;
            animation: backgroundPan 15s ease infinite;
        }
        .glass-panel {
            background: rgba(12, 10, 24, 0.6);
            -webkit-backdrop-filter: blur(20px);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .form-input {
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: all 0.2s ease-in-out;
            color: #fff;
        }
        .form-input:focus, .form-input:focus-within {
            outline: none;
            border-color: #E94057;
            box-shadow: 0 0 0 3px rgba(233, 64, 87, 0.3);
        }
        .form-input::placeholder { color: #8a8a8a; }
        select.form-input {
             background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%239ca3af' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e");
             background-position: right 0.5rem center;
             background-repeat: no-repeat;
             background-size: 1.5em 1.5em;
             padding-right: 2.5rem;
             -webkit-appearance: none;
             -moz-appearance: none;
             appearance: none;
        }
        .btn-primary {
            @apply w-full flex justify-center items-center px-6 py-4 border border-transparent text-base font-bold rounded-xl shadow-lg text-white transition duration-300 ease-in-out;
            background: linear-gradient(90deg, #E94057, #8A2387);
            background-size: 200% 200%;
        }
        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.25);
            background-position: right center;
        }
         .btn-primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: translateY(0);
            box-shadow: none;
        }
        .btn-secondary {
            @apply w-full flex justify-center items-center px-6 py-3 border border-transparent text-base font-medium rounded-md shadow-sm transition-colors duration-200;
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }
         .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.2);
        }
    </style>
</head>
<body class="font-sans antialiased">
    <div class="fixed inset-0 w-full h-full bg-pan-animated -z-10"></div>
    <div id="toast-container" class="fixed top-5 right-5 z-50 space-y-3"></div>

    <div class="min-h-screen flex flex-col items-center justify-center p-4 animate-fade-in">
        <main class="w-full max-w-2xl glass-panel rounded-2xl shadow-2xl overflow-hidden">
            <div class="p-8 text-center border-b border-b-white/10">
                <h1 class="text-4xl font-extrabold text-white">Gemini Video Analyzer</h1>
                <p class="text-lg text-gray-300 mt-2">Craft the perfect narration for your videos, powered by AI.</p>
            </div>

            <div class="p-8">
                <form id="videoForm" action="" method="post" enctype="multipart/form-data" class="space-y-8">
                    <!-- Step 1 -->
                    <div class="space-y-2">
                        <label for="apiKey" class="text-lg font-semibold text-white flex items-center"><span class="text-2xl mr-4">🔑</span>Your Google AI API Key:</label>
                        <input type="password" name="apiKey" id="apiKey" class="form-input w-full rounded-lg p-3" value="<?php echo htmlspecialchars($apiKey); ?>" required placeholder="Enter your secret API key">
                    </div>

                    <!-- Step 2 -->
                    <div class="space-y-2">
                        <label for="videoFile" class="text-lg font-semibold text-white flex items-center"><span class="text-2xl mr-4">🎬</span>Choose Video File:</label>
                        <input type="file" name="videoFile" id="videoFile" class="w-full text-sm text-gray-400 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-pink-500/20 file:text-pink-300 hover:file:bg-pink-500/30" accept="video/*" required>
                        <div id="video-duration-display" class="text-sm text-gray-400 pt-1 h-5"></div>
                    </div>

                    <!-- Step 3 -->
                    <div class="space-y-2">
                        <label for="model" class="text-lg font-semibold text-white flex items-center"><span class="text-2xl mr-4">🧠</span>Select Gemini Model:</label>
                        <select name="model" id="model" class="form-input w-full rounded-lg p-3">
                            <?php foreach ($allowedModels as $modelValue => $modelName): ?>
                                <option value="<?php echo $modelValue; ?>" <?php if ($modelValue === $selectedModel) echo 'selected'; ?>>
                                    <?php echo $modelName; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Step 4 -->
                    <div class="space-y-2">
                        <label for="prompt" class="text-lg font-semibold text-white flex items-center"><span class="text-2xl mr-4">✍️</span>Enter Your Prompt:</label>
                        <textarea name="prompt" id="prompt" class="form-input w-full rounded-lg p-3" rows="8" required>You are a professional narrator. Your task is to create a detailed, continuous narration script for the provided video.
The most important rule is that the length of your spoken narration must be timed to perfectly match the video's total duration. When read aloud at a natural, unhurried pace (approximately 150 words per minute), the narration should start at the beginning of the video and end exactly when the video ends.
Break the script into timed segments that align with key visual changes, actions, or transitions in the video. Each segment must include a precise timestamp in [HH:MM:SS] format, starting from [00:00:00] and progressing sequentially without gaps or overlaps. The final segment must end exactly at the video's total length of (HH:MM:SS). Estimate the duration of each segment based on the narration length and speaking pace to ensure the total adds up accurately.
Describe the key actions, the setting, and the overall mood as they happen on screen in each timed segment. Do not include any introductory or concluding text. Do not write "Here is the script," "Certainly," or any other conversational phrases. Your entire response must consist ONLY of the timestamped narration script, starting directly with the first timestamp [00:00:00].</textarea>
                    </div>

                    <div class="pt-4 space-y-4">
                         <button type="submit" id="submitBtn" class="btn-primary">
                            <span id="btn-text" class="mx-auto">Analyze Video</span>
                            <svg id="btn-spinner" class="animate-spin h-5 w-5 text-white hidden mx-auto" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                        </button>
                        <div id="resultsWrapper" class="hidden space-y-4">
                            <button type="button" id="downloadBtn" class="btn-secondary">Download .txt file</button>
                            <div id="audioConversionWrapper" class="pt-4 border-t border-white/10">
                                <div class="space-y-4">
                                    <div class="space-y-2">
                                        <label for="voice" class="text-lg font-semibold text-white flex items-center"><span class="text-2xl mr-4">🗣️</span>Select a Voice for the Audio:</label>
                                        <select name="voice" id="voice" class="form-input w-full mt-2 rounded-lg p-3">
                                            <?php foreach ($allowedVoices as $voiceValue => $voiceName): ?>
                                                <option value="<?php echo $voiceValue; ?>"><?php echo $voiceName; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <button type="button" id="audioBtn" class="btn-secondary">
                                        <span id="audio-btn-text" class="flex items-center justify-center">Convert to Audio File</span>
                                        <svg id="audio-btn-spinner" class="animate-spin h-5 w-5 hidden mx-auto" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </main>
        <footer class="text-center mt-8 text-sm text-gray-400">
            <p>Powered by the <a href="https://ai.google.dev/" target="_blank" rel="noopener noreferrer" class="font-medium text-pink-400 hover:underline">Google AI Gemini API</a>. UI by Jules.</p>
        </footer>
    </div>

    <script>
        const form = document.getElementById('videoForm');
        const submitBtn = document.getElementById('submitBtn');
        const btnText = document.getElementById('btn-text');
        const btnSpinner = document.getElementById('btn-spinner');
        const resultsWrapper = document.getElementById('resultsWrapper');
        const downloadBtn = document.getElementById('downloadBtn');
        const audioConversionWrapper = document.getElementById('audioConversionWrapper');
        const toastContainer = document.getElementById('toast-container');
        let lastResponseText = '';
        let pollingInterval;

        function showToast(message, type = 'info') {
            const gradients = {
                info: 'from-blue-500 to-cyan-500',
                success: 'from-green-500 to-emerald-500',
                warning: 'from-yellow-500 to-amber-500',
                danger: 'from-red-500 to-rose-500',
            };
            const toastId = 'toast-' + Date.now();
            const toastHtml = `
                <div id="${toastId}" class="flex items-center w-full max-w-xs p-4 text-white bg-gradient-to-r ${gradients[type]} rounded-lg shadow-lg" role="alert" style="opacity:0; transform: translateX(100%); transition: all 0.5s cubic-bezier(0.25, 1, 0.5, 1);">
                    <div class="text-sm font-semibold">${message}</div>
                    <button type="button" class="ms-auto -mx-1.5 -my-1.5 bg-white/20 text-white hover:text-gray-900 rounded-lg focus:ring-2 focus:ring-gray-300 p-1.5 hover:bg-gray-100 inline-flex items-center justify-center h-8 w-8" onclick="this.parentElement.remove()" aria-label="Close">
                        <span class="sr-only">Close</span>
                        <svg class="w-3 h-3" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 14"><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 6 6m0 0 6 6M7 7l6-6M7 7l-6 6"/></svg>
                    </button>
                </div>
            `;
            toastContainer.insertAdjacentHTML('beforeend', toastHtml);
            const toastElement = document.getElementById(toastId);
            requestAnimationFrame(() => {
                toastElement.style.opacity = '1';
                toastElement.style.transform = 'translateX(0)';
            });
            setTimeout(() => {
                if (toastElement) {
                    toastElement.style.opacity = '0';
                    toastElement.style.transform = 'translateX(100%)';
                    toastElement.addEventListener('transitionend', () => toastElement.remove());
                }
            }, 4500);
        }

        function setLoadingState(isLoading, text = 'Analyze Video') {
            submitBtn.disabled = isLoading;
            btnText.textContent = text;
            if (isLoading) {
                btnText.classList.add('hidden');
                btnSpinner.classList.remove('hidden');
            } else {
                btnText.classList.remove('hidden');
                btnSpinner.classList.add('hidden');
            }
        }

        function resetUI() {
            setLoadingState(false, 'Analyze Video');
            submitBtn.classList.remove('hidden');
            resultsWrapper.classList.add('hidden');
            if(pollingInterval) clearInterval(pollingInterval);
        }

        async function checkStatus(fileUri, apiKey, mimeType, prompt, model) {
            let retries = 0;
            const maxRetries = 30; // ~2.5 minutes
            pollingInterval = setInterval(async () => {
                if (retries++ > maxRetries) {
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
                            showToast('Video ready! Generating narration...', 'success');
                            getResult(fileUri, apiKey, mimeType, prompt, model);
                        } else if (result.state === 'FAILED') {
                            clearInterval(pollingInterval);
                            showToast('Error: Video processing failed.', 'danger');
                            resetUI();
                        }
                    } else { throw new Error(result.error); }
                } catch (error) {
                    clearInterval(pollingInterval);
                    showToast(`Status Check Error: ${error.message}`, 'danger');
                    resetUI();
                }
            }, 5000);
        }

        async function getResult(fileUri, apiKey, mimeType, prompt, model) {
            setLoadingState(true, "Generating...");
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
                    submitBtn.classList.add('hidden');
                    resultsWrapper.classList.remove('hidden');
                } else { throw new Error(result.error || 'Failed to get result.'); }
            } catch (error) {
                showToast(`Result Error: ${error.message}`, 'danger');
                resetUI();
            } finally {
                setLoadingState(false, 'Analyze Again');
            }
        }

        form.addEventListener('submit', async function(e) {
            e.preventDefault();
            resetUI();
            setLoadingState(true, 'Uploading...');
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
                    showToast('Upload successful. Processing video...', 'info');
                    setLoadingState(true, 'Processing...');
                    checkStatus(result.fileUri, apiKey, mimeType, prompt, model);
                } else { throw new Error(result.error || 'Upload failed.'); }
            } catch (error) {
                showToast(`Upload Error: ${error.message}`, 'danger');
                resetUI();
            }
        });

        const videoFileInput = document.getElementById('videoFile');
        const durationDisplay = document.getElementById('video-duration-display');
        const promptTextarea = document.getElementById('prompt');
        const originalPrompt = promptTextarea.value;

        function formatDuration(seconds) {
            const h = Math.floor(seconds / 3600).toString().padStart(2, '0');
            const m = Math.floor((seconds % 3600) / 60).toString().padStart(2, '0');
            const s = Math.floor(seconds % 60).toString().padStart(2, '0');
            return `${h}:${m}:${s}`;
        }
        videoFileInput.addEventListener('change', function(event) {
            const file = event.target.files[0];
            promptTextarea.value = originalPrompt;
            durationDisplay.textContent = '';
            if (file) {
                const videoElement = document.createElement('video');
                const objectUrl = URL.createObjectURL(file);
                videoElement.src = objectUrl;
                videoElement.addEventListener('loadedmetadata', function() {
                    const duration = videoElement.duration;
                    const formattedDuration = formatDuration(duration);
                    durationDisplay.textContent = `Video Duration: ${formattedDuration}`;
                    promptTextarea.value = originalPrompt.replace(/\(HH:MM:SS\)/g, formattedDuration);
                    URL.revokeObjectURL(objectUrl);
                });
                videoElement.addEventListener('error', function() {
                    durationDisplay.textContent = 'Could not determine video duration.';
                    URL.revokeObjectURL(objectUrl);
                });
            }
        });

        downloadBtn.addEventListener('click', function() {
            if (!lastResponseText) { showToast('No response to download.', 'warning'); return; }
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

        function setAudioLoading(isLoading) {
             audioBtn.disabled = isLoading;
             if (isLoading) {
                audioBtnText.classList.add('hidden');
                audioBtnSpinner.classList.remove('hidden');
            } else {
                audioBtnText.classList.remove('hidden');
                audioBtnSpinner.classList.add('hidden');
            }
        }

        function createWaveFile(base64String) {
            try {
                const binaryString = window.atob(base64String);
                const len = binaryString.length;
                const bytes = new Uint8Array(len);
                for (let i = 0; i < len; i++) { bytes[i] = binaryString.charCodeAt(i); }
                const sampleRate = 24000; const numChannels = 1; const bitsPerSample = 16;
                const dataSize = len; const blockAlign = (numChannels * bitsPerSample) / 8;
                const byteRate = sampleRate * blockAlign;
                const buffer = new ArrayBuffer(44 + dataSize);
                const view = new DataView(buffer);
                const writeString = (v, o, s) => { for (let i = 0; i < s.length; i++) v.setUint8(o + i, s.charCodeAt(i)); };
                writeString(view, 0, 'RIFF');
                view.setUint32(4, 36 + dataSize, true);
                writeString(view, 8, 'WAVE');
                writeString(view, 12, 'fmt ');
                view.setUint32(16, 16, true);
                view.setUint16(20, 1, true);
                view.setUint16(22, numChannels, true);
                view.setUint32(24, sampleRate, true);
                view.setUint32(28, byteRate, true);
                view.setUint16(32, blockAlign, true);
                view.setUint16(34, bitsPerSample, true);
                writeString(view, 36, 'data');
                view.setUint32(40, dataSize, true);
                new Uint8Array(buffer, 44).set(bytes);
                return new Blob([view], { type: 'audio/wav' });
            } catch (e) {
                showToast(`Audio Creation Error: ${e.message}`, 'danger');
                return null;
            }
        }

        audioBtn.addEventListener('click', async function() {
            if (!lastResponseText) { showToast('No text to convert.', 'warning'); return; }
            setAudioLoading(true);
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
                } else { throw new Error(result.error || 'Audio conversion failed.'); }
            } catch (error) {
                showToast(`Audio Conversion Error: ${error.message}`, 'danger');
            } finally {
                setAudioLoading(false);
            }
        });
    </script>
</body>
</html>
<?php
} // End of HTML page logic
?>
