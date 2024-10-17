<?php
   ini_set('display_errors', 1);
   ini_set('display_startup_errors', 1);
   error_reporting(E_ALL);

   require_once '../../vendor/autoload.php';

   ini_set('session.upload_progress.enabled', 1);
   ini_set('session.upload_progress.cleanup', 1);
   ini_set('session.gc_maxlifetime', 3600);
   ini_set('session.cookie_lifetime', 3600);
   ini_set('upload_max_filesize', '50M');
   ini_set('post_max_size', '50M');
   ini_set('max_execution_time', '300');
   ini_set('memory_limit', '256M');

   session_start();

   function loadEnv($path) {
       if (!file_exists($path)) {
           throw new Exception(".env file not found");
       }

       $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
       foreach ($lines as $line) {
           if (strpos(trim($line), '#') === 0) {
               continue;
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

   $env_path = '/home/textgmpa/readingparadox.com/.env';

   loadEnv($env_path);

   $db_user = getenv('DB_USER');
   $db_name = getenv('DB_NAME');
   $db_pass = getenv('PASS');
   $db_host = getenv('DB_HOST');

   $db = new mysqli($db_host, $db_user, $db_pass, $db_name);
   if ($db->connect_error) {
       die("Connection failed: " . $db->connect_error);
   }

   function callOpenAI($prompt, $max_tokens = 500) {
       $api_key = getenv('OPENAI_API_KEY');
       $url = 'https://api.openai.com/v1/chat/completions';

       $data = array(
           'model' => 'gpt-4',
           'messages' => [
               ['role' => 'user', 'content' => $prompt]
           ],
           'max_tokens' => $max_tokens,
           'temperature' => 0.7,
           'top_p' => 1,
           'frequency_penalty' => 0,
           'presence_penalty' => 0
       );

       $ch = curl_init($url);
       curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
       curl_setopt($ch, CURLOPT_POST, true);
       curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
       curl_setopt($ch, CURLOPT_HTTPHEADER, array(
           'Content-Type: application/json',
           'Authorization: Bearer ' . $api_key
       ));

       $response = curl_exec($ch);

       if (curl_errno($ch)) {
           throw new Exception(curl_error($ch));
       }

       curl_close($ch);

       $result = json_decode($response, true);

       if (isset($result['choices'][0]['message']['content'])) {
           return $result['choices'][0]['message']['content'];
       } else {
           throw new Exception("Unexpected response from OpenAI API: " . $response);
       }
   }

   function processWithAI($text, $feature, $userQuestion = '', $description = '', $paragraph = '') {
       switch ($feature) {
           case 'dp1':
               $prompt = "Convert the input text into a simpler version so that it is understandable for a person without specialist knowledge. Technical terms are explained. Use clearer formulations and avoid complicated terms. The text should still remain precise and factual but be easier for laypeople to understand. Only return the converted output text without adding anything to it. If you replace heavy words, write them after the new light words in brackets.";
               break;
           case 'dp2':
               $prompt = "Convert the input text into a structured version, ensuring that it becomes more accessible to the reader. For each distinct section of content, insert a space. Additionally, provide a three-word summary at the start of every new content part to give the reader a quick understanding of the topic being discussed. Make all important words bold by enclosing them in asterisks (*). Return only the converted and structured output text without any additional commentary or instructions.";
               break;
           case 'dp3':
               $prompt = "Convert the input text into an essential version, making it more accessible to the reader. Write the text in bullet points. Long and complex sentences are broken down into shorter, simpler ones. Unnecessary words, like filler words, are removed. Return only the converted and essential output text without any additional commentary or instructions.";
               break;
           case 'dp4':
               $prompt = "Based on the following text, generate 5 relevant and thought-provoking questions or prompts for analysis. Each prompt should be concise and directly related to the content of the text. Number each question.";
               break;
           case 'answerQuestion':
               $prompt = "You are an expert assistant. Provide a detailed and informative answer to the user's question based on the following text and its description. Use the information from the text and description, and incorporate relevant general knowledge to support your answer. Do not mention if information is missing; instead, provide the best possible answer.

   Text: " . $text . "

   Description: " . $description . "

   Full context: " . $paragraph . "

   Question: " . $userQuestion;
               break;
           default:
               throw new Exception("Invalid feature specified.");
       }

       $full_prompt = $prompt . "\n\nSelected text: " . $text . "\n\nFull context: " . $paragraph;

       return callOpenAI($full_prompt);
   }

   if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'processText') {
       $text = $_POST['text'] ?? '';
       $feature = $_POST['feature'] ?? '';
       $paragraph = $_POST['paragraph'] ?? '';
       $userId = $_POST['userId'] ?? '';

       if (empty($text) || empty($feature)) {
           echo json_encode(['error' => 'Missing required parameters']);
           exit;
       }

       try {
           $processed_text = processWithAI($text, $feature, '', '', $paragraph);
           echo $processed_text;
       } catch (Exception $e) {
           echo json_encode(['error' => $e->getMessage()]);
       }

       exit;
   }

   function handlePDFUpload() {
       error_log("handlePDFUpload called");
       if (isset($_FILES['pdfFile'])) {
           $file = $_FILES['pdfFile'];
           error_log("File received: " . print_r($file, true));
           if ($file['error'] !== UPLOAD_ERR_OK) {
               error_log("Upload error: " . $file['error']);
               echo json_encode(['success' => false, 'message' => 'File upload error: ' . $file['error']]);
               exit;
           }
           if ($file['type'] === 'application/pdf') {
               $pdfContent = file_get_contents($file['tmp_name']);
               if ($pdfContent !== false) {
                   $_SESSION['pdf_content'] = base64_encode($pdfContent);
                   $_SESSION['pdf_filename'] = $file['name'];
                   error_log("PDF content saved to session");
                   echo json_encode(['success' => true, 'message' => 'File uploaded successfully.']);
               } else {
                   error_log("Error reading file");
                   echo json_encode(['success' => false, 'message' => 'Error reading file.']);
               }
           } else {
               error_log("Invalid file type: " . $file['type']);
               echo json_encode(['success' => false, 'message' => 'Please upload a PDF file.']);
           }
       } else {
           error_log("No file uploaded");
           echo json_encode(['success' => false, 'message' => 'No file uploaded.']);
       }
       exit;
   }
   function saveState() {
       if (isset($_SESSION['pdf_content'])) {
           $_SESSION['current_page'] = $_POST['page'] ?? 1;
           $_SESSION['current_zoom'] = $_POST['zoom'] ?? 1.5;
           echo json_encode(['success' => true, 'message' => 'State saved successfully']);
       } else {
           echo json_encode(['success' => false, 'message' => 'No PDF file in session']);
       }
       exit;
   }

   function getPDFInfo() {
       if (isset($_SESSION['pdf_content'])) {
           echo json_encode([
               'pdf_content' => $_SESSION['pdf_content'],
               'filename' => $_SESSION['pdf_filename'],
               'current_page' => $_SESSION['current_page'] ?? 1,
               'current_zoom' => $_SESSION['current_zoom'] ?? 1.5
           ]);
       } else {
           echo json_encode(['error' => 'No PDF file in session']);
       }
       exit;
   }

   if ($_SERVER['REQUEST_METHOD'] === 'POST') {
       if (isset($_POST['action'])) {
           switch ($_POST['action']) {
               case 'uploadPDF':
                   handlePDFUpload();
                   break;
               case 'saveState':
                   saveState();
                   break;
               case 'processText':

                   break;
           }
       }
   } elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
       if ($_GET['action'] === 'getPDF') {
           getPDFInfo();
       }
   }

   ?>
<html lang="en">
   <head>
      <meta charset="UTF-8">
      <title>Reading Assistant Dashboard</title>
      <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
      <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
      <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
      <script src="https://code.jquery.com/ui/1.13.0/jquery-ui.min.js"></script>
      <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
      <link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">
      <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/shepherd.js@8.3.1/dist/css/shepherd.css"/>
      <script src="https://cdn.jsdelivr.net/npm/shepherd.js@8.3.1/dist/js/shepherd.min.js"></script>
      <style> 
         * {
         margin: 0;
         padding: 0;
         box-sizing: border-box;
         font-family: "Poppins", sans-serif;
         }
         body {
         background-color: #E4E9F7;
         }
         .dashboard-app .dashboard {
         display: flex;
         }
         .dashboard-app .sidebar {
         position: fixed;
         left: 0;
         top: 0;
         height: 100%;
         width: 78px;
         background: #11101D;
         padding: 6px 14px;
         z-index: 99;
         transition: all 0.5s ease;
         }
         .dashboard-app .sidebar.open {
         width: 250px;
         }
         .dashboard-app .sidebar .logo-details {
         height: 60px;
         display: flex;
         align-items: center;
         position: relative;
         }
         .dashboard-app .sidebar .logo-details .icon {
         opacity: 0;
         transition: all 0.5s ease;
         }
         .dashboard-app .sidebar .logo-details .logo_name {
         color: #fff;
         font-size: 20px;
         font-weight: 600;
         opacity: 0;
         transition: all 0.5s ease;
         }
         .dashboard-app .sidebar.open .logo-details .icon, .dashboard-app .sidebar.open .logo-details .logo_name {
         opacity: 1;
         }
         .dashboard-app .sidebar .logo-details #btn {
         position: absolute;
         top: 50%;
         right: 0;
         transform: translateY(-50%);
         font-size: 23px;
         text-align: center;
         cursor: pointer;
         transition: all 0.5s ease;
         }
         .dashboard-app .sidebar.open .logo-details #btn {
         text-align: right;
         }
         .dashboard-app .sidebar i {
         color: #fff;
         height: 60px;
         min-width: 50px;
         font-size: 28px;
         text-align: center;
         line-height: 60px;
         }
         .dashboard-app .sidebar .nav-list {
         margin-top: 20px;
         height: 100%;
         }
         .dashboard-app .sidebar li {
         position: relative;
         margin: 8px 0;
         list-style: none;
         }
         .dashboard-app .sidebar li .tooltip {
         position: absolute;
         top: -20px;
         left: calc(100% + 15px);
         z-index: 3;
         background: #fff;
         box-shadow: 0 5px 10px rgba(0, 0, 0, 0.3);
         padding: 6px 12px;
         border-radius: 4px;
         font-size: 15px;
         font-weight: 400;
         opacity: 0;
         white-space: nowrap;
         pointer-events: none;
         transition: 0s;
         }
         .dashboard-app .sidebar li:hover .tooltip {
         opacity: 1;
         pointer-events: auto;
         transition: all 0.4s ease;
         top: 50%;
         transform: translateY(-50%);
         }
         .dashboard-app .sidebar.open li .tooltip {
         display: none;
         }
         .dashboard-app .sidebar input {
         font-size: 15px;
         color: #FFF;
         font-weight: 400;
         outline: none;
         height: 50px;
         width: 100%;
         width: 50px;
         border: none;
         border-radius: 12px;
         transition: all 0.5s ease;
         background: #1d1b31;
         }
         .dashboard-app .sidebar.open input {
         padding: 0 20px 0 50px;
         width: 100%;
         }
         .dashboard-app .sidebar .bx-search {
         position: absolute;
         top: 50%;
         left: 0;
         transform: translateY(-50%);
         font-size: 22px;
         background: #1d1b31;
         color: #FFF;
         }
         .dashboard-app .sidebar.open .bx-search:hover {
         background: #1d1b31;
         color: #FFF;
         }
         .dashboard-app .sidebar .bx-search:hover {
         background: #FFF;
         color: #11101d;
         }
         .dashboard-app .sidebar li a {
         display: flex;
         width: 100%;
         border-radius: 12px;
         align-items: center;
         text-decoration: none;
         transition: all 0.4s ease;
         background: #11101D;
         }
         .dashboard-app .sidebar li a:hover {
         background: #FFF;
         }
         .dashboard-app .sidebar li a .links_name {
         color: #fff;
         font-size: 15px;
         font-weight: 400;
         white-space: nowrap;
         opacity: 0;
         pointer-events: none;
         transition: 0.4s;
         }
         .dashboard-app .sidebar.open li a .links_name {
         opacity: 1;
         pointer-events: auto;
         }
         .dashboard-app .sidebar li a:hover .links_name, .dashboard-app .sidebar li a:hover i {
         transition: all 0.5s ease;
         color: #11101D;
         }
         .dashboard-app .sidebar li i {
         height: 50px;
         line-height: 50px;
         font-size: 18px;
         border-radius: 12px;
         }
         .dashboard-app .home-section {
         position: relative;
         background: #E4E9F7;
         min-height: 100vh;
         top: 0;
         left: 78px;
         width: calc(100% - 78px);
         transition: all 0.5s ease;
         z-index: 2;
         padding: 20px;
         }
         .dashboard-app .sidebar.open ~ .home-section {
         left: 250px;
         width: calc(100% - 250px);
         }
         .dashboard-app .home-section .text {
         display: inline-block;
         color: #11101d;
         font-size: 25px;
         font-weight: 500;
         margin: 18px;
         }
         .dashboard-app .file-upload-container {
         max-width: 800px;
         margin: 2rem auto;
         padding: 2rem;
         background-color: #11101D;
         color: #fff;
         border-radius: 12px;
         }
         .dashboard-app .upload-title {
         font-size: 2rem;
         margin-bottom: 0.5rem;
         text-align: center;
         }
         .dashboard-app .upload-subtitle {
         font-size: 1rem;
         margin-bottom: 2rem;
         text-align: center;
         color: #ccc;
         }
         .dashboard-app .upload-area {
         border: 2px dashed #1d1b31;
         border-radius: 12px;
         padding: 2rem;
         text-align: center;
         cursor: pointer;
         transition: all 0.3s ease;
         }
         .dashboard-app .upload-area:hover {
         background-color: #1d1b31;
         }
         .dashboard-app .upload-icon {
         font-size: 3rem;
         margin-bottom: 1rem;
         }
         .dashboard-app .upload-limit {
         font-size: 0.8rem;
         color: #ccc;
         margin-top: 0.5rem;
         }
         .dashboard-app .file-input {
         display: none;
         }
         .dashboard-app .selected-file {
         display: flex;
         align-items: center;
         margin-top: 1rem;
         padding: 0.5rem;
         background-color: #1d1b31;
         border-radius: 8px;
         }
         .dashboard-app .file-icon {
         font-size: 1.5rem;
         margin-right: 0.5rem;
         }
         .dashboard-app .convert-btn {
         margin-left: auto;
         background-color: #7a7a7e;
         color: white;
         border: none;
         padding: 0.5rem 1rem;
         border-radius: 4px;
         cursor: pointer;
         transition: background-color 0.3s ease;
         }
         .dashboard-app .convert-btn:hover {
         background-color: #45a049;
         }
         .dashboard-app .convert-btn:disabled {
         background-color: #cccccc;
         cursor: not-allowed;
         }
         .dashboard-app .error-message {
         color: #ff6b6b;
         margin-top: 1rem;
         }
         .dashboard-app .storage-info {
         font-size: 0.8rem;
         color: #ccc;
         margin-top: 2rem;
         text-align: center;
         }
         .dashboard-app .content-container {
         margin-top: 2rem;
         padding: 1rem;
         border-radius: 8px;
         position: relative;
         }
         .dashboard-app .content-controls {
         display: flex;
         justify-content: flex-end;
         margin-bottom: 1rem;
         }
         .dashboard-app .zoom-btn, .expand-btn {
         background-color: #202654;
         color: white;
         border: none;
         padding: 0.5rem;
         margin-left: 0.5rem;
         border-radius: 4px;
         cursor: pointer;
         transition: background-color 0.3s ease;
         }
         .dashboard-app .zoom-btn:hover, .expand-btn:hover {
         background-color: #202654;
         }
         .dashboard-app #content {
         color: #333;
         line-height: 1.6;
         transition: all 0.3s ease;
         }
         .dashboard-app .reading-tools {
         position: fixed;
         bottom: 20px;
         right: 20px;
         z-index: 1000;
         }
         .dashboard-app .reading-tools-toggle {
         width: 60px;
         height: 60px;
         border-radius: 50%;
         background-color: #202654;
         color: white;
         display: flex;
         justify-content: center;
         align-items: center;
         cursor: pointer;
         box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
         }
         .dashboard-app .reading-tools-menu {
         position: absolute;
         bottom: 70px;
         right: 0;
         background-color: white;
         border-radius: 8px;
         box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
         display: none;
         padding: 10px;
         }
         .dashboard-app .reading-tools-menu.show {
         display: block;
         }
         .dashboard-app .tool-icon {
         width: 40px;
         height: 40px;
         border-radius: 50%;
         background-color: #f0f0f0;
         display: flex;
         justify-content: center;
         align-items: center;
         margin: 5px;
         cursor: pointer;
         transition: background-color 0.3s ease;
         }
         .dashboard-app .tool-icon:hover {
         background-color: #e0e0e0;
         }
         .dashboard-app .tool-submenu {
         display: none;
         margin-top: 5px;
         }
         .dashboard-app .tool-submenu.show {
         display: flex;
         }
         .dashboard-app .undo-icon {
         font-size: 20px;
         margin-right: 5px;
         }
         .job {
         display: none;
         }
         display: block;
         #pdf-content {
         all: unset;
         }
         .formatting-animation {
         position: fixed;
         right: 20px;
         top: 50%;
         transform: translateY(-50%);
         background-color: rgba(0, 0, 0, 0.8);
         color: white;
         padding: 10px;
         border-radius: 5px;
         max-width: 300px;
         max-height: 400px;
         overflow-y: auto;
         }
         .formatting-animation pre {
         white-space: pre-wrap;
         word-wrap: break-word;
         }
         .highlight {
         background-color: yellow;
         transition: background-color 0.5s ease;
         }
         #pdfViewer {
         margin-top: 20px;
         width: 100%;
         display: flex;
         flex-direction: column;
         align-items: center;
         }
         .page {
         position: relative;
         margin-bottom: 20px !important;
         max-width: 100%;
         overflow: auto;
         }
         .textLayer {
         height: unset !important;
         }
         .textLayer > span {
         color: #000000;
         position: absolute;
         white-space: pre;
         cursor: text;
         transform-origin: 0% 0%;
         background: white;
         z-index: 2;
         font-family: "CMU Concrete", san-serif !important;
         }
         br {
         display: none;
         }
         .textLayer .highlight {
         background-color: yellow;
         }
         .textLayer .bg {
         position: absolute;
         background: white;
         }
         .intermediateLayer {
         position: absolute;
         left: 0;
         top: 0;
         right: 0;
         bottom: 0;
         pointer-events: none;
         }
         .intermediateLayer .bgRect {
         position: absolute;
         background: white;
         }
         .textContainer {
         position: absolute;
         background-color: white;
         pointer-events: none;
         z-index: 1;
         }
         .content-container.fullscreen {
         position: fixed;
         top: 0;
         left: 0;
         width: 100%;
         height: 100%;
         background-color: white;
         z-index: 1000;
         overflow: auto;
         }
         .content-controls {
         display: flex;
         justify-content: flex-end;
         margin-bottom: 1rem;
         }
         .zoom-btn, .expand-btn {
         background-color: #202654;
         color: white;
         border: none;
         padding: 0.5rem;
         margin-left: 0.5rem;
         border-radius: 4px;
         cursor: pointer;
         transition: background-color 0.3s ease;
         }
         .zoom-btn:hover, .expand-btn:hover {
         background-color: #45a049;
         }
         .reading-tools {
         position: fixed;
         right: 20px;
         transform: translateY(-50%);
         z-index: 1000;
         }
         .reading-tools-toggle {
         width: 50px;
         height: 50px;
         background-color: #202654;
         color: white;
         border-radius: 50%;
         display: flex;
         justify-content: center;
         align-items: center;
         cursor: pointer;
         font-size: 24px;
         }
         .reading-tools-menu {
         display: none;
         background-color: white;
         border-radius: 8px;
         box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
         padding: 10px;
         margin-top: 10px;
         }
         .reading-tools-menu.show {
         display: block;
         }
         .tool-icon {
         width: 40px;
         height: 40px;
         display: flex;
         justify-content: center;
         align-items: center;
         cursor: pointer;
         border-radius: 50%;
         margin: 5px 0;
         transition: background-color 0.3s;
         }
         .tool-icon:hover {
         background-color: #f0f0f0;
         }
         .processing-panel {
         position: fixed;
         right: -400px;
         top: 0;
         width: 400px;
         height: 100%;
         background-color: #11101d; 
         box-shadow: -2px 0 10px rgba(0, 0, 0, 0.1);
         transition: right 0.3s ease;
         z-index: 1001;
         display: none;
         color:white;
         }
         .processing-panel.open {
         right: 0;
         }
         .processing-panel-header {
         padding: 20px;
         background-color: #11101D;
         color: white;
         display: flex;
         justify-content: space-between;
         align-items: center;
         }
         .processing-panel-content {
         padding: 20px;
         max-height: calc(100% - 140px);
         overflow-y: auto;
         }
         .processing-panel-actions {
         padding: 20px;
         display: flex;
         justify-content: flex-end;
         gap: 10px;
         border-top: 1px solid #e0e0e0;
         }
         .action-button {
         padding: 10px 20px;
         border: none;
         border-radius: 4px;
         cursor: pointer;
         transition: background-color 0.3s;
         }
         .accept-button {
         background-color: #202654;
         color: white;
         }
         .reject-button {
         background-color: #f44336;
         color: white;
         }
         .close-button {
         background: none;
         border: none;
         color: white;
         font-size: 24px;
         cursor: pointer;
         }
         .processed-text {
         background-color: rgba(255, 255, 0, 0.3);
         transition: background-color 0.3s ease;
         }
         #ai-loading-overlay {
         position: fixed;
         top: 0;
         left: 0;
         width: 100%;
         height: 100%;
         background-color: rgba(255, 255, 255, 0.8);
         display: flex;
         justify-content: center;
         align-items: center;
         z-index: 9999;
         }
         .ai-loading-content {
         text-align: center;
         }
         .loading-spinner {
         border: 4px solid #f3f3f3;
         border-top: 4px solid #3498db;
         border-radius: 50%;
         width: 40px;
         height: 40px;
         animation: spin 1s linear infinite;
         margin: 0 auto 20px;
         }
         @keyframes spin {
         0% {
         transform: rotate(0deg);
         }
         100% {
         transform: rotate(360deg);
         }
         }
         #ai-loading-message {
         font-size: 18px;
         color: #333;
         }
         .textLayer {
         position: absolute;
         left: 0;
         top: 0;
         right: 0;
         bottom: 0;
         overflow: hidden;
         line-height: 1.0;
         user-select: text;
         -webkit-user-select: text;
         -moz-user-select: text;
         -ms-user-select: text;
         }
         .reading-tools {
         position: fixed;
         bottom: 20px;
         right: 20px;
         z-index: 1000;
         }
         .reading-tools-toggle {
         width: 60px;
         height: 60px;
         border-radius: 50%;
         background-color: var(--primary-color);
         color: var(--text-color);
         display: flex;
         justify-content: center;
         align-items: center;
         cursor: pointer;
         box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
         transition: all var(--transition-speed) ease;
         }
         .tool-icon {
         width: 40px;
         height: 40px;
         border-radius: 50%;
         background-color: var(--secondary-color);
         color: var(--text-color);
         display: flex;
         justify-content: center;
         align-items: center;
         margin: 5px;
         cursor: pointer;
         transition: all var(--transition-speed) ease;
         }
         .tool-icon:hover {
         background-color: var(--text-color);
         color: var(--primary-color);
         }
         .floating-toolbar {
         position: absolute;
         background-color: var(--primary-color);
         border-radius: 20px;
         box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
         display: flex;
         padding: 5px;
         z-index: 1000;
         transition: all var(--transition-speed) ease;
         }
         .processed-content-container {
         position: absolute;
         background-color: var(--text-color);
         color: var(--primary-color);
         border-radius: 8px;
         box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
         padding: 15px;
         max-width: 300px;
         z-index: 1001;
         cursor: move;
         }
         .processed-content-container .header {
         display: flex;
         justify-content: space-between;
         align-items: center;
         margin-bottom: 10px;
         }
         .processed-content-container .close-button {
         background: none;
         border: none;
         color: var(--primary-color);
         cursor: pointer;
         font-size: 1.2em;
         }
         .processed-text {
         background-color: var(--highlight-color);
         transition: background-color var(--transition-speed) ease;
         }
         .floating-toolbar {
         background-color: #f0f0f0;
         border: 1px solid #ccc;
         border-radius: 4px;
         padding: 5px;
         display: none;
         z-index: 1000;
         }
         .floating-toolbar button {
         margin: 0 2px;
         padding: 5px 10px;
         background-color: #202654;
         color: white;
         border: none;
         border-radius: 3px;
         cursor: pointer;
         }
         .floating-toolbar button:hover {
         background: #45a049;
         }
         button#zoomOutBtn:hover {
         background:#45a049;
         }
         button#zoomInBtn:hover {
         background: #45a049;
         }
         .floating-toolbar .close-btn {
         background-color: transparent;
         color: #202654;
         font-size: 16px;
         font-weight: bold;
         padding: 0 5px;
         }
         .processed-content {
         position: fixed;
         top: 50%;
         left: 50%;
         transform: translate(-50%, -50%);
         background-color: white;
         border: 1px solid #ccc;
         border-radius: 4px;
         padding: 20px;
         max-width: 30%;
         max-height: 80%;
         overflow-y: auto;
         z-index: 1001;
         text-align: left;
         }
         .action-buttons {
         text-align: right;
         margin-top: 10px;
         }
         .action-buttons button {
         margin-left: 5px;
         padding: 5px 10px;
         background-color:#202654;
         color: white;
         border: none;
         border-radius: 3px;
         cursor: pointer;
         }
         .processed-content-dialog {
         background-color: white;
         border: 1px solid #ccc;
         border-radius: 4px;
         box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
         max-width: 400px;
         z-index: 1000;
         }
         .processed-content-dialog .dialog-header {
         display: flex;
         justify-content: space-between;
         align-items: center;
         padding: 10px;
         border-bottom: 1px solid #ccc;
         }
         .processed-content-dialog .dialog-content {
         padding: 15px;
         max-height: 300px;
         overflow-y: auto;
         }
         .processed-content-dialog .dialog-actions {
         display: flex;
         justify-content: flex-end;
         padding: 10px;
         border-top: 1px solid #ccc;
         }
         .processed-content-dialog button {
         margin-left: 10px;
         padding: 5px 10px;
         background-color: #202654;
         color: white;
         border: none;
         border-radius: 4px;
         cursor: pointer;
         }
         .processed-content-dialog button:hover {
         background-color: #2d367a;
         }
         @import url('https://fonts.googleapis.com/css2?family=CMU+Concrete&display=swap');
         .noteHolder {
         font-family: 'CMU Concrete', serif;
         width: 250px;
         min-height: 250px;
         background: #fefabc; 
         padding: 15px;
         box-shadow: 0 4px 6px rgba(0,0,0,0.1);
         margin: 20px;
         position: relative;
         overflow: hidden;
         border-radius: 0 0 0 30px/45px;
         transform: rotate(-2deg);
         display: flex;
         flex-direction: column;
         }
         .noteHolder::before {
         content: '';
         position: absolute;
         top: 0;
         right: 0;
         border-width: 0 25px 25px 0;
         border-style: solid;
         border-color: #fefabc #fff;
         box-shadow: -2px 2px 2px rgba(0,0,0,0.1);
         }
         .note-header {
         display: flex;
         justify-content: space-between;
         align-items: center;
         margin-bottom: 10px;
         }
         .noteHolder h4 {
         margin: 0;
         padding: 0 0 5px 0;
         font-size: 20px;
         font-weight: bold;
         color: #41403E;
         }
         .note-actions {
         display: flex;
         gap: 5px;
         }
         div#floatingToolbar {
         display: none !important;
         }
         .noteHolder .btn {
         background: transparent;
         border: none;
         color: #41403E;
         cursor: pointer;
         font-size: 16px;
         padding: 2px 5px;
         border-radius: 3px;
         transition: background-color 0.3s ease;
         }
         .noteHolder .btn:hover {
         background-color: rgba(0,0,0,0.1);
         }
         .note-content, .note-edit {
         flex-grow: 1;
         overflow-y: auto;
         max-height: 300px;
         }
         .note-content {
         font-size: 14px;
         line-height: 1.4;
         color: #333;
         padding-right: 10px;
         }
         .note-edit {
         display: none;
         }
         .note-edit textarea {
         width: 100%;
         height: 100%;
         border: 1px solid #e6e098;
         padding: 5px;
         font-family: inherit;
         font-size: 14px;
         line-height: 1.4;
         color: #333;
         background: transparent;
         resize: none;
         }
         .note-edit textarea:focus {
         outline: none;
         border-color: #d8d28b;
         }
         .noteHolder h5 {
         font-size: 16px;
         margin: 10px 0 5px;
         color: #2c3e50;
         border-bottom: 1px solid #e6e098;
         padding-bottom: 3px;
         }
         .noteHolder ul {
         padding-left: 20px;
         margin: 5px 0;
         }
         .noteHolder li {
         margin-bottom: 5px;
         }
         .note-footer {
         display: flex;
         justify-content: flex-end;
         margin-top: 10px;
         }
         .note-footer .btn {
         margin-left: 5px;
         padding: 5px 10px;
         background-color: #e6e098;
         color: #41403E;
         border: none;
         border-radius: 3px;
         cursor: pointer;
         transition: background-color 0.3s ease;
         }
         .note-footer .btn:hover {
         background-color: #d8d28b;
         }
         .noteHolder .colors {
         display: none;
         position: absolute;
         bottom: 40px;
         right: 10px;
         background: #fff;
         border-radius: 5px;
         box-shadow: 0 2px 5px rgba(0,0,0,0.2);
         padding: 5px;
         }
         .noteHolder .colors.act {
         display: flex;
         }
         .noteHolder .colors div {
         width: 20px;
         height: 20px;
         margin: 0 3px;
         border-radius: 50%;
         cursor: pointer;
         transition: transform 0.2s ease;
         }
         .noteHolder .colors div:hover {
         transform: scale(1.2);
         }
         .noteHolder .note-yellow { background: #fefabc; }
         .noteHolder .note-green { background: #b5e8d5; }
         .noteHolder .note-levendor { background: #d9ccff; }
         .noteHolder .note-orange { background: #ffd3b6; }
         #stickyNotesContainer {
         position: fixed;
         right: 20px;
         top: 20px;
         z-index: 1000;
         display: flex;
         flex-direction: column;
         align-items: flex-end;
         gap: 20px;
         pointer-events:all !important;
         }
         dashboard-app .dashboard {
         pointer-events: all !important;
         }
         .page-container {
         transition: transform 0.5s ease-in-out;
         }
         button.swal2-confirm.swal2-styled.swal2-default-outline {
         background: #131733 !important;
         }
         #chat-interface {
         position: fixed;
         top: 0;
         right: 0;
         width: 50%;
         height: 100%;
         background-color: white;
         box-shadow: -2px 0 5px rgba(0,0,0,0.1);
         display: flex;
         flex-direction: column;
         transform: translateX(100%);
         transition: transform 0.5s ease-in-out;
         z-index: 1000;
         }
         .chat-header {
         padding: 10px;
         background-color: #f0f0f0;
         display: flex;
         justify-content: space-between;
         align-items: center;
         }
         .chat-messages {
         flex-grow: 1;
         overflow-y: auto;
         padding: 10px;
         }
         .chat-input {
         display: flex;
         padding: 10px;
         }
         .chat-input input {
         flex-grow: 1;
         margin-right: 10px;
         }
         .message {
         margin-bottom: 10px;
         padding: 5px 10px;
         border-radius: 5px;
         }
         .message.user {
         background-color: #e1f5fe;
         align-self: flex-end;
         }
         .message.ai {
         background-color: #f0f0f0;
         align-self: flex-start;
         }
         .prompt-button {
         display: block;
         width: 100%;
         padding: 10px;
         margin-bottom: 5px;
         background-color: #f0f0f0;
         border: none;
         border-radius: 5px;
         cursor: pointer;
         text-align: left;
         }
         .prompt-button:hover {
         background-color: #e0e0e0;
         }
         div#stickyNotesContainer {
         height: unset !important;
         }
         .reading-tools-menu .tool-icon {
         position: relative;
         cursor: pointer;
         }
         .reading-tools-menu .tool-icon::after {
         content: attr(title);
         position: absolute;
         bottom: 100%;
         left: 50%;
         transform: translateX(-50%);
         background-color: rgba(0, 0, 0, 0.8);
         color: white;
         padding: 5px 10px;
         border-radius: 4px;
         font-size: 12px;
         white-space: nowrap;
         opacity: 0;
         visibility: hidden;
         transition: opacity 0.3s, visibility 0.3s;
         z-index: 1000;
         }
         .reading-tools-menu .tool-icon:hover::after {
         opacity: 1;
         visibility: visible;
         }
         .reading-tools-menu {
         position: relative;
         z-index: 100;
         }
      </style>
   </head>
   <body class="dashboard-app">
      <?php
         if ($_SERVER['REQUEST_METHOD'] === 'POST') {
             if (isset($_FILES['pdfFile'])) {
                 $file = $_FILES['pdfFile'];

                 if ($file['type'] === 'application/pdf') {

                     $uploadDir = 'uploads/';
                     $uploadFile = $uploadDir . basename($file['name']);
                     if (move_uploaded_file($file['tmp_name'], $uploadFile)) {

                         echo '<script>alert("File uploaded successfully.");</script>';
                     } else {
                         echo '<script>alert("Error uploading file.");</script>';
                     }
                 } else {
                     echo '<script>alert("Please upload a PDF file.");</script>';
                 }
             }
         }
         ?>
      <div class="dashboard">
      <div class="sidebar">
         <div class="logo-details">
            <i class='bx bxl-c-plus-plus icon'></i>
            <div class="logo_name">Reading Paradox</div>
            <i class='bx bx-menu' id="btn"></i>
         </div>
         <ul class="nav-list">
            <li>
               <i class='bx bx-search'></i>
               <input type="text" placeholder="Search...">
               <span class="tooltip">Search</span>
            </li>
            <li>
               <a href="#">
               <i class='bx bx-user'></i>
               <span class="links_name">User</span>
               </a>
               <span class="tooltip">User</span>
            </li>
            <li>
               <a href="#">
               <i class='bx bx-folder'></i>
               <span class="links_name">Library</span>
               </a>
               <span class="tooltip">Library</span>
            </li>
            <li>
               <a href="#">
               <i class='bx bx-heart'></i>
               <span class="links_name">Saved</span>
               </a>
               <span class="tooltip">Saved</span>
            </li>
            <li>
               <a href="#">
               <i class='bx bx-cog'></i>
               <span class="links_name">Setting</span>
               </a>
               <span class="tooltip">Setting</span>
            </li>
            <li id="uploadNewPdfBtn">
               <a href="#">
               <i class='bx bx-upload'></i>
               <span class="links_name">Upload New PDF</span>
               </a>
               <span class="tooltip">Upload New PDF</span>
            </li>
            <li class="profile">
               <div class="profile-details">
                  <div class="name_job">
                     <div class="name">Reading Paradox</div>
                     <div class="job">2024@ReadingParadox</div>
                  </div>
               </div>
               <i class='bx bx-log-out' id="log_out"></i>
            </li>
         </ul>
      </div>
      <section class="home-section">
         <div class="file-upload-container" id="uploadContainer">
            <h2 class="upload-title">Upload and start reading easily</h2>
            <p class="upload-subtitle">PDF upload for your reading convenience</p>
            <div class="upload-area" id="uploadArea">
               <i class='bx bx-upload upload-icon'></i>
               <p>Click here or drop a file to upload</p>
               <p class="upload-limit">(Maximum file size: 1 MB)</p>
               <input type="file" accept=".pdf" id="pdfFile" name="pdfFile" class="file-input">
            </div>
            <div id="selectedFile" class="selected-file" style="display: none;">
               <i class='bx bxs-file-pdf file-icon'></i>
               <span id="fileName"></span>
               <button id="convertBtn" class="convert-btn">Start Reading</button>
            </div>
            <p id="errorMessage" class="error-message" style="display: none;"></p>
            <p class="storage-info">
               Your PDF is temporarily saved in your browser session for reading. We do not store your files on our servers. The file will be automatically removed when you close your browser or start a new session.
            </p>
         </div>
         <div class="content-container" id="contentContainer" style="display: none;">
            <div class="content-controls">
               <button class="zoom-btn" id="zoomOutBtn" style="display:none">-</button>
               <button class="zoom-btn" id="zoomInBtn" style="display:none">+</button>
               <button class="expand-btn" id="expandBtn"><i class='bx bx-expand-alt'></i></button>
            </div>
            <div id="pdfViewer"></div>
         </div>
      </section>

      <div id="readingTools" class="reading-tools">
         <div id="readingToolsToggle" class="reading-tools-toggle">
            <i class='bx bx-book-reader'></i>
         </div>
         <div id="readingToolsMenu" class="reading-tools-menu">
            <div class="tool-icon" data-tool="dp1" title="Simplify: Convert text to simpler version">
               <i class='bx bx-bulb'></i>
            </div>
            <div class="tool-icon" data-tool="dp2" title="Structure: Convert text to structured version">
               <i class='bx bx-highlight'></i>
            </div>
            <div class="tool-icon" data-tool="dp3" title="Essentials: Extract key points">
               <i class='bx bx-list-ul'></i>
            </div>
            <div class="tool-icon" data-tool="dp4" title="Analyze: Generate analysis questions">
               <i class='bx bx-chat'></i>
            </div>
            <div class="tool-icon" data-tool="undo" title="Undo last action">
               <i class='bx bx-undo'></i>
            </div>
         </div>
      </div>
      <div id="floatingToolbar" class="floating-toolbar hidden">
         <div class="tool-icon" data-tool="dp1" title="Simplify: Convert text to simpler version">
            <i class='bx bx-book-open'></i>
         </div>
         <div class="tool-icon" data-tool="dp2" title="Structure: Convert text to structured version">
            <i class='bx bx-list-ul'></i>
         </div>
         <div class="tool-icon" data-tool="dp3" title="Essentials: Extract key points">
            <i class='bx bx-bookmark'></i>
         </div>
         <div class="tool-icon" data-tool="dp4" title="Analyze: Generate analysis questions">
            <i class='bx bx-analyse'></i>
         </div>
      </div>
      <div id="processingPanel" class="processing-panel">
         <div class="processing-panel-header">
            <h3 id="processingPanelTitle">Processing Text</h3>
            <button id="closeProcessingPanel" class="close-button"><i class='bx bx-x'></i></button>
         </div>
         <div id="processingPanelContent" class="processing-panel-content"></div>
         <div class="processing-panel-actions">
            <button id="acceptButton" class="action-button accept-button">Accept</button>
            <button id="rejectButton" class="action-button reject-button">Reject</button>
         </div>
      </div>
      <div id="ai-loading-overlay" style="display: none;">
         <div class="ai-loading-content">
            <svg viewBox="0 0 100 100">
               <g fill="none" stroke="#202654" stroke-linecap="round" stroke-linejoin="round" stroke-width="6">
                  <path d="M 21 40 V 59">
                     <animateTransform
                        attributeName="transform"
                        attributeType="XML"
                        type="rotate"
                        values="0 21 59; 180 21 59"
                        dur="2s"
                        repeatCount="indefinite" />
                  </path>
                  <path d="M 79 40 V 59">
                     <animateTransform
                        attributeName="transform"
                        attributeType="XML"
                        type="rotate"
                        values="0 79 59; -180 79 59"
                        dur="2s"
                        repeatCount="indefinite" />
                  </path>
                  <path d="M 50 21 V 40">
                     <animate
                        attributeName="d"
                        values="M 50 21 V 40; M 50 59 V 40"
                        dur="2s"
                        repeatCount="indefinite" />
                  </path>
                  <path d="M 50 60 V 79">
                     <animate
                        attributeName="d"
                        values="M 50 60 V 79; M 50 98 V 79"
                        dur="2s"
                        repeatCount="indefinite" />
                  </path>
                  <path d="M 50 21 L 79 40 L 50 60 L 21 40 Z">
                     <animate
                        attributeName="stroke"
                        values="rgba(32,38,84,1); rgba(100,100,100,0)"
                        dur="2s"
                        repeatCount="indefinite" />
                  </path>
                  <path d="M 50 40 L 79 59 L 50 79 L 21 59 Z"/>
                  <path d="M 50 59 L 79 78 L 50 98 L 21 78 Z">
                     <animate
                        attributeName="stroke"
                        values="rgba(100,100,100,0); rgba(32,38,84,1)"
                        dur="2s"
                        repeatCount="indefinite" />
                  </path>
                  <animateTransform
                     attributeName="transform"
                     attributeType="XML"
                     type="translate"
                     values="0 0; 0 -19"
                     dur="2s"
                     repeatCount="indefinite" />
               </g>
            </svg>
            <p id="ai-loading-message"></p>
         </div>
      </div>
      <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.9.359/pdf.min.js"></script>
      <script>
         pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.9.359/pdf.worker.min.js';

         let dpCounts = {
             dp1: 0,
             dp2: 0,
             dp3: 0,
             dp4: 0
         };

         let userId = 'unknown';
         let currentProcessedText = '';
         let currentSelectedRange = null;
         let undoStack = [];
         let pdfDoc = null;
         let currentScale = 1.5;

         let floatingDialog = null;
         let isDialogExpanded = false;
         let isDragging = false;
         let colorOptions = null;

         function getCurrentTask() {
             return '1';
         }

         function handlePageChange() {
             const currentPageNumber = getCurrentPage();
             console.log('Handling page change. Current page:', currentPageNumber);

             const pages = document.querySelectorAll('.page');
             pages.forEach((page, index) => {
                 const chatOverlay = page.querySelector('#chat-overlay');
                 if (chatOverlay) {
                     if (index + 1 === currentPageNumber) {
                         chatOverlay.style.display = 'flex';
                     } else {
                         chatOverlay.style.display = 'none';
                     }
                 }
             });

         }

         function getCurrentPage() {
             const pages = document.querySelectorAll('.page');
             const viewportHeight = window.innerHeight;
             const scrollPosition = window.scrollY;

             for (let i = 0; i < pages.length; i++) {
                 const page = pages[i];
                 const rect = page.getBoundingClientRect();

                 if (rect.top <= viewportHeight / 2 && rect.bottom >= viewportHeight / 2) {
                     return i + 1;
                 }
             }

             return 1; 
         }

         let scrollTimeout;

         function getEntireParagraph(range) {
             let node = range.startContainer;
             while (node && node.nodeName !== 'P') {
                 node = node.parentNode;
             }
             return node ? node.textContent : range.toString();
         }

         function logInteraction(action, details) {
             console.log('Interaction logged:', action, details);
         }

         function getSelectedText() {
         console.log('getSelectedText called');
         let text = '';
         if (window.getSelection) {
             text = window.getSelection().toString();
         } else if (document.selection && document.selection.type != "Control") {
             text = document.selection.createRange().text;
         }
         console.log('Selected text:', text);
         return text.trim();
         }

         function getSelectedText() {
             let text = '';
             if (window.getSelection) {
                 text = window.getSelection().toString();
             } else if (document.selection && document.selection.type != "Control") {
                 text = document.selection.createRange().text;
             }
             return text.trim();
         }

         window.processText = function(feature, event, source = 'readingToolsMenu') {
             console.log('processText called with feature:', feature, 'source:', source);
             if (event) {
                 event.preventDefault();
                 event.stopPropagation();
             }

             const loadingOverlay = document.getElementById('ai-loading-overlay');
             const loadingMessage = document.getElementById('ai-loading-message');

             if (!loadingOverlay || !loadingMessage) {
                 console.error('Loading elements not found');
                 alert('An error occurred. Please refresh the page and try again.');
                 return;
             }

             const messages = [
                 "Hold on, I'm thinking...",
                 "Just a sec, processing your request...",
                 "Hmm, let me ponder this for a moment...",
                 "Crunching the data, won't be long!",
                 "Consulting my virtual brain cells...",
                 "Simplifying... It's harder than it looks!",
                 "Transforming complex into simple... Loading..."
             ];
             loadingMessage.textContent = messages[Math.floor(Math.random() * messages.length)];

             loadingOverlay.style.display = 'flex';

             dpCounts[feature]++;

             const selectedText = lastSelectedText;
             currentSelectedRange = lastSelectedRange;

             console.log('Selected text:', selectedText);
             console.log('Selected text length:', selectedText.length);

             if (!selectedText) {
                 console.error('No text selected');
                 loadingOverlay.style.display = 'none';
                 alert('Please select some text before processing.');
                 return;
             }

             if (!currentSelectedRange) {
                 console.error('No range selected');
                 loadingOverlay.style.display = 'none';
                 alert('An error occurred while processing the selection. Please try again.');
                 return;
             }

             console.log('Current selected range:', currentSelectedRange);
             const paragraph = getEntireParagraph(currentSelectedRange);
             const currentTask = getCurrentTask();
             const currentPage = getCurrentPage();

             console.log('Current task:', currentTask);
             console.log('Current page:', currentPage);

             if (source === 'readingToolsMenu') {

                 fetch('', {
                     method: 'POST',
                     headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                     body: 'action=processText' +
                           '&text=' + encodeURIComponent(selectedText) +
                           '&feature=' + encodeURIComponent(feature) +
                           '&paragraph=' + encodeURIComponent(paragraph) +
                           '&task=' + encodeURIComponent(currentTask) +
                           '&page=' + encodeURIComponent(currentPage) +
                           '&userId=' + encodeURIComponent(userId)
                 })
                 .then(response => response.text())
                 .then(rawResponse => {
                     console.log('Received raw response:', rawResponse);
                     const processedText = extractProcessedText(rawResponse);
                     console.log('Extracted processed text:', processedText);
                     loadingOverlay.style.display = 'none';
                     if (feature === 'dp4') {
                         showAnalysisInterface(processedText);
                     } else {
                         const formattedText = formatProcessedText(processedText, feature);
                         try {
                             showProcessingPanel(feature, formattedText);
                         } catch (error) {
                             console.error('Error in showProcessingPanel:', error);
                             alert('An error occurred while displaying the processed content. Please try again.');
                         }
                     }
                     logInteraction('process_text', {
                         feature: feature,
                         selectedText: selectedText,
                         processedText: processedText,
                         task: getCurrentTask(),
                         page: getCurrentPage(),
                         aiResponse: processedText
                     });
                 })
                 .catch(error => {
                     console.error('Error in fetch:', error);
                     loadingOverlay.style.display = 'none';
                     alert('An error occurred while processing the text. Please try again.');
                 });
             } else if (source === 'floatingToolbar') {
                 fetch('', {
                     method: 'POST',
                     headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                     body: 'action=processText' +
                           '&text=' + encodeURIComponent(selectedText) +
                           '&feature=' + encodeURIComponent(feature)
                 })
                 .then(response => response.text())
                 .then(processedText => {
                     loadingOverlay.style.display = 'none';
                     if (feature === 'dp4') {
                         showAnalysisInterface(processedText);
                     } else {
                         const formattedText = formatProcessedText(processedText, feature);
                         showProcessedContent(formattedText, feature);
                     }
                 })
                 .catch(error => {
                     console.error('Error:', error);
                     loadingOverlay.style.display = 'none';
                     alert('An error occurred while processing the text. Please try again.');
                 });
             }
         };

         function formatProcessedText(text) {

             text = text.replace(/\*(.*?)\*/g, '<strong>$1</strong>');

             text = text.replace(/\*/g, '');

             text = text.replace(/<strong>(.*?)<\/strong>/g, '<b>$1</b>');

             let firstBoldReplaced = false;
             text = text.replace(/<b>(.*?)<\/b>/g, (match, content) => {
                 if (!firstBoldReplaced) {
                     firstBoldReplaced = true;
                     return `<h3>${content}</h3>`;
                 }
                 return `<b>${content}</b>`;
             });

             text = text.split('\n').map(line => {
                 if (!line.startsWith('<h3>')) {
                     return `<p>${line}</p>`;
                 }
                 return line;
             }).join('');

             return text;
         }

         function extractProcessedText(response) {
             try {
                 const jsonResponse = JSON.parse(response);
                 if (jsonResponse.error) {
                     console.error('Error from server:', jsonResponse.error);
                     return 'Error: ' + jsonResponse.error;
                 }
             } catch (e) {

                 return response.trim();
             }
         }

         function showProcessingPanel(feature, processedText) {
             const processingPanel = document.getElementById('processingPanel');
             const processingPanelTitle = document.getElementById('processingPanelTitle');
             const processingPanelContent = document.getElementById('processingPanelContent');
             const acceptButton = document.getElementById('acceptButton');
             const rejectButton = document.getElementById('rejectButton');

             if (!processingPanel || !processingPanelTitle || !processingPanelContent || !acceptButton || !rejectButton) {
                 console.error('Processing panel elements not found');
                 return;
             }

             processingPanelTitle.textContent = getFeatureTitle(feature);
             processingPanelContent.innerHTML = processedText;

             processingPanel.style.display = 'block';
             setTimeout(() => processingPanel.classList.add('open'), 10);

             acceptButton.onclick = () => acceptProcessedText(feature, processedText);
             rejectButton.onclick = () => rejectProcessedText();

             const closeButton = document.getElementById('closeProcessingPanel');
             if (closeButton) {
                 closeButton.onclick = closeProcessingPanel;
             }
         }

         function getFeatureTitle(feature) {
             switch(feature) {
                 case 'dp1': return 'Simplified Text';
                 case 'dp2': return 'Structured Text';
                 case 'dp3': return 'Essential Points';
                 case 'dp4': return 'Analysis';
                 default: return 'Processed Text';
             }
         }

         function acceptProcessedText(feature, processedText) {
             if (feature === 'dp2' || feature === 'dp3') { 
                 createStickyNote(feature, processedText);
             }

             closeProcessingPanel();
         }

         function createStickyNote(feature, content) {
         const noteId = 'note-' + Date.now();
         const noteColor = feature === 'dp2' ? 'note-green' : 'note-levendor';
         const noteTitle = feature === 'dp2' ? 'Structure' : 'Essential';
         const currentPage = getCurrentPage();

         const noteHTML = `
             <div class="noteHolder ${noteColor}" id="${noteId}" data-page="${currentPage}">
                 <div class="note-header">
                     <h4>${noteTitle}</h4>
                     <div class="note-actions">
                         <button class="btn btn-edit" title="Edit"><i class="fas fa-edit"></i></button>
                         <button class="btn btn-delete" title="Delete"><i class="fas fa-trash"></i></button>
                     </div>
                 </div>
                 <div class="note-content">${content}</div>
             </div>
         `;

         const notesContainer = document.getElementById('stickyNotesContainer') || createNotesContainer();
         notesContainer.insertAdjacentHTML('beforeend', noteHTML);

         const noteElement = document.getElementById(noteId);
         setupNoteEventListeners(noteElement);

         const selection = window.getSelection();
         if (selection.rangeCount > 0) {
             const range = selection.getRangeAt(0);
             const rect = range.getBoundingClientRect();
             noteElement.style.position = 'absolute';
             noteElement.style.left = `${document.documentElement.clientWidth - 270}px`; 
             noteElement.style.top = `${rect.top + window.pageYOffset}px`;
         }

         updateNotesVisibility();
         return noteElement;
         }
         function setupNoteEventListeners(noteElement) {
             let isDraggable = false;

             noteElement.addEventListener('mouseenter', function() {
                 noteElement.style.boxShadow = '0 0 10px rgba(0, 255, 0, 0.5)';
             });

             noteElement.addEventListener('mouseleave', function() {
                 if (!isDraggable) {
                     noteElement.style.boxShadow = 'none';
                 }
             });

             noteElement.addEventListener('click', function(e) {
                 if (e.target.closest('.btn')) return; 
                 isDraggable = !isDraggable;
                 noteElement.style.cursor = isDraggable ? 'grab' : 'default';
                 noteElement.style.boxShadow = isDraggable ? '0 0 10px rgba(0, 255, 0, 0.5)' : 'none';

                 if (isDraggable) {
                     $(noteElement).draggable('enable');
                 } else {
                     $(noteElement).draggable('disable');
                     saveNotePosition(noteElement);
                 }
             });

              $(noteElement).draggable({
             handle: ".note-header",
             containment: "window",
             scroll: false,
             disabled: true,
             stop: function(event, ui) {
                 saveNotePosition(noteElement);
                 noteElement.dataset.page = getCurrentPage();
                 updateNotesVisibility();
             }
         });

             noteElement.querySelector('.btn-edit').addEventListener('click', function() {
                 const contentDiv = noteElement.querySelector('.note-content');
                 const currentContent = contentDiv.innerHTML;
                 contentDiv.innerHTML = `<textarea class="edit-textarea">${currentContent}</textarea>
                                         <button class="btn btn-save">Save</button>`;

                 const saveBtn = contentDiv.querySelector('.btn-save');
                 saveBtn.addEventListener('click', function() {
                     const newContent = contentDiv.querySelector('.edit-textarea').value;
                     contentDiv.innerHTML = newContent;
                     saveNoteContent(noteElement.id, newContent);
                 });
             });

             noteElement.querySelector('.btn-delete').addEventListener('click', function() {
                 Swal.fire({
                     title: 'Are you sure?',
                     text: "You won't be able to revert this!",
                     icon: 'warning',
                     showCancelButton: true,
                     confirmButtonColor: '#3085d6',
                     cancelButtonColor: '#d33',
                     confirmButtonText: 'Yes, delete it!'
                 }).then((result) => {
                     if (result.isConfirmed) {
                         noteElement.remove();
                         deleteNoteFromStorage(noteElement.id);
                         Swal.fire(
                             'Deleted!',
                             'Your note has been deleted.',
                             'success'
                         )
                     }
                 });
             });

             noteElement.dataset.pageNumber = getCurrentPage();
             saveNotePosition(noteElement);
         }

         function createNotesContainer() {
             const container = document.createElement('div');
             container.id = 'stickyNotesContainer';
             container.style.position = 'absolute';
             container.style.top = '0';
             container.style.left = '0';
             container.style.width = '100%';
             container.style.height = '100%';
             container.style.pointerEvents = 'none';
             document.body.appendChild(container);
             return container;
         }

         function saveNotePosition(noteElement) {
             const position = {
                 left: noteElement.style.left,
                 top: noteElement.style.top,
                 pageNumber: noteElement.dataset.page
             };
             localStorage.setItem(`note_position_${noteElement.id}`, JSON.stringify(position));
         }
         function saveNoteContent(noteId, content) {
             localStorage.setItem(`note_content_${noteId}`, content);
         }

         function deleteNoteFromStorage(noteId) {
             localStorage.removeItem(`note_position_${noteId}`);
             localStorage.removeItem(`note_content_${noteId}`);
         }

         function loadSavedNotes() {
             const notesContainer = document.getElementById('stickyNotesContainer') || createNotesContainer();
             for (let i = 0; i < localStorage.length; i++) {
                 const key = localStorage.key(i);
                 if (key.startsWith('note_position_')) {
                     const noteId = key.replace('note_position_', '');
                     const position = JSON.parse(localStorage.getItem(key));
                     const content = localStorage.getItem(`note_content_${noteId}`);

                     if (content) {
                         const noteElement = createStickyNote(position.pageNumber === getCurrentPage() ? 'dp2' : 'dp3', content);
                         noteElement.style.left = position.left;
                         noteElement.style.top = position.top;
                         noteElement.dataset.page = position.pageNumber;
                         notesContainer.appendChild(noteElement);
                     }
                 }
             }
             updateNotesVisibility();
         }
         function onPDFLoad() {
             loadSavedNotes();

         }

         function formatStructureContent(content) {
             const parser = new DOMParser();
             const doc = parser.parseFromString(content, 'text/html');
             const sections = doc.body.children;

             let formattedContent = '<div class="content">';

             for (let section of sections) {
                 if (section.tagName === 'P' && section.textContent.trim()) {
                     formattedContent += `<h5>${section.textContent}</h5>`;
                 } else if (section.tagName === 'UL') {
                     formattedContent += '<ul>';
                     for (let li of section.children) {
                         formattedContent += `<li>${li.textContent}</li>`;
                     }
                     formattedContent += '</ul>';
                 }
             }

             formattedContent += '</div>';
             return formattedContent;
         }

         function rejectProcessedText() {
             closeProcessingPanel();
         }

         function closeProcessingPanel() {
             const processingPanel = document.getElementById('processingPanel');
             if (processingPanel) {
                 processingPanel.classList.remove('open');
                 setTimeout(() => processingPanel.style.display = 'none', 300);
             }
         }

         function undoLastAction() {
             if (undoStack.length > 0) {
                 const lastAction = undoStack.pop();
                 if (lastAction.range) {
                     lastAction.range.deleteContents();
                     const tempDiv = document.createElement('div');
                     tempDiv.innerHTML = lastAction.originalHTML;
                     while (tempDiv.firstChild) {
                         lastAction.range.insertNode(tempDiv.firstChild);
                     }
                 }
                 updateUndoRedoButtons();
             }
         }

         function updateNotesVisibility() {
         const currentPage = getCurrentPage();
         const notes = document.querySelectorAll('.noteHolder');
         notes.forEach(note => {
             if (parseInt(note.dataset.page) === currentPage) {
                 note.style.display = 'block';
             } else {
                 note.style.display = 'none';
             }
         });
         }

         function onPageChange() {
             updateNotesVisibility();
         }

         function updateUndoRedoButtons() {
             const undoButton = document.querySelector('[data-tool="undo"]');
             if (undoButton) {
                 undoButton.style.opacity = undoStack.length > 0 ? '1' : '0.5';
             }
         }

         function processTextLayer(textLayerDiv, intermediateLayer, viewport) {
             const textItems = Array.from(textLayerDiv.querySelectorAll('span'));
             textItems.forEach((item, index) => {
                 const bgDiv = document.createElement('div');
                 bgDiv.className = 'bg';
                 item.parentNode.insertBefore(bgDiv, item);
                 bgDiv.appendChild(item);

                 item.setAttribute('data-index', index);
                 item.style.cursor = 'text';

                 const transform = item.style.transform;
                 const matrix = transform.match(/matrix\((.*?)\)/)[1].split(',').map(Number);
                 const [scaleX, skewY, skewX, scaleY, translateX, translateY] = matrix;

                 bgDiv.style.transform = `matrix(${scaleX}, ${skewY}, ${skewX}, ${scaleY}, ${translateX}, ${translateY})`;

                 item.style.transform = 'none';

                 const bgRect = document.createElement('div');
                 bgRect.className = 'bgRect';
                 intermediateLayer.appendChild(bgRect);

                 const padding = 9;
                 bgRect.style.width = `${parseFloat(item.style.width) + padding * 2}px`;
                 bgRect.style.height = `${parseFloat(item.style.height) + padding * 2}px`;
                 bgRect.style.left = `${translateX - padding}px`;
                 bgRect.style.top = `${translateY - padding}px`;
             });
         }

         function adjustFontSizes() {
             console.log("Starting font size adjustment...");
             const presentationSpans = document.querySelectorAll('span[role="presentation"]');
             console.log(`Found ${presentationSpans.length} spans with role="presentation"`);

             presentationSpans.forEach((span, index) => {
                 const currentSize = parseFloat(span.style.fontSize);
                 if (!isNaN(currentSize)) {
                     const newSize = currentSize + 0.0;
                     span.style.fontSize = `${newSize}px`;
                     console.log(`Span ${index + 1}:`);
                     console.log(`  Text: "${span.textContent.trim()}"`);
                     console.log(`  Original size: ${currentSize}px`);
                     console.log(`  New size: ${newSize}px`);
                 } else {
                     console.log(`Span ${index + 1}: Unable to adjust. Current font-size: ${span.style.fontSize}`);
                 }
             });

             console.log("Font size adjustment completed.");
         }

         document.addEventListener('DOMContentLoaded', function() {
             floatingDialog = document.getElementById('floatingDialog');
             colorOptions = document.getElementById('colorOptions');

             const readingToolsToggle = document.getElementById('readingToolsToggle');
             const readingToolsMenu = document.getElementById('readingToolsMenu');
             const simplifySubmenu = document.getElementById('simplifySubmenu');
             const essentialsSubmenu = document.getElementById('essentialsSubmenu');
             const uploadArea = document.getElementById('uploadArea');
             const pdfFileInput = document.getElementById('pdfFile');
             const convertBtn = document.getElementById('convertBtn');
             const zoomInBtn = document.getElementById('zoomInBtn');
             const zoomOutBtn = document.getElementById('zoomOutBtn');
             const expandBtn = document.getElementById('expandBtn');
             const contentContainer = document.getElementById('contentContainer');
             const pdfViewer = document.getElementById('pdfViewer');

             let lastSelectedText = '';
             let lastSelectedRange = null;

             if (readingToolsToggle && readingToolsMenu) {
                 readingToolsToggle.addEventListener('click', () => {
                     readingToolsMenu.classList.toggle('show');
                 });
             }

             if (readingToolsMenu) {
                 readingToolsMenu.addEventListener('click', (e) => {
                     e.preventDefault();
                     e.stopPropagation();
                     const clickedTool = e.target.closest('.tool-icon');
                     if (!clickedTool) return;

                     const tool = clickedTool.dataset.tool;

                     if (tool === 'dp1' || tool === 'dp2' || tool === 'dp3' || tool === 'dp4') {
                         processText(tool, e, 'readingToolsMenu');
                     } else if (tool === 'undo') {
                         undoLastAction();
                     }

                     logInteraction('tool_click', { tool });
                 });
             }
             document.addEventListener('click', (e) => {
                 if (readingToolsMenu && readingToolsToggle && 
                     !readingToolsMenu.contains(e.target) && 
                     !readingToolsToggle.contains(e.target)) {
                     readingToolsMenu.classList.remove('show');
                     if (simplifySubmenu) simplifySubmenu.classList.remove('show');
                     if (essentialsSubmenu) essentialsSubmenu.classList.remove('show');
                 }
             });

         if (uploadArea && pdfFileInput) {
             uploadArea.addEventListener('click', function() {
                  console.log('Upload area clicked');
                 pdfFileInput.click();
             });
         }

         if (pdfFileInput) {
             pdfFileInput.addEventListener('change', function() {
                 console.log('File input changed');
                 if (pdfFileInput.files.length > 0) {
                     const file = pdfFileInput.files[0];
                     console.log('File selected:', file.name);
                     if (fileName) fileName.textContent = file.name;
                     if (selectedFile) selectedFile.style.display = 'flex';
                     if (convertBtn) convertBtn.disabled = false;
                 } else {
                     console.log('No file selected');
                     if (selectedFile) selectedFile.style.display = 'none';
                     if (convertBtn) convertBtn.disabled = true;
                 }
             });
         }

         if (zoomInBtn) {
         zoomInBtn.addEventListener('click', () => {
             currentScale += 0.2;
             renderAllPages();
             saveCurrentState();
         });
         }

         if (zoomOutBtn) {
         zoomOutBtn.addEventListener('click', () => {
             currentScale = Math.max(currentScale - 0.2, 0.5);
             renderAllPages();
             saveCurrentState();
         });
         }

             if (expandBtn && contentContainer) {
                 expandBtn.addEventListener('click', () => {
                     contentContainer.classList.toggle('fullscreen');
                     expandBtn.innerHTML = contentContainer.classList.contains('fullscreen') 
                         ? '<i class="bx bx-collapse-alt"></i>' 
                         : '<i class="bx bx-expand-alt"></i>';
                 });
             }

             updateUndoRedoButtons();
         });

         window.insertProcessedContent = function(range, processedText, showAcceptButton) {
             console.log('insertProcessedContent called');
             if (!range) {
                 console.error('Range is null');
                 throw new Error('Range is null');
             }

             const container = document.createElement('div');
             container.className = 'processed-content-container';

             const processedTextElement = document.createElement('div');
             processedTextElement.className = 'processed-text';
             processedTextElement.innerHTML = processedText;

             container.appendChild(processedTextElement);

             const buttonContainer = document.createElement('div');
             buttonContainer.className = 'button-container';
             container.appendChild(buttonContainer);

             if (showAcceptButton) {
                 const acceptButton = document.createElement('button');
                 acceptButton.className = 'action-button accept-button mdc-button mdc-button--raised';
                 acceptButton.innerHTML = '<i class="material-icons mdc-button__icon" aria-hidden="true">check</i><span class="mdc-button__label">Accept</span>';
                 acceptButton.onclick = function(e) {
                     e.stopPropagation();
                     acceptProcessedText();
                     container.remove();
                 };
                 buttonContainer.appendChild(acceptButton);
             }

             const closeButton = document.createElement('button');
             closeButton.className = 'action-button close-button mdc-button mdc-button--outlined';
             closeButton.innerHTML = '<i class="material-icons mdc-button__icon" aria-hidden="true">close</i><span class="mdc-button__label">Close</span>';
             closeButton.onclick = function(e) {
                 e.stopPropagation();
                 container.remove();
             };
             buttonContainer.appendChild(closeButton);

             Object.assign(container.style, {
                 position: 'fixed',
                 top: '50%',
                 left: '50%',
                 transform: 'translate(-50%, -50%)',
                 width: '300px',
                 backgroundColor: 'rgb(243 243 243)',
                 border: '1px solid rgb(232 232 232)',
                 borderRadius: '8px',
                 boxShadow: 'rgba(0, 0, 0, 0.1) 0px 1px 2px',
                 padding: '16px',
                 fontSize: '14px',
                 lineHeight: '1.4',
                 color: 'rgb(32, 33, 34)',
                 zIndex: '1000',
                 cursor: 'grab'
             });

             document.body.appendChild(container);

             makeDraggable(container);

             console.log('Container inserted successfully');
             return container;
         };

         function makeDraggable(element) {
             let isDragging = false;
             let currentX;
             let currentY;
             let initialX;
             let initialY;
             let xOffset = 0;
             let yOffset = 0;

             element.addEventListener("mousedown", dragStart);
             document.addEventListener("mousemove", drag);
             document.addEventListener("mouseup", dragEnd);

             function dragStart(e) {
                 initialX = e.clientX - xOffset;
                 initialY = e.clientY - yOffset;

                 if (e.target === element) {
                     isDragging = true;
                 }
             }

             function drag(e) {
                 if (isDragging) {
                     e.preventDefault();
                     currentX = e.clientX - initialX;
                     currentY = e.clientY - initialY;

                     xOffset = currentX;
                     yOffset = currentY;

                     setTranslate(currentX, currentY, element);
                 }
             }

             function dragEnd(e) {
                 initialX = currentX;
                 initialY = currentY;

                 isDragging = false;
             }

             function setTranslate(xPos, yPos, el) {
                 el.style.transform = `translate3d(${xPos}px, ${yPos}px, 0)`;
             }
         }

         function showAnalysisInterface(processedText) {
         console.log('Showing improved analysis interface');

         const currentPageNumber = getCurrentPage();

         const totalPages = pdfDoc ? pdfDoc.numPages : 'Unknown';

         const chatOverlay = document.createElement('div');
         chatOverlay.id = 'chat-overlay';
         chatOverlay.style.cssText = `
             position: fixed;
             top: 0;
             right: 0;
             width: 400px;
             height: 100vh;
             background-color: #f0f0f0;
             box-shadow: -2px 0 5px rgba(0,0,0,0.1);
             z-index: 1000;
             display: flex;
             flex-direction: column;
             transform: translateX(100%);
             transition: transform 0.3s ease-in-out;
         `;

         chatOverlay.innerHTML = `
             <div class="chat-header" style="padding: 15px; background-color: #202654; color: white; display: flex; justify-content: space-between; align-items: center;">
                 <h3 style="margin: 0; font-size: 18px;">Analyze</h3>
                 <div>
                     <span style="font-size: 12px; margin-right: 10px;">Page ${currentPageNumber}/${totalPages}</span>
                     <button id="togglePdfViewBtn" style="background: none; border: none; color: white; font-size: 18px; cursor: pointer;">
                         <i class="fas fa-expand-alt"></i>
                     </button>
                     <button id="closeChatBtn" style="background: none; border: none; color: white; font-size: 18px; cursor: pointer;">
                         <i class="fas fa-times"></i>
                     </button>
                 </div>
             </div>
             <div class="chat-content" style="flex-grow: 1; padding: 15px; overflow-y: auto; display: flex; flex-direction: column; gap: 15px;">
                 <div class="selected-text" style="background-color: #e0e0e0; padding: 10px; border-radius: 5px; font-style: italic; font-size: 14px;">
                     "${lastSelectedText}"
                 </div>
                 <div id="pregenerated-prompts" class="pregenerated-prompts">
                     <h4 style="margin-bottom: 10px; color: #202654;">Suggested Prompts:</h4>
                     <div style="display: flex; flex-direction: column; gap: 5px;">
                         ${processedText.split('\n').map(prompt => 
                             `<button class="prompt-btn" style="background: #202654; color: white; border: none; padding: 8px 12px; border-radius: 5px; cursor: pointer; transition: all 0.3s ease; text-align: left; font-size: 14px;">
                                 <i class="fas fa-lightbulb" style="margin-right: 5px;"></i>${prompt}
                             </button>`
                         ).join('')}
                     </div>
                 </div>
                 <div class="chat-messages" style="flex-grow: 1; overflow-y: auto; padding: 10px; background-color: white; border-radius: 5px;"></div>
             </div>
             <div class="chat-input" style="display: flex; padding: 15px; background-color: #e0e0e0;">
                 <input type="text" placeholder="Type your message..." style="flex-grow: 1; padding: 10px; border: 1px solid #ccc; border-radius: 5px 0 0 5px; font-size: 14px; outline: none;">
                 <button id="sendMessageBtn" style="background-color: #202654; color: white; border: none; padding: 10px 15px; border-radius: 0 5px 5px 0; cursor: pointer; font-size: 14px;">
                     <i class="fas fa-paper-plane"></i>
                 </button>
             </div>
         `;

         document.body.appendChild(chatOverlay);

         document.getElementById('togglePdfViewBtn').addEventListener('click', togglePDFView);
         document.getElementById('closeChatBtn').addEventListener('click', closeChatInterface);
         document.getElementById('sendMessageBtn').addEventListener('click', () => sendMessage());

         document.querySelectorAll('.prompt-btn').forEach(btn => {
             btn.addEventListener('click', (e) => selectPrompt(e.target.textContent.trim()));
         });

         document.querySelector('.chat-input input').addEventListener('keypress', (e) => {
             if (e.key === 'Enter') {
                 sendMessage();
             }
         });

         setTimeout(() => {
             chatOverlay.style.transform = 'translateX(0)';
         }, 50);

         console.log('Improved analysis interface setup complete');
         }
         function togglePDFView() {
         const chatOverlay = document.getElementById('chat-overlay');
         const pdfViewer = document.getElementById('pdfViewer');

         if (chatOverlay.style.transform === 'translateX(0px)') {
             chatOverlay.style.transform = 'translateX(100%)';
             pdfViewer.style.marginRight = '0';
         } else {
             chatOverlay.style.transform = 'translateX(0)';
             pdfViewer.style.marginRight = '400px'; 
         }
         }

         function sendMessage(message = null) {
         const input = document.querySelector('.chat-input input');
         const messageText = message || input.value.trim();
         if (messageText) {
             addMessage('user', messageText);
             input.value = '';

             const loadingIndicator = document.createElement('div');
             loadingIndicator.className = 'loading-indicator';
             loadingIndicator.innerHTML = '<i class="fas fa-spinner fa-spin"></i> AI is thinking...';
             document.querySelector('.chat-messages').appendChild(loadingIndicator);

             fetch('', {
                 method: 'POST',
                 headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                 body: 'action=processText' +
                       '&text=' + encodeURIComponent(lastSelectedText) +
                       '&feature=answerQuestion' +
                       '&userQuestion=' + encodeURIComponent(messageText)
             })
             .then(response => response.text())
             .then(aiResponse => {

                 loadingIndicator.remove();
                 addMessage('ai', aiResponse);
             })
             .catch(error => {
                 console.error('Error:', error);
                 loadingIndicator.remove();
                 addMessage('ai', "I'm sorry, I encountered an error while processing your request.");
             });
         }
         }
         function selectPrompt(promptText) {
         const promptsSection = document.getElementById('pregenerated-prompts');
         const chatMessages = document.querySelector('.chat-messages');

         promptsSection.style.transition = 'opacity 0.3s ease-out';
         promptsSection.style.opacity = '0';

         setTimeout(() => {
             promptsSection.remove();

             chatMessages.style.flexGrow = '1';
             chatMessages.style.transition = 'flex-grow 0.3s ease-in-out';

             sendMessage(promptText);
         }, 300);
         }
         function addMessage(sender, text) {
         const messagesContainer = document.querySelector('.chat-messages');
         const messageElement = document.createElement('div');
         messageElement.className = `message ${sender}`;
         messageElement.style.cssText = `
             padding: 15px;
             margin-bottom: 15px;
             border-radius: 20px;
             max-width: 80%;
             opacity: 0;
             transform: translateY(20px);
             transition: opacity 0.3s ease, transform 0.3s ease;
             ${sender === 'user' 
                 ? 'align-self: flex-end; background-color: #202654; color: white;' 
                 : 'align-self: flex-start; background-color: white; color: #202654; box-shadow: 0 2px 5px rgba(0,0,0,0.1);'}
         `;
         messageElement.textContent = text;
         messagesContainer.appendChild(messageElement);

         messageElement.offsetHeight;

         messageElement.style.opacity = '1';
         messageElement.style.transform = 'translateY(0)';

         messagesContainer.scrollTop = messagesContainer.scrollHeight;
         }

         function closeChatInterface() {
         const chatOverlay = document.getElementById('chat-overlay');
         const pdfViewer = document.getElementById('pdfViewer');

         if (chatOverlay) {
             chatOverlay.style.transform = 'translateX(100%)';
             pdfViewer.style.marginRight = '0';

             setTimeout(() => {
                 chatOverlay.remove();
             }, 300);
         }
         }
         let lastSelectedText = '';
         let lastSelectedRange = null;

         document.addEventListener('mouseup', function(e) {
             if (isDialogExpanded || isDragging) {
                 return; 
             }
             let selectedText = getSelectedText();
             console.log('Selected text in mouseup:', selectedText);
             console.log('Selected text length in mouseup:', selectedText.length);

             if (selectedText.length > 0) {
                 let selection = window.getSelection();
                 let selectedRange = selection.rangeCount > 0 ? selection.getRangeAt(0) : null;

                 if (selectedRange) {
                     console.log('Selected range:', selectedRange);
                     const rect = selectedRange.getBoundingClientRect();
                     console.log('Range rect:', rect);

                     lastSelectedText = selectedText;
                     lastSelectedRange = selectedRange;

                     if (floatingDialog) {
                         console.log('Floating dialog found:', floatingDialog);
                         floatingDialog.style.display = 'block';
                         floatingDialog.style.left = `${rect.right + window.scrollX}px`;
                         floatingDialog.style.top = `${rect.top + window.scrollY}px`;
                         floatingDialog.classList.remove('expanded');

                         const analysisInterface = document.getElementById('analysisInterface');
                         const processedText = document.getElementById('processedText');
                         if (analysisInterface) analysisInterface.style.display = 'none';
                         if (processedText) processedText.style.display = 'none';
                         if (colorOptions) colorOptions.style.display = 'block';

                         const featureOptions = floatingDialog.querySelector('.feature-options');
                         if (featureOptions) featureOptions.style.display = 'flex';

                         isDialogExpanded = false;
                     }
                 } else {
                     console.error('No range selected in mouseup event');
                 }
             } else if (floatingDialog && !floatingDialog.contains(e.target) && floatingDialog.style.display === 'block') {
                 floatingDialog.style.display = 'none';
                 isDialogExpanded = false;
             }
         });

         if (floatingDialog) {
             floatingDialog.addEventListener('click', function(e) {
                 e.stopPropagation(); 
             });
         }

         document.addEventListener('keydown', function(e) {
             if (e.ctrlKey || e.metaKey) {
                 if (e.key === 'z') {
                     e.preventDefault();
                     undoLastAction();
                 } else if (e.key === 'y' || (e.shiftKey && e.key === 'Z')) {
                     e.preventDefault();
                     redoLastAction();
                 }
             }
         });

         document.querySelectorAll('.menu-button').forEach(button => {
             button.addEventListener('click', function(e) {
                 logInteraction('button_click', {
                     buttonId: this.id,
                     buttonText: this.textContent.trim(),
                     task: getCurrentTask(),
                     page: getCurrentPage()
                 });
             });
         });

         function initializeShepherdTour() {
         if (typeof Shepherd === 'undefined') {
             console.error('Shepherd library is not loaded. Please include the Shepherd script in your HTML.');
             return;
         }

         if (sessionStorage.getItem('tourShown') === 'true') {
             console.log('Tour has already been shown in this session.');
             return;
         }

         const tour = new Shepherd.Tour({
             useModalOverlay: true,
             defaultStepOptions: {
                 cancelIcon: {
                     enabled: true
                 },
                 classes: 'shepherd-theme-custom',
                 scrollTo: true
             }
         });

         const style = document.createElement('style');
         style.textContent = `
             .shepherd-modal-overlay-container {
                 z-index: 9999 !important;
             }
             .shepherd-element {
                 z-index: 10000 !important;
             }
         `;
         document.head.appendChild(style);

         tour.addStep({
             id: 'welcome',
             text: 'Welcome to the PDF Reader! Let\'s explore its features.',
             attachTo: {
                 element: '#pdfViewer',
                 on: 'bottom'
             },
             buttons: [
                 {
                     text: 'Next',
                     action: tour.next
                 }
             ]
         });

         tour.addStep({
             id: 'pdf-navigation',
             text: 'Use these controls to navigate through the PDF pages.',
             attachTo: {
                 element: '.content-controls',
                 on: 'bottom'
             },
             buttons: [
                 {
                     text: 'Back',
                     action: tour.back
                 },
                 {
                     text: 'Next',
                     action: tour.next
                 }
             ]
         });

         tour.addStep({
             id: 'text-selection',
             text: 'Try selecting some text in the PDF. A toolbar will appear with analysis options.',
             attachTo: {
                 element: '#pdfViewer',
                 on: 'right'
             },
             buttons: [
                 {
                     text: 'Back',
                     action: tour.back
                 },
                 {
                     text: 'Next',
                     action: tour.next
                 }
             ]
         });

         tour.addStep({
             id: 'floating-toolbar',
             text: 'This toolbar appears when you select text. It offers various analysis tools.',
             attachTo: {
                 element: '#floatingToolbar',
                 on: 'bottom'
             },
             beforeShowPromise: function() {
                 return new Promise(function(resolve) {

                     const event = new MouseEvent('mouseup', {
                         view: window,
                         bubbles: true,
                         cancelable: true
                     });
                     document.querySelector('#pdfViewer').dispatchEvent(event);
                     setTimeout(resolve, 500); 
                 });
             },
             buttons: [
                 {
                     text: 'Back',
                     action: tour.back
                 },
                 {
                     text: 'Next',
                     action: tour.next
                 }
             ]
         });

         tour.addStep({
             id: 'reading-tools',
             text: 'Alternatively, you can use these tools to analyze the entire page or document.',
             attachTo: {
                 element: '#readingTools',
                 on: 'left'
             },
             buttons: [
                 {
                     text: 'Back',
                     action: tour.back
                 },
                 {
                     text: 'Next',
                     action: tour.next
                 }
             ]
         });

         tour.addStep({
             id: 'analysis-interface',
             text: 'The AI Analysis Assistant will appear here when you use any of the analysis tools.',
             attachTo: {
                 element: 'body',
                 on: 'right'
             },
             beforeShowPromise: function() {
                 return new Promise(function(resolve) {

                     showAnalysisInterface("Sample processed text");
                     setTimeout(resolve, 500);
                 });
             },
             buttons: [
                 {
                     text: 'Back',
                     action: tour.back
                 },
                 {
                     text: 'Finish',
                     action: tour.complete
                 }
             ]
         });

         tour.on('complete', () => {
             sessionStorage.setItem('tourShown', 'true');
             closeChatInterface(); 
         });

         tour.on('cancel', () => {
             sessionStorage.setItem('tourShown', 'true');
             closeChatInterface(); 
         });

         tour.start();
         }

         function loadPDF(fileOrData) {
         console.log('loadPDF called', typeof fileOrData, fileOrData);
         if (fileOrData instanceof File) {
             console.log('Loading from File object');
             const fileReader = new FileReader();
             fileReader.onload = function() {
                 console.log('FileReader onload triggered');
                 const typedarray = new Uint8Array(this.result);
                 console.log('File read, length:', typedarray.length);
                 pdfjsLib.getDocument(typedarray).promise.then(function(pdf) {
                     console.log('PDF loaded, number of pages:', pdf.numPages);
                     pdfDoc = pdf;
                     renderAllPages();
                 }).catch(function(error) {
                     console.error('Error loading PDF:', error);
                 });
             };
             fileReader.onerror = function(error) {
                 console.error('FileReader error:', error);
             };
             fileReader.readAsArrayBuffer(fileOrData);
         } else if (fileOrData instanceof Uint8Array) {
             console.log('Loading from Uint8Array, length:', fileOrData.length);
             pdfjsLib.getDocument(fileOrData).promise.then(function(pdf) {
                 console.log('PDF loaded, number of pages:', pdf.numPages);
                 pdfDoc = pdf;
                 renderAllPages();
             }).catch(function(error) {
                 console.error('Error loading PDF:', error);
             });
         } else {
             console.error('Invalid input for loadPDF function');
         }
         }

         let floatingToolbar;

         document.addEventListener('mouseup', function(e) {
             const selection = window.getSelection();
             if (selection.toString().trim().length > 0) {
                 if (!floatingToolbar) {
                     createFloatingToolbar();
                 }
                 positionFloatingToolbar(selection);
             } else if (floatingToolbar && !floatingToolbar.contains(e.target)) {
                 floatingToolbar.style.display = 'none';
             }
         });

         function createFloatingToolbar() {
             floatingToolbar = document.createElement('div');
             floatingToolbar.className = 'floating-toolbar';
             floatingToolbar.innerHTML = `
                 <button onclick="processText('dp1', event, 'floatingToolbar')">Simplify</button>
                 <button onclick="processText('dp2', event, 'floatingToolbar')">Structure</button>
                 <button onclick="processText('dp3', event, 'floatingToolbar')">Essential</button>
                 <button onclick="processText('dp4', event, 'floatingToolbar')">Analyze</button>
                 <button class="close-btn" onclick="closeFloatingToolbar()"><i class='bx bx-x'></i></button>
             `;
             document.body.appendChild(floatingToolbar);
         }
         function positionFloatingToolbar(selection) {
             const range = selection.getRangeAt(0);
             const rect = range.getBoundingClientRect();
             floatingToolbar.style.display = 'block';
             floatingToolbar.style.position = 'absolute';
             floatingToolbar.style.left = `${rect.left + window.pageXOffset}px`;
             floatingToolbar.style.top = `${rect.bottom + window.pageYOffset}px`;
         }

         function closeFloatingToolbar() {
             if (floatingToolbar) {
                 floatingToolbar.style.display = 'none';
             }
         }

         function showChatInterface(prompt) {
             const currentPage = document.querySelector('.page-container:nth-child(' + (getCurrentPage()) + ')');
             const chatInterface = document.createElement('div');
             chatInterface.id = 'chat-interface';
             chatInterface.innerHTML = `
                 <div class="chat-header">
                     <h3>AI Assistant</h3>
                     <button onclick="toggleView()">Toggle View</button>
                     <button onclick="closeChatInterface()">Close</button>
                 </div>
                 <div class="chat-messages"></div>
                 <div class="chat-input">
                     <input type="text" placeholder="Type your message...">
                     <button onclick="sendMessage()">Send</button>
                 </div>
             `;

             document.body.appendChild(chatInterface);

             currentPage.style.transition = 'transform 0.5s ease-in-out';
             currentPage.style.transform = 'translateX(-50%)';
             chatInterface.style.transition = 'transform 0.5s ease-in-out';
             chatInterface.style.transform = 'translateX(100%)';

             setTimeout(() => {
                 chatInterface.style.transform = 'translateX(0)';
             }, 50);

             addMessage('user', prompt);

             addMessage('ai', "I'm processing your request. How can I assist you with this topic?");
         }

         function openChatInterface(button) {
             const dialogContainer = button.closest('.processed-content-dialog');
             const processedText = dialogContainer.querySelector('.processed-text').textContent;

             const chatContainer = document.createElement('div');
             chatContainer.className = 'chat-interface';
             chatContainer.innerHTML = `
                 <div class="chat-header">
                     <h3>Chat Assistant</h3>
                     <button onclick="closeChatInterface(this)">×</button>
                 </div>
                 <div class="chat-messages"></div>
                 <div class="chat-input">
                     <input type="text" placeholder="Type your question...">
                     <button onclick="sendChatMessage(this)">Send</button>
                 </div>
             `;

             document.body.appendChild(chatContainer);

             const rect = dialogContainer.getBoundingClientRect();
             chatContainer.style.position = 'absolute';
             chatContainer.style.left = `${rect.right + 20}px`;
             chatContainer.style.top = `${rect.top}px`;

             const blurOverlay = document.createElement('div');
             blurOverlay.className = 'blur-overlay';
             document.body.appendChild(blurOverlay);

             const messagesContainer = chatContainer.querySelector('.chat-messages');
             addChatMessage(messagesContainer, 'AI', `How can I help you understand this text: "${processedText.substring(0, 50)}..."`);
         }

         function sendChatMessage(button) {
             const chatContainer = button.closest('.chat-interface');
             const input = chatContainer.querySelector('input');
             const message = input.value.trim();

             if (message) {
                 const messagesContainer = chatContainer.querySelector('.chat-messages');
                 addChatMessage(messagesContainer, 'User', message);
                 input.value = '';

                 setTimeout(() => {
                     addChatMessage(messagesContainer, 'AI', 'This is a simulated response. Replace with actual AI response.');
                 }, 1000);
             }
         }

         function addChatMessage(container, sender, message) {
             const messageElement = document.createElement('div');
             messageElement.className = `chat-message ${sender.toLowerCase()}`;
             messageElement.textContent = `${sender}: ${message}`;
             container.appendChild(messageElement);
             container.scrollTop = container.scrollHeight;
         }

         function toggleView() {
             const currentPage = document.querySelector('.page-container:nth-child(' + (getCurrentPage()) + ')');
             const chatInterface = document.getElementById('chat-interface');

             if (currentPage.style.transform === 'translateX(-50%)') {
                 currentPage.style.transform = 'translateX(0)';
                 chatInterface.style.transform = 'translateX(100%)';
             } else {
                 currentPage.style.transform = 'translateX(-50%)';
                 chatInterface.style.transform = 'translateX(0)';
             }
         }

         function closeChatInterface() {
         console.log('Closing chat interface');
         const chatOverlay = document.getElementById('chat-overlay');
         const pdfViewer = document.getElementById('pdfViewer');

         if (chatOverlay) {
             chatOverlay.style.transform = 'translateX(100%)';

             if (pdfViewer) {
                 pdfViewer.style.marginRight = '0';
             } else {
                 console.warn('PDF viewer element not found');
             }

             setTimeout(() => {
                 chatOverlay.remove();
                 console.log('Chat overlay removed');
             }, 300);
         } else {
             console.warn('Chat overlay not found, it may have been already removed');
         }

         const floatingToolbar = document.querySelector('.floating-toolbar');
         if (floatingToolbar) {
             floatingToolbar.style.display = 'flex';
         }

         console.log('Chat interface closed');
         }

         function showProcessedContent(content, feature) {
         console.log('showProcessedContent called with feature:', feature);
         const contentContainer = document.createElement('div');
         contentContainer.className = 'processed-content';
         contentContainer.setAttribute('data-feature', feature);
         contentContainer.innerHTML = `
             <div class="processed-text">${content}</div>
             <div class="action-buttons">
                 <button onclick="acceptProcessedContent(this, '${feature}')"><i class='bx bx-check'></i></button>
                 <button onclick="closeProcessedContent(this)"><i class='bx bx-x'></i></button>
             </div>
         `;
         document.body.appendChild(contentContainer);
         console.log('Processed content container added to body with feature:', feature);
         }

         function closeProcessedContent(button) {
         console.log('closeProcessedContent called');
         const container = button.closest('.processed-content');
         const feature = container.getAttribute('data-feature');
         container.remove();
         console.log('Processed content container removed. Feature:', feature);
         } 
         function showProcessedContentDialog(content, feature) {
             const dialogContainer = document.createElement('div');
             dialogContainer.className = 'processed-content-dialog';
             dialogContainer.innerHTML = `
                 <div class="dialog-header">
                     <h3>${getFeatureTitle(feature)}</h3>
                     <button onclick="closeProcessedContentDialog(this)">×</button>
                 </div>
                 <div class="dialog-content">
                     <div class="processed-text">${content}</div>
                 </div>
             `;

             if (feature === 'dp4') {
                 const promptsContainer = document.createElement('div');
                 promptsContainer.className = 'prompts-container';
                 content.split('\n').forEach((prompt, index) => {
                     const promptButton = document.createElement('button');
                     promptButton.className = 'prompt-button';
                     promptButton.textContent = prompt;
                     promptButton.onclick = () => showChatInterface(prompt);
                     promptsContainer.appendChild(promptButton);
                 });
                 dialogContainer.querySelector('.dialog-content').appendChild(promptsContainer);
             }

             document.body.appendChild(dialogContainer);

             positionDialog(dialogContainer);
         }

         function acceptProcessedContent(button, feature) {
         console.log('acceptProcessedContent called with feature:', feature);
         const container = button.closest('.processed-content');
         console.log('Container found:', container);
         const processedText = container.querySelector('.processed-text').textContent;
         console.log('Processed text:', processedText);

         if (feature === 'dp2' || feature === 'dp3') {
             console.log('Creating sticky note for feature:', feature);
             createStickyNote(feature, processedText);
         } else {
             console.log('Feature not dp2 or dp3, sticky note not created');
         }

         closeProcessedContent(button);
         }

         function closeProcessedContentDialog(button) {
             const dialogContainer = button.closest('.processed-content-dialog');
             dialogContainer.remove();
         }

         window.addEventListener('scroll', function() {
             clearTimeout(scrollTimeout);
             scrollTimeout = setTimeout(function() {
                 handlePageChange();
                 updateNotesVisibility();
                 saveCurrentState();
             }, 100); 
         });

         document.addEventListener('DOMContentLoaded', function() {
         const fontAwesomeLink = document.createElement('link');
         fontAwesomeLink.rel = 'stylesheet';
         fontAwesomeLink.href = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css';
         document.head.appendChild(fontAwesomeLink);
         });

         function checkChatInterface() {
         const chatOverlay = document.getElementById('chat-overlay');
         if (chatOverlay) {
             console.log('Chat overlay exists');
             console.log('Opacity:', chatOverlay.style.opacity);
             console.log('Display:', getComputedStyle(chatOverlay).display);

             const chatInterface = document.getElementById('chat-interface');
             if (chatInterface) {
                 console.log('Chat interface exists');
                 console.log('Chat interface dimensions:', chatInterface.getBoundingClientRect());
             } else {
                 console.error('Chat interface not found');
             }
         } else {
             console.error('Chat overlay not found');
         }
         }

         setTimeout(checkChatInterface, 100);

         function scrollToPage(pageNumber) {
         const pageElement = document.querySelector(`.page:nth-child(${pageNumber})`);
         if (pageElement) {
             pageElement.scrollIntoView({ behavior: 'smooth' });
         }
         }

         function saveCurrentState() {
         const currentPage = getCurrentPage();
         const currentZoom = currentScale;

         fetch('', {
             method: 'POST',
             headers: {
                 'Content-Type': 'application/x-www-form-urlencoded',
             },
             body: `action=saveState&page=${currentPage}&zoom=${currentZoom}`
         })
         .then(response => response.json())
         .then(data => console.log(data.message))
         .catch(error => console.error('Error:', error));
         }

         function manageLoadingState() {
         let loadingOverlay;
         let loadingSpinner;
         let loadingMessage;

         function createLoadingElements() {
             loadingOverlay = document.createElement('div');
             loadingOverlay.id = 'loadingOverlay';
             loadingOverlay.style.cssText = `
                 position: fixed;
                 top: 0;
                 left: 0;
                 width: 100%;
                 height: 100%;
                 background-color: rgba(0, 0, 0, 0.5);
                 display: flex;
                 flex-direction: column;
                 justify-content: center;
                 align-items: center;
                 z-index: 9999;
                 display: none;
             `;

             loadingSpinner = document.createElement('div');
             loadingSpinner.className = 'loading-spinner';
             loadingSpinner.style.cssText = `
                 border: 5px solid #f3f3f3;
                 border-top: 5px solid #3498db;
                 border-radius: 50%;
                 width: 50px;
                 height: 50px;
                 animation: spin 1s linear infinite;
             `;

             loadingMessage = document.createElement('p');
             loadingMessage.id = 'loadingMessage';
             loadingMessage.style.cssText = `
                 color: white;
                 margin-top: 20px;
                 font-size: 18px;
             `;

             loadingOverlay.appendChild(loadingSpinner);
             loadingOverlay.appendChild(loadingMessage);
             document.body.appendChild(loadingOverlay);

             const style = document.createElement('style');
             style.textContent = `
                 @keyframes spin {
                     0% { transform: rotate(0deg); }
                     100% { transform: rotate(360deg); }
                 }
             `;
             document.head.appendChild(style);
         }

         function setLoading(isLoading, message = 'Loading PDF...') {
             if (!loadingOverlay) createLoadingElements();

             loadingMessage.textContent = message;
             loadingOverlay.style.display = isLoading ? 'flex' : 'none';
         }

         function uploadPDF(file) {
             console.log('New file upload');
             console.log('File object:', file);
             const formData = new FormData();
             formData.append('pdfFile', file);
             formData.append('action', 'uploadPDF');

             setLoading(true, 'Uploading PDF...');

             return fetch('', {
                 method: 'POST',
                 body: formData
             })
             .then(response => {
                 console.log('Raw response:', response);
                 return response.json();
             })
             .then(data => {
                 console.log('Server response:', data);
                 if (data.success) {
                     console.log('File upload successful, loading PDF');
                     setLoading(true, 'Processing PDF...');
                     return loadPDF(file);
                 } else {
                     console.error('File upload failed:', data.message);
                     throw new Error(data.message);
                 }
             });
         }

         function loadSavedPDF() {
             console.log('loadSavedPDF called');
             setLoading(true, 'Loading saved PDF...');
             return fetch('?action=getPDF')
                 .then(response => response.json())
                 .then(data => {
                     console.log('Saved PDF data received:', data);
                     if (data.pdf_content) {
                         const pdfContent = atob(data.pdf_content);
                         const pdfData = new Uint8Array(pdfContent.length);
                         for (let i = 0; i < pdfContent.length; i++) {
                             pdfData[i] = pdfContent.charCodeAt(i);
                         }
                         console.log('PDF data prepared, length:', pdfData.length);
                         return loadPDF(pdfData);
                     } else {
                         console.error('No PDF content in saved data');
                         throw new Error('No saved PDF found. Please upload a new PDF.');
                     }
                 });
         }

         function loadPDF(fileOrData) {
             console.log('loadPDF called', typeof fileOrData, fileOrData);
             setLoading(true, 'Rendering PDF...');

             const loadPromise = (fileOrData instanceof File)
                 ? new Promise((resolve, reject) => {
                     const fileReader = new FileReader();
                     fileReader.onload = () => resolve(new Uint8Array(fileReader.result));
                     fileReader.onerror = reject;
                     fileReader.readAsArrayBuffer(fileOrData);
                 })
                 : Promise.resolve(fileOrData);

             return loadPromise
                 .then(data => pdfjsLib.getDocument(data).promise)
                 .then(pdf => {
                     console.log('PDF loaded, number of pages:', pdf.numPages);
                     window.pdfDoc = pdf;
                     return renderAllPages();
                 })
                 .catch(error => {
                     console.error('Error loading PDF:', error);
                     throw error;
                 });
         }

         async function renderAllPages() {
             console.log('renderAllPages called');
             const pdfViewer = document.getElementById('pdfViewer');
             if (!pdfViewer) {
                 console.error('PDF viewer element not found');
                 throw new Error('PDF viewer element not found');
             }
             pdfViewer.innerHTML = '';
             for (let pageNum = 1; pageNum <= window.pdfDoc.numPages; pageNum++) {
                 setLoading(true, `Rendering page ${pageNum} of ${window.pdfDoc.numPages}...`);
                 console.log('Rendering page', pageNum);
                 await renderPage(pageNum);
             }

             console.log("All PDF pages rendered. Injecting text containers...");
             injectTextContainers();
         }

         async function renderPage(num) {
             const page = await window.pdfDoc.getPage(num);
             const scale = window.currentScale || 1.5;
             const viewport = page.getViewport({scale: scale});

             const pageDiv = document.createElement('div');
             pageDiv.className = 'page';
             const pdfViewer = document.getElementById('pdfViewer');
             pdfViewer.appendChild(pageDiv);

             const canvas = document.createElement('canvas');
             const context = canvas.getContext('2d');
             canvas.height = viewport.height;
             canvas.width = viewport.width;

             const intermediateLayer = document.createElement('div');
             intermediateLayer.className = 'intermediateLayer';
             intermediateLayer.style.width = `${viewport.width}px`;
             intermediateLayer.style.height = `${viewport.height}px`;
             pageDiv.appendChild(intermediateLayer);

             const renderContext = {
                 canvasContext: context,
                 viewport: viewport
             };

             pageDiv.appendChild(canvas);

             const textLayerDiv = document.createElement('div');
             textLayerDiv.className = 'textLayer';
             textLayerDiv.style.width = `${viewport.width}px`;
             textLayerDiv.style.height = `${viewport.height}px`;
             pageDiv.appendChild(textLayerDiv);

             await page.render(renderContext).promise;

             const textContent = await page.getTextContent();
             pdfjsLib.renderTextLayer({
                 textContent: textContent,
                 container: textLayerDiv,
                 viewport: viewport,
                 textDivs: []
             });

             pageDiv.style.width = `${viewport.width}px`;
             pageDiv.style.height = `${viewport.height}px`;
         }

         return {
             setLoading,
             uploadPDF,
             loadSavedPDF
         };
         }

         function injectTextContainers() {
         console.log('Starting to inject text containers');
         const pages = document.querySelectorAll('.page');
         console.log(`Found ${pages.length} page elements`);

         if (pages.length === 0) {
             console.warn('No .page elements found');
             return;
         }

         const observer = new MutationObserver((mutations, obs) => {
             const allPagesRendered = Array.from(pages).every(page => 
                 page.querySelector('.textLayer') && page.querySelector('.textLayer').children.length > 0
             );

             if (allPagesRendered) {
                 obs.disconnect();
                 setTimeout(processPages, 500); 
             }
         });

         observer.observe(document.body, {
             childList: true,
             subtree: true
         });

         function processPages() {
             pages.forEach((page, pageIndex) => {
                 console.log(`Processing page ${pageIndex + 1}`);
                 const textLayer = page.querySelector('.textLayer');
                 const intermediateLayer = page.querySelector('.intermediateLayer');

                 if (!textLayer || !intermediateLayer) {
                     console.warn(`Required layers not found in page ${pageIndex + 1}`);
                     return;
                 }

                 const textItems = Array.from(textLayer.querySelectorAll('span'));
                 console.log(`Found ${textItems.length} text items in page ${pageIndex + 1}`);

                 const pageRect = page.getBoundingClientRect();
                 console.log('Page bounding rectangle:', pageRect);

                 const scrollX = window.pageXOffset || document.documentElement.scrollLeft;
                 const scrollY = window.pageYOffset || document.documentElement.scrollTop;
                 console.log(`Current scroll position: (${scrollX}, ${scrollY})`);

                 textItems.forEach((item, itemIndex) => {
                     if (item.textContent.trim() !== '') {
                         console.log(`Processing text item ${itemIndex}: "${item.textContent}"`);

                         const rect = item.getBoundingClientRect();
                         console.log('Text item bounding rectangle:', rect);

                         const textContainer = document.createElement('div');
                         textContainer.className = 'textContainer';

                         const padding = 9;
                         const width = rect.width + padding * 2;
                         const height = rect.height + padding * 2;
                         const left = rect.left - pageRect.left + scrollX;
                         const top = rect.top - pageRect.top + scrollY;

                         console.log('Calculated dimensions and position:');
                         console.log(`Width: ${width}, Height: ${height}`);
                         console.log(`Left: ${left}, Top: ${top}`);

                         textContainer.style.cssText = `
                             width: ${width}px;
                             height: ${height}px;
                             left: ${left}px;
                             top: ${top}px;
                             position: absolute;
                             background-color: rgba(255, 255, 255);
                             pointer-events: none;
                         `;

                         intermediateLayer.appendChild(textContainer);
                         console.log(`Added text container for item ${itemIndex} in page ${pageIndex + 1}`);
                         console.log('Text container style:', textContainer.style.cssText);

                         const containerRect = textContainer.getBoundingClientRect();
                         console.log('Text container bounding rectangle:', containerRect);
                     }
                 });
             });
             console.log('Finished injecting text containers');
         }
         }
         const pdfLoader = manageLoadingState();

         const convertBtn = document.getElementById('convertBtn');
         const pdfFileInput = document.getElementById('pdfFile');
         const contentContainer = document.getElementById('contentContainer');
         const uploadContainer = document.getElementById('uploadContainer');

         if (convertBtn) {
         convertBtn.addEventListener('click', function() {
             console.log('Convert button clicked');
             pdfLoader.setLoading(true, 'Preparing to load PDF...');

             const loadPDFPromise = pdfFileInput && pdfFileInput.files.length > 0
                 ? pdfLoader.uploadPDF(pdfFileInput.files[0])
                 : pdfLoader.loadSavedPDF();

             loadPDFPromise
                 .then(() => {
                     if (contentContainer) contentContainer.style.display = 'block';
                     if (uploadContainer) uploadContainer.style.display = 'none';
                 })
                 .catch(error => {
                     console.error('Error loading PDF:', error);
                     alert(error.message || 'An error occurred while loading the PDF. Please try again.');
                 })
                 .finally(() => {
                     pdfLoader.setLoading(false);
                     console.log('PDF loading process completed');
                 });
         });
         }

         <?php if(isset($_SESSION['pdf_content'])): ?>
         console.log('Existing PDF session found');
         if (convertBtn) convertBtn.disabled = false;
         const fileName = document.getElementById('fileName');
         const selectedFile = document.getElementById('selectedFile');
         if (fileName) fileName.textContent = "<?php echo isset($_SESSION['pdf_filename']) ? $_SESSION['pdf_filename'] : 'Saved PDF'; ?>";
         if (selectedFile) selectedFile.style.display = 'flex';
         pdfLoader.loadSavedPDF()
         .then(() => {
             if (contentContainer) contentContainer.style.display = 'block';
             if (uploadContainer) uploadContainer.style.display = 'none';
         })
         .catch(error => console.error('Error auto-loading saved PDF:', error))
         .finally(() => pdfLoader.setLoading(false));
         <?php endif; ?>

         document.addEventListener('DOMContentLoaded', function() {
         const uploadNewPdfBtn = document.getElementById('uploadNewPdfBtn');

         if (uploadNewPdfBtn) {
             uploadNewPdfBtn.addEventListener('click', function(e) {
                 e.preventDefault(); 

                 sessionStorage.clear();

                 const pdfViewer = document.getElementById('pdfViewer');
                 if (pdfViewer) {
                     pdfViewer.innerHTML = '';
                 }

                 const contentContainer = document.getElementById('contentContainer');
                 const uploadContainer = document.getElementById('uploadContainer');
                 if (contentContainer) contentContainer.style.display = 'none';
                 if (uploadContainer) uploadContainer.style.display = 'block';

                 const pdfFileInput = document.getElementById('pdfFile');
                 if (pdfFileInput) pdfFileInput.value = '';

                 window.currentScale = 1.5; 

                 const stickyNotesContainer = document.getElementById('stickyNotesContainer');
                 if (stickyNotesContainer) stickyNotesContainer.innerHTML = '';

                 if (typeof closeChatInterface === 'function') closeChatInterface();
                 if (typeof closeProcessingPanel === 'function') closeProcessingPanel();

                 window.lastSelectedText = '';
                 window.lastSelectedRange = null;
                 window.pdfDoc = null;

                 fetch('?action=clearSession')
                     .then(response => response.json())
                     .then(data => {
                         if (data.success) {
                             console.log('Server-side session cleared');
                         }
                     })
                     .catch(error => console.error('Error clearing server-side session:', error));

                 const floatingToolbar = document.querySelector('.floating-toolbar');
                 if (floatingToolbar) floatingToolbar.style.display = 'none';

                 const textContainers = document.querySelectorAll('.textContainer');
                 textContainers.forEach(container => container.remove());

                 if (typeof getCurrentPage === 'function') {
                     const currentPage = getCurrentPage();
                     if (typeof scrollToPage === 'function') {
                         scrollToPage(1); 
                     }
                 }

                 console.log('Cache cleared and returned to main page');
             });
         }
         });
      </script>
   </body>
</html>
