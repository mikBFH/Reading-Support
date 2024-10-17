<?php
   ini_set('display_errors', 1);
   ini_set('display_startup_errors', 1);
   error_reporting(E_ALL);

   require_once 'vendor/autoload.php';

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

   $env_path = __DIR__ . '/../.env';
   loadEnv($env_path);

   $db_user = getenv('DB_USER');
   $db_name = getenv('DB_NAME');
   $db_pass = getenv('PASS');
   $db_host = getenv('DB_HOST');

   $db = new mysqli($db_host, $db_user, $db_pass, $db_name);
   if ($db->connect_error) {
       die("Connection failed: " . $db->connect_error);
   }

   if (!isset($_SESSION['current_task'])) {
       $_SESSION['current_task'] = 1;
       $_SESSION['current_section'] = 'registration';
       $_SESSION['completed_tasks'] = [];
       $_SESSION['scores'] = [];
       $_SESSION['viewed_pages'] = [];
   }

   $questions_file = 'test/questions_answers.json';
   $questions_data = json_decode(file_get_contents($questions_file), true);

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

   $current_section = $_SESSION['current_section'];
   $current_task = $_SESSION['current_task'];

   $task_content = [
       1 => [
           'page1' => '<h2>Task 1 - Page 1</h2><p><strong>Ragwort Introduction to New Zealand</strong></p><p>Ragwort was accidentally introduced to New Zealand in the late nineteenth century and, like so many invading foreign species, quickly became a pest. By the 1920s, the weed was rampant. What made matters worse was that its proliferation coincided with sweeping changes in agriculture and a massive shift from sheep farming to dairying. Ragwort contains a battery of toxic and resilient alkaloids; even honey made from its flowers contains the poison in dilute form. Livestock generally avoid grazing where ragwort is growing, but they will do so once it displaces grass and clover in their pasture. Though sheep can eat it for months before showing any signs of illness, if cattle eat it, they sicken quickly, and fatality can even result.</p>',
           'page2' => '<h2>Task 1 - Page 2</h2><p><strong>Catering vs. Restaurant Food Poisoning</strong></p><p>Despite the fact that the health-inspection procedures for catering establishments are more stringent than those for ordinary restaurants, more of the cases of food poisoning reported to the city health department were brought on by banquets served by catering services than were brought on by restaurant meals.</p>',
           'page3' => '<h2>Task 1 - Page 3</h2><p><strong>African American Newspapers in the 1930s</strong></p><p>African American newspapers in the 1930s faced many hardships. For instance, knowing that buyers of African American papers also bought general-circulation papers, advertisers of consumer products often ignored African American publications. Advertisers\' discrimination did free the African American press from advertiser domination. Editors could print politically charged material more readily than could the large national dailies, which depended on advertisers\' ideological approval to secure revenues. Unfortunately, it also made the selling price of Black papers much higher than that of general-circulation dailies. Often as much as two-thirds of publication costs had to come from subscribers or subsidies from community politicians and other interest groups. And despite their editorial freedom, African American publishers often felt compelled to print a disproportionate amount of sensationalism, sports, and society news to boost circulation.</p>',
           'page4' => '<h2>Task 1 - Page 4</h2><p><strong>Frieland Energy Tax</strong></p><p>Years ago, consumers in Frieland began paying an energy tax in the form of two Frieland pennies for each unit of energy consumed that came from nonrenewable sources. Following the introduction of this energy tax, there was a steady reduction in the total yearly consumption of energy from nonrenewable sources.</p>',
           'page5' => '<h2>Task 1 - Page 5</h2><p><strong>Global Warming and the Antarctic Environment</strong></p><p>In a plausible but speculative scenario, oceanographer Douglas Martinson suggests that temperature increases caused by global warming would not significantly affect the stability of the Antarctic environment, where sea ice forms on the periphery of the continent in the autumn and winter and mostly disappears in the summer. True, less sea ice would form in the winter because global warming would cause temperatures to rise. However, Martinson argues, the effect of a warmer atmosphere may be offset as follows. The formation of sea ice causes the concentration of salt in surface waters to increase; less sea ice would mean a smaller increase in the concentration of salt. Less salty surface waters would be less dense and, therefore, less likely to sink and stir up deep water. The deep water, with all its stored heat, would rise to the surface at a slower rate. Thus, although the winter sea-ice cover might decrease, the surface waters would remain cold enough so that the decrease would not be excessive.</p>'
       ],
       2 => [
           'page1' => '<h2>Task 2 - Page 1</h2><p><strong>Sunlight and Store Sales</strong></p><p>That sales can be increased by the presence of sunlight within a store has been shown by the experience of the only Savefast department store with a large skylight. The skylight allows sunlight into half of the store, reducing the need for artificial light. The rest of the store uses only artificial light. Since the store opened two years ago, the departments on the sunlit side have had substantially higher sales than the other departments.</p>',
           'page2' => '<h2>Task 2 - Page 2</h2><p><strong>Sixteenth-century Renaissance Scholars and Latin Classics</strong></p><p>While the best sixteenth-century Renaissance scholars mastered the classics of ancient Roman literature in the original Latin and understood them in their original historical context, most of the scholars’ educated contemporaries knew the classics only from school lessons on selected Latin texts. These were chosen by Renaissance teachers after much deliberation, for works written by and for the sophisticated adults of pagan Rome were not always considered suitable for the Renaissance young: the central Roman classics refused (as classics often do) to teach appropriate morality and frequently suggested the opposite. Teachers accordingly made students’ needs, not textual and historical accuracy, their supreme interest, chopping dangerous texts into short phrases, and using these to impart lessons extemporaneously on a variety of subjects, from syntax to science.</p>',
           'page3' => '<h2>Task 2 - Page 3</h2><p><strong>Pilomotor Reflex and Goose Bumps</strong></p><p>In humans, the pilomotor reflex leads to the response commonly known as goose bumps, and this response is widely considered to be vestigial—that is, something formerly having a greater physiological advantage than at present. It occurs when the tiny muscle at the base of a hair follicle contracts, pulling the hair upright. In animals with feathers, fur, or quills, this creates a layer of insulating warm air or a reason for predators to think twice before attacking. But human hair is too puny to serve these functions. Goose bumps in humans may, however, have acquired a new role. Like flushing—another thermoregulatory (heat-regulating) mechanism—goose bumps have become linked with emotional responses, notably fear, rage, or the pleasure of say, listening to beautiful music. They may thus serve as a signal to others.</p>',
           'page4' => '<h2>Task 2 - Page 4</h2><p><strong>Frederick Douglass and Nineteenth-Century Thought</strong></p><p>Frederick Douglass was unquestionably the most famous African American of the nineteenth century; indeed when he died in 1895 he was among the most distinguished public figures in the United States. In his study of Douglass’ career as a major figure in the movement to abolish slavery and as a spokesman for Black rights, Waldo Martin has provoked controversy by contending that Douglass also deserves a prominent place in the intellectual history of the United States because he exemplified so many strands of nineteenth-century thought: romanticism, idealism, individualism, liberal humanism, and an unshakable belief in progress.</p><p>Yet there is a central aspect of Douglass\' thought that seems not in the least bit dated or irrelevant to current concerns. He has no rival in the history of the nineteenth-century United States as an insistent and effective critic of the doctrine of innate racial inequality. He not only attacked racist ideas in his speeches and writings, but he offered his entire career and all his achievements as living proof that racists were wrong in their belief that one race could be inherently superior to another.</p>',
           'page5' => '<h2>Task 2 - Page 5</h2><p><strong>Pollination Patterns of Scarlet Gilia</strong></p><p>The plant called the scarlet gilia can have either red or white flowers. It had long been thought that hummingbirds, which forage by day, pollinate its red flowers, and that hawkmoths, which forage at night, pollinate its white flowers. To try to show that this pattern of pollination by colors exists, scientists recently covered some scarlet gilia flowers only at night and others only by day: plants with red flowers covered at night became pollinated; plants with white flowers covered by day became pollinated.</p>'
       ],
       3 => [
           'page1' => '<h2>Task 3 - Page 1</h2><p><strong>Supernovas and Cosmic Rays</strong></p><p>Supernovas in the Milky Way are the likeliest source for most of the cosmic rays reaching Earth. However, calculations show that supernovas cannot produce ultrahigh-energy cosmic rays (UHECRs) which have energies exceeding 10<sup>18</sup> electron volts. It would seem sensible to seek the source of these in the universe\'s most conspicuous energy factories: quasars and gamma-ray bursts billions of light-years away from Earth. But UHECRs tend to collide with photons of the cosmic microwave background—pervasive radiation that is a relic of the early universe. The odds favor a collision every 20 million light-years, each collision costing 20 percent of the cosmic ray\'s energy. Consequently, no cosmic ray traveling much beyond 100 million light-years can retain the energy observed in UHECRs.</p>',
           'page2' => '<h2>Task 3 - Page 2</h2><p><strong>The Cycling Boom and Women\'s Rights</strong></p><p>The massive influx of women cyclists, making up at least a third of the total market, was perhaps the most striking and profound social consequence of the mid-1890s cycling boom. Although the new improved bicycle had appealed immediately to a few privileged women, its impact would have been modest had it not attracted a greater cross section of the female population. It soon became apparent that many of these pioneer women bicyclists had not taken up the sport as an idle pastime. Rather, they saw cycling as a noble cause to be promoted among all women as a means to improve the general female condition.</p>',
           'page3' => '<h2>Task 3 - Page 3</h2><p><strong>The Mystery of Helical Forms in Nature</strong></p><p>What causes a helix in nature to appear with either a dextral (right-handed or clockwise) twist or a sinistral (left-handed or counterclockwise) twist is one of the most intriguing puzzles in the science of form. Most spiral-shaped snail species are predominantly dextral. But at one time handedness (twist direction of the shell) was equally distributed within some snail species that have become predominantly dextral or, in a few species, predominantly sinistral. What mechanisms control handedness and keep left-handedness rare?</p>',
           'page4' => '<h2>Task 3 - Page 4</h2><p><strong>Van Gogh Self-Portrait and Underimage</strong></p><p>X-ray examination of a recently discovered painting, judged by some authorities to be a self-portrait by Vincent van Gogh, revealed an underimage of a woman\'s face. Either van Gogh or another painter covered the first painting with the portrait now seen on the surface of the canvas. Because the face of the woman in the underimage also appears on canvases van Gogh is known to have painted, the surface painting must be an authentic self-portrait by van Gogh.</p>',
           'page5' => '<h2>Task 3 - Page 5</h2><p><strong>Hidden Layers in Art</strong></p><p>Some paintings attributed to famous artists have undergone scrutiny in recent years, revealing underlayers of previously unknown works. For example, a famous van Gogh portrait was found to have a woman\'s face hidden beneath the final image. This discovery raises questions about the process of artistic creation and the decisions made by artists to conceal earlier works.</p>'
       ]
   ];

   if ($_SERVER['REQUEST_METHOD'] === 'POST') {
   if (isset($_POST['register'])) {

       $prolific_id = $db->real_escape_string($_POST['prolific_id']);

       $stmt = $db->prepare("SELECT id FROM study_data WHERE prolific_id = ?");
       $stmt->bind_param("s", $prolific_id);
       $stmt->execute();
       $stmt->bind_result($existing_id);
       $stmt->fetch();

 if ($stmt->num_rows > 0) {

            ?>
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Error: Duplicate Prolific ID</title>
                <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
                <style>
                    body {
                        font-family: 'Roboto', sans-serif;
                        display: flex;
                        justify-content: center;
                        align-items: center;
                        height: 100vh;
                        margin: 0;
                        background-color: #f5f5f5;
                    }
                    .error-container {
                        text-align: center;
                        background-color: white;
                        padding: 2rem;
                        border-radius: 8px;
                        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                        max-width: 80%;
                    }
                    h1 {
                        color: #202654;
                    }
                    .redirect-button {
                        background-color: #202654;
                        color: white;
                        border: none;
                        padding: 10px 20px;
                        border-radius: 4px;
                        cursor: pointer;
                        font-size: 16px;
                        margin-top: 20px;
                        text-decoration: none;
                        display: inline-block;
                    }
                    #countdown {
                        font-weight: bold;
                    }

                </style>
            </head>
            <body>
                <div class="error-container">
                    <h1>Oops! This Prolific ID has already been used</h1>
                    <p>It looks like you've already participated in this study. Each Prolific ID can only be used once.</p>
                    <p>You will be redirected to the main page in <span id="countdown">10</span> seconds.</p>
                    <a href="https://readingparadox.com/public_html/control.php" class="redirect-button">Go to Main Page Now</a>
                </div>
                <script>
                    let countdown = 10;
                    const countdownElement = document.getElementById('countdown');
                    const countdownInterval = setInterval(() => {
                        countdown--;
                        countdownElement.textContent = countdown;
                        if (countdown <= 0) {
                            clearInterval(countdownInterval);
                            window.location.href = 'https://readingparadox.com/public_html/control.php';
                        }
                    }, 1000);
                </script>
            </body>
            </html>

            <?php
            exit;
        }
       $age = $db->real_escape_string($_POST['age']);
       $gender = $db->real_escape_string($_POST['gender']);
       $education = $db->real_escape_string($_POST['education_level']);
       $english_proficiency = $db->real_escape_string($_POST['english_proficiency']);
       $consent = isset($_POST['consent']) ? 'Yes' : 'No';
       $group = 'control';
       $current_time = date('Y-m-d H:i:s');

       $stmt = $db->prepare("INSERT INTO study_data (prolific_id, age, gender, education_level, english_proficiency, consent, study_group, created_at, task1_start_time, task1_end_time, task1_score, task1_answers, task2_start_time, task2_end_time, task2_score, task2_answers, task3_start_time, task3_end_time, task3_score, task3_answers, total_score, completion_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, '', '', 0, '[]', '', '', 0, '[]', '', '', 0, '[]', 0, 'incomplete')");
       $stmt->bind_param("sissssss", $prolific_id, $age, $gender, $education, $english_proficiency, $consent, $group, $current_time);

       if ($stmt->execute()) {
           $_SESSION['participant_id'] = $stmt->insert_id;
           $_SESSION['study_group'] = $group;
           $_SESSION['current_section'] = 'tool_description';
           $_SESSION['task1_start_time'] = $current_time;

           $stmt = $db->prepare("UPDATE study_data SET task1_start_time = ? WHERE id = ?");
           $stmt->bind_param("si", $current_time, $_SESSION['participant_id']);
           $stmt->execute();

           header("Location: " . $_SERVER['PHP_SELF']);
           exit();
       } else {
           $error_message = "Registration failed. Please try again.";
       }
   }
    elseif (isset($_POST['start_reading_task'])) {
           $_SESSION['current_section'] = 'task_reading';
           $_SESSION['current_task'] = 1;
           $current_time = date('Y-m-d H:i:s');
           $_SESSION['task1_start_time'] = $current_time;

           $stmt = $db->prepare("UPDATE study_data SET task1_start_time = ? WHERE id = ?");
           $stmt->bind_param("si", $current_time, $_SESSION['participant_id']);
           $stmt->execute();

           header("Location: " . $_SERVER['PHP_SELF']);
           exit();
       } elseif (isset($_POST['next_section'])) {
           $_SESSION['current_section'] = 'task_questions';
       } elseif (isset($_POST['submit_answers'])) {
           $current_task = $_SESSION['current_task'];
           $task_key = "Practice Set {$current_task}";
           $questions = $questions_data[$task_key] ?? [];
           $score = 0;
           $results = [];
           $answers = [];

           foreach ($questions as $index => $question) {
               $user_answer = $_POST['task_answers'][$index] ?? [];
               $user_answer = is_array($user_answer) ? $user_answer : [$user_answer];
               $correct_answer = explode(", ", $question['Answer']);
               $is_correct = count($user_answer) == count($correct_answer) && empty(array_diff($user_answer, $correct_answer));
               if ($is_correct) $score++;

               $answers[] = [
                   'question_number' => $index + 1,
                   'user_answer' => implode(", ", $user_answer),
                   'is_correct' => $is_correct
               ];

               $results[] = [
                   'question' => $question['Question'],
                   'user_answer' => implode(", ", $user_answer),
                   'correct_answer' => $question['Answer'],
                   'explanation' => $question['Explanation'],
                   'is_correct' => $is_correct
               ];
           }

           $_SESSION['scores'][$current_task] = $score;
           $_SESSION['results'][$current_task] = $results;
           $_SESSION['completed_tasks'][] = $current_task;

           $current_time = date('Y-m-d H:i:s');
           $answers_json = json_encode($answers);

           $stmt = $db->prepare("UPDATE study_data SET task{$current_task}_end_time = ?, task{$current_task}_score = ?, task{$current_task}_answers = ? WHERE id = ?");
           $stmt->bind_param("sisi", $current_time, $score, $answers_json, $_SESSION['participant_id']);
           $stmt->execute();

           if (count($_SESSION['completed_tasks']) < 3) {
               $_SESSION['current_task']++;
               $_SESSION['current_section'] = 'task_reading';
               $_SESSION['viewed_pages'] = [];

               $next_task = $_SESSION['current_task'];
               $next_task_start_time = date('Y-m-d H:i:s');
               $_SESSION["task{$next_task}_start_time"] = $next_task_start_time;

               $stmt = $db->prepare("UPDATE study_data SET task{$next_task}_start_time = ? WHERE id = ?");
               $stmt->bind_param("si", $next_task_start_time, $_SESSION['participant_id']);
               $result = $stmt->execute();

               error_log("Task {$current_task} completed. Score: {$score}. Update success: " . ($result ? 'Yes' : 'No'));

           } else {
               $total_score = array_sum($_SESSION['scores']);
               $stmt = $db->prepare("UPDATE study_data SET total_score = ?, completion_status = 'complete' WHERE id = ?");
               $stmt->bind_param("ii", $total_score, $_SESSION['participant_id']);
               $stmt->execute();

               $_SESSION['current_section'] = 'results';
           }

           header("Location: " . $_SERVER['PHP_SELF']);
           exit();
       } elseif (isset($_POST['process_text']) && $_SESSION['study_group'] === 'control') {
           $text = $_POST['text'];
           $feature = $_POST['feature'];
           echo json_encode(['result' => processWithAI($text, $feature)]);
           exit;
       } elseif (isset($_POST['view_page'])) {
           $page = $_POST['view_page'];
           if (!in_array($page, $_SESSION['viewed_pages'])) {
               $_SESSION['viewed_pages'][] = $page;
           }
           echo json_encode(['success' => true]);
           exit;
       } elseif (isset($_POST['chat_message'])) {
           $user_message = $_POST['chat_message'];
           $current_task = $_POST['current_task'];

           $prompt = "You are an AI assistant helping a user understand a research study with multiple tasks. The user is currently working on Task {$current_task}. Here's the content for the current task:\n\n";

           foreach ($task_content[$current_task] as $page_num => $page_content) {
               $prompt .= "Page {$page_num}: " . strip_tags($page_content) . "\n";
           }

           $prompt .= "\nUser question: {$user_message}\n\n";
           $prompt .= "Please provide a helpful response based on the task content. Focus on the current task's content. If the question is not related to the task, politely guide the user back to the study material.";

           try {
               $response = callOpenAI($prompt);
               echo json_encode(['response' => $response]);
           } catch (Exception $e) {
               http_response_code(500);
               echo json_encode(['error' => $e->getMessage()]);
           }
           exit;
       } 
        elseif (isset($_POST['save_task_data'])) {
            $task_number = intval($_POST['task_number']);
            $score = intval($_POST['score']);
            $answers = $_POST['answers'];
            $end_time = $_POST['end_time'];
            $participant_id = $_SESSION['participant_id'];
            $elapsed_time = floatval($_POST['elapsed_time']);
            $total_score = intval($_POST['total_score']);

            $completion_status = ($task_number == 3) ? 'complete' : 'incomplete';

            $stmt = $db->prepare("UPDATE study_data SET 
                task{$task_number}_end_time = ?, 
                task{$task_number}_score = ?, 
                task{$task_number}_answers = ?, 
                task{$task_number}_elapsed_time = ?, 
                total_score = ?, 
                completion_status = ? 
                WHERE id = ?");
            $stmt->bind_param("sisidsi", $end_time, $score, $answers, $elapsed_time, $total_score, $completion_status, $participant_id);

            if ($stmt->execute()) {
                if ($task_number < 3) {
                    $next_task = $task_number + 1;
                    $start_time = date('Y-m-d H:i:s');
                    $stmt = $db->prepare("UPDATE study_data SET task{$next_task}_start_time = ? WHERE id = ?");
                    $stmt->bind_param("si", $start_time, $participant_id);
                    $stmt->execute();
                }
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to save task data']);
            }
            exit;
        }
       elseif (isset($_POST['set_next_task_start_time'])) {
           $task_number = intval($_POST['task_number']);
           $start_time = $_POST['start_time'];
           $participant_id = $_SESSION['participant_id'];

           $stmt = $db->prepare("UPDATE study_data SET task{$task_number}_start_time = ? WHERE id = ?");
           $stmt->bind_param("si", $start_time, $participant_id);

           if ($stmt->execute()) {
                   echo json_encode(['success' => true]);
               } else {
                   echo json_encode(['success' => false, 'message' => 'Failed to set next task start time']);
           }
           exit;
       }

   }

   function getCurrentContent($current_section, $current_task) {
       ob_start();
       if ($current_section === 'registration') {
           include 'views/registration.php';
       } elseif ($current_section === 'tool_description') {
           include 'views/tool_description.php';
       } elseif ($current_section === 'task_reading') {
           include 'views/task_reading.php';
       } elseif ($current_section === 'results') {
           include 'views/results.php';
       } else {
           echo "<p>Invalid section.</p>";
       }
       return ob_get_clean();
   }

   $content = getCurrentContent($current_section, $current_task);
   ?>
<!DOCTYPE html>
<html lang="en">
   <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>Reading Comprehension Study</title>
      <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.9.359/pdf.min.js"></script>
      <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.9.359/pdf.worker.min.js"></script>
      <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
      <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
      <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
      <link href="https://unpkg.com/material-components-web@latest/dist/material-components-web.min.css" rel="stylesheet">
      <script src="https://unpkg.com/material-components-web@latest/dist/material-components-web.min.js"></script>
      <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
      <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
      <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
      <link href="https://unpkg.com/material-components-web@latest/dist/material-components-web.min.css" rel="stylesheet">
      <script src="https://unpkg.com/material-components-web@latest/dist/material-components-web.min.js"></script>
      <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.9.359/pdf.min.js"></script>
      <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.9.359/pdf.worker.min.js"></script>
      <style>
         :root {
         --primary-color: rgb(32, 38, 84);
         --secondary-color: #174ea6;
         --white-color: #ffffff;
         --black-color: #000000;
         --light-gray: #f5f5f5;
         --border-color: #e0e0e0;
         --sidebar-color: #e8eaed;
         --text-color: #202124;
         --white-color: #ffffff;
         --light-gray: #e8eaed;
         --border-color: #dadce0;
         --error-color: #d93025;
         --success-color: #1e8e3e;
         --dark-blue:#202654;
         --text-color-button:#ffffff;
         }
         body, html {
         margin: 0;
         padding: 0;
         height: 100%;
         font-family: 'Roboto', Arial, sans-serif;
         font-size: 14px;
         background-color: var(--background-color);
         color: var(--text-color);
         line-height: 1.5;
         -webkit-font-smoothing: antialiased;
         }
         h1, h2, h3, h4, h5, h6,
         .mdc-typography--headline3,
         .mdc-typography--headline4,
         .mdc-typography--headline5 {
         color: var(--primary-color);
         margin-bottom: 0.5em;
         font-weight: 500;
         }
         .container {
         max-width: 1200px;
         margin: 40px auto;
         padding: 30px;
         background-color: var(--white-color);
         box-shadow: 0 1px 2px 0 rgba(60,64,67,0.3), 0 1px 3px 1px rgba(60,64,67,0.15);
         border-radius: 8px;
         }
         h1, h2, h3, h4, h5, h6 {
         margin-bottom: 0.5em;
         font-weight: 500;
         color: var(--primary-color);
         }
         .mdc-typography--headline3 {
         font-size: 3rem;
         line-height: 3.125rem;
         }
         .mdc-typography--headline4 {
         font-size: 2.125rem;
         line-height: 2.5rem;
         }
         .mdc-typography--headline5 {
         font-size: 1.5rem;
         line-height: 2rem;
         }
         .mdc-typography--body1 {
         font-size: 0.8rem;
         line-height: 1.5rem;
         color: var(--black-color);
         }
        button,
        .mdc-button,
        .mdc-button--raised {
          background-color: var(--primary-color);
          color: var(--white-color);
          border: none;
          padding: 0 16px;
          border-radius: 4px;

          transition: background-color 0.3s ease;
          cursor: pointer;
          height: 36px;
          display: inline-flex;
          align-items: center;
          justify-content: center;
        }
         button:hover,
         .mdc-button:hover,
         .mdc-button--raised:hover {
         background-color: rgba(32, 38, 84, 0.8);
         }
         .mdc-button--raised.mdc-ripple-upgraded--background-focused,
         .mdc-button--raised:not(.mdc-ripple-upgraded):focus {
         background-color: var(--primary-color);
         }
         .mdc-text-field,
         .mdc-select {
         width: 100%;
         margin-bottom: 24px;
         }
         .mdc-text-field--outlined,
         .mdc-select--outlined {
         --mdc-theme-primary: var(--primary-color);
         --mdc-theme-error: #b00020;
         }
         .mdc-text-field__input {
         padding: 12px 16px;
         font-size: 16px;
         }
         .mdc-floating-label {
         font-size: 1rem;
         color: rgba(0, 0, 0, 0.6);
         }
         .mdc-list {
         padding: 0;
         margin: 0 0 24px 0;
         list-style-type: none;
         }
         .mdc-list-item {
         height: auto !important;
         padding: 12px 16px;
         display: flex;
         align-items: flex-start;
         }
         .mdc-list-item__graphic {
         color: var(--primary-color);
         margin-right: 16px;
         font-size: 24px;
         }
         .mdc-form-field {
         display: flex;
         align-items: center;
         margin-bottom: 16px;
         }
         .mdc-checkbox {
         margin-right: 8px;
         }
         button:hover, .mdc-button:hover {
         background-color: var(--secondary-color);
         }
         .mdc-button--raised {
         box-shadow: 0 3px 1px -2px rgba(0,0,0,0.2), 0 2px 2px 0 rgba(0,0,0,0.14), 0 1px 5px 0 rgba(0,0,0,0.12);
         }
         .mdc-text-field, .mdc-select {
         width: 100%;
         margin-bottom: 16px;
         }
         .mdc-text-field--outlined {
         --mdc-theme-primary: var(--primary-color);
         --mdc-theme-error: var(--error-color);
         }
         .mdc-text-field--outlined .mdc-text-field__input {
            height: unset !important;
        }
         .mdc-select--outlined {
         --mdc-theme-primary: var(--primary-color);
         --mdc-theme-error: var(--error-color);
         }
         .mdc-text-field__input {
         padding: 12px 16px;
         }
         .mdc-floating-label {
         font-size: 1rem;
         }
         .study-container {
         display: flex;
         width: 100%;
         height: 100vh;
         overflow: hidden;
         }
         .study-sidebar {
         flex: 0 0 300px;
         background-color: var(--sidebar-color);
         color: var(--black-color);
         padding: 2rem 1.5rem;
         box-shadow: 2px 0 5px rgba(0,0,0,0.1);
         display: flex;
         flex-direction: column;
         border: 1px solid #c8cacc;
         }
         .study-main-content {
         flex-grow: 1;
         padding: 20px;
         overflow: auto;
         display: flex;
         flex-direction: column;
         }
         #study-pdf-viewer {
         flex-grow: 1;
         display: flex;
         flex-direction: column;
         align-items: center;
         overflow-y: auto;
         }
         .study-page {
         margin-bottom: 20px;
         box-shadow: 0 1px 3px rgba(0,0,0,0.12), 0 1px 2px rgba(0,0,0,0.24);
         }
         .study-textLayer {
         opacity: 0.2;
         line-height: 1.0;
         }
         .study-textLayer > span {
         color: transparent;
         position: absolute;
         white-space: pre;
         cursor: text;
         transform-origin: 0% 0%;
         }
         .study-textLayer ::selection {
         background: rgba(0,0,255,0.3);
         }
         #study-task-navigation {
         display: flex;
         justify-content: space-between;
         margin-top: 20px;
         }
         #study-task-navigation button {
         background-color: var(--primary-color);
         color: var(--white-color);
         border: none;
         padding: 10px 20px;
         border-radius: 4px;
         cursor: pointer;
         transition: background-color 0.3s ease;
         }
         #study-task-navigation button:hover {
         background-color: var(--secondary-color);
         }
         #study-task-navigation button:disabled {
         background-color: var(--dark-blue);
         color: var(--text-color-button);
         cursor: not-allowed;
         }
         .study-floating-menu {
         position: fixed;
         right: 50px;
         top: 50%;
         transform: translateY(-50%);
         background-color: var(--primary-color);
         border-radius: 8px;
         padding: 10px;
         display: flex;
         flex-direction: column;
         align-items: center;
         box-shadow: 0 2px 10px rgba(0,0,0,0.2);
         }
         .study-floating-menu button {
         background-color: transparent;
         color: var(--white-color);
         font-size: 20px;
         width: 40px;
         height: 40px;
         margin: 5px 0;
         border-radius: 50%;
         display: flex;
         align-items: center;
         justify-content: center;
         border: none;
         cursor: pointer;
         }
         .study-floating-menu button:hover {
         background-color: rgba(255,255,255,0.1);
         }
         .study-chat-container {
         background-color: var(--white-color);
         border-radius: 8px;
         display: flex;
         flex-direction: column;
         height: 400px;
         margin-top: 20px;
         box-shadow: 0 1px 3px rgba(0,0,0,0.12), 0 1px 2px rgba(0,0,0,0.24);
         }
         .study-chat-messages {
         flex-grow: 1;
         overflow-y: auto;
         padding: 15px;
         display: flex;
         flex-direction: column;
         }
         .study-message {
         max-width: 70%;
         padding: 10px;
         margin-bottom: 10px;
         border-radius: 18px;
         line-height: 1.4;
         }
         .study-user-message {
         align-self: flex-end;
         background-color: var(--primary-color);
         color: var(--white-color);
         }
         .study-bot-message {
         align-self: flex-start;
         background-color: var(--light-gray);
         color: var(--text-color);
         }
         .study-chat-input {
         display: flex;
         padding: 10px;
         background-color: var(--light-gray);
         border-bottom-left-radius: 8px;
         border-bottom-right-radius: 8px;
         }
         .study-chat-input input {
         flex-grow: 1;
         padding: 10px;
         border: 1px solid var(--border-color);
         border-radius: 20px;
         margin-right: 10px;
         font-size: 14px;
         }
         .study-chat-button {
         background-color: var(--primary-color);
         color: var(--white-color);
         border: none;
         padding: 10px;
         border-radius: 50%;
         cursor: pointer;
         transition: background-color 0.3s;
         width: 40px;
         height: 40px;
         display: flex;
         align-items: center;
         justify-content: center;
         }
         .study-chat-button:hover {
         background-color: var(--secondary-color);
         }
         .mdc-form-field {
         display: flex;
         align-items: center;
         margin-bottom: 10px;
         font-size:0.8rem;
         }
         .mdc-checkbox {
         margin-right: 8px;
         }
         .mdc-list {
         padding: 0;
         margin: 0;
         list-style-type: none;
         }
         .mdc-list-item {
         height: 48px;
         display: flex;
         align-items: center;
         padding: 0 16px;
         }
         .mdc-list-item__graphic {
         margin-right: 32px;
         display: inline-flex;
         }
         .notification {
         position: fixed;
         bottom: 20px;
         left: 50%;
         transform: translateX(-50%);
         background-color: var(--primary-color);
         color: var(--white-color);
         padding: 10px 20px;
         border-radius: 4px;
         box-shadow: 0 2px 4px rgba(0,0,0,0.2);
         z-index: 1000;
         display: none;
         }
         #results-summary, #detailed-results {
         margin-bottom: 20px;
         }
         #review-timer {
         font-size: 1.2em;
         font-weight: bold;
         margin-bottom: 20px;
         color: var(--primary-color);
         }
         @media (max-width: 768px) {
         .container {
         padding: 20px;
         margin: 20px auto;
         }
         .study-container {
         flex-direction: column;
         }
         .study-sidebar {
         flex: 0 0 auto;
         width: 100%;
         }
         .study-main-content {
         padding: 10px;
         }
         .study-floating-menu {
         position: static;
         transform: none;
         flex-direction: row;
         justify-content: center;
         margin-top: 20px;
         }
         .study-floating-menu button {
         margin: 0 5px;
         }
         }
         .mdc-button--raised:not(:disabled) {
         background-color:#202654!important;
         }
         .study-sidebar {
         min-width: 310px;
         }
         .study-page {
         margin-bottom: 20px;
         }
         .question-page {
         background-color: #f0f0f0;
         padding: 20px;
         border-radius: 8px;
         }
         .question-card {
         background-color: #f2f2f2;
         padding: 15px;
         margin-bottom: 15px;
         border-radius: 4px;
         box-shadow: 0 2px 4px rgba(0,0,0,0.1);
         }
         .page-container {
         display: flex;
         flex-direction: column;
         align-items: center;
         margin-bottom: 40px;
         }
         .question-page {
         background-color: #f0f0f0;
         border: 1px solid #ddd;
         border-radius: 5px;
         box-shadow: 0 2px 5px rgba(0,0,0,0.1);
         }
         .question-card {
         margin-bottom:
         }
         :root {
         --primary-color: rgb(32, 38, 84);
         --secondary-color: #174ea6;
         --white-color: #ffffff;
         --black-color: #000000;
         --light-gray: #f5f5f5;
         --border-color: #e0e0e0;
         --sidebar-color: #e8eaed;
         --text-color: #202124;
         --error-color: #d93025;
         --success-color: #1e8e3e;
         --dark-blue: #202654;
         --text-color-button: #ffffff;
         }
         body, html {
         margin: 0;
         padding: 0;
         height: 100%;
         font-family: 'Roboto', Arial, sans-serif;
         font-size: 14px;
         background-color: var(--white-color);
         color: var(--text-color);
         line-height: 1.5;
         -webkit-font-smoothing: antialiased;
         }
         .container {
         max-width: 1200px;
         margin: 40px auto;
         padding: 30px;
         background-color: var(--white-color);
         box-shadow: 0 1px 2px 0 rgba(60,64,67,0.3), 0 1px 3px 1px rgba(60,64,67,0.15);
         border-radius: 8px;
         }
         h1, h2, h3, h4, h5, h6,
         .mdc-typography--headline3,
         .mdc-typography--headline4,
         .mdc-typography--headline5 {
         color: var(--primary-color);
         margin-bottom: 0.5em;
         font-weight: 500;
         }
         .mdc-typography--headline4 {
         font-size: 2.125rem;
         line-height: 2.5rem;
         }
         .mdc-typography--headline5 {
         font-size: 1.5rem;
         line-height: 2rem;
         }
         .mdc-typography--body1 {
         font-size: 1rem;
         line-height: 1.5rem;
         color: var(--black-color);
         }
         button,
         .mdc-button,
         .mdc-button--raised {
         background-color: var(--primary-color);
         color: var(--white-color);
         border: none;
         padding: 0 16px;
         border-radius: 4px;
         transition: background-color 0.3s ease;
         cursor: pointer;
         height: 36px;
         display: inline-flex;
         align-items: center;
         justify-content: center;
         }
         button:hover,
         .mdc-button:hover,
         .mdc-button--raised:hover {
         background-color: rgba(32, 38, 84, 0.8);
         }
         .results-summary {
         background-color: var(--light-gray);
         padding: 20px;
         border-radius: 8px;
         margin-bottom: 20px;
         box-shadow: 0 2px 4px rgba(0,0,0,0.1);
         }
         .question-result {
         background-color: var(--white-color);
         border: 1px solid var(--border-color);
         border-radius: 4px;
         padding: 15px;
         margin-bottom: 15px;
         transition: all 0.3s ease;
         }
         .question-result:hover {
         box-shadow: 0 4px 8px rgba(0,0,0,0.1);
         }
         .question-result.correct {
         border-left: 5px solid var(--success-color);
         }
         .question-result.incorrect {
         border-left: 5px solid var(--error-color);
         }
         #showDetailedResults,
         #completeStudyBtn {
         margin-top: 20px;
         width: 100%;
         }
         .study-page.pdf-page{
             border: 1px solid #c8cacc;
        }
         #detailedResults {
         margin-top: 20px;
         padding: 20px;
         background-color: var(--light-gray);
         border-radius: 8px;
         box-shadow: 0 2px 4px rgba(0,0,0,0.1);
         }
         .highlight {
         background-color: yellow;
         padding: 2px 4px;
         border-radius: 2px;
         }
         @media (max-width: 768px) {
         .container {
         padding: 20px;
         margin: 20px auto;
         }
         }
         @keyframes slideDown {
         from { opacity: 0; transform: translateY(-20px); }
         to { opacity: 1; transform: translateY(0); }
         }
         #detailedResults {
         animation: slideDown 0.5s ease-out;
         }
         .result-indicator {
         font-weight: bold;
         margin-right: 10px;
         }
         .result-indicator.correct {
         color: var(--success-color);
         }
         .result-indicator.incorrect {
         color: var(--error-color);
         }
         .question-text {
         font-weight: 500;
         margin-bottom: 10px;
         }
         .answer-text {
         margin-bottom: 5px;
         }
         .explanation-text {
         font-style: italic;
         color: var(--secondary-color);
         margin-top: 10px;
         }

         #study-task-navigation button:disabled {
    background-color: #d1d1d1 !important;
    color: var(--text-color-button);
    cursor: not-allowed;
}
        #study-task-navigation button:disabled {
          position: relative;
          cursor: not-allowed;
        }
         #study-task-navigation button:disabled:hover::after {
          content: 'Please complete by answering all questions to proceed';
          position: absolute;
          background-color: var(--dark-blue);
          color: #ffffff;
          padding: 5px;
          border-radius: 4px;
          bottom: 140%;  
          left: 50%;
          transform: translateX(-50%);
          white-space: normal; 
          min-width: 250px; 
          z-index: 1000;
          text-align: center; 
          box-shadow: 0px 2px 10px rgba(0, 0, 0, 0.5); 
        }

        #study-task-navigation button:disabled:hover::before {
          content: '';
          position: absolute;
          bottom: 110%;
          left: 50%;
          transform: translateX(-50%);
          border-width: 5px;
          border-style: solid;
          border-color: rgba(0,0,0,0.8) transparent transparent transparent; 
          z-index: 1000;
        }
        #study-prev-task {
            background-color: #d1d1d1 !important;
            color: #888888 !important;
            cursor: not-allowed !important;
            opacity: 0.7;
        }

        #study-prev-task:hover {
            background-color: #d1d1d1 !important;
        }

        #question-progress {
            width: 100%;
            background-color: #f0f0f0;
            border-radius: 5px;
        }

        #questionProgressBar {
            width: 0;
            height: 20px;
            background-color: #4CAF50;
            border-radius: 5px;
            text-align: center;
            line-height: 20px;
            color: white;
        }
        .study-textLayer > span {
    color: #000000;
    position: absolute;
    white-space: pre;
    cursor: text;
    transform-origin: 0% 0%;
    background: white;
}
.study-textLayer {
    opacity: 1;
    line-height: 1.0;
}
      </style>
      <script async defer src="https://tools.luckyorange.com/core/lo.js?site-id=209d14e7"></script>
      <!-- Hotjar Tracking Code for Control -->
        <script>
            (function(h,o,t,j,a,r){
                h.hj=h.hj||function(){(h.hj.q=h.hj.q||[]).push(arguments)};
                h._hjSettings={hjid:5164422,hjsv:6};
                a=o.getElementsByTagName('head')[0];
                r=o.createElement('script');r.async=1;
                r.src=t+h._hjSettings.hjid+j+h._hjSettings.hjsv;
                a.appendChild(r);
            })(window,document,'https://static.hotjar.com/c/hotjar-','.js?sv=');
        </script>
   </head>
   <body class="mdc-typography">

      <?php if ($current_section === 'registration'): ?>
      <div class="container">
         <h1 class="mdc-typography--headline3">Welcome to the Reading Comprehension Study</h1>
         <p class="mdc-typography--body1">Thank you for participating in this study! The purpose of this research is to investigate reading comprehension skills. Below you will find the consent form and demographic details needed to participate.</p>
         <h3 class="mdc-typography--headline4">Overview of the Study</h3>
         <ul class="mdc-list mdc-list--dense">
            <li class="mdc-list-item" tabindex="0">
               <span class="mdc-list-item__graphic material-icons" aria-hidden="true" style="color: #202654; margin-right: 8px;">assignment</span>
               <span class="mdc-list-item__text">You will be asked to complete several questionnaires that assess your reading comprehension and reading experience.</span>
            </li>
            <li class="mdc-list-item" tabindex="0">
               <span class="mdc-list-item__graphic material-icons" aria-hidden="true" style="color: #202654; margin-right: 8px;">book</span>
               <span class="mdc-list-item__text">You will read three documents and answer questions about them to test your comprehension skills.</span>
            </li>
            <li class="mdc-list-item" tabindex="0">
               <span class="mdc-list-item__graphic material-icons" aria-hidden="true" style="color: #202654; margin-right: 8px;">timer</span>
               <span class="mdc-list-item__text">The study has a total time limit of 40 minutes 30 minutes for reading comprehension test and 10 minutes for a survey about the reading experience. You will read three documents and answer questions about them to test your comprehension skills. You can see on the left side how much time is remaining for the reading comprehension task when you transit to the next question. Please ensure that you stay within the allotted time.</span>
            </li>
            <li class="mdc-list-item" tabindex="0">
               <span class="mdc-list-item__graphic material-icons" aria-hidden="true" style="color: #202654; margin-right: 8px;">check_circle</span>
               <span class="mdc-list-item__text">Please complete each section carefully and answer all questions truthfully.</span>
            </li>
         </ul>

         <h2 class="mdc-typography--headline5">Demographic Information</h2>
         <p class="mdc-typography--body1">Please fill in the following information to help us better understand the participants of this study.</p>
         <form method="POST" id="registrationForm">
            <div class="mdc-text-field mdc-text-field--outlined" data-mdc-auto-init="MDCTextField">
               <input type="text" id="prolific_id" name="prolific_id" class="mdc-text-field__input" required>
               <div class="mdc-notched-outline">
                  <div class="mdc-notched-outline__leading"></div>
                  <div class="mdc-notched-outline__notch">
                     <label for="prolific_id" class="mdc-floating-label">Prolific ID</label>
                  </div>
                  <div class="mdc-notched-outline__trailing"></div>
               </div>
            </div>
            <div class="mdc-text-field mdc-text-field--outlined" data-mdc-auto-init="MDCTextField">
               <input type="number" id="age" name="age" class="mdc-text-field__input" required min="18" max="100">
               <div class="mdc-notched-outline">
                  <div class="mdc-notched-outline__leading"></div>
                  <div class="mdc-notched-outline__notch">
                     <label for="age" class="mdc-floating-label">Age</label>
                  </div>
                  <div class="mdc-notched-outline__trailing"></div>
               </div>
            </div>
            <div class="mdc-select mdc-select--outlined" data-mdc-auto-init="MDCSelect">
               <input type="hidden" name="gender" id="gender-hidden">
               <div class="mdc-select__anchor">
                  <span class="mdc-notched-outline">
                  <span class="mdc-notched-outline__leading"></span>
                  <span class="mdc-notched-outline__notch">
                  <span id="gender-label" class="mdc-floating-label">Gender</span>
                  </span>
                  <span class="mdc-notched-outline__trailing"></span>
                  </span>
                  <span class="mdc-select__selected-text-container">
                  <span class="mdc-select__selected-text"></span>
                  </span>
                  <span class="mdc-select__dropdown-icon">
                     <svg class="mdc-select__dropdown-icon-graphic" viewBox="7 10 10 5">
                        <polygon class="mdc-select__dropdown-icon-inactive" stroke="none" fill-rule="evenodd" points="7 10 12 15 17 10"></polygon>
                        <polygon class="mdc-select__dropdown-icon-active" stroke="none" fill-rule="evenodd" points="7 15 12 10 17 15"></polygon>
                     </svg>
                  </span>
               </div>
               <div class="mdc-select__menu mdc-menu mdc-menu-surface mdc-menu-surface--fullwidth">
                  <ul class="mdc-list" role="listbox" aria-label="Gender select">
                     <li class="mdc-list-item" data-value="male" role="option">
                        <span class="mdc-list-item__ripple"></span>
                        <span class="mdc-list-item__text">Male</span>
                     </li>
                     <li class="mdc-list-item" data-value="female" role="option">
                        <span class="mdc-list-item__ripple"></span>
                        <span class="mdc-list-item__text">Female</span>
                     </li>
                     <li class="mdc-list-item" data-value="other" role="option">
                        <span class="mdc-list-item__ripple"></span>
                        <span class="mdc-list-item__text">Other</span>
                     </li>
                     <li class="mdc-list-item" data-value="prefer_not_to_say" role="option">
                        <span class="mdc-list-item__ripple"></span>
                        <span class="mdc-list-item__text">Prefer not to say</span>
                     </li>
                  </ul>
               </div>
            </div>

             <div class="mdc-select mdc-select--outlined" data-mdc-auto-init="MDCSelect">
               <input type="hidden" name="education_level" id="education-level-hidden">
               <div class="mdc-select__anchor">
                  <span class="mdc-notched-outline">
                  <span class="mdc-notched-outline__leading"></span>
                  <span class="mdc-notched-outline__notch">
                  <span id="education-label" class="mdc-floating-label">Education Level</span>
                  </span>
                  <span class="mdc-notched-outline__trailing"></span>
                  </span>
                  <span class="mdc-select__selected-text-container">
                  <span class="mdc-select__selected-text"></span>
                  </span>
                  <span class="mdc-select__dropdown-icon">
                     <svg class="mdc-select__dropdown-icon-graphic" viewBox="7 10 10 5">
                        <polygon class="mdc-select__dropdown-icon-inactive" stroke="none" fill-rule="evenodd" points="7 10 12 15 17 10"></polygon>
                        <polygon class="mdc-select__dropdown-icon-active" stroke="none" fill-rule="evenodd" points="7 15 12 10 17 15"></polygon>
                     </svg>
                  </span>
               </div>
               <div class="mdc-select__menu mdc-menu mdc-menu-surface mdc-menu-surface--fullwidth">
                  <ul class="mdc-list" role="listbox" aria-label="Education Level select">
                     <li class="mdc-list-item" data-value="high_school" role="option">
                        <span class="mdc-list-item__ripple"></span>
                        <span class="mdc-list-item__text">High School</span>
                     </li>
                     <li class="mdc-list-item" data-value="bachelors" role="option">
                        <span class="mdc-list-item__ripple"></span>
                        <span class="mdc-list-item__text">Bachelor's Degree</span>
                     </li>
                     <li class="mdc-list-item" data-value="masters" role="option">
                        <span class="mdc-list-item__ripple"></span>
                        <span class="mdc-list-item__text">Master's Degree</span>
                     </li>
                     <li class="mdc-list-item" data-value="phd" role="option">
                        <span class="mdc-list-item__ripple"></span>
                        <span class="mdc-list-item__text">PhD or Doctorate</span>
                     </li>
                     <li class="mdc-list-item" data-value="other" role="option">
                        <span class="mdc-list-item__ripple"></span>
                        <span class="mdc-list-item__text">Other</span>
                     </li>
                  </ul>
               </div>
            </div>

            <div class="mdc-select mdc-select--outlined" data-mdc-auto-init="MDCSelect">
               <input type="hidden" name="english_proficiency" id="english-proficiency-hidden">
               <div class="mdc-select__anchor">
                  <span class="mdc-notched-outline">
                  <span class="mdc-notched-outline__leading"></span>
                  <span class="mdc-notched-outline__notch">
                  <span id="english-proficiency-label" class="mdc-floating-label">English Proficiency</span>
                  </span>
                  <span class="mdc-notched-outline__trailing"></span>
                  </span>
                  <span class="mdc-select__selected-text-container">
                  <span class="mdc-select__selected-text"></span>
                  </span>
                  <span class="mdc-select__dropdown-icon">
                     <svg class="mdc-select__dropdown-icon-graphic" viewBox="7 10 10 5">
                        <polygon class="mdc-select__dropdown-icon-inactive" stroke="none" fill-rule="evenodd" points="7 10 12 15 17 10"></polygon>
                        <polygon class="mdc-select__dropdown-icon-active" stroke="none" fill-rule="evenodd" points="7 15 12 10 17 15"></polygon>
                     </svg>
                  </span>
               </div>
               <div class="mdc-select__menu mdc-menu mdc-menu-surface mdc-menu-surface--fullwidth">
                  <ul class="mdc-list" role="listbox" aria-label="English Proficiency select">
                     <li class="mdc-list-item" data-value="native" role="option">
                        <span class="mdc-list-item__ripple"></span>
                        <span class="mdc-list-item__text">Native</span>
                     </li>
                     <li class="mdc-list-item" data-value="fluent" role="option">
                        <span class="mdc-list-item__ripple"></span>
                        <span class="mdc-list-item__text">Fluent</span>
                     </li>
                     <li class="mdc-list-item" data-value="intermediate" role="option">
                        <span class="mdc-list-item__ripple"></span>
                        <span class="mdc-list-item__text">Intermediate</span>
                     </li>
                     <li class="mdc-list-item" data-value="beginner" role="option">
                        <span class="mdc-list-item__ripple"></span>
                        <span class="mdc-list-item__text">Beginner</span>
                     </li>
                  </ul>
               </div>
            </div>
            <h2 class="mdc-typography--headline5">Consent Form</h2>
            <p class="mdc-typography--body1">
               By participating in this study, you agree to the following:
            <ul style="list-style-type: none; padding-left: 0;">
               <li><i class="fas fa-check-circle" style="color: green; margin-right: 8px;"></i> You are voluntarily participating in this study.</li>
               <li><i class="fas fa-check-circle" style="color: green; margin-right: 8px;"></i> You can withdraw from the study at any time without any consequences.</li>
               <li><i class="fas fa-check-circle" style="color: green; margin-right: 8px;"></i> Your data will remain confidential and will only be used for research purposes.</li>
               <li><i class="fas fa-check-circle" style="color: green; margin-right: 8px;"></i> The study involves reading tasks and answering questions related to reading comprehension.</li>
               <li><i class="fas fa-check-circle" style="color: green; margin-right: 8px;"></i> There are no known risks involved in participating in this study.</li>
            </ul>
            </p>
            <div class="mdc-form-field" style="display: inline-block;">
               <div class="mdc-checkbox">
                  <input type="checkbox" class="mdc-checkbox__native-control" id="consent" name="consent" value="yes" required>
                  <div class="mdc-checkbox__background">
                     <svg class="mdc-checkbox__checkmark" viewBox="0 0 24 24">
                        <path class="mdc-checkbox__checkmark-path" fill="none" d="M1.73,12.91 8.1,19.28 22.79,4.59"/>
                     </svg>
                     <div class="mdc-checkbox__mixedmark"></div>
                  </div>
               </div>
               <label for="consent">I have read and understood the consent form and agree to participate in this study.</label>
            </div>
            <button class="mdc-button mdc-button--raised" type="submit" name="register">
            <span class="mdc-button__label">Start Study</span>
            </button>
         </form>
      </div>
      <?php elseif ($current_section === 'tool_description'): ?>
      <div class="container">
         <h1 class="mdc-typography--headline4">Study Tool Description</h1>
         <div class="tool-demo">
            <h2 class="mdc-typography--headline5">How It Works</h2>
            <div class="demo-container">
               <img src="control.png" alt="Tool Demo" class="demo-image">
               <ol class="mdc-list">
                  <li class="mdc-list-item">
                     <span class="mdc-list-item__graphic material-icons">looks_one</span>
                     <span class="mdc-list-item__text">Read the provided PDF document</span>
                  </li>
                  <li class="mdc-list-item">
                     <span class="mdc-list-item__graphic material-icons">looks_two</span>
                     <span class="mdc-list-item__text">Answer comprehension questions</span>
                  </li>
                  <li class="mdc-list-item">
                     <span class="mdc-list-item__graphic material-icons">looks_3</span>
                     <span class="mdc-list-item__text">Proceed to the next task</span>
                  </li>
               </ol>
            </div>
         </div>
         <h2 class="mdc-typography--headline5">PDF Reader</h2>
         <p class="mdc-typography--body1">You will be reading documents in a PDF format. The reader allows you to:</p>
         <ul class="mdc-list">
            <li class="mdc-list-item">
               <span class="mdc-list-item__graphic material-icons">zoom_in</span>
               <span class="mdc-list-item__text">Zoom in and out for comfortable reading</span>
            </li>
            <li class="mdc-list-item">
               <span class="mdc-list-item__graphic material-icons">navigate_next</span>
               <span class="mdc-list-item__text">Navigate between pages</span>
            </li>
            <li class="mdc-list-item">
               <span class="mdc-list-item__graphic material-icons">fullscreen</span>
               <span class="mdc-list-item__text">View in full screen mode</span>
            </li>
         </ul>
         <h2 class="mdc-typography--headline5">Answering Questions and Moving to Next Task</h2>
         <p class="mdc-typography--body1">After reading each document:</p>
         <ul class="mdc-list">
            <li class="mdc-list-item">
               <span class="mdc-list-item__graphic material-icons">quiz</span>
               <span class="mdc-list-item__text">Answer all comprehension questions</span>
            </li>
            <li class="mdc-list-item">
               <span class="mdc-list-item__graphic material-icons">check_circle</span>
               <span class="mdc-list-item__text">The 'Next Task' button will activate once all questions are answered</span>
            </li>
            <li class="mdc-list-item">
               <span class="mdc-list-item__graphic material-icons">arrow_forward</span>
               <span class="mdc-list-item__text">Click 'Next Task' to proceed to the next document</span>
            </li>
         </ul>
         <p class="mdc-typography--body1 important-note">Please answer all questions to the best of your ability based on the information provided in the document. You must answer all questions before proceeding to the next task.</p>
         <form method="POST">
            <button class="mdc-button mdc-button--raised" id="start-reading-task" name="start_reading_task" type="submit">
            <span class="mdc-button__label">Begin Reading Task</span>
            </button>
         </form>
      </div>
      <style>
         .tool-demo {
         margin-bottom: 2rem;
         background-color: var(--light-gray);
         padding: 1.5rem;
         border-radius: 8px;
         box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
         }
         .demo-container {
         display: flex;
         align-items: center;
         justify-content: space-between;
         margin-top: 1rem;
         }
         .demo-image {
         width: 60%;
         border-radius: 8px;
         box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
         }
         .demo-container .mdc-list {
         width: 35%;
         }
         .important-note {
         font-style: italic;
         color: var(--error-color);
         margin-top: 1rem;
         margin-bottom: 1rem;
         }
            .important-note {
         background-color: #fff3cd;
         border-left: 5px solid #ffeeba;
         padding: 15px;
         margin-top: 20px;
         border-radius: 4px;
         }
         @media (max-width: 768px) {
         .demo-container {
         flex-direction: column;
         }
         .demo-image, .demo-container .mdc-list {
         width: 100%;
         }
         .demo-container .mdc-list {
         margin-top: 1rem;
         }
         }
      </style>
      <?php elseif ($current_section === 'task_reading'): ?>
      <div class="study-container">
         <div class="study-sidebar">
            <h2 class="mdc-typography--headline4">Reading Task Progress</h2>
            <div id="task-timer" style="display:none"></div>
            <div id="question-progress">
               <div id="questionProgressBar" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
            </div>

         <div id="study-task-info">
                Task <?php echo $current_task; ?> of 3<br>
                <?php
                    $completed_tasks = count($_SESSION['completed_tasks']);
                    echo "Completed: $completed_tasks / 3";
                ?>

            </div>
            <div style="padding-bottom:7px">
                 <p>Please ensure you read each passage and answer the corresponding questions after each passage and wait for the time to elapse to move to the next task.</p>
            </div>

            <div id="study-task-navigation">
               <button id="study-prev-task" <?php echo $current_task == 1 ? 'disabled' : ''; ?>>Previous Task</button>
               <button id="study-next-task" <?php echo $current_task == 3 ? 'disabled' : ''; ?>>Next Task</button>
            </div>
             <div id="notification-container"></div>

         </div>
         <div class="study-main-content">
            <div id="study-pdf-viewer"></div>
            <div class="study-chat-container" style="display:none !important">
               <div class="study-chat-messages" id="study-chat-messages"></div>
               <div class="study-chat-input">
                  <input type="text" id="study-chat-input" placeholder="Type your message...">
                  <button class="study-chat-button" id="study-send-button"><i class="fas fa-paper-plane"></i></button>
               </div>
            </div>
         </div>
      </div>
      <div class="study-floating-menu">
         <button onclick="studyToggleFullscreen()" title="Toggle Fullscreen"><i class="fas fa-expand"></i></button>
         <button onclick="studyZoomIn()" title="Zoom In"><i class="fas fa-search-plus"></i></button>
         <button onclick="studyZoomOut()" title="Zoom Out"><i class="fas fa-search-minus"></i></button>
         <button onclick="studyResetZoom()" title="Reset Zoom"><i class="fas fa-sync-alt"></i></button>
      </div>
          <script>

const IntelligentStudyMonitor = (function() {
    let lastScrollTime = Date.now();
    let lastAnswerTime = Date.now();
    let answersWithoutScroll = 0;
    let recentAnswerTimes = [];
    let lastReminderTime = 0;
    let goodBehaviorStreak = 0;
    let badBehaviorCount = 0;
    let answerPattern = [];
    let startTime = Date.now();

    const RAPID_CLICK_THRESHOLD = 500; 
    const RAPID_CLICK_COUNT_THRESHOLD = 3;
    const MIN_TIME_BETWEEN_REMINDERS = 30000; 
    const PATTERN_LENGTH_THRESHOLD = 5;
    const MAX_STUDY_TIME = 40 * 60 * 1000; 

    const warningMessages = [
        "Please take more time to consider each question carefully.",
        "Your answer pattern suggests you might not be reading the questions. Please take your time.",
        "Remember, your careful consideration is crucial for the study's success.",
        "Rushing through questions may affect the quality of the study. Please slow down.",
        "Your responses seem too quick. Take a moment to think about each question."
    ];

    const timeWarningMessages = [
        "You're approaching the time limit. Please ensure you've answered all questions thoughtfully.",
        "Time is running out. Make sure to review your answers if you haven't already.",
        "Only a few minutes left. Double-check your responses for accuracy."
    ];

    function createChatBubble() {
        const bubble = document.createElement('div');
        bubble.id = 'study-monitor-bubble';
        document.body.appendChild(bubble);

        const style = document.createElement('style');
        style.textContent = `
            #study-monitor-bubble {
                position: fixed;
                bottom: 20px;
                left: 450px;
                background-color: rgba(240, 240, 240, 0.95);
                border-radius: 18px;
                padding: 12px 18px;
                font-family: Arial, sans-serif;
                font-size: 14px;
                line-height: 1.4;
                color: #333;
                max-width: 280px;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
                opacity: 0;
                transform: translateY(20px);
                transition: opacity 0.3s ease, transform 0.3s ease;
                z-index: 1000;
            }
        `;
        document.head.appendChild(style);
    }

    function showMessage(message, type = 'warning') {
        const currentTime = Date.now();
        if (currentTime - lastReminderTime < MIN_TIME_BETWEEN_REMINDERS) {
            return;
        }

        const bubble = document.getElementById('study-monitor-bubble');
        bubble.textContent = message;

        switch(type) {
            case 'warning':
                bubble.style.backgroundColor = 'rgba(255, 243, 205, 0.95)';
                bubble.style.borderLeft = '4px solid #ffc107';
                break;
            case 'error':
                bubble.style.backgroundColor = 'rgba(250, 200, 200, 0.95)';
                bubble.style.borderLeft = '4px solid #dc3545';
                break;
            default:
                bubble.style.backgroundColor = 'rgba(240, 240, 240, 0.95)';
                bubble.style.borderLeft = '4px solid #17a2b8';
        }

        bubble.style.opacity = '1';
        bubble.style.transform = 'translateY(0)';

        setTimeout(() => {
            bubble.style.opacity = '0';
            bubble.style.transform = 'translateY(20px)';
        }, 7000);

        lastReminderTime = currentTime;
    }

    function checkRapidClicking() {
        const currentTime = Date.now();
        recentAnswerTimes.push(currentTime);

        if (recentAnswerTimes.length > RAPID_CLICK_COUNT_THRESHOLD) {
            recentAnswerTimes.shift();
        }

        if (recentAnswerTimes.length === RAPID_CLICK_COUNT_THRESHOLD) {
            const isRapid = recentAnswerTimes.every((time, index) => 
                index === 0 || time - recentAnswerTimes[index - 1] < RAPID_CLICK_THRESHOLD
            );

            if (isRapid) {
                showMessage(warningMessages[Math.floor(Math.random() * warningMessages.length)], 'error');
                badBehaviorCount++;
            }
        }
    }

    function checkAnswerPattern(answer) {
        answerPattern.push(answer);
        if (answerPattern.length > PATTERN_LENGTH_THRESHOLD) {
            answerPattern.shift();
        }

        if (answerPattern.length === PATTERN_LENGTH_THRESHOLD) {
            const isPattern = answerPattern.every(a => a === answerPattern[0]) ||
                              answerPattern.every((a, i) => a === String.fromCharCode(65 + (i % 4))); 

            if (isPattern) {
                showMessage("Your answer pattern suggests you might not be reading the questions. Please take your time.", 'error');
                badBehaviorCount++;
            }
        }
    }

    function checkTimeProgress() {
        const elapsedTime = Date.now() - startTime;
        const timeRemaining = MAX_STUDY_TIME - elapsedTime;

        if (timeRemaining < 10 * 60 * 1000 && timeRemaining > 9 * 60 * 1000) { 
            showMessage(timeWarningMessages[0]);
        } else if (timeRemaining < 5 * 60 * 1000 && timeRemaining > 4 * 60 * 1000) { 
            showMessage(timeWarningMessages[1], 'warning');
        } else if (timeRemaining < 2 * 60 * 1000 && timeRemaining > 1 * 60 * 1000) { 
            showMessage(timeWarningMessages[2], 'error');
        }
    }

    function checkUserBehavior() {
        const currentTime = Date.now();
        const timeSinceLastScroll = currentTime - lastScrollTime;
        const timeSinceLastAnswer = currentTime - lastAnswerTime;

        if (timeSinceLastAnswer < 5000 && answersWithoutScroll > 2) {
            showMessage("Remember to read through all information before answering.", 'warning');
            badBehaviorCount++;
        } else if (timeSinceLastScroll > 60000) {
            showMessage("Don't forget to scroll and review all the content provided.", 'warning');
            badBehaviorCount++;
        }

        if (badBehaviorCount >= 3) {
            showMessage("Your response pattern suggests you may not be giving this study your full attention. Please remember the importance of your contributions.", 'error');
            badBehaviorCount = 0;
        }

        checkTimeProgress();
    }

    function init() {
        createChatBubble();

        window.addEventListener('scroll', () => {
            lastScrollTime = Date.now();
            answersWithoutScroll = 0;
        });

        document.addEventListener('change', (event) => {
            if (event.target.matches('input[type="radio"], input[type="checkbox"]')) {
                lastAnswerTime = Date.now();
                answersWithoutScroll++;
                checkRapidClicking();
                checkAnswerPattern(event.target.value);
            }
        });

        setInterval(checkUserBehavior, 300); 
    }

    return { init };
})();

document.addEventListener('DOMContentLoaded', IntelligentStudyMonitor.init);

      </script>

      <?php elseif ($current_section === 'results'): ?>
      <h1 class="mdc-typography--headline4">Study Results</h1>
      <div class="results-summary">
         <h2 class="mdc-typography--headline5">Summary</h2>
         <?php
            $total_score = 0;
            $total_questions = 0;
            $participant_id = $_SESSION['participant_id'];

            $questions_file = 'test/questions_answers.json';
            $questions_data = json_decode(file_get_contents($questions_file), true);

            for ($task = 1; $task <= 3; $task++) {

                $score = isset($_SESSION['scores'][$task]) ? $_SESSION['scores'][$task] : 0;
                $answers = isset($_SESSION['results'][$task]) ? $_SESSION['results'][$task] : [];

                if ($score == 0 || empty($answers)) {
                    $stmt = $db->prepare("SELECT task{$task}_score, task{$task}_answers FROM study_data WHERE id = ?");
                    $stmt->bind_param("i", $participant_id);
                    $stmt->execute();
                    $stmt->bind_result($db_score, $db_answers);
                    $stmt->fetch();
                    $stmt->close();

                    $score = $db_score ?: 0;
                    $answers = json_decode($db_answers, true) ?: [];
                }

                $questions_count = count($questions_data["Practice Set {$task}"]);
                $total_score += $score;
                $total_questions += $questions_count;

                $percentage = $questions_count > 0 ? ($score / $questions_count) * 100 : 0;
                echo "<p>Task {$task} Score: {$score}</p>";

                echo "<h3>Task {$task} Detailed Results</h3>";
                foreach ($questions_data["Practice Set {$task}"] as $index => $question) {
                    $user_answer = isset($answers[$index]) ? $answers[$index]['user_answer'] : 'Not answered';
                    $is_correct = isset($answers[$index]) ? $answers[$index]['is_correct'] : false;

                    echo "<div class='question-result " . ($is_correct ? 'correct' : 'incorrect') . "'>";
                    echo "<p><strong>Question " . ($index + 1) . ":</strong> " . htmlspecialchars($question['Question']) . "</p>";
                    echo "<p><strong>Your Answer:</strong> " . htmlspecialchars($user_answer) . "</p>";
                    echo "<p><strong>Correct Answer:</strong> " . htmlspecialchars($question['Answer']) . "</p>";
                    echo "<p><strong>Explanation:</strong> " . htmlspecialchars($question['Explanation']) . "</p>";
                    echo "</div>";
                }
            }

            $overall_percentage = $total_questions > 0 ? ($total_score / $total_questions) * 100 : 0;
            echo "<h3>Overall Results</h3>";
            echo "<p><strong>Total Score: {$total_score}</strong></p>";

            $stmt = $db->prepare("UPDATE study_data SET total_score = ?, completion_status = 'complete' WHERE id = ?");
            $stmt->bind_param("ii", $total_score, $participant_id);
            $stmt->execute();
            ?>
      </div>
      <?php
         $completion_code = 'STUDY_' . strtoupper(substr(md5(uniqid($participant_id, true)), 0, 8));

         $stmt = $db->prepare("UPDATE study_data SET completion_code = ? WHERE id = ?");
         $stmt->bind_param("si", $completion_code, $participant_id);
         $stmt->execute();

         $prolific_completion_url = "https://app.prolific.co/submissions/complete?cc=" . $completion_code;
         ?>
      <form action="<?php echo $prolific_completion_url; ?>" method="get" id="completeStudyForm">
         <input type="hidden" name="cc" value="<?php echo $completion_code; ?>">
         <button id="completeStudyBtn" class="mdc-button mdc-button--raised" type="submit">
         <span class="mdc-button__label">Complete Study and Return to Prolific</span>
         </button>
      </form>
      <style>
         .results-summary {
         background-color: #f5f5f5;
         padding: 20px;
         border-radius: 8px;
         margin-bottom: 20px;
         }
         .question-result {
         background-color: #ffffff;
         border: 1px solid #e0e0e0;
         border-radius: 4px;
         padding: 15px;
         margin-bottom: 15px;
         }
         .question-result.correct {
         border-left: 5px solid #4caf50;
         }
         .question-result.incorrect {
         border-left: 5px solid #f44336;
         }
         #completeStudyBtn {
         margin-top: 20px;
         }

      </style>
      <?php endif; ?>
      <script>
            const taskDurations = {
                1: 540,  
                2: 660,  
                3: 600   
            };
            let taskTimer;
            let currentTaskStartTime;

         document.addEventListener('DOMContentLoaded', function() {

             mdc.autoInit();

             const pages = document.querySelectorAll('.page');
             const prevBtn = document.getElementById('prevBtn');
             const nextBtn = document.getElementById('nextBtn');
             const pageInfo = document.getElementById('pageInfo');
             const floatingDialog = document.getElementById('floatingDialog');
             const closeDialog = document.getElementById('closeDialog');
             const nextSectionBtn = document.getElementById('nextSectionBtn');
             const notification = document.getElementById('notification');
             let currentPage = 0;

             function showPage(index) {

                 pages.forEach((page, i) => {
                     page.style.transform = `translateX(${100 * (i - index)}%)`;
                 });
                 currentPage = index;
                 updatePageInfo();
                 updateButtonStates();

                 fetch(window.location.href, {
                     method: 'POST',
                     headers: {
                         'Content-Type': 'application/x-www-form-urlencoded',
                     },
                     body: `view_page=${index + 1}`
                 });
             }

             function updatePageInfo() {

                 pageInfo.textContent = `Page ${currentPage + 1} of ${pages.length}`;
             }

             function updateButtonStates() {

                 prevBtn.disabled = currentPage === 0;
                 nextBtn.disabled = currentPage === pages.length - 1;

                 if (currentPage === pages.length - 1) {
                     nextSectionBtn.disabled = false;
                     showNotification("You've reached the last page. You can now proceed to the questions.");
                 }
             }

             prevBtn.addEventListener('click', () => {

                 if (currentPage > 0) {
                     showPage(currentPage - 1);
                 }
             });

             nextBtn.addEventListener('click', () => {

                 if (currentPage < pages.length - 1) {
                     showPage(currentPage + 1);
                 }
             });

             if (closeDialog) {
                 closeDialog.addEventListener('click', () => {

                     floatingDialog.style.display = 'none';
                 });
             }

             if (floatingDialog) {
                 document.addEventListener('mouseup', function() {
                     let selection = window.getSelection();
                     let selectedText = selection.toString().trim();

                     if (selectedText.length > 0) {
                         const rect = selection.getRangeAt(0).getBoundingClientRect();
                         floatingDialog.style.display = 'block';
                         floatingDialog.style.left = `${rect.left + window.scrollX}px`;
                         floatingDialog.style.top = `${rect.bottom + window.scrollY}px`;

                     } else if (!floatingDialog.contains(event.target)) {
                         floatingDialog.style.display = 'none';
                     }
                 });
             }

             window.processText = function(feature) {
                 const selectedText = window.getSelection().toString().trim();
                 if (selectedText.length === 0) return;

                 fetch(window.location.href, {
                     method: 'POST',
                     headers: {
                         'Content-Type': 'application/x-www-form-urlencoded',
                     },
                     body: `process_text=1&text=${encodeURIComponent(selectedText)}&feature=${feature}`
                 })
                 .then(response => response.json())
                 .then(data => {
                     if (data.error) {
                         console.error('Error:', data.error);
                         showNotification('An error occurred while processing the text. Please try again.');
                     } else {
                         document.getElementById('processedText').innerHTML = data.result;

                     }
                 })
                 .catch(error => {
                     console.error('Error:', error);
                     showNotification('An error occurred while processing the text. Please try again.');
                 });
             }

             const registrationForm = document.getElementById('registrationForm');
             if (registrationForm) {

                 registrationForm.addEventListener('submit', function(event) {
                     const prolificId = document.getElementById('prolific_id').value;
                     const age = document.getElementById('age').value;
                     const gender = document.querySelector('.mdc-select[aria-label="Gender select"].mdc-select__selected-text').textContent;
                      const education = document.querySelector('.mdc-select[aria-label="Education select"] .mdc-select__selected-text').textContent;
                     const englishProficiency = document.querySelector('.mdc-select[aria-label="English Proficiency select"].mdc-select__selected-text').textContent;
                     const consent = document.getElementById('consent').checked;

                     if (!prolificId || !age || !gender || !education || !englishProficiency) {
                         event.preventDefault();
                         showNotification('Please fill out all fields before submitting.');

                     }
                 });
             }

             const questionsForm = document.getElementById('questionsForm');
             if (questionsForm) {

                 questionsForm.addEventListener('submit', function(event) {
                     const questions = document.querySelectorAll('.question-card');
                     let allAnswered = true;

                     questions.forEach(question => {
                         const inputs = question.querySelectorAll('input[type="radio"], input[type="checkbox"]');
                         const answered = Array.from(inputs).some(input => input.checked);
                         if (!answered) {
                             allAnswered = false;
                         }
                     });

                     if (!allAnswered) {
                         event.preventDefault();
                         showNotification('Please answer all questions before submitting.');

                     }
                 });
             }

             showPage(0);
             showNotification('Welcome to the reading task. Please read all pages before proceeding to questions.');
         });

      </script>
      <script>
         document.addEventListener('DOMContentLoaded', function() {

             const notificationContainer = document.getElementById('notification-container');
             let notificationTimeout;
             const showDetailedResults = document.getElementById('showDetailedResults');
             const detailedResults = document.getElementById('detailedResults');
             function showNotification(message, type = 'info', duration = 5000) {
                 clearTimeout(notificationTimeout);
                 const notification = document.createElement('div');
                 notification.className = `notification ${type}`;
                 notification.textContent = message;
                 notificationContainer.innerHTML = '';
                 notificationContainer.appendChild(notification);
                 notification.style.display = 'block';
                 notificationTimeout = setTimeout(() => {
                     notification.style.display = 'none';
                 }, duration);
             }
             function highlightUnansweredQuestions() {
                 const questions = document.querySelectorAll('.question-card');
                 let unansweredFound = false;
                 questions.forEach(question => {
                     const inputs = question.querySelectorAll('input[type="radio"], input[type="checkbox"]');
                     const answered = Array.from(inputs).some(input => input.checked);
                     if (!answered) {
                         question.classList.add('unanswered');
                         if (!unansweredFound) {
                             question.scrollIntoView({ behavior: 'smooth', block: 'center' });
                             unansweredFound = true;
                         }
                     } else {
                         question.classList.remove('unanswered');
                     }
                 });
             }
             if (showDetailedResults && detailedResults) {

                 showDetailedResults.addEventListener('click', function() {

                     if (detailedResults.style.display === 'none' || detailedResults.style.display === '') {
                         detailedResults.style.display = 'block';
                         this.textContent = 'Hide Detailed Results';
                         detailedResults.scrollIntoView({ behavior: 'smooth', block: 'start' });

                     } else {
                         detailedResults.style.display = 'none';
                         this.textContent = 'Show Detailed Results';

                     }
                 });
             } else {

             }

         });

      </script>    
      <script>
         document.addEventListener('DOMContentLoaded', function() {

             pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.9.359/pdf.worker.min.js';

             let SCALE = 1.0;
             const RENDER_QUALITY = 2;
             let currentPdf = null;
             let currentTask = <?php echo $current_task; ?>;
             let questionsData;
             let taskTimer;
             let idleTimer;

             const questionMapping = {
                 1: {1: [1, 2], 2: [3], 3: [4, 5], 4: [6], 5: [7, 8, 9]},
                 2: {1: [1], 2: [2, 3, 4], 3: [5, 6], 4: [7, 8, 9, 10], 5: [11]},
                 3: {1: [1, 2], 2: [3, 4, 5], 3: [6, 7, 8, 9], 4: [10]}
             };

             if (!document.getElementById('notification-container')) {
                 const notificationContainer = document.createElement('div');
                 notificationContainer.id = 'notification-container';
                 document.body.appendChild(notificationContainer);
             }

            function showNotification(message, type = 'info', duration = 1000) {
                const notificationContainer = document.getElementById('notification-container');
                const notification = document.createElement('div');
                notification.className = `notification ${type}`;
                notification.textContent = message;
                notificationContainer.appendChild(notification);

                setTimeout(() => {
                    notification.classList.add('show');
                }, 10);

                setTimeout(() => {
                    notification.classList.remove('show');
                    setTimeout(() => {
                        notification.remove();
                    }, 300);
                }, duration);
            }

             fetch('test/questions_answers.json')
                 .then(response => response.json())
                 .then(data => {
                     questionsData = data;

                     loadTask(currentTask);
                 })
                 .catch(error => {
                     console.error('Error loading questions:', error);
                     showNotification('Failed to load questions. Please refresh the page.', 'error');
                 });

             async function renderPdf(pdf) {

                 currentPdf = pdf;
                 const viewer = document.getElementById('study-pdf-viewer');
                 if (!viewer) {
                     console.error('PDF viewer element not found');
                     return;
                 }
                 viewer.innerHTML = '';

                 const questions = questionsData[`Practice Set ${currentTask}`] || [];

                 for (let pageNum = 1; pageNum <= pdf.numPages; pageNum++) {

                     const page = await pdf.getPage(pageNum);
                     const originalViewport = page.getViewport({ scale: 1 });
                     const scale = SCALE * RENDER_QUALITY;
                     const scaledViewport = page.getViewport({ scale: scale });

                     const pageContainer = document.createElement('div');
                     pageContainer.className = 'page-container';
                     viewer.appendChild(pageContainer);

                     const pageDiv = document.createElement('div');
                     pageDiv.className = 'study-page pdf-page';
                     pageDiv.style.position = 'relative';
                     pageDiv.style.width = `${originalViewport.width * SCALE}px`;
                     pageDiv.style.minHeight = `${originalViewport.height * SCALE}px`;
                     pageDiv.style.marginBottom = '20px';
                     pageContainer.appendChild(pageDiv);

                     const canvas = document.createElement('canvas');
                     const context = canvas.getContext('2d');
                     canvas.width = scaledViewport.width;
                     canvas.height = scaledViewport.height;
                     canvas.style.width = `${originalViewport.width * SCALE}px`;
                     canvas.style.height = `${originalViewport.height * SCALE}px`;
                     pageDiv.appendChild(canvas);

                     const renderContext = {
                         canvasContext: context,
                         viewport: scaledViewport,
                     };

                     await page.render(renderContext).promise;

                     const textContent = await page.getTextContent();
                     const textLayer = document.createElement('div');
                     textLayer.className = 'study-textLayer';
                     textLayer.style.width = `${originalViewport.width * SCALE}px`;
                     textLayer.style.height = `${originalViewport.height * SCALE}px`;
                     textLayer.style.position = 'absolute';
                     textLayer.style.left = '0';
                     textLayer.style.top = '0';
                     textLayer.style.overflow = 'hidden';
                     pageDiv.appendChild(textLayer);

                     pdfjsLib.renderTextLayer({
                         textContent: textContent,
                         container: textLayer,
                         viewport: page.getViewport({ scale: SCALE }),
                         textDivs: []
                     });

                     const questionNumbers = questionMapping[currentTask][pageNum] || [];
                     if (questionNumbers.length > 0) {
                         const questionDiv = document.createElement('div');
                         questionDiv.className = 'question-page';
                         questionDiv.style.width = `${originalViewport.width * SCALE}px`;
                         questionDiv.style.minHeight = `${originalViewport.height * SCALE}px`;
                         questionDiv.style.backgroundColor = 'rgb(235 235 235)';
                         questionDiv.style.padding = '20px';
                         questionDiv.style.marginBottom = '20px';
                         questionDiv.style.boxSizing = 'border-box';

                         questionNumbers.forEach(questionIndex => {
                             const question = questions[questionIndex - 1];
                             if (question) {
                                 const questionCard = createQuestionCard(question, questionIndex - 1);
                                 questionDiv.appendChild(questionCard);
                             }
                         });

                         pageContainer.appendChild(questionDiv);
                     }
                 }
                 updateNextButtonState();
                 updateProgressBar();
             }

             function createQuestionCard(question, index) {

                 const questionCard = document.createElement('div');
                 questionCard.className = 'question-card';
                 questionCard.innerHTML = `
                     <p class="mdc-typography--body1"><strong>${question.Question}</strong></p>
                     ${question.Answer.includes(',') ? '<p class="mdc-typography--caption"><em>(Select all that apply)</em></p>' : ''}
                     ${createAnswerOptions(question, currentTask, index)}
                 `;
                 return questionCard;
             }

             function createAnswerOptions(question, taskNum, questionIndex) {

                 const isMultipleChoice = question.Answer.includes(',');
                 const inputType = isMultipleChoice ? 'checkbox' : 'radio';

                 return Object.entries(question.Choices).map(([choice, text]) => {

                     return `
                         <div class="mdc-form-field">
                             <div class="${isMultipleChoice ? 'mdc-checkbox' : 'mdc-radio'}">
                                 <input type="${inputType}" 
                                        id="question-${taskNum}-${questionIndex}-${choice}" 
                                        name="task_answers[${questionIndex}]${isMultipleChoice ? '[]' : ''}" 
                                        value="${choice}"
                                        class="${isMultipleChoice ? 'mdc-checkbox__native-control' : 'mdc-radio__native-control'}"
                                        onchange="handleAnswerSelection(event)">
                                 <div class="${isMultipleChoice ? 'mdc-checkbox__background' : 'mdc-radio__background'}">
                                     ${isMultipleChoice ? `
                                         <svg class="mdc-checkbox__checkmark" viewBox="0 0 24 24">
                                             <path class="mdc-checkbox__checkmark-path" fill="none" d="M1.73,12.91 8.1,19.28 22.79,4.59"/>
                                         </svg>
                                         <div class="mdc-checkbox__mixedmark"></div>
                                     ` : `
                                         <div class="mdc-radio__outer-circle"></div>
                                         <div class="mdc-radio__inner-circle"></div>
                                     `}
                                 </div>
                             </div>
                             <label for="question-${taskNum}-${questionIndex}-${choice}">${choice}: ${text}</label>
                         </div>
                     `;
                 }).join('');
             }

            async function loadTask(taskNum) {
                try {
                    const pdfPath = `papers/ReadingTask${taskNum}.pdf`;

                    showNotification(`Loading Task ${taskNum}...`, 'info');

                    const loadingTask = pdfjsLib.getDocument(pdfPath);
                    const pdf = await loadingTask.promise;

                    await renderPdf(pdf);
                    const taskInfo = document.getElementById('study-task-info');
                    if (taskInfo) {
                        taskInfo.textContent = `Task ${taskNum} of 3`;
                    } else {
                        console.error('study-task-info element not found');
                    }
                    updateTaskNavigation();
                    startTaskTimer(taskNum);
                    showNotification(`Task ${taskNum} loaded. Good luck!`, 'success');
                } catch (error) {
                    console.error('Error loading task:', error);
                    showNotification('Failed to load task. Please try again.', 'error');
                }
            }
             function updateTaskNavigation() {

                 const prevTaskBtn = document.getElementById('study-prev-task');
                 const nextTaskBtn = document.getElementById('study-next-task');

                 if (prevTaskBtn) prevTaskBtn.disabled = (currentTask === 1);
                 if (nextTaskBtn) nextTaskBtn.disabled = (currentTask === 3);
                 updateNextButtonState();
             }
            document.addEventListener('DOMContentLoaded', function() {
                const prevTaskButton = document.getElementById('study-prev-task');

                if (prevTaskButton) {

                    prevTaskButton.disabled = true;

                    prevTaskButton.addEventListener('click', function(event) {
                        event.preventDefault();
                        return false;
                    });

                    setInterval(function() {
                        if (!prevTaskButton.disabled) {
                            prevTaskButton.disabled = true;
                        }
                    }, 1000); 
                }
            });

            document.addEventListener('DOMContentLoaded', updateProgressBar);
                document.body.addEventListener('change', function(event) {
                    if (event.target.matches('.question-card input')) {
                        updateProgressBar();
                    }
                });

                  window.updateNextButtonState = function() {
                 const nextTaskBtn = document.getElementById('study-next-task');
                 const allQuestions = document.querySelectorAll('input[type="radio"], input[type="checkbox"]');
                 const questionGroups = {};

                 allQuestions.forEach(input => {
                     if (!questionGroups[input.name]) {
                         questionGroups[input.name] = false;
                     }
                     if (input.checked) {
                         questionGroups[input.name] = true;
                     }
                 });

                 const allAnswered = Object.values(questionGroups).every(value => value === true);
                 if (nextTaskBtn) {
                     nextTaskBtn.disabled = true;
                 }

                 if (allAnswered) {
                     showNotification('All questions answered! You can now proceed to the next task or finish the study.', 'success');
                 }
             }

             window.handleAnswerSelection = function(event) {
                 const selectedAnswer = event.target;
                 const questionCard = selectedAnswer.closest('.question-card');
                 const allAnswers = questionCard.querySelectorAll('input[type="radio"], input[type="checkbox"]');

                 allAnswers.forEach(answer => answer.parentElement.classList.remove('selected'));
                 selectedAnswer.parentElement.classList.add('selected');

                 updateProgressBar();
                 updateNextButtonState();
                 resetIdleTimer();
             }

            function updateProgressBar() {
                const questionCards = document.querySelectorAll('.question-card');
                const totalQuestions = questionCards.length;
                let answeredQuestions = 0;

                questionCards.forEach(questionCard => {
                    const inputs = questionCard.querySelectorAll('input[type="radio"], input[type="checkbox"]');
                    const isAnswered = Array.from(inputs).some(input => input.checked);
                    if (isAnswered) {
                        answeredQuestions++;
                    }
                });

                const progress = Math.min((answeredQuestions / totalQuestions) * 100, 100);

                const progressBar = document.getElementById('questionProgressBar');
                if (progressBar) {
                    progressBar.style.width = `${progress}%`;
                    progressBar.textContent = `${answeredQuestions}/${totalQuestions}`;

                } else {
                    console.error('Progress bar element not found');
                }
            }

function saveSelections() {
                const selections = {};
                const inputs = document.querySelectorAll('input[type="radio"], input[type="checkbox"]');
                inputs.forEach(input => {
                    if (input.checked) {
                        if (!selections[input.name]) {
                            selections[input.name] = [];
                        }
                        selections[input.name].push(input.value);
                    }
                });
                return selections;
            }

            function restoreSelections(selections) {
                Object.entries(selections).forEach(([name, values]) => {
                    values.forEach(value => {
                        const input = document.querySelector(`input[name="${name}"][value="${value}"]`);
                        if (input) {
                            input.checked = true;
                        }
                    });
                });
            }

            window.studyZoomIn = function() {
                const selections = saveSelections();
                SCALE *= 1.2;
                if (currentPdf) {
                    renderPdf(currentPdf).then(() => {
                        restoreSelections(selections);
                        updateProgressBar();
                        updateNextButtonState();
                    });
                }
            }

            window.studyZoomOut = function() {
                const selections = saveSelections();
                SCALE /= 1.2;
                if (currentPdf) {
                    renderPdf(currentPdf).then(() => {
                        restoreSelections(selections);
                        updateProgressBar();
                        updateNextButtonState();
                    });
                }
            }

            window.studyResetZoom = function() {
                const selections = saveSelections();
                SCALE = 1.0;
                if (currentPdf) {
                    renderPdf(currentPdf).then(() => {
                        restoreSelections(selections);
                        updateProgressBar();
                        updateNextButtonState();
                    });
                }
            }

             window.studyToggleFullscreen = function() {
                 if (!document.fullscreenElement) {
                     document.documentElement.requestFullscreen();
                 } else {
                     if (document.exitFullscreen) {
                         document.exitFullscreen();
                     }
                 }
             }
             window.addEventListener('load', function() {
                 history.pushState(null, document.title, location.href);
                 window.addEventListener('popstate', function(event) {
                     history.pushState(null, document.title, location.href);
                 });
             });

             const prevTaskBtn = document.getElementById('study-prev-task');
             const nextTaskBtn = document.getElementById('study-next-task');

             if (prevTaskBtn) {
                 prevTaskBtn.addEventListener('click', function() {

                     if (currentTask > 1) {
                         currentTask--;
                         loadTask(currentTask);
                     }
                 });
             } else {
                 console.error('study-prev-task element not found');
             }

             if (nextTaskBtn) {
                 nextTaskBtn.addEventListener('click', function() {

                     saveCurrentTaskData()
                         .then(() => {
                             if (currentTask < 3) {
                                 currentTask++;
                                 loadTask(currentTask);
                             } else {
                                 showResults();
                             }
                         })
                         .catch(error => {
                             console.error('Error saving task data:', error);
                             showNotification('Error saving your answers. Please try again.', 'error');
                         });
                 });
             } else {
                 console.error('study-next-task element not found');
             }

    function saveCurrentTaskData(elapsedTime) {
            return new Promise((resolve, reject) => {
                const taskKey = `Practice Set ${currentTask}`;
                const questions = questionsData[taskKey] || [];
                let score = 0;
                const answers = [];

                questions.forEach((question, index) => {
                    const userAnswerElements = document.querySelectorAll(`input[name="task_answers[${index}]"]:checked`);
                    const userAnswer = Array.from(userAnswerElements).map(el => el.value);
                    const correctAnswer = question.Answer.split(', ');
                    const isCorrect = JSON.stringify(userAnswer.sort()) === JSON.stringify(correctAnswer.sort());
                    if (isCorrect) score++;

                    answers.push({
                        question_number: index + 1,
                        user_answer: userAnswer.join(', '),
                        is_correct: isCorrect
                    });
                });

                sessionStorage.setItem(`task${currentTask}Score`, score);

                let totalScore = 0;
                for (let i = 1; i <= currentTask; i++) {
                    totalScore += parseInt(sessionStorage.getItem(`task${i}Score`) || 0);
                }

                const formData = new FormData();
                formData.append('save_task_data', '1');
                formData.append('task_number', currentTask);
                formData.append('score', score);
                formData.append('answers', JSON.stringify(answers));
                formData.append('end_time', new Date().toISOString());
                formData.append('elapsed_time', elapsedTime);
                formData.append('total_score', totalScore);

                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        if (currentTask < 3) {
                            const nextTaskStartTime = new Date().toISOString();
                            sessionStorage.setItem(`task${currentTask + 1}StartTime`, nextTaskStartTime);

                            const nextTaskFormData = new FormData();
                            nextTaskFormData.append('set_next_task_start_time', '1');
                            nextTaskFormData.append('task_number', currentTask + 1);
                            nextTaskFormData.append('start_time', nextTaskStartTime);

                            return fetch(window.location.href, {
                                method: 'POST',
                                body: nextTaskFormData
                            });
                        } else {

                        }
                        resolve();
                    } else {
                        reject(new Error(result.message || 'Failed to save task data'));
                    }
                })
                .then(response => {
                    if (response) return response.json();
                })
                .then(result => {
                    if (result && !result.success) {
                        console.error('Failed to set next task start time:', result.message);
                    }
                    resolve();
                })
                .catch(error => {
                    console.error('Error in saveCurrentTaskData:', error);
                    reject(error);
                });
            });
        }    
             function sendMessage() {
                 const input = document.getElementById('study-chat-input');
                 const message = input.value.trim();
                 if (message) {
                     addMessage(message, 'user');
                     input.value = '';
                     showTypingIndicator();

                     fetch(window.location.href, {
                         method: 'POST',
                         headers: {
                             'Content-Type': 'application/x-www-form-urlencoded',
                         },
                         body: `chat_message=${encodeURIComponent(message)}&current_task=${currentTask}`
                     })
                     .then(response => response.json())
                     .then(data => {
                         hideTypingIndicator();
                         addMessage(data.response, 'bot');
                     })
                     .catch(error => {
                         console.error('Error:', error);
                         hideTypingIndicator();
                         addMessage('Sorry, there was an error processing your request.', 'bot');
                     });
                 }
             }

             function addMessage(text, sender) {
                 const chatMessages = document.getElementById('study-chat-messages');
                 const messageElement = document.createElement('div');
                 messageElement.classList.add('study-message', sender === 'user' ? 'study-user-message' : 'study-bot-message');
                 messageElement.textContent = text;
                 chatMessages.appendChild(messageElement);
                 chatMessages.scrollTop = chatMessages.scrollHeight;
             }

             function showTypingIndicator() {
                 const chatMessages = document.getElementById('study-chat-messages');
                 const typingIndicator = document.createElement('div');
                 typingIndicator.id = 'typing-indicator';
                 typingIndicator.textContent = 'AI is thinking...';
                 chatMessages.appendChild(typingIndicator);
                 chatMessages.scrollTop = chatMessages.scrollHeight;
             }

             function hideTypingIndicator() {
                 const typingIndicator = document.getElementById('typing-indicator');
                 if (typingIndicator) {
                     typingIndicator.remove();
                 }
             }

             const chatInput = document.getElementById('study-chat-input');
             const sendButton = document.getElementById('study-send-button');

             if (chatInput) {
                 chatInput.addEventListener('keypress', function(e) {
                     if (e.key === 'Enter') {
                         sendMessage();
                     }
                 });
             } else {
                 console.error('study-chat-input element not found');
             }

             if (sendButton) {
                 sendButton.addEventListener('click', sendMessage);
             } else {
                 console.error('study-send-button element not found');
             }

        function startTaskTimer(taskNum) {
            clearInterval(taskTimer);
            const duration = taskDurations[taskNum];
            let timeLeft = duration;
            currentTaskStartTime = new Date();
            const timerDisplay = document.getElementById('task-timer');

            function updateTimerDisplay() {
                const minutes = Math.floor(timeLeft / 60);
                const seconds = timeLeft % 60;
                timerDisplay.textContent = `Time left: ${minutes}:${seconds < 10 ? '0' : ''}${seconds}`;

                if (timeLeft <= 300 && timeLeft % 60 === 0) {
                    showNotification(`${minutes} minute${minutes !== 1 ? 's' : ''} left!`, 'warning');
                }

                if (--timeLeft < 0) {
                    clearInterval(taskTimer);
                    showNotification('Time\'s up! Moving to the next task.', 'info');
                    saveTaskDataAndProgress(taskNum);
                }
            }

            updateTimerDisplay();
            taskTimer = setInterval(updateTimerDisplay, 1000);
        }

        function saveTaskDataAndProgress(taskNum) {
            const endTime = new Date();
            const elapsedTime = (endTime - currentTaskStartTime) / 1000; 

            saveCurrentTaskData(elapsedTime)
                .then(() => {
                    if (taskNum < 3) {
                        currentTask++;
                        loadTask(currentTask);
                    } else {
                        showResults();
                    }
                })
                .catch(error => {
                    console.error('Error saving task data:', error);
                    showNotification('Error saving your answers. Please try again.', 'error');
                });
        }

             function resetIdleTimer() {
                 clearTimeout(idleTimer);
                 idleTimer = setTimeout(function() {
                     showNotification('Are you still there? Don\'t forget to answer all questions!', 'warning');
                 }, 120000); 
             }

         function showResults() {
             clearInterval(taskTimer);

             const mainContent = document.querySelector('.study-main-content');
             mainContent.innerHTML = '<h2 class="mdc-typography--headline4">Study Results</h2>';

             const resultsContainer = document.createElement('div');
             resultsContainer.id = 'results-container';
             resultsContainer.className = 'container';
             mainContent.appendChild(resultsContainer);

             let totalScore = 0;
             let totalQuestions = 0;

             const summaryDiv = document.createElement('div');
             summaryDiv.className = 'results-summary';
             summaryDiv.innerHTML = '<h3 class="mdc-typography--headline5">Summary</h3>';

             for (let task = 1; task <= 3; task++) {
                 const taskScore = parseInt(sessionStorage.getItem(`task${task}Score`) || 0);
                 const taskResults = JSON.parse(sessionStorage.getItem(`task${task}Results`) || '[]');
                 const questionsCount = taskResults.length;

                 totalScore += taskScore;
                 totalQuestions += questionsCount;

                 const percentage = (questionsCount > 0) ? (taskScore / questionsCount) * 100 : 0;
                 summaryDiv.innerHTML += `<p>Task ${task}: <span class="highlight">${taskScore}</span></p>`;
             }

             const overallPercentage = (totalQuestions > 0) ? (totalScore / totalQuestions) * 100 : 0;
             summaryDiv.innerHTML += `<p><strong>Overall Score: <span class="highlight">${totalScore}</span></strong></p>`;

             resultsContainer.appendChild(summaryDiv);

             const showDetailedBtn = document.createElement('button');
             showDetailedBtn.id = 'showDetailedResults';
             showDetailedBtn.className = 'mdc-button mdc-button--raised';
             showDetailedBtn.innerHTML = '<span class="mdc-button__label">Show Detailed Results</span>';
             resultsContainer.appendChild(showDetailedBtn);

             const detailedResultsDiv = document.createElement('div');
             detailedResultsDiv.id = 'detailedResults';
             detailedResultsDiv.style.display = 'none';
             resultsContainer.appendChild(detailedResultsDiv);

             for (let task = 1; task <= 3; task++) {
                 const taskResults = JSON.parse(sessionStorage.getItem(`task${task}Results`) || '[]');
                 const taskDiv = document.createElement('div');
                 taskDiv.innerHTML = `<h3 class="mdc-typography--headline5">Task ${task} Results</h3>`;

                 taskResults.forEach((result, index) => {
                     const resultDiv = document.createElement('div');
                     resultDiv.className = `question-result ${result.is_correct ? 'correct' : 'incorrect'}`;
                     resultDiv.innerHTML = `
                         <p class="question-text"><strong>Question ${index + 1}:</strong> ${result.question}</p>
                         <p class="answer-text"><strong>Your Answer:</strong> <span class="highlight">${result.user_answer}</span></p>
                         <p class="answer-text"><strong>Correct Answer:</strong> <span class="highlight">${result.correct_answer}</span></p>
                         <p class="explanation-text"><strong>Explanation:</strong> ${result.explanation}</p>
                         <p class="result-indicator ${result.is_correct ? 'correct' : 'incorrect'}">
                             ${result.is_correct ? '✓ Correct' : '✗ Incorrect'}
                         </p>
                     `;
                     taskDiv.appendChild(resultDiv);
                 });

                 detailedResultsDiv.appendChild(taskDiv);
             }

             showDetailedBtn.addEventListener('click', function() {
                 if (detailedResultsDiv.style.display === 'none') {
                     detailedResultsDiv.style.display = 'block';
                     this.textContent = 'Hide Detailed Results';
                 } else {
                     detailedResultsDiv.style.display = 'none';
                     this.textContent = 'Show Detailed Results';
                 }
             });

             const completeStudyBtn = document.createElement('button');
             completeStudyBtn.id = 'completeStudyBtn';
             completeStudyBtn.className = 'mdc-button mdc-button--raised';
             completeStudyBtn.innerHTML = '<span class="mdc-button__label">Complete the Study by Taking This Survey</span>';
             resultsContainer.appendChild(completeStudyBtn);

             completeStudyBtn.addEventListener('click', function() {

                 window.location.href = 'https://ww2.unipark.de/uc/iui25/';
             });

             saveTotalScore(totalScore);

            setTimeout(function() {
                window.location.href = 'https://ww2.unipark.de/uc/iui25/';
            }, 500); 

         }
         function saveTotalScore(totalScore) {
             fetch(window.location.href, {
                 method: 'POST',
                 headers: {
                     'Content-Type': 'application/x-www-form-urlencoded',
                 },
                 body: `save_total_score=1&total_score=${totalScore}`
             })
             .then(response => response.json())
             .then(data => {
                 if (data.success) {

                 } else {
                     console.error('Error saving total score');
                 }
             })
             .catch(error => {
                 console.error('Error:', error);
             });
         }
             function calculateTaskScore(taskNum) {
                 const questions = questionsData[`Practice Set ${taskNum}`];
                 let score = 0;

                 questions.forEach((question, index) => {
                     const userAnswerElements = document.querySelectorAll(`input[name="task_answers[${index}]"]:checked`);
                     const userAnswer = Array.from(userAnswerElements).map(el => el.value);
                     const correctAnswer = question.Answer.split(', ');
                     const isCorrect = JSON.stringify(userAnswer.sort()) === JSON.stringify(correctAnswer.sort());
                     if (isCorrect) score++;
                 });

                 return score;
             }

             function initializeStudy() {
                 showNotification('Welcome to the Reading Comprehension Study!', 'info');
                 resetIdleTimer();
                 document.addEventListener('mousemove', resetIdleTimer);
                 document.addEventListener('keypress', resetIdleTimer);
             }

             try {
                 initializeStudy();
             } catch (error) {
                 console.error('Error initializing study:', error);
                 showNotification('An error occurred while initializing the study. Please refresh the page.', 'error');
             }
         });

         function showLoadingSpinner() {
             const spinner = document.createElement('div');
             spinner.id = 'loading-spinner';
             spinner.innerHTML = '<div class="spinner"></div>';
             document.body.appendChild(spinner);
         }

         function hideLoadingSpinner() {
             const spinner = document.getElementById('loading-spinner');
             if (spinner) {
                 spinner.remove();
             }
         }

         function scrollToTop() {
             window.scrollTo({
                 top: 0,
                 behavior: 'smooth'
             });
         }

         function createScrollToTopButton() {
             const button = document.createElement('button');
             button.id = 'scroll-to-top';
             button.innerHTML = '↑';
             button.addEventListener('click', scrollToTop);
             document.body.appendChild(button);

             window.addEventListener('scroll', function() {
                 if (window.pageYOffset > 100) {
                     button.style.display = 'block';
                 } else {
                     button.style.display = 'none';
                 }
             });
         }

         createScrollToTopButton();

         (function() {
    const taskDurations = {
        1: 540000, 
        2: 660000, 
        3: 600000  
    };

    let currentTask = 1;
    let timeLeft = 0;
    let startTime = 0;
    let timerInterval;
    let allQuestionsAnswered = false;
    let timeElapsed = false;

    function createTimerElements() {
        const timerContainer = document.createElement('div');
        timerContainer.className = 'task-timer';
        timerContainer.innerHTML = 'Time remaining: <span id="task-timer-display"></span>';

        const timerMessage = document.createElement('p');
        timerMessage.className = 'timer-message';
        timerMessage.style.display = 'none';
        timerMessage.textContent = 'Minimum time reached. You can proceed when all questions are answered.';

        return { timerContainer, timerMessage };
    }

    function insertTimerElements() {
        const sidebar = document.querySelector('.study-sidebar');
        const taskNavigation = document.getElementById('study-task-navigation');

        if (sidebar && taskNavigation) {
            const { timerContainer, timerMessage } = createTimerElements();
            sidebar.insertBefore(timerContainer, taskNavigation);
            sidebar.insertBefore(timerMessage, taskNavigation);
        }
    }

    function updateTaskTimer() {
        const elapsed = Date.now() - startTime;
        const remaining = Math.max(0, timeLeft - elapsed);

        const minutes = Math.floor(remaining / 60000);
        const seconds = Math.floor((remaining % 60000) / 1000);

        const timerDisplay = document.getElementById('task-timer-display');
        if (timerDisplay) {
            timerDisplay.textContent = `${minutes}:${seconds < 10 ? '0' : ''}${seconds}`;
        }

        const timerMessage = document.querySelector('.timer-message');

        if (remaining <= 0) {
            if (timerMessage) timerMessage.style.display = 'block';
            clearInterval(timerInterval);
            timeElapsed = true;

            checkAllQuestionsAnswered();
            updateNextButtonState();

            if (currentTask === 3 && allQuestionsAnswered && timeElapsed) {
                setTimeout(() => {
                    window.location.href = 'https://ww2.unipark.de/uc/iui25/';
                }, 500);
            }
        }
    }

    function checkAllQuestionsAnswered() {
        const questionCards = document.querySelectorAll('.question-card');
        allQuestionsAnswered = Array.from(questionCards).every(card => {
            const inputs = card.querySelectorAll('input[type="radio"], input[type="checkbox"]');
            return Array.from(inputs).some(input => input.checked);
        });
    }

    function updateNextButtonState() {
        const nextTaskBtn = document.getElementById('study-next-task');
        if (nextTaskBtn) {
            nextTaskBtn.disabled = !(timeElapsed && allQuestionsAnswered);

            if (!timeElapsed && !allQuestionsAnswered) {
                nextTaskBtn.title = "Please wait for the timer and answer all questions.";
            } else if (!timeElapsed) {
                nextTaskBtn.title = "Please wait for the timer to complete.";
            } else if (!allQuestionsAnswered) {
                nextTaskBtn.title = "Please answer all questions before proceeding.";
            } else {
                nextTaskBtn.title = "Proceed to next task";
            }
        }
    }

    function startTimer() {
        timeLeft = taskDurations[currentTask] || 600000;
        startTime = Date.now();
        timeElapsed = false;
        updateTaskTimer();
        timerInterval = setInterval(updateTaskTimer, 1000);
    }

    function setupNextTaskButton() {
        const nextTaskBtn = document.getElementById('study-next-task');
        if (nextTaskBtn) {
            nextTaskBtn.addEventListener('click', function(event) {
                if (!timeElapsed || !allQuestionsAnswered) {
                    event.preventDefault();
                    if (!timeElapsed && !allQuestionsAnswered) {
                        showNotification('Please wait for the timer and answer all questions.', 'warning');
                    } else if (!timeElapsed) {
                        showNotification('Please wait for the timer to complete.', 'warning');
                    } else {
                        showNotification('Please answer all questions before proceeding.', 'warning');
                    }
                } else {

                    const timerMessage = document.querySelector('.timer-message');
                    if (timerMessage) timerMessage.style.display = 'none';

                    currentTask++;
                    if (currentTask <= 3) {
                        allQuestionsAnswered = false;
                        timeElapsed = false;
                        startTimer();
                    }
                }
            });
        }
    }

    function showNotification(message, type) {

        alert(message);  
    }

    function init() {
        insertTimerElements();
        startTimer();
        setupNextTaskButton();
    }

    const style = document.createElement('style');
    style.textContent = `
        .task-timer {
            font-size: 1.2em;
            font-weight: bold;
            margin: 20px 0;
            padding: 10px;
            background-color: #f0f0f0;
            border-radius: 5px;
            text-align: center;
        }
        .timer-message {
            color: #4CAF50;
            font-weight: bold;
            margin: 20px 0;
            padding: 10px;
            background-color: #e8f5e9;
            border-radius: 5px;
            text-align: center;
        }
    `;
    document.head.appendChild(style);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    document.addEventListener('change', function(event) {
        if (event.target.matches('input[type="radio"], input[type="checkbox"]')) {
            checkAllQuestionsAnswered();
            updateNextButtonState();
        }
    });
})();

        if (document.getElementById('start-reading-task')) {
            const startReadingTaskBtn = document.getElementById('start-reading-task');
            startReadingTaskBtn.disabled = true;

            const timerContainer = document.createElement('div');
            timerContainer.className = 'instruction-timer';
            timerContainer.innerHTML = 'Please wait, the next task will begin in: <span id="timer-display">2:00</span>';

            const timerMessage = document.createElement('p');
            timerMessage.className = 'timer-message';
            timerMessage.style.display = 'none';
            timerMessage.textContent = 'You can now begin the reading task!';

            startReadingTaskBtn.parentNode.insertBefore(timerMessage, startReadingTaskBtn);
            startReadingTaskBtn.parentNode.insertBefore(timerContainer, timerMessage);

            const timerDisplay = document.getElementById('timer-display');

            let instructionTimeLeft = 120; 

            function updateInstructionTimer() {
                const minutes = Math.floor(instructionTimeLeft / 60);
                const seconds = instructionTimeLeft % 60;
                timerDisplay.textContent = `${minutes}:${seconds < 10 ? '0' : ''}${seconds}`;

                if (instructionTimeLeft <= 0) {
                    clearInterval(instructionTimer);
                    startReadingTaskBtn.disabled = false;
                    timerMessage.style.display = 'block';
                    timerContainer.style.display = 'none';
                    showNotification('You can now start the reading task!', 'success');
                }
                instructionTimeLeft--;
            }

            updateInstructionTimer(); 
            const instructionTimer = setInterval(updateInstructionTimer, 1000);

            startReadingTaskBtn.addEventListener('click', function(event) {
                if (instructionTimeLeft > 0) {
                    event.preventDefault();
                    showNotification('Please wait for the timer to complete before starting the task.', 'warning');
                }
            });

            const style = document.createElement('style');
            style.textContent = `
                .instruction-timer {
                    font-size: 1.2em;
                    font-weight: bold;
                    margin: 20px 0;
                    padding: 10px;
                    background-color: #f0f0f0;
                    border-radius: 5px;
                    text-align: center;
                }

                .timer-message {
                    color: #4CAF50;
                    font-weight: bold;
                    margin: 20px 0;
                    padding: 10px;
                    background-color: #e8f5e9;
                    border-radius: 5px;
                    text-align: center;
                }
            `;
            document.head.appendChild(style);
        }

(function() {

    const style = document.createElement('style');
    style.textContent = `
        #browser-check-popup {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) scale(0.9);
            background-color: #f0f0f0;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 0 10px rgba(0,0,0,0.3);
            z-index: 1000;
            max-width: 80%;
            text-align: center;
            opacity: 0;
            transition: opacity 0.3s ease, transform 0.3s ease;
        }
        #browser-check-popup.show {
            opacity: 1;
            transform: translate(-50%, -50%) scale(1);
        }
        #browser-check-popup h2 {
            color: #333;
            margin-top: 0;
        }
        #browser-check-popup p {
            color: #666;
        }
        #browser-check-close {
            background-color: #202654;
            color: white;
            border: none;
            padding: 10px 20px;
            text-align: center;
            text-decoration: none;
            display: inline-block;
            font-size: 16px;
            margin: 4px 2px;
            cursor: pointer;
            border-radius: 5px;
            transition: background-color 0.3s;
        }
        #browser-check-close:hover {
            background-color: #202654;
        }
    `;
    document.head.appendChild(style);

    const popupHTML = `
        <div id="browser-check-popup">
            <h2>Browser Recommendation</h2>
            <p id="browser-check-message"></p>
            <button id="browser-check-close">Close</button>
        </div>
    `;
    document.body.insertAdjacentHTML('beforeend', popupHTML);

    function checkBrowserAndDevice() {
        const userAgent = navigator.userAgent.toLowerCase();
        const screenWidth = window.innerWidth || document.documentElement.clientWidth || document.body.clientWidth;

        let browser = "unknown";
        let device = screenWidth < 768 ? "mobile" : (screenWidth < 1024 ? "tablet" : "desktop");

        if (userAgent.indexOf("chrome") > -1 && userAgent.indexOf("edg") === -1) {
            browser = "Chrome";
        } else if (userAgent.indexOf("safari") > -1 && userAgent.indexOf("chrome") === -1) {
            browser = "Safari";
        } else if (userAgent.indexOf("firefox") > -1) {
            browser = "Firefox";
        } else if (userAgent.indexOf("edge") > -1 || userAgent.indexOf("edg") > -1) {
            browser = "Edge";
        } else if (userAgent.indexOf("opera") > -1 || userAgent.indexOf("opr") > -1) {
            browser = "Opera";
        }

        if (browser !== "Chrome" || device !== "desktop") {
            let message = `You're using ${browser} on a ${device} device. `;
            if (browser !== "Chrome") {
                message += "For the best experience, we strongly recommend using Google Chrome. ";
                if (browser === "Safari") {
                    message += "While Safari is a good browser, Chrome offers better performance and compatibility for our site. ";
                }
                message += "Please consider switching to Chrome for optimal performance and features.";
            }

            if (device !== "desktop") {
                message += " If possible, please access our site from a laptop or desktop computer for full functionality.";
            }

            document.getElementById("browser-check-message").textContent = message;
            const popup = document.getElementById("browser-check-popup");
            popup.style.display = "block";
            setTimeout(() => popup.classList.add("show"), 10); 
        }
    }

    function closePopup() {
        const popup = document.getElementById("browser-check-popup");
        popup.classList.remove("show");
        setTimeout(() => popup.style.display = "none", 300); 
    }

    document.getElementById("browser-check-close").addEventListener("click", closePopup);

    window.addEventListener('load', checkBrowserAndDevice);
})();

document.addEventListener('DOMContentLoaded', function() {
    const floatingMenu = document.querySelector('.study-floating-menu');
    if (!floatingMenu) return;

    let isDragging = false;
    let currentX;
    let currentY;
    let initialX;
    let initialY;
    let xOffset = 0;
    let yOffset = 0;

    floatingMenu.addEventListener('mousedown', dragStart);
    document.addEventListener('mousemove', drag);
    document.addEventListener('mouseup', dragEnd);
    document.addEventListener('mouseleave', dragEnd);

    function dragStart(e) {
        initialX = e.clientX - xOffset;
        initialY = e.clientY - yOffset;

        if (e.target === floatingMenu) {
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

            setTranslate(currentX, currentY, floatingMenu);
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

    floatingMenu.style.cursor = 'move';
    floatingMenu.style.position = 'fixed';
    floatingMenu.style.zIndex = '10000000000'; 
});

      </script>
   </body>
</html>
