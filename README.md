# Gemini Video Analyzer

A simple yet powerful web application that leverages the Google Gemini API to perform advanced analysis on video files. Upload a video, provide a prompt, and receive a detailed, text-based analysis. This tool also includes a text-to-speech feature to convert the generated narration into a downloadable audio file.

<!-- Add a screenshot of the application here -->
<!-- ![Screenshot of Gemini Video Analyzer](screenshot.png) -->

---

## Features

- **Direct Video Upload:** Easily upload video files through the web interface.
- **Powered by Google Gemini:** Utilizes the cutting-edge Gemini family of models for video understanding.
- **Customizable Analysis:** Use custom prompts to guide the AI's analysis. The default prompt is optimized for creating detailed, timed video narration scripts.
- **Flexible Model Selection:** Choose from a range of supported Gemini models (`gemini-1.5-pro`, `gemini-2.5-flash`, etc.).
- **Text-to-Speech Conversion:** Convert the generated text analysis into a high-quality audio file (`.wav`) using Google's TTS API.
- **Voice Selection:** Choose from several different voices for the audio narration.
- **Downloadable Results:** Download the text analysis as a `.txt` file and the audio as a `.wav` file.
- **Responsive UI:** Clean and simple interface built with Bootstrap that works on all screen sizes.
- **Light/Dark Mode:** Includes a theme toggler for user comfort.
- **Self-Contained:** The entire application runs from a single `index.php` file.

## How It Works

The application follows a simple, multi-step process to analyze your video:

1.  **File Upload:** The user selects a video file, which is uploaded to the server. The application uses the Google AI File API to perform a resumable upload, making it suitable for larger files.
2.  **File Processing:** Google's backend processes and prepares the video for analysis. The application polls Google's API to check the status of the file processing.
3.  **Content Generation:** Once the file is processed, the application sends the video and the user's prompt to the selected Gemini model via the `generateContent` API call.
4.  **Text-to-Speech (Optional):** After the text is generated, the user can click a button to send the text to the Google Text-to-Speech API, which returns the audio data.
5.  **Download:** The final text and audio are made available for the user to download directly from their browser.

## Setup and Installation

This project is designed to be simple to set up.

**Prerequisites:**
- A web server with PHP support (e.g., Apache, Nginx with PHP-FPM).
- cURL extension for PHP must be enabled.
- A valid Google AI API Key.

**Installation:**

1.  **Get an API Key:**
    - Go to the [Google AI Studio](https://aistudio.google.com/) and create an API key.
    - Make sure the "Gemini API" is enabled for your project in the Google Cloud Console if required.

2.  **Deploy the File:**
    - Clone or download the `index.php` file from this repository.
    - Place the `index.php` file in a directory served by your web server (e.g., `/var/www/html/`).

3.  **Run the Application:**
    - Open your web browser and navigate to the location of your `index.php` file (e.g., `http://localhost/index.php`).

## How to Use

1.  **Enter API Key:** Paste your Google AI API Key into the first input field. The key is stored in the session for your convenience but is required for all API interactions.
2.  **Choose Video:** Select a video file from your local machine.
3.  **Select Model:** Choose the Gemini model you wish to use for the analysis.
4.  **Edit Prompt:** Modify the prompt in the textarea to instruct the AI on the desired analysis. The default prompt is designed to generate a narration script. The `(HH:MM:SS)` placeholder will be automatically replaced with the actual duration of your uploaded video.
5.  **Analyze:** Click the "Analyze Video" button. The process involves uploading, server-side processing, and generation, which may take some time depending on the video length. The UI will show the current status.
6.  **Download Results:** Once complete, a "Download .txt file" button will appear. Click it to save the text analysis.
7.  **Convert to Audio:** An option to convert the text to an audio file will also appear. Select a voice from the dropdown and click "Convert to Audio File" to generate and download a `.wav` file of the narration.

## Technologies Used

- **Backend:** PHP
- **Frontend:** HTML, JavaScript, [Bootstrap 5](https://getbootstrap.com/)
- **APIs:**
    - Google AI Gemini API (File API, `generateContent`)
    - Google AI Text-to-Speech API
