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
       $_SESSION['prolific_id'] = $prolific_id;
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
                    <a href="https://readingparadox.com/public_html/experiment.php" class="redirect-button">Go to Main Page Now</a>
                </div>
                <script>
                    let countdown = 10;
                    const countdownElement = document.getElementById('countdown');
                    const countdownInterval = setInterval(() => {
                        countdown--;
                        countdownElement.textContent = countdown;
                        if (countdown <= 0) {
                            clearInterval(countdownInterval);
                            window.location.href = 'https://readingparadox.com/public_html/experiment.php';
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
        $group = 'experiment';
        $current_time = date('Y-m-d H:i:s');

        $stmt = $db->prepare("INSERT INTO study_data (prolific_id, age, gender, education_level, english_proficiency, consent, study_group, created_at, task1_start_time, task1_end_time, task1_score, task1_answers, task2_start_time, task2_end_time, task2_score, task2_answers, task3_start_time, task3_end_time, task3_score, task3_answers, total_score, completion_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, '', '', 0, '[]', '', '', 0, '[]', '', '', 0, '[]', 0, 'incomplete')");
        $stmt->bind_param("sissssss", $prolific_id, $age, $gender, $education, $english_proficiency, $consent, $group, $current_time);

        $_SESSION['prolific_id'] = $prolific_id;
        $_SESSION['user_group'] = $user_group;
        $_SESSION['action_sequence'] = 1;

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
       } elseif (isset($_POST['process_text']) && $_SESSION['study_group'] === 'experimental') {
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

           $prompt = "You are an AI Reading assistant helping a user understand a research study with multiple tasks. The user is currently working on Task {$current_task}. Here's the content for the current task:\n\n";

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
       } elseif (isset($_POST['save_task_data'])) {
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

function saveUserData($data) {
    $file = 'user_data.json';
    file_put_contents($file, json_encode($data));
}

function loadUserData() {
    $file = 'user_data.json';
    if (file_exists($file)) {
        return json_decode(file_get_contents($file), true);
    }
    return ['highlights' => [], 'adaptations' => [], 'dialogPosition' => ['x' => 20, 'y' => '10%']];
}

function getDescriptionForText($text, $practiceSets) {
    foreach ($practiceSets as $setName => $setItems) {
        foreach ($setItems as $item) {
            if (stripos($text, $item['Paragraph']) !== false) {
                return $item['Description'];
            }
        }
    }
    return '';
}

function processWithAI($text, $feature, $userQuestion = '', $description = '', $paragraph = '') {
    switch ($feature) {
        case 'dp1':
            $prompt = "Convert the input text into a simpler version so that it is understandable for a person without specialist knowledge. Technical terms are explained. Use clearer formulations and avoid complicated terms. The text should still remain precise and factual but be easier for laypeople to understand. Only return the converted output text without adding anything to it. If you replace heavy words, write them after the new light words in brackets.";
            $full_prompt = $prompt . "\n\nSelected text: " . $text . "\n\nFull context: " . $paragraph;
            break;
        case 'dp2':
            $prompt = "Convert the input text into a structured version, ensuring that it becomes more accessible to the reader. For each distinct section of content, insert a space. Additionally, provide a three-word summary at the start of every new content part to give the reader a quick understanding of the topic being discussed. Make all important words bold by enclosing them in asterisks (*). Return only the converted and structured output text without any additional commentary or instructions.";
            $full_prompt = $prompt . "\n\nSelected text: " . $text . "\n\nFull context: " . $paragraph;
            break;
        case 'dp3':
            $prompt = "Convert the input text into an essential version, making it more accessible to the reader. Write the text in bullet points. Long and complex sentences are broken down into shorter, simpler ones. Unnecessary words, like filler words, are removed. Return only the converted and essential output text without any additional commentary or instructions.";
            $full_prompt = $prompt . "\n\nSelected text: " . $text . "\n\nFull context: " . $paragraph;
            break;
        case 'dp4':
            $prompt = "Based on the following text, generate 5 relevant and thought-provoking questions or prompts for analysis. Each prompt should be concise and directly related to the content of the text. Number each question.\n\nText: " . $text . "\n\nFull context: " . $paragraph;
            $full_prompt = $prompt;
            break;
        case 'answerQuestion':
            $prompt = "You are an expert assistant. Provide a detailed and informative answer to the user's question based on the following text and its description. Use the information from the text and description, and incorporate relevant general knowledge to support your answer. Do not mention if information is missing; instead, provide the best possible answer.

Text: " . $text . "

Description: " . $description . "

Full context: " . $paragraph . "

Question: " . $userQuestion;
            $full_prompt = $prompt;
            break;
        default:
            throw new Exception("Invalid feature specified.");
    }

    return callOpenAI($full_prompt);
}

$error = '';
$htmlContent = '';
$userData = loadUserData();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type'])) {
    $prolificId = $_POST['prolific_id'] ?? 'unknown';
    $group = $_POST['group'] ?? 'unknown';
    $actionType = $_POST['action_type'];
    $details = json_decode($_POST['details'], true);

    logInteraction($prolificId, $group, $actionType, $details);

    echo json_encode(['success' => true]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_FILES['pdfFile'])) {
        $uploadDir = 'uploads/';
        $uploadFile = $uploadDir . basename($_FILES['pdfFile']['name']);

        try {
            if (!file_exists($uploadDir) && !mkdir($uploadDir, 0777, true)) {
                throw new Exception("Failed to create uploads directory.");
            }
            if (!is_writable($uploadDir)) {
                throw new Exception("Uploads directory is not writable.");
            }
            if (!move_uploaded_file($_FILES['pdfFile']['tmp_name'], $uploadFile)) {
                throw new Exception("Failed to move uploaded file.");
            }

            $htmlContent = convertPdfToHtml($uploadFile);
            unlink($uploadFile);

            echo json_encode(['htmlContent' => $htmlContent]);
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }elseif (isset($_POST['action'])) {
        $userId = $_POST['userId'] ?? 'unknown';
        if ($_POST['action'] === 'saveData') {
            $data = json_decode($_POST['data'], true);
            saveUserData($data);

            echo "Data saved successfully";
            exit;
        } elseif ($_POST['action'] === 'processText') {
            $text = $_POST['text'];
            $feature = $_POST['feature'];
            $userQuestion = isset($_POST['question']) ? $_POST['question'] : '';

            $practiceSets = json_decode(file_get_contents(__DIR__ . '/prompt.json'), true);
            $description = getDescriptionForText($text, $practiceSets);

            echo processWithAI($text, $feature, $userQuestion, $description);
            exit;
        } 

        elseif ($_POST['action'] === 'logInteraction') {
            $interactionType = $_POST['interactionType'];
            $details = $_POST['details'] ?? '';

            echo "Interaction logged successfully";
            exit;
        }
    }
}
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
        'page4' => '<h2>Task 3 - Page 4</h2><p><strong>Van Gogh Self-Portrait and Underimage</strong></p><p>X-ray examination of a recently discovered painting, judged by some authorities to be a self-portrait by Vincent van Gogh, revealed an underimage of a woman\'s face. Either van Gogh or another painter covered the first painting with the portrait now seen on the surface of the canvas. Because the face of the woman in the underimage also appears on canvases van Gogh is known to have painted, the surface painting must be an authentic self-portrait by van Gogh. Some paintings attributed to famous artists have undergone scrutiny in recent years, revealing underlayers of previously unknown works. For example, a famous van Gogh portrait was found to have a woman\'s face hidden beneath the final image. This discovery raises questions about the process of artistic creation and the decisions made by artists to conceal earlier works.</p>',

    ]
];

function logInteraction($prolificId, $group, $actionType, $details) {
    $filename = "uploads/{$prolificId}.csv";
    $isNewFile = !file_exists($filename);

    $data = [
        $prolificId,
        $group,
        date('Y-m-d H:i:s'),
        $actionType,
        $details['task'] ?? '',
        $details['page'] ?? '',
        $details['userInput'] ?? '',
        $details['aiResponse'] ?? '',
        $details['selectedText'] ?? '',
        $details['processedText'] ?? '',
        $details['feature'] ?? '',
        $details['elapsedTime'] ?? '',
        $details['score'] ?? ''
    ];

    $fp = fopen($filename, 'a');

    if ($isNewFile) {
        fputcsv($fp, ['prolific_id', 'group', 'timestamp', 'action_type', 'task_number', 'page_number', 'user_input', 'ai_response', 'selected_text', 'processed_text', 'feature_used', 'elapsed_time', 'score']);
    }

    fputcsv($fp, $data);
    fclose($fp);
}

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
      <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/shepherd.js/10.0.1/css/shepherd.css"/>
      <script src="https://cdnjs.cloudflare.com/ajax/libs/shepherd.js/10.0.1/js/shepherd.min.js"></script>
            <!-- Hotjar Tracking Code for Experiment -->
            <script async defer src="https://tools.luckyorange.com/core/lo.js?site-id=209d14e7"></script>
<script>
    (function(h,o,t,j,a,r){
        h.hj=h.hj||function(){(h.hj.q=h.hj.q||[]).push(arguments)};
        h._hjSettings={hjid:5163886,hjsv:6};
        a=o.getElementsByTagName('head')[0];
        r=o.createElement('script');r.async=1;
        r.src=t+h._hjSettings.hjid+j+h._hjSettings.hjsv;
        a.appendChild(r);
    })(window,document,'https://static.hotjar.com/c/hotjar-','.js?sv=');
</script>

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
  --error-color: #d93025;
  --success-color: #1e8e3e;
  --dark-blue: #202654;
  --text-color-button: #ffffff;
}

body, html {
  margin: 0;
  padding: 0;
  height: 100%;
  font-family: "Roboto", Arial, sans-serif;
  font-size: 14px;
  background-color: var(--white-color);
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
.analysis-chat-container {
    top: 350px !important;
}

.container {
  max-width: 1200px;
  margin: 40px auto;
  padding: 30px;
  background-color: var(--white-color);
  box-shadow: 0 1px 2px 0 rgba(60, 64, 67, 0.3),
    0 1px 3px 1px rgba(60, 64, 67, 0.15);
  border-radius: 8px;
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

input#prolific_id {
  padding: 0px;
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

.mdc-button--raised {
  box-shadow: 0 3px 1px -2px rgba(0, 0, 0, 0.2), 0 2px 2px 0 rgba(0, 0, 0, 0.14),
    0 1px 5px 0 rgba(0, 0, 0, 0.12);
}

.study-container {
  display: flex;
  width: 100%;
  height: 100vh;
  overflow: hidden;
}

.study-sidebar {
  flex: 0 0 300px;
  min-width: 310px;
  background-color: var(--sidebar-color);
  color: var(--black-color);
  padding: 2rem 1.5rem;
  box-shadow: 2px 0 5px rgba(0, 0, 0, 0.1);
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
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12), 0 1px 2px rgba(0, 0, 0, 0.24);
}

.study-textLayer > span {
  color: transparent;
  position: absolute;
  white-space: pre;
  cursor: text;
  transform-origin: 0% 0%;
}

.study-textLayer ::selection {
  background: rgba(0, 0, 255, 0.3);
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

.study-chat-container {
  background-color: var(--white-color);
  border-radius: 8px;
  display: flex;
  flex-direction: column;
  height: 400px;
  margin-top: 20px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12), 0 1px 2px rgba(0, 0, 0, 0.24);
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

.notification {
  position: fixed;
  bottom: 20px;
  left: 50%;
  transform: translateX(-50%);
  background-color: var(--primary-color);
  color: var(--white-color);
  padding: 10px 20px;
  border-radius: 4px;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
  z-index: 1000;
  display: none;
}

#results-summary,
#detailed-results {
  margin-bottom: 20px;
}

#review-timer {
  font-size: 1.2em;
  font-weight: bold;
  margin-bottom: 20px;
  color: var(--primary-color);
}

.question-page {
  background-color: #f0f0f0;
  padding: 20px;
  border-radius: 8px;
  border: 1px solid #ddd;
  box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
}

.question-card {
  background-color: #f2f2f2;
  padding: 15px;
  margin-bottom: 15px;
  border-radius: 4px;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.page-container {
  display: flex;
  flex-direction: column;
  align-items: center;
  margin-bottom: 40px;
}

.results-summary {
  background-color: var(--light-gray);
  padding: 20px;
  border-radius: 8px;
  margin-bottom: 20px;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
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
  box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
}

.question-result.correct {
  border-left: 5px solid var(--success-color);
}

.question-result.incorrect {
  border-left: 5px solid var(--error-color);
}

span.mdc-button__ripple:hover {
    display: none !important;
}

#showDetailedResults,
#completeStudyBtn {
  margin-top: 20px;
  width: 100%;
}

#detailedResults {
  margin-top: 20px;
  padding: 20px;
  background-color: var(--light-gray);
  border-radius: 8px;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
  animation: slideDown 0.5s ease-out;
}

.highlight {
  background-color: yellow;
  padding: 2px 4px;
  border-radius: 2px;
}

@keyframes slideDown {
  from {
    opacity: 0;
    transform: translateY(-20px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

button.action-button.close-button.mdc-button.mdc-button--outlined:hover {
    background-color: #d32f2f !important;
}

.result-indicator {
  font-weight: bold;
  margin-right: 10px;
}

.study-chat-container {
    background-color: var(--white-color);
    border-radius: 8px;
    display: flex;
    flex-direction: column;
    height: 400px;
    margin-top: 20px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12), 0 1px 2px rgba(0, 0, 0, 0.24);
    display: none;
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

.processed-text-container, .analysis-interface {
  position: fixed;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  background-color: var(--white-color);
  border-radius: 8px;
  box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
  padding: 20px;
  max-width: 80%;
  max-height: 80%;
  overflow-y: auto;
  z-index: 1001;
}

.mdc-button--raised:not(:disabled) {
  background-color: #202654 !important;
}

.mdc-select__dropdown-icon {
  bottom: 16px;
}

.mdc-text-field--outlined .mdc-text-field__input {
  padding-top: 12px;
  padding-bottom: 12px;
}

.mdc-text-field--outlined .mdc-floating-label {
  left: 16px;
  right: initial;
  top: 50%;
  transform: translateY(-50%);
  pointer-events: none;
}

.mdc-text-field--outlined .mdc-notched-outline .mdc-notched-outline__leading {
  border-top-left-radius: 4px;
  border-bottom-left-radius: 4px;
}

.mdc-text-field--outline

.mdc-text-field--outlined .mdc-notched-outline .mdc-notched-outline__trailing {
  border-top-right-radius: 4px;
  border-bottom-right-radius: 4px;
}

.mdc-select--outlined .mdc-select__anchor {
  height: 56px;
}

.mdc-select--outlined .mdc-floating-label {
  left: 16px;
  right: initial;
  top: 50%;
  transform: translateY(-50%);
  pointer-events: none;
}

.mdc-select--outlined .mdc-notched-outline .mdc-notched-outline__leading {
  border-top-left-radius: 4px;
  border-bottom-left-radius: 4px;
}

.mdc-select--outlined .mdc-notched-outline .mdc-notched-outline__trailing {
  border-top-right-radius: 4px;
  border-bottom-right-radius: 4px;
}

.loading-indicator {
  position: fixed;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  background-color: rgba(255, 255, 255, 0.8);
  padding: 20px;
  border-radius: 8px;
  box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
  z-index: 1002;
  display: none;
}

.loading-spinner {
  border: 4px solid #f3f3f3;
  border-top: 4px solid var(--primary-color);
  border-radius: 50%;
  width: 40px;
  height: 40px;
  animation: spin 1s linear infinite;
}

@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}

.predefined-prompts {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 10px;
}

button.menu-button.mdc-button.mdc-button--outlined{
min-width:32px ;
}

.study-page.pdf-page{
     border: 1px solid #c8cacc;
}

.predefined-prompt {
  border: none;
  border-radius: 16px;
  padding: 6px 12px;
  font-size: 14px;
  cursor: pointer;
  transition: background-color 0.3s;
}

.color-picker {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 10px;
}

.color-option {
  width: 24px;
  height: 24px;
  border-radius: 50%;
  cursor: pointer;
  border: 2px solid transparent;
  transition: border-color 0.3s;
}

.color-option:hover, .color-option.selected {
  border-color: var(--primary-color);
}

.success-animation {
  width: 100px;
  height: 100px;
  margin: 20px auto;
}

.study-textLayer {
    opacity: 0.9;
    line-height: 1.0;
}
.shepherd-has-title .shepherd-content .shepherd-header {
    background: #e6e6e6;
    padding: 0.5rem;
}

.success-checkmark {
  width: 80px;
  height: 80px;
  border-radius: 50%;
  display: block;
  stroke-width: 2;
  stroke: #4bb71b;
  stroke-miterlimit: 10;
  box-shadow: inset 0px 0px 0px #4bb71b;
  animation: fill .4s ease-in-out .4s forwards, scale .3s ease-in-out .9s both;
}

.success-checkmark__circle {
  stroke-dasharray: 166;
  stroke-dashoffset: 166;
  stroke-width: 2;
  stroke-miterlimit: 10;
  stroke: #4bb71b;
  fill: none;
  animation: stroke 0.6s cubic-bezier(0.65, 0, 0.45, 1) forwards;
}

.success-checkmark__check {
  transform-origin: 50% 50%;
  stroke-dasharray: 48;
  stroke-dashoffset: 48;
  animation: stroke 0.3s cubic-bezier(0.65, 0, 0.45, 1) 0.8s forwards;
}

@keyframes stroke {
  100% {
    stroke-dashoffset: 0;
  }
}

@keyframes scale {
  0%, 100% {
    transform: none;
  }
  50% {
    transform: scale3d(1.1, 1.1, 1);
  }
}

@keyframes fill {
  100% {
    box-shadow: inset 0px 0px 0px 30px #4bb71b;
  }
}

.study-floating-menu {
  position: fixed;
  top: 50%;
  right: 20px;
  transform: translateY(-50%);
  z-index: 1000;
  background-color: var(--primary-color);
  border-radius: 8px;
  padding: 10px;
  display: flex;
  flex-direction: column;
}

.menu-button {
  background-color: transparent;
  border: none;
  color: var(--white-color);
  cursor: pointer;
  font-size: 2px;
  padding: 10px;
  display: flex;
  align-items: center;
  transition: background-color 0.3s ease;
  position: relative;
}

.menu-button:hover {
  background-color: rgba(255, 255, 255, 0.1);
}

.menu-button i {
  width: 24px;
  text-align: center;
}

.menu-button span {
  position: absolute;
  right: 100%;
  white-space: nowrap;
  background-color: var(--primary-color);
  color: var(--white-color);
  padding: 5px 10px;
  border-radius: 4px;
  font-size: 14px;
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.3s ease, transform 0.3s ease;
  transform: translateX(10px);
}

.menu-button:hover span {
  opacity: 1;
  transform: translateX(0);
}

.floating-dialog {
  position: fixed;
  right: 65px;
  top: 295px;
  background-color: #ffffff;
  border-radius: 8px;
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
  width: 48px;
  transition: width 0.3s ease, height 0.3s ease;
  overflow: hidden;
  z-index: 1000;
  opacity: 0.9;
}

.floating-dialog.expanded {
  width: 320px;
  height: auto;
}

.dialog-header {
  display: inline-block;
  justify-content: flex-end;
  padding: 1px;
}

.dialog-controls {
  display: flex;
}

#closeDialog, #undoButton, #redoButton {
  background: none;
  border: none;
  color: #555;
  cursor: pointer;
  font-size: 18px;
  padding: 4px;
  transition: color 0.3s ease;
}

#undoButton {
    min-width: auto !important;
    overflow: visible !important;
    white-space: nowrap !important;
}

#closeDialog:hover, #undoButton:hover, #redoButton:hover {
  color: #000;
}

.predefined-prompt:hover {
    background-color: #1b1919 !important;
}

.feature-options {
  display: flex;
  flex-direction: column;
}

.feature-options button {
  background: none;
  border: none;
  padding: 1px;
  cursor: pointer;
  transition: background-color 0.3s ease;
  display: flex;
  align-items: center;
  justify-content: center;
}

.feature-options button:hover {
  background-color: #f0f0f0;
}

.feature-options button i {
  font-size: 24px;
  color: #333;
}

.feature-options button span {
  display: none;
}

.floating-dialog.expanded .feature-options {
  display: none !important;
}

.floating-dialog.expanded .processed-content {
  display: block;
}

#processedText, #analysisInterface {
  display: none;
}

.floating-dialog.expanded #processedText,
.floating-dialog.expanded #analysisInterface {
  display: block;
}
button#saveProcessedTextBtn {
    display: none;
}

div#floatingDialog {
    right: 75px !important;
    left:unset !important;
    display:block !important;
}

button.predefined-prompt {
    height: auto;
    background: #202654;

}

.floating-dialog {
    position: fixed;
    right: 65px;
    top: 295px;
    background-color: #ffffff;
    border-radius: 8px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
    width: 48px;
    transition: width 0.3s ease, height 0.3s ease;
    overflow: visible;
    z-index: 1000;
    opacity: 0.9;
}

.dialog-header button,
.feature-options button {
    position: relative;
}

.dialog-header button span,
.feature-options button .mdc-button__label {
    position: absolute;
    right: 100%;
    top: 50%;
    transform: translateY(-50%);
    white-space: nowrap;
    background-color: var(--primary-color);
    color: var(--white-color);
    padding: 5px 10px;
    border-radius: 4px;
    font-size: 14px;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.3s ease, transform 0.3s ease;
    transform: translateX(10px);
}

.dialog-header button:hover span,
.feature-options button:hover .mdc-button__label {
    opacity: 1;
    transform: translateY(-50%) translateX(0);
}

.feature-options {
    display: flex;
    flex-direction: column;
}

.feature-options button {
    justify-content: center;
    width: 100%;
    margin-bottom: 8px;
}

.feature-options button .mdc-button__icon {
    margin-right: 0;
}

.floating-dialog.expanded {
    width: 320px;
}

.floating-dialog.expanded .feature-options button .mdc-button__label {
    position: static;
    opacity: 1;
    transform: none;
    margin-left: 8px;
}

.floating-dialog {
    position: fixed;
    right: 65px;
    top: 295px;
    background-color: #ffffff;
    border-radius: 8px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
    width: 48px;
    transition: width 0.3s ease, height 0.3s ease;
    overflow: visible;
    z-index: 1000;
    opacity: 0.9;
}

.dialog-header .menu-button,
.feature-options .menu-button {
    position: relative;
    overflow: visible;
}

.dialog-header .menu-button span,
.feature-options .menu-button .mdc-button__label {
    position: absolute;
    right: calc(100% + 10px);
    top: 50%;
    transform: translateY(-50%);
    white-space: nowrap;
    background-color: var(--primary-color, #202654);
    color: var(--white-color, #ffffff);
    padding: 5px 10px;
    border-radius: 4px;
    font-size: 14px;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.3s ease, transform 0.3s ease;
    z-index: 1001;
}

.dialog-header .menu-button:hover span,
.feature-options .menu-button:hover .mdc-button__label {
    opacity: 1;
    transform: translateY(-50%);
}

.feature-options {
    display: flex;
    flex-direction: column;
}

.feature-options .menu-button {
    justify-content: center;
    width: 100%;
    margin-bottom: 8px;
}

.feature-options .menu-button .mdc-button__icon {
    margin-right: 0;
}

.floating-dialog.expanded {
    width: 320px;
}

.floating-dialog.expanded .feature-options .menu-button .mdc-button__label {
    position: static;
    opacity: 1;
    transform: none;
    margin-left: 8px;
}

.dialog-header .menu-button span,
.feature-options .menu-button .mdc-button__label {
    display: inline-block;
    visibility: visible;
}

.dialog-header .menu-button span::after,
.feature-options .menu-button .mdc-button__label::after {
    content: '';
    position: absolute;
    right: -10px;
    top: 50%;
    transform: translateY(-50%);
    border-left: 10px solid var(--primary-color, #202654);
    border-top: 5px solid transparent;
    border-bottom: 5px solid transparent;
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

span[role="presentation"] {
    color: black !important;
    background-color: white !important;
}

.study-textLayer {
    background: white;
    opacity: 1;
}

.study-page.pdf-page {
    background-color: white;
    padding: 20px;
    margin-bottom: 20px;
    box-sizing: border-box;
    width: 8.5in;
    height: 11in;
    overflow: hidden;
}

.question-page {
    width: 8.5in;
    min-height: 11in;
    background-color: rgb(235, 235, 235);
    padding: 20px;
    margin-bottom: 20px;
    box-sizing: border-box;
}

.shepherd-has-title .shepherd-content .shepherd-cancel-icon:hover {
    color: rgb(11 11 11 / 75%);
    background: #80808080;
}
.processed-content-container {
    cursor: grab;  
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

  #ai-loading-overlay svg {
    height: 150px;
    width: 150px;
  }

  #ai-loading-message {
    margin-top: 20px;
    font-size: 18px;
    color: #202654;
    font-weight: bold;
  }
  button.action-button.accept-button.mdc-button.mdc-button--raised:hover {
    background: green !important;
}

</style>
   </head>
   <body class="mdc-typography">
      <div id="notification-container"></div>
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
             <img src="experiment.png" alt="Tool Demo" class="demo-image">

               <ol class="mdc-list">
                  <li class="mdc-list-item">
                     <span class="mdc-list-item__graphic material-icons">looks_one</span>
                     <span class="mdc-list-item__text">Read the provided PDF document</span>
                  </li>
                  <li class="mdc-list-item">
                     <span class="mdc-list-item__graphic material-icons">looks_two</span>
                     <span class="mdc-list-item__text">Select text and use the AI Reading assistant for clarifications</span>
                  </li>
                  <li class="mdc-list-item">
                     <span class="mdc-list-item__graphic material-icons">looks_3</span>
                     <span class="mdc-list-item__text">Answer comprehension questions</span>
                  </li>
                  <li class="mdc-list-item">
                     <span class="mdc-list-item__graphic material-icons">looks_4</span>
                     <span class="mdc-list-item__text">Proceed to the next task</span>
                  </li>
               </ol>
            </div>
         </div>
         <!--<h2 class="mdc-typography--headline5">PDF Reader</h2>-->
         <!--<p class="mdc-typography--body1">You will be reading documents in a PDF format. The reader allows you to:</p>-->
         <!--<ul class="mdc-list">-->
         <!--   <li class="mdc-list-item">-->
         <!--      <span class="mdc-list-item__graphic material-icons">zoom_in</span>-->
         <!--      <span class="mdc-list-item__text">Zoom in and out for comfortable reading</span>-->
         <!--   </li>-->
         <!--   <li class="mdc-list-item">-->
         <!--      <span class="mdc-list-item__graphic material-icons">navigate_next</span>-->
         <!--      <span class="mdc-list-item__text">Navigate between pages</span>-->
         <!--   </li>-->
         <!--   <li class="mdc-list-item">-->
         <!--      <span class="mdc-list-item__graphic material-icons">fullscreen</span>-->
         <!--      <span class="mdc-list-item__text">View in full screen mode</span>-->
         <!--   </li>-->
         <!--</ul>-->
         <h2 class="mdc-typography--headline5">AI-Powered Reading Assistant</h2>
         <p class="mdc-typography--body1">Our advanced AI Reading assistant will help you:</p>
         <ul class="mdc-list">
            <li class="mdc-list-item">
               <span class="mdc-list-item__graphic material-icons">help_outline</span>
               <span class="mdc-list-item__text">Receive simplified explanations of difficult passages and complex terms or concepts</span>
            </li>
            <li class="mdc-list-item">
               <span class="mdc-list-item__graphic material-icons">comment</span>
               <span class="mdc-list-item__text">Structure the selected content to simple points</span>
            </li>
            <li class="mdc-list-item">
               <span class="mdc-list-item__graphic material-icons">short_text</span>
               <span class="mdc-list-item__text">Get essential on specific paragraphs</span>
            </li>
            <li class="mdc-list-item">
               <span class="mdc-list-item__graphic material-icons">psychology</span>
               <span class="mdc-list-item__text">Ask questions about the content you're reading</span>
            </li>
         </ul>
         <h2 class="mdc-typography--headline5">Using the AI Assistant</h2>
         <p class="mdc-typography--body1">To make the most of the AI assistant:</p>
            <ul class="mdc-list">
                <li class="mdc-list-item">
                     <i class="material-icons mdc-list-item__graphic">select_all</i>
                    <span class="mdc-list-item__text">Select any text in the document</span>

                </li>
                <li class="mdc-list-item">
                    <i class="material-icons mdc-list-item__graphic">menu</i>
                    <span class="mdc-list-item__text">Choose an option from the pop-up menu (Simplify, Structure, Essentials, or Analyze)</span>

                </li>
                <li class="mdc-list-item">
                    <i class="material-icons mdc-list-item__graphic">visibility</i>
                    <span class="mdc-list-item__text">Review the AI-generated content to enhance your understanding</span>

                </li>
                <li class="mdc-list-item">
                    <i class="material-icons mdc-list-item__graphic">chat</i>
                    <span class="mdc-list-item__text">For more in-depth assistance, use the chat feature to ask specific questions</span>

                </li>
                <li class="mdc-list-item">
                    <i class="material-icons mdc-list-item__graphic">highlight</i>
                    <span class="mdc-list-item__text">Utilize the highlight feature to bookmark important information for later review</span>

                </li>
                <li class="mdc-list-item">
                    <i class="material-icons mdc-list-item__graphic">autorenew</i>
                    <span class="mdc-list-item__text">Experiment with multiple options to understand different angles on the text</span>

                </li>
                <li class="mdc-list-item">
                    <i class="material-icons mdc-list-item__graphic">feedback</i>
                    <span class="mdc-list-item__text">Provide feedback on AI responses to improve the tool's suggestions</span>

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
         <p class="mdc-typography--body1 important-note">Remember: While the AI Reading assistant is here to help you understand the text, you must answer all questions based on your own comprehension. You must answer all questions before proceeding to the next task.</p>
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
         @media (max-width: 768px) {
         .demo-container {
         flex-direction: column;
         }
         .demo-image,
         .demo-container .mdc-list {
         width: 100%;
         }
         .demo-container .mdc-list {
         margin-top: 1rem;
         }
         }
         .important-note {
         background-color: #fff3cd;
         border-left: 5px solid #ffeeba;
         padding: 15px;
         margin-top: 20px;
         border-radius: 4px;
         }
         .mdc-list--ordered {
         list-style-type: decimal;
         padding-left: 30px;
         }
         .mdc-list--ordered .mdc-list-item {
         display: list-item;
         padding-left: 10px;
         }
         .mdc-list--ordered .mdc-list-item::marker {
         color: var(--mdc-theme-primary);
         font-weight: bold;
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
         </div>
         <div class="study-main-content">
            <div id="study-pdf-viewer"></div>
            <div class="study-chat-container">
               <div class="study-chat-messages" id="study-chat-messages"></div>
               <div class="study-chat-input">

                  <input type="text" id="study-chat-input" placeholder="Type your message...">

                  <button class="study-chat-button" id="study-send-button"><i class="fas fa-paper-plane"></i></button>
               </div>
            </div>
         </div>

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
<!--<div id="floatingDialog" class="floating-dialog mdc-elevation--z8">-->
<!--    <div class="dialog-header">-->
<!--        <h3 class="mdc-typography--subtitle1">Options</h3>-->
<!--        <div class="dialog-controls">-->
<!--            <button id="undoButton" class="mdc-icon-button material-icons" title="Undo">undo</button>-->
<!--            <button id="redoButton" class="mdc-icon-button material-icons" title="Redo">redo</button>-->
<!--            <button id="closeDialog" class="mdc-icon-button material-icons" title="Close">close</button>-->
<!--        </div>-->
<!--    </div>-->
<!--    <div class="dialog-content">-->
<!--        <div class="highlight-section" >-->
<!--            <h4 class="mdc-typography--subtitle2">Highlight</h4>-->
<!--            <div id="colorOptions"></div>-->
<!--        </div>-->
<!--        <div class="feature-section">-->
<!--            <h4 class="mdc-typography--subtitle2">Text Processing</h4>-->
<!--            <div class="feature-options">-->
<!--                <button class="mdc-button mdc-button--outlined" onclick="processText('dp1')">-->
<!--                    <span class="mdc-button__ripple"></span>-->
<!--                    <i class="material-icons mdc-button__icon" aria-hidden="true">lightbulb</i>-->
<!--                    <span class="mdc-button__label">Simplify</span>-->
<!--                </button>-->
<!--                <button class="mdc-button mdc-button--outlined" onclick="processText('dp2')">-->
<!--                    <span class="mdc-button__ripple"></span>-->
<!--                    <i class="material-icons mdc-button__icon" aria-hidden="true">format_list_bulleted</i>-->
<!--                    <span class="mdc-button__label">Structure</span>-->
<!--                </button>-->
<!--                <button class="mdc-button mdc-button--outlined" onclick="processText('dp3')">-->
<!--                    <span class="mdc-button__ripple"></span>-->
<!--                    <i class="material-icons mdc-button__icon" aria-hidden="true">format_size</i>-->
<!--                    <span class="mdc-button__label">Essentials</span>-->
<!--                </button>-->
<!--                <button class="mdc-button mdc-button--outlined" onclick="processText('dp4')">-->
<!--                    <span class="mdc-button__ripple"></span>-->
<!--                    <i class="material-icons mdc-button__icon" aria-hidden="true">question_answer</i>-->
<!--                    <span class="mdc-button__label">Analyze</span>-->
<!--                </button>-->
<!--            </div>-->
<!--        </div>-->
<!--        <div id="processedText" class="mdc-typography--body2"></div>-->
<!--        <button onclick="saveProcessedText()" id="saveProcessedTextBtn" class="mdc-button mdc-button--raised" style="display: none;">-->
<!--            <span class="mdc-button__ripple"></span>-->
<!--            <i class="material-icons mdc-button__icon" aria-hidden="true">save</i>-->
<!--            <span class="mdc-button__label">Save Processed Text</span>-->
<!--        </button>-->
<!--        <div id="analysisInterface" style="display: none;">-->
<!--            <div class="analysis-container">-->
<!--                <div class="selected-text">-->
<!--                    <h4 class="mdc-typography--subtitle2">Selected Text</h4>-->
<!--                    <div id="selectedTextContent" class="mdc-typography--body2"></div>-->
<!--                </div>-->
<!--                <div class="chat-container">-->
<!--                    <div class="predefined-prompts" id="predefinedPrompts"></div>-->
<!--                    <div class="chat-messages" id="chatMessages"></div>-->
<!--                    <div class="chat-input">-->
<!--                        <div class="mdc-text-field mdc-text-field--outlined mdc-text-field--with-trailing-icon">-->
<!--                            <input type="text" id="userInput" class="mdc-text-field__input">-->
<!--                            <div class="mdc-notched-outline">-->
<!--                                <div class="mdc-notched-outline__leading"></div>-->
<!--                                <div class="mdc-notched-outline__notch">-->
<!--                                    <label for="userInput" class="mdc-floating-label">Type your message...</label>-->
<!--                                </div>-->
<!--                                <div class="mdc-notched-outline__trailing"></div>-->
<!--                            </div>-->
<!--                            <i class="material-icons mdc-text-field__icon mdc-text-field__icon--trailing" tabindex="0" role="button" onclick="sendMessage()">send</i>-->
<!--                        </div>-->
<!--                    </div>-->
<!--                </div>-->
<!--            </div>-->
<!--        </div>-->
<!--    </div>-->
<!--</div>-->

<!--<div id="floatingDialog" class="floating-dialog">-->
<!--    <div class="dialog-header">-->
<!--        <button id="closeDialog" class="material-icons close-button">close</button>-->
<!--    </div>-->
<!--    <div class="dialog-content">-->
<!--        <div class="feature-options">-->
<!--            <button onclick="processText('dp1')" title="Simplify">-->
<!--                <i class="material-icons">lightbulb</i>-->
<!--            </button>-->
<!--            <button onclick="processText('dp2')" title="Structure">-->
<!--                <i class="material-icons">format_list_bulleted</i>-->
<!--            </button>-->
<!--            <button onclick="processText('dp3')" title="Essentials">-->
<!--                <i class="material-icons">format_size</i>-->
<!--            </button>-->
<!--            <button onclick="processText('dp4')" title="Analyze">-->
<!--                <i class="material-icons">question_answer</i>-->
<!--            </button>-->
<!--        </div>-->
<!--        <div class="processed-content">-->
<!--            <div id="processedText"></div>-->
<!--            <div id="analysisInterface"></div>-->
<!--            <button id="saveProcessedTextBtn">Save</button>-->
<!--        </div>-->
<!--    </div>-->
<!--</div>-->

<div id="floatingDialog" class="floating-dialog mdc-elevation--z8">
    <div class="dialog-header">

    </div>
    <div class="dialog-content">
        <div class="feature-options">
                <button id="undoButton" class="menu-button menu-button mdc-button mdc-button--outlined mdc-icon-button material-icons">

         <i class="material-icons mdc-button__icon" aria-hidden="true">undo</i>
        <span class="mdc-button__label">Undo</span>
    </button>
            <button class="menu-button mdc-button mdc-button--outlined" onclick="processText('dp1')">
                <span class="mdc-button__ripple"></span>
                <i class="material-icons mdc-button__icon" aria-hidden="true">lightbulb</i>
                <span class="mdc-button__label">Simplify</span>
            </button>
              <button class="menu-button mdc-button mdc-button--outlined" onclick="processText('dp2')">
                <span class="mdc-button__ripple"></span>
                <i class="material-icons mdc-button__icon" aria-hidden="true">format_list_bulleted</i>
                <span class="mdc-button__label">Structure</span>
            </button>
             <button class="menu-button mdc-button mdc-button--outlined" onclick="processText('dp3')">
                <span class="mdc-button__ripple"></span>
                <i class="material-icons mdc-button__icon" aria-hidden="true">format_size</i>
                <span class="mdc-button__label">Essential</span>
            </button>

            <button class="menu-button mdc-button mdc-button--outlined" onclick="processText('dp4')">
                <span class="mdc-button__ripple"></span>
                <i class="material-icons mdc-button__icon" aria-hidden="true">question_answer</i>
                <span class="mdc-button__label">Analyze</span>
            </button>
        </div>
        <!--<div id="processedText" class="mdc-typography--body2"></div>-->
        <!--<button onclick="saveProcessedText()" id="saveProcessedTextBtn" class="mdc-button mdc-button--raised" style="display: none;">-->
        <!--    <span class="mdc-button__ripple"></span>-->
        <!--    <i class="material-icons mdc-button__icon" aria-hidden="true">save</i>-->
        <!--    <span class="mdc-button__label">Save Processed Text</span>-->
        <!--</button>-->
        <div id="analysisInterface" style="display: none;">
            <div class="analysis-container">
                <div class="selected-text">
                    <h4 class="mdc-typography--subtitle2">Selected Text</h4>
                    <div id="selectedTextContent" class="mdc-typography--body2"></div>
                </div>
                <div class="chat-container">
                    <div class="predefined-prompts" id="predefinedPrompts"></div>
                    <div class="chat-messages" id="chatMessages"></div>
                    <div class="chat-input">
                        <div class="mdc-text-field mdc-text-field--outlined mdc-text-field--with-trailing-icon">
                            <input type="text" id="userInput" class="mdc-text-field__input">
                            <div class="mdc-notched-outline">
                                <div class="mdc-notched-outline__leading"></div>
                                <div class="mdc-notched-outline__notch">
                                    <label for="userInput" class="mdc-floating-label">Type your message...</label>
                                </div>
                                <div class="mdc-notched-outline__trailing"></div>
                            </div>
                            <i class="material-icons mdc-text-field__icon mdc-text-field__icon--trailing" tabindex="0" role="button" onclick="sendMessage()">send</i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="loadingIndicator" style="display: none;">Loading...</div>

     <!--<div class="loading" id="loadingIndicator">-->
     <!--   <div class="loading-content">-->
     <!--       <div class="loading-spinner"></div>-->
     <!--       <p class="mdc-typography--body2">Processing your request...</p>-->
     <!--</div>-->
    </div>

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

             function logInteraction(actionType, details = {}) {
                const prolificId = '<?php echo $_SESSION['prolific_id'] ?? 'unknown'; ?>';
                const userGroup = '<?php echo $_SESSION['study_group']; ?>';

                fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action_type=' + encodeURIComponent(actionType) +
                          '&details=' + encodeURIComponent(JSON.stringify(details)) +
                          '&prolific_id=' + encodeURIComponent(prolificId) +
                          '&group=' + encodeURIComponent(userGroup)
                });
            }

            const taskDurations = {
                1: 540,  
                2: 660,  
                3: 600   
            };
            let taskTimer;
            let currentTaskStartTime;      
            let dpCounts = {
                dp1: 0,
                dp2: 0,
                dp3: 0,
                dp4: 0
            };
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

             function showNotification(message) {
                 notification.textContent = message;
                 notification.style.display = 'block';

                 setTimeout(() => {
                     notification.style.display = 'none';
                 }, 3000);
             }

             function showPage(index) {

                   logInteraction('page_view', {
                        pageNumber: index + 1,
                        task: getCurrentTask()
                    });
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

                     } else if (!floatingDialog.contains(event.target)) {
                         floatingDialog.style.display = 'none';
                     }
                 });
             }

function resetDialog() {
    const floatingDialog = document.getElementById('floatingDialog');
    floatingDialog.classList.remove('expanded');
    document.querySelector('.feature-options').style.display = 'flex';
    document.querySelector('.processed-content').style.display = 'none';
    document.getElementById('processedText').innerHTML = '';
    document.getElementById('analysisInterface').style.display = 'none';

}
             const registrationForm = document.getElementById('registrationForm');

             if (registrationForm) {

                 registrationForm.addEventListener('submit', function(event) {
                     const prolificId = document.getElementById('prolific_id').value;
                     sessionStorage.setItem('prolific_id', prolificId);
                     const age = document.getElementById('age').value;
                     const gender = document.querySelector('.mdc-select[aria-label="Gender select"] .mdc-select__selected-text').textContent;
                     const education = document.querySelector('.mdc-select[aria-label="Education select"] .mdc-select__selected-text').textContent;
                     const englishProficiency = document.querySelector('.mdc-select[aria-label="English Proficiency select"] .mdc-select__selected-text').textContent;
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

             function showNotification(message, type = 'info', duration = 5000) {
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

          async function renderPdf(taskNum) {

    const viewer = document.getElementById('study-pdf-viewer');
    if (!viewer) {
        console.error('PDF viewer element not found');
        return;
    }
    viewer.innerHTML = '';

    const taskContent = window.taskContent[taskNum];
    const questions = questionsData[`Practice Set ${taskNum}`] || [];

    for (let pageNum = 1; pageNum <= Object.keys(taskContent).length; pageNum++) {
        const pageContent = taskContent[`page${pageNum}`];

        const pageContainer = document.createElement('div');
        pageContainer.className = 'page-container';
        viewer.appendChild(pageContainer);

        const contentDiv = document.createElement('div');
        contentDiv.className = 'study-page pdf-page';
        contentDiv.style.backgroundColor = 'white';
        contentDiv.style.padding = '20px';
        contentDiv.style.marginBottom = '20px';
        contentDiv.style.boxSizing = 'border-box';
        contentDiv.style.width = '8.5in'; 
        contentDiv.style.height = '11in'; 
        contentDiv.style.overflow = 'hidden';
        contentDiv.innerHTML = pageContent;
        pageContainer.appendChild(contentDiv);

        const questionNumbers = questionMapping[taskNum][pageNum] || [];
        if (questionNumbers.length > 0) {
            const questionDiv = document.createElement('div');
            questionDiv.className = 'question-page';
            questionDiv.style.width = '8.5in'; 
            questionDiv.style.minHeight = '11in'; 
            questionDiv.style.backgroundColor = 'rgb(235, 235, 235)';
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
            document.addEventListener('DOMContentLoaded', updateProgressBar);
            document.body.addEventListener('change', function(event) {
                if (event.target.matches('.question-card input')) {
                    updateProgressBar();
                }
            });
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

                    showNotification(`Loading Task ${taskNum}...`, 'info');
                    await renderPdf(taskNum);
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
                 loadTask(currentTask);
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
                     nextTaskBtn.disabled = !allAnswered;
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

             window.studyZoomIn = function() {
                 SCALE *= 1.2;
                 if (currentPdf) renderPdf(currentPdf);
             }

             window.studyZoomOut = function() {
                 SCALE /= 1.2;
                 if (currentPdf) renderPdf(currentPdf);
             }

             window.studyResetZoom = function() {
                 SCALE = 1.0;
                 if (currentPdf) renderPdf(currentPdf);
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

            logInteraction('task_complete', {
                task: currentTask,
                elapsedTime: elapsedTime,
                score: score
            });

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
                    logInteraction('chat_message', {
                        userInput: message,
                        task: getCurrentTask(),
                        page: getCurrentPage()
                    });
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

                 window.location.href = 'https://ww2.unipark.de/uc/expgroup/';
             });

             saveTotalScore(totalScore);

            setTimeout(function() {
                window.location.href = 'https://ww2.unipark.de/uc/expgroup/';
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

window.insertProcessedContent = function(range, processedText, showAcceptButton) {

    if (!range) {
        console.error('Range is null');
        throw new Error('Range is null');
    }

    const container = document.createElement('div');
    container.className = 'processed-content-container';

    const processedTextElement = document.createElement('div');
    processedTextElement.className = 'processed-text';

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
            container.remove();  
            saveProcessedText(range, processedText);
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

    Object.assign(processedTextElement.style, {
        marginBottom: '16px',
        maxHeight: '200px',
        overflowY: 'auto'
    });

    Object.assign(buttonContainer.style, {
        display: 'flex',
        justifyContent: 'flex-end',
        gap: '8px'
    });

    const buttonStyle = {
        minWidth: 'auto',
        padding: '0 16px',
        height: '36px',
        fontSize: '14px',
        fontWeight: '500',
        textTransform: 'uppercase',
        borderRadius: '4px',
        transition: 'background-color 0.3s ease'
    };

    if (showAcceptButton) {
        const acceptButton = buttonContainer.querySelector('.accept-button');
        Object.assign(acceptButton.style, buttonStyle, {
            backgroundColor: '#202654',
            color: 'white'
        });
        acceptButton.onmouseover = function() { this.style.backgroundColor = '#2d367a'; };
        acceptButton.onmouseout = function() { this.style.backgroundColor = '#202654'; };
    }

    Object.assign(closeButton.style, buttonStyle, {
        backgroundColor: '#d32f2f',
        color: 'white',
        border: 'none'
    });
    closeButton.onmouseover = function() { this.style.backgroundColor = '#f44336'; };
    closeButton.onmouseout = function() { this.style.backgroundColor = '#d32f2f'; };

    document.body.appendChild(container);

    makeDraggable(container);

    if (showAcceptButton) {
        typeText(processedTextElement, processedText, 10, 'rgba(255, 255, 255, 0.1)');
    } else {
        const formattedText = formatProcessedText(processedText);
        processedTextElement.innerHTML = formattedText;

    }

    window.lastProcessedContainer = container;
    return container;
}

function formatProcessedText(text) {

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

function makeDraggable(element) {
    let isDragging = false;
    let offsetX = 0, offsetY = 0;

    element.addEventListener('mousedown', startDragging);
    document.addEventListener('mousemove', drag);
    document.addEventListener('mouseup', stopDragging);

    function startDragging(e) {
        isDragging = true;
        offsetX = e.clientX - element.offsetLeft;
        offsetY = e.clientY - element.offsetTop;
        element.style.cursor = 'grabbing';
    }

    function drag(e) {
        if (isDragging) {
            element.style.left = `${e.clientX - offsetX}px`;
            element.style.top = `${e.clientY - offsetY}px`;
        }
    }

    function stopDragging() {
        isDragging = false;
        element.style.cursor = 'grab';
    }
}

const draggableElements = document.querySelectorAll(".processed-content-container");

draggableElements.forEach(draggableElement => {
    let isDragging = false;  
    let offsetX = 0, offsetY = 0;

    draggableElement.addEventListener("mousedown", (e) => {
        isDragging = true;
        offsetX = e.clientX - draggableElement.offsetLeft;
        offsetY = e.clientY - draggableElement.offsetTop;
        draggableElement.style.cursor = "grabbing"; 
    });

    document.addEventListener("mousemove", (e) => {
        if (isDragging) {
            draggableElement.style.left = `${e.clientX - offsetX}px`;
            draggableElement.style.top = `${e.clientY - offsetY}px`;
        }
    });

    document.addEventListener("mouseup", () => {
        isDragging = false;
        draggableElement.style.cursor = "grab";  
    });
});
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
      </script>

          <script src="https://unpkg.com/material-components-web@latest/dist/material-components-web.min.js"></script>
 <script>
        let userData = { highlights: [], adaptations: [], dialogPosition: { x: 20, y: '10%' } };

        let selectedText = '';
        let selectedRange = null;
        let isDialogExpanded = false;
        let currentPage = 0;
        let pages;
        let prevBtn;
        let nextBtn;
        let pageInfo;
        let floatingDialog;
        let colorOptions;
        let loadingIndicator;
        let isDragging = false;
        let dragOffset = { x: 0, y: 0 };
        let userId = 'unknown';
        let lastHighlight = null;
        window.undoStack = [];
        window.redoStack = [];
        let lastProcessedContainer = null;

        document.addEventListener('DOMContentLoaded', function() {
            pages = document.querySelectorAll('.page');
            prevBtn = document.getElementById('prevBtn');
            nextBtn = document.getElementById('nextBtn');
            pageInfo = document.getElementById('pageInfo');
            floatingDialog = document.getElementById('floatingDialog');
            colorOptions = document.getElementById('colorOptions');
            loadingIndicator = document.getElementById('loadingIndicator');

            try {
                userData = <?php echo json_encode($userData); ?> || {};
                if (userData.dialogPosition) {
                    floatingDialog.style.left = userData.dialogPosition.x + 'px';
                    floatingDialog.style.top = userData.dialogPosition.y + 'px';
                }
            } catch (error) {
                userData = { highlights: [], adaptations: [], dialogPosition: { x: 20, y: '10%' } };
            }

            const colors = ['yellow', 'lightgreen', 'lightblue', 'pink', 'orange'];

            colors.forEach(color => {
                const div = document.createElement('div');
                div.className = 'color-option';
                div.style.backgroundColor = color;
                div.addEventListener('click', (e) => {
                    e.stopPropagation(); 
                    selectColor(color);
                });
                colorOptions.appendChild(div);
            });

            prevBtn.addEventListener('click', () => {
                if (currentPage > 0) {
                    currentPage--;
                    showPage(currentPage);
                }
            });

            nextBtn.addEventListener('click', () => {
                if (currentPage < pages.length - 1) {
                    currentPage++;
                    showPage(currentPage);
                }
            });

            document.getElementById('closeDialog').addEventListener('click', function(e) {
                e.stopPropagation();
                floatingDialog.style.display = 'none';
                floatingDialog.classList.remove('expanded');
                isDialogExpanded = false;
            });

            document.getElementById('undoButton').addEventListener('click', function(e) {
                e.stopPropagation();
                undo();
            });

            document.getElementById('redoButton').addEventListener('click', function(e) {
                e.stopPropagation();
                redo();
            });

            if (userData.highlights && Array.isArray(userData.highlights)) {
                userData.highlights.forEach(highlight => {
                    let page = document.getElementById(`page-${highlight.page}`);
                    if (page) {
                        let textNodes = getTextNodes(page);
                        textNodes.forEach(node => {
                            let index = node.textContent.indexOf(highlight.text);
                            if (index >= 0) {
                                let range = document.createRange();
                                range.setStart(node, index);
                                range.setEnd(node, index + highlight.text.length);
                                let span = document.createElement('span');
                                span.className = 'highlight';
                                span.style.backgroundColor = highlight.color;
                                range.surroundContents(span);
                            }
                        });
                    }
                });
            }

            if (userData.adaptations && Array.isArray(userData.adaptations)) {
                userData.adaptations.forEach(adaptation => {
                    let page = document.getElementById(`page-${adaptation.page}`);
                    if (page) {
                        let textNodes = getTextNodes(page);
                        textNodes.forEach(node => {
                            let index = node.textContent.indexOf(adaptation.original);
                            if (index >= 0) {
                                let range = document.createRange();
                                range.setStart(node, index);
                                range.setEnd(node, index + adaptation.original.length);
                                let span = document.createElement('span');
                                span.className = 'processed';
                                span.innerHTML = adaptation.processed;
                                range.deleteContents();
                                range.insertNode(span);
                            }
                        });
                    }
                });
            }

            showPage(0);

            mdc.autoInit();

            const textFields = document.querySelectorAll('.mdc-text-field');
            textFields.forEach(textField => new mdc.textField.MDCTextField(textField));

            floatingDialog.addEventListener('mousedown', startDragging);
            document.addEventListener('mousemove', drag);
            document.addEventListener('mouseup', stopDragging);

            userId = prompt("Please enter your Prolific ID:");
            if (!userId) userId = 'unknown';

            const pdfUploadForm = document.getElementById('pdfUploadForm');
            pdfUploadForm.addEventListener('submit', handlePdfUpload);
        });

        function handlePdfUpload(e) {
            e.preventDefault();
            const formData = new FormData(e.target);
            const progressBarContainer = document.getElementById('progressBarContainer');
            const bookContainer = document.getElementById('bookContainer');
            const errorContainer = document.getElementById('errorContainer');

            progressBarContainer.innerHTML = `
                <p>Converting PDF, please wait...</p>
                <div class="loading-animation">
                    <div class="loading-dot"></div>
                    <div class="loading-dot"></div>
                    <div class="loading-dot"></div>
                </div>
            `;
            progressBarContainer.style.display = 'block';
            bookContainer.style.display = 'none';
            errorContainer.style.display = 'none';

            const xhr = new XMLHttpRequest();
            xhr.open('POST', '', true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.error) {
                            showError(response.error);
                        } else if (response.htmlContent) {
                            showSuccessAnimation(() => {
                                finishProcessing(response.htmlContent);
                            });
                        }
                    } catch (error) {
                        showError('An error occurred while processing the response.');
                    }
                } else {
                    showError('An error occurred while processing the PDF. Please try again.');
                }
            };
            xhr.onerror = function() {
                showError('An error occurred while uploading the file. Please try again.');
            };
            xhr.send(formData);
        }

        function showSuccessAnimation(callback) {
            const progressBarContainer = document.getElementById('progressBarContainer');
            progressBarContainer.innerHTML = `
                <p>PDF converted successfully!</p>
                <div class="success-animation">
                    <svg class="success-checkmark" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 52 52">
                        <circle class="success-checkmark__circle" cx="26" cy="26" r="25" fill="none"/>
                        <path class="success-checkmark__check" fill="none" d="M14.1 27.2l7.1 7.2 16.7-16.8"/>
                    </svg>
                </div>
            `;

            setTimeout(callback, 2000);
        }

        function showError(message) {
            const errorContainer = document.getElementById('errorContainer');
            const progressBarContainer = document.getElementById('progressBarContainer');
            errorContainer.textContent = message;
            errorContainer.style.display = 'block';
            progressBarContainer.style.display = 'none';
        }

        function finishProcessing(htmlContent) {
            const bookContainer = document.getElementById('bookContainer');
            const progressBarContainer = document.getElementById('progressBarContainer');
            bookContainer.querySelector('.book').innerHTML = htmlContent;
            bookContainer.style.display = 'block';
            progressBarContainer.style.display = 'none';
            initializeBook();
        }

        function initializeBook() {
            pages = document.querySelectorAll('.page');
            showPage(0);
            updatePageInfo();
            updateButtonStates();
        }

        function startDragging(e) {
            if (e.target === floatingDialog || e.target.closest('.floating-dialog')) {
                isDragging = true;
                dragOffset.x = e.clientX - floatingDialog.offsetLeft;
                dragOffset.y = e.clientY - floatingDialog.offsetTop;
            }
        }

        function drag(e) {
            if (isDragging) {
                floatingDialog.style.left = (e.clientX - dragOffset.x) + 'px';
                floatingDialog.style.top = (e.clientY - dragOffset.y) + 'px';
                e.preventDefault();
            }
        }

        function stopDragging() {
            if (isDragging) {
                isDragging = false;
                userData.dialogPosition = {
                    x: parseInt(floatingDialog.style.left),
                    y: parseInt(floatingDialog.style.top)
                };
                saveUserData();

            }
        }

        function showPage(index) {
            pages.forEach((page, i) => {
                page.style.transform = `translateX(${100 * (i - index)}%)`;
            });
            currentPage = index;
            updatePageInfo();
            updateButtonStates();
        }

        function updatePageInfo() {
            const infoText = `Page ${currentPage + 1} of ${pages.length}`;
            pageInfo.textContent = infoText;
        }

        function updateButtonStates() {
            prevBtn.disabled = currentPage === 0;
            nextBtn.disabled = currentPage === pages.length - 1;
        }

        function selectColor(color) {
            if (selectedRange) {
                let span = document.createElement('span');
                span.className = 'highlight';
                span.style.backgroundColor = color;
                selectedRange.surroundContents(span);

                const highlight = {
                    text: selectedText,
                    color: color,
                    page: currentPage + 1,
                    span: span
                };

                undoStack.push({ type: 'highlight', data: highlight });
                redoStack = [];
                userData.highlights.push(highlight);
                saveUserData();
                updateUndoRedoButtons();

            }
        }

function updateUndoRedoButtons() {
    const undoButton = document.getElementById('undoButton');
    const redoButton = document.getElementById('redoButton');

    if (undoButton) {
        undoButton.disabled = window.undoStack.length === 0;
    }
    if (redoButton) {
        redoButton.disabled = window.redoStack.length === 0;
    }
}

window.processText = function(feature, event) {

    if (event) {
        event.preventDefault(); 
        event.stopPropagation();
    }

    const loadingOverlay = document.getElementById('ai-loading-overlay');
    const loadingMessage = document.getElementById('ai-loading-message');

    const loadingIndicator = document.getElementById('loadingIndicator');

    if (!loadingOverlay || !loadingMessage || !loadingIndicator) {
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
    loadingIndicator.style.display = 'block';

    dpCounts[feature]++;

    const selectedText = window.getSelection().toString().trim();

    if (!selectedText) {
        console.error('No text selected');
        loadingOverlay.style.display = 'none';
        loadingIndicator.style.display = 'none';
        alert('Please select some text before processing.');
        return;
    }

    const selectedRange = window.getSelection().getRangeAt(0);
    const paragraph = getEntireParagraph(selectedRange);
    const currentTask = getCurrentTask();
    const currentPage = getCurrentPage();

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
    .then(processedText => {

        loadingOverlay.style.display = 'none';
        loadingIndicator.style.display = 'none';

        if (feature === 'dp4') {
            showAnalysisInterface(processedText);
        } else {
            processedText = processedText.replace(/\*([^*]+)\*/g, '<strong>$1</strong>');
            try {
                const container = window.insertProcessedContent(selectedRange, processedText, feature === 'dp1');

                window.lastProcessedContainer = container;

                const floatingDialog = document.getElementById('floatingDialog');
                if (floatingDialog) {
                    floatingDialog.style.display = 'none';

                } else {
                    console.error('floatingDialog is null');
                }
            } catch (error) {
                console.error('Error in insertProcessedContent:', error);
                alert('An error occurred while inserting the processed content. Please try again.');
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
        loadingIndicator.style.display = 'none';

        alert('An error occurred while processing the text. Please try again.');
    });
}
function getEntireParagraph(range) {
    let node = range.startContainer;
    while (node && node.nodeName !== 'P') {
        node = node.parentNode;
    }
    return node ? node.textContent : range.toString();
}

window.insertProcessedChat = function(selectedText, prompts) {

    const container = document.createElement('div');
    container.className = 'analysis-chat-container';

    Object.assign(container.style, {
        position: 'absolute',
        top: '-120px',
        left: '50%',
        transform: 'translateX(-50%)',
        width: '400px', 
        backgroundColor: 'white',
        border: '1px solid #e0e0e0',
        borderRadius: '8px',
        boxShadow: '0 2px 10px rgba(0, 0, 0, 0.1)',
        padding: '12px',
        fontSize: '14px',
        lineHeight: '1.4',
        color: 'black',
        zIndex: '1000',
        display: 'flex',
        flexDirection: 'column',
        maxHeight: '300px', 
        overflow: 'hidden'
    });

    const closeButton = document.createElement('button');
    closeButton.innerHTML = '&times;';
    Object.assign(closeButton.style, {
        position: 'absolute',
        top: '4px',
        right: '4px',
        background: 'none',
        border: 'none',
        fontSize: '20px',
        cursor: 'pointer',
        color: '#555',
        padding: '0',
        width: '20px',
        height: '20px',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        borderRadius: '50%'
    });
    closeButton.onclick = () => container.remove();
    container.appendChild(closeButton);

    const promptsContainer = document.createElement('div');
    promptsContainer.className = 'predefined-prompts';
    Object.assign(promptsContainer.style, {
        display: 'flex',
        flexDirection: 'column',
        gap: '8px',
        marginTop: '8px',
        overflowY: 'auto',
        maxHeight: '250px' 
    });

    prompts.split('\n').forEach((prompt, index) => {
        prompt = prompt.trim().replace(/^\d+\.\s*/, '');
        if (prompt) {
            const promptButton = document.createElement('button');
            promptButton.className = 'predefined-prompt';
            promptButton.textContent = prompt;

            Object.assign(promptButton.style, {
                background: 'rgb(32 38 84)',
                border: '1px solid #202654',
                borderRadius: '8px',
                padding: '10px',
                fontSize: '13px',
                cursor: 'pointer',
                transition: 'all 0.2s ease',
                textAlign: 'left',
                wordWrap: 'break-word',
                whiteSpace: 'normal',
                width: '100%',
                position: 'relative',
                paddingRight: '30px' 
            });

            const chatIcon = document.createElement('i');
            chatIcon.className = 'fas fa-comment';
            Object.assign(chatIcon.style, {
                position: 'absolute',
                right: '10px',
                top: '50%',
                transform: 'translateY(-50%)',
                color: '#fff'
            });
            promptButton.appendChild(chatIcon);

            promptButton.onmouseover = () => promptButton.style.backgroundColor = 'rgb(32 38 84)';
            promptButton.onmouseout = () => promptButton.style.backgroundColor = 'rgb(32, 38, 84)';
            promptButton.onclick = function() {
                sendToChatAndNotify(prompt);
                container.remove();
            };
            promptsContainer.appendChild(promptButton);
        }
    });
    container.appendChild(promptsContainer);

    const selection = window.getSelection();
    if (selection.rangeCount > 0) {
        const range = selection.getRangeAt(0);
        const rect = range.getBoundingClientRect();
        container.style.left = `${rect.left + window.scrollX + (rect.width / 2)}px`;
        container.style.top = `${rect.top + window.scrollY - 130}px`;
    }

    document.body.appendChild(container);

    return container;
};

function setupEnhancedChatInterface() {
    const sidebar = document.querySelector('.study-sidebar');
    if (!sidebar) {
        console.error('Sidebar not found');
        return;
    }

    let chatContainer = sidebar.querySelector('.study-chat-container');
    if (!chatContainer) {
        chatContainer = document.createElement('div');
        chatContainer.className = 'study-chat-container';
        sidebar.appendChild(chatContainer);
    }

    Object.assign(chatContainer.style, {
        marginTop: 'auto',
        width: '100%',
        backgroundColor: 'white',
        borderTop: '1px solid #e0e0e0',
        transition: 'height 0.3s ease',
        display: 'flex',
        flexDirection: 'column',
        overflow: 'hidden'
    });

    const chatHeader = document.createElement('div');
    Object.assign(chatHeader.style, {
        padding: '4.5px',
        backgroundColor: '#202654',
        color: 'white',
        display: 'flex',
        justifyContent: 'space-between',
        alignItems: 'center',
        cursor: 'pointer'
    });
    chatHeader.innerHTML = `
        <span>Chat Assistant</span>
        <div>
            <button id="clearChatBtn" style="background: none; border: none; color: white; margin-right: 10px;">
                <i class="fas fa-trash"></i>
            </button>
            <button id="toggleChatBtn" style="background: none; border: none; color: white;">
                <i class="fas fa-chevron-up"></i>
            </button>
        </div>
    `;
    chatContainer.prepend(chatHeader);

    let chatMessages = chatContainer.querySelector('#study-chat-messages');
    if (!chatMessages) {
        chatMessages = document.createElement('div');
        chatMessages.id = 'study-chat-messages';
        chatContainer.appendChild(chatMessages);
    }

    let chatInput = chatContainer.querySelector('.study-chat-input');
    if (!chatInput) {
        chatInput = document.createElement('div');
        chatInput.className = 'study-chat-input';
        chatInput.innerHTML = `
            <input type="text" id="study-chat-input" placeholder="Type your message...">
            <button onclick="sendMessage()">Send</button>
        `;
        chatContainer.appendChild(chatInput);
    }

   if (chatMessages) {
        Object.assign(chatMessages.style, {
            flex: '1',
            overflowY: 'auto',
            padding: '10px',
            display: 'block',
            maxHeight: '300px'
        });
    }

    if (chatInput) {
        Object.assign(chatInput.style, {
            padding: '10px',
            borderTop: '1px solid #eee',
            display: 'flex'
        });
    }

    function toggleChat() {
        const isCollapsed = chatContainer.style.height === '40px';
        chatContainer.style.height = isCollapsed ? '350px' : '40px';
        chatMessages.style.display = isCollapsed ? 'block' : 'none';
        chatInput.style.display = isCollapsed ? 'flex' : 'none';
        document.getElementById('toggleChatBtn').innerHTML = `<i class="fas fa-chevron-${isCollapsed ? 'down' : 'up'}"></i>`;
    }

    function clearChat() {
        chatMessages.innerHTML = '';
    }

    chatHeader.querySelector('#toggleChatBtn').addEventListener('click', toggleChat);
    chatHeader.querySelector('#clearChatBtn').addEventListener('click', clearChat);

    chatContainer.style.height = '350px';  
    chatMessages.style.display = 'block';
    chatInput.style.display = 'flex';
}

function sendToChatAndNotify(message) {
    const chatInput = document.getElementById('study-chat-input');
    if (chatInput) {
        chatInput.value = message;
        chatInput.focus();

        const chatContainer = document.querySelector('.study-chat-container');
        if (chatContainer) {
            chatContainer.scrollIntoView({ behavior: 'smooth', block: 'end' });
            if (typeof window.toggleChat === 'function') {
                window.toggleChat(); 
            } else {
                console.error('toggleChat function not found');
            }
        }

            window.toggleChat = function() {
        const isCollapsed = chatContainer.style.height === '40px';
        chatContainer.style.height = isCollapsed ? '350px' : '40px';
        chatMessages.style.display = isCollapsed ? 'block' : 'none';
        chatInput.style.display = isCollapsed ? 'flex' : 'none';
        document.getElementById('toggleChatBtn').innerHTML = `<i class="fas fa-chevron-${isCollapsed ? 'down' : 'up'}"></i>`;
    };

        showNotification('Prompt added to chat. Feel free to modify or send as is!', 'info', 5000);
    } else {
        console.error('Chat input not found');
    }
}

function showNotification(message, type = 'info', duration = 3000) {
    const notification = document.createElement('div');
    notification.textContent = message;
    Object.assign(notification.style, {
        position: 'fixed',
        bottom: '20px',
        left: '50%',
        transform: 'translateX(-50%)',
        backgroundColor: type === 'info' ? '#202654' : '#d32f2f',
        color: 'white',
        padding: '8px 16px',
        borderRadius: '4px',
        boxShadow: '0 2px 5px rgba(0,0,0,0.2)',
        zIndex: '1001',
        opacity: '0',
        transition: 'opacity 0.3s ease',
        fontSize: '14px'
    });
    document.body.appendChild(notification);

    setTimeout(() => notification.style.opacity = '1', 10);
    setTimeout(() => {
        notification.style.opacity = '0';
        setTimeout(() => notification.remove(), 300);
    }, duration);
}
window.showAnalysisInterface = function(prompts) {

    window.insertProcessedChat(window.getSelection().toString(), prompts);
};

function sendMessage() {
    const chatInput = document.getElementById('study-chat-input');
    const chatMessages = document.getElementById('study-chat-messages');
    if (!chatInput || !chatMessages) {
        console.error('Chat elements not found');
        return;
    }

    const message = chatInput.value.trim();
    if (message) {

        addMessageToChatHistory(message, 'user');

        chatInput.value = '';

        const loadingDiv = document.createElement('div');
        loadingDiv.textContent = "AI is thinking...";
        loadingDiv.style.fontStyle = 'italic';
        loadingDiv.style.color = '#888';
        loadingDiv.style.padding = '8px';
        chatMessages.appendChild(loadingDiv);

        const currentTask = getCurrentTask();

        fetch(window.location.href, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `chat_message=${encodeURIComponent(message)}&current_task=${currentTask}`
        })
        .then(response => response.json())
        .then(data => {

            chatMessages.removeChild(loadingDiv);

            addMessageToChatHistory(data.response, 'ai');
      logInteraction('chat_message', {
            userInput: message,
            aiResponse: data.response,
            task: getCurrentTask(),
            page: getCurrentPage()
        });

        })
        .catch(error => {

            chatMessages.removeChild(loadingDiv);

            const errorDiv = document.createElement('div');
            errorDiv.textContent = "Sorry, there was an error processing your request.";
            errorDiv.style.color = 'red';
            errorDiv.style.padding = '8px';
            chatMessages.appendChild(errorDiv);
            console.error('Error:', error);
        })
        .finally(() => {
            chatMessages.scrollTop = chatMessages.scrollHeight;
        });
    }
}

        function addMessageToChatHistory(message, sender) {
    const chatMessages = document.getElementById('study-chat-messages');
    const messageDiv = document.createElement('div');
    messageDiv.textContent = message;
    Object.assign(messageDiv.style, {
        padding: '8px',
        borderRadius: '12px',
        marginBottom: '8px',
        maxWidth: '80%',
        wordWrap: 'break-word'
    });

    if (sender === 'user') {
        messageDiv.style.backgroundColor = '#e6f2ff';
        messageDiv.style.marginLeft = 'auto';
    } else {
        messageDiv.style.backgroundColor = '#f0f0f0';
    }

    chatMessages.appendChild(messageDiv);
    chatMessages.scrollTop = chatMessages.scrollHeight;
}

function getCurrentTask() {
    const taskInfo = document.getElementById('study-task-info');
    if (taskInfo) {
        const match = taskInfo.textContent.match(/Task (\d+) of 3/);
        return match ? match[1] : '1';
    }
    return '1';
}

document.addEventListener('DOMContentLoaded', setupEnhancedChatInterface);

function typeText(element, text, speed = 10, highlightColor = 'rgba(255, 255, 0, 0.3)') {
    return new Promise((resolve) => {
        let i = 0;
        element.style.backgroundColor = highlightColor;
        function type() {
            if (i < text.length) {
                element.innerHTML += text.charAt(i);
                i++;
                setTimeout(type, speed);
            } else {
                resolve();
            }
        }
        type();
    });
}

function eraseText(element, speed = 10) {
    return new Promise((resolve) => {
        function erase() {
            if (element.textContent.length > 0) {
                element.textContent = element.textContent.slice(0, -1);
                setTimeout(erase, speed);
            } else {
                resolve();
            }
        }
        erase();
    });
}

window.saveProcessedText = function(range, processedText) {

    if (!range) {
        console.error('Range is null');
        return;
    }

    const originalContent = range.cloneContents();
    const tempDiv = document.createElement('div');
    tempDiv.appendChild(originalContent);
    const originalHTML = tempDiv.innerHTML;

    const processedSpan = document.createElement('span');
    processedSpan.className = 'processed-content';
    processedSpan.style.backgroundColor = 'rgba(255, 255, 0, 0.3)'; 

    range.deleteContents();
    range.insertNode(processedSpan);

    typeText(processedSpan, processedText, 10, 'rgba(255, 255, 0, 0.3)').then(() => {
        const undoAction = {
            range: range.cloneRange(),
            originalHTML: originalHTML,
            processedHTML: processedSpan.outerHTML
        };

        window.undoStack.push(undoAction);
        updateUndoRedoButtons();
        logInteraction('save_processed_text');
    });
}

function getCurrentPage() {
    const pageContainers = document.querySelectorAll('.page-container');
    for (let i = 0; i < pageContainers.length; i++) {
        if (isElementInViewport(pageContainers[i])) {
            return (i + 1).toString();
        }
    }
    return '1';
}

function isElementInViewport(el) {
    const rect = el.getBoundingClientRect();
    return (
        rect.top >= 0 &&
        rect.left >= 0 &&
        rect.bottom <= (window.innerHeight || document.documentElement.clientHeight) &&
        rect.right <= (window.innerWidth || document.documentElement.clientWidth)
    );
}
function getNodesInRange(range) {

    const nodes = [];
    const nodeIterator = document.createNodeIterator(
        range.commonAncestorContainer,
        NodeFilter.SHOW_TEXT,
        { acceptNode: node => {

            return NodeFilter.FILTER_ACCEPT;
        }}
    );

    let node;
    while (node = nodeIterator.nextNode()) {

        if (range.intersectsNode(node)) {

            nodes.push(node);
        }
    }

    return nodes;
}

window.undo = function() {
    if (window.undoStack.length > 0) {
        const action = window.undoStack.pop();
        const range = action.range;

        window.redoStack.push({
            range: range.cloneRange(),
            originalHTML: range.cloneContents(),
            processedHTML: action.processedHTML
        });

        range.deleteContents();
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = action.originalHTML;
        while (tempDiv.firstChild) {
            range.insertNode(tempDiv.firstChild);
        }

        updateUndoRedoButtons();

        showNotification('Undo successful', 'info', 2000);

        logInteraction('undo');
    } else {
        showNotification('Nothing to undo', 'info', 2000);
    }
}

window.redo = function() {
    if (window.redoStack.length > 0) {
        const action = window.redoStack.pop();
        const range = action.range;

        window.undoStack.push({
            range: range.cloneRange(),
            originalHTML: range.cloneContents(),
            processedHTML: action.processedHTML
        });

        range.deleteContents();
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = action.processedHTML;
        while (tempDiv.firstChild) {
            range.insertNode(tempDiv.firstChild);
        }

        updateUndoRedoButtons();

        showNotification('Redo successful', 'info', 2000);

        logInteraction('redo');
    } else {
        showNotification('Nothing to redo', 'info', 2000);
    }
}

window.updateUndoRedoButtons = function() {
    const undoButton = document.getElementById('undoButton');
    const redoButton = document.getElementById('redoButton');

    if (undoButton) {
        undoButton.disabled = window.undoStack.length === 0;
    }
    if (redoButton) {
        redoButton.disabled = window.redoStack.length === 0;
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const undoButton = document.getElementById('undoButton');
    const redoButton = document.getElementById('redoButton');

    if (undoButton) {
        undoButton.addEventListener('click', function(e) {
            e.stopPropagation();
            window.undo();
        });
    }

    if (redoButton) {
        redoButton.addEventListener('click', function(e) {
            e.stopPropagation();
            window.redo();
        });
    }

    if (typeof window.undoStack === 'undefined') {
        window.undoStack = [];
    }
    if (typeof window.redoStack === 'undefined') {
        window.redoStack = [];
    }

    updateUndoRedoButtons();
});

    if (typeof window.undoStack === 'undefined') window.undoStack = [];
    if (typeof window.redoStack === 'undefined') window.redoStack = [];
    if (typeof window.userData === 'undefined') window.userData = { adaptations: [] };
    if (typeof window.currentPage === 'undefined') window.currentPage = 0;

    if (typeof window.updateUndoRedoButtons !== 'function') {
        window.updateUndoRedoButtons = function() {

        };
    }

    if (typeof window.saveUserData !== 'function') {
        window.saveUserData = function() {

        };
    }

    if (typeof window.logInteraction !== 'function') {
        window.logInteraction = function(action) {

        };
    }

        function saveUserData() {
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=saveData&data=' + encodeURIComponent(JSON.stringify(userData)) + '&userId=' + encodeURIComponent(userId)
            });
        }

        function getTextNodes(node) {
            let textNodes = [];
            if (node.nodeType === Node.TEXT_NODE) {
                textNodes.push(node);
            } else {
                for (let childNode of node.childNodes) {
                    textNodes.push(...getTextNodes(childNode));
                }
            }
            return textNodes;
        }

        document.addEventListener('mouseup', function(e) {
            if (isDialogExpanded || isDragging) {
                return; 
            }
            let selection = window.getSelection();
            selectedText = selection.toString().trim(); 

            if (selectedText.length > 0) {
                selectedRange = selection.getRangeAt(0);

                const rect = selectedRange.getBoundingClientRect();

                if (floatingDialog) {

                    floatingDialog.style.display = 'block';
                    floatingDialog.style.left = `${rect.left + window.scrollX}px`;
                    floatingDialog.style.top = `${rect.bottom + window.scrollY}px`;
                    floatingDialog.classList.remove('expanded');

                    const analysisInterface = document.getElementById('analysisInterface');
                    const processedText = document.getElementById('processedText');
                    if (analysisInterface) analysisInterface.style.display = 'none';
                    if (processedText) processedText.style.display = 'none';
                    if (colorOptions) colorOptions.style.display = 'block';

                    const featureOptions = document.querySelector('.feature-options');
                    if (featureOptions) featureOptions.style.display = 'flex';

                    isDialogExpanded = false;
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
                    undo();
                } else if (e.key === 'y' || (e.shiftKey && e.key === 'Z')) {
                    e.preventDefault();
                    redo();
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

    </script>
    <script>var taskContent = <?php echo json_encode($task_content); ?>;
</script>
<script>

document.addEventListener('DOMContentLoaded', function () {

    if (document.querySelector('.study-container')) {

        const style = document.createElement('style');
        style.textContent = `
            .shepherd-element {
                background: #ffffff;
                color: #202654;
                border-radius: 4px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                max-width: 280px;
                z-index: 9999;
                font-family: 'Roboto', sans-serif;
            }
            .shepherd-text {
                padding: 0.75rem;
                font-size: 14px;
                line-height: 1.4;
                min-height: 60px;
            }
            .shepherd-button {
                background: #174ea6;
                color: white;
                border: none;
                border-radius: 3px;
                padding: 0.4rem 0.8rem;
                margin: 0.25rem;
                cursor: pointer;
                transition: background 0.2s;
                font-size: 12px;
            }
            .shepherd-button:hover {
                background: #1a5fcc;
            }
            .shepherd-arrow:before {
                background: #ffffff;
            }
            .shepherd-modal-overlay-container {
                background: rgba(0, 0, 0, 0.4);
            }
            .shepherd-title {
                font-size: 16px;
                font-weight: bold;
                margin-bottom: 0.5rem;
            }
            .shepherd-cancel-icon {
                color: #202654;
                font-size: 20px;
                font-weight: bold;
                opacity: 0.7;
                transition: opacity 0.2s;
            }
            .shepherd-cancel-icon:hover {
                opacity: 1;
            }
            .typing-effect::after {
                content: '|';
                animation: blink 0.7s infinite;
            }
            @keyframes blink {
                0%, 100% { opacity: 1; }
                50% { opacity: 0; }
            }
        `;
        document.head.appendChild(style);

        let typingTimer;

        function typeEffect(element, text, speed = 30) {
            return new Promise((resolve) => {
                let i = 0;
                if (typingTimer) clearInterval(typingTimer);
                element.innerHTML = '';
                element.classList.add('typing-effect');
                typingTimer = setInterval(() => {
                    if (i < text.length) {
                        element.innerHTML += text.charAt(i);
                        i++;
                    } else {
                        clearInterval(typingTimer);
                        element.classList.remove('typing-effect');
                        resolve();
                    }
                }, speed);
            });
        }

        const tour = new Shepherd.Tour({
            useModalOverlay: true,
            defaultStepOptions: {
                cancelIcon: {
                    enabled: true
                },
                classes: 'shepherd-theme-custom',
                scrollTo: { behavior: 'smooth', block: 'center' }
            }
        });

        const addStep = (id, title, text, element, position, isFirst = false) => {
            tour.addStep({
                id: id,
                title: title,
                text: isFirst ? ' ' : text,
                attachTo: {
                    element: element,
                    on: position
                },
                buttons: [
                    {
                        text: 'Back',
                        action: tour.back,
                        classes: 'shepherd-button-secondary'
                    },
                    {
                        text: 'Next',
                        action: tour.next
                    }
                ],
                beforeShow: () => {
                    const textElement = document.querySelector('.shepherd-text');
                    if (textElement) {
                        textElement.innerHTML = isFirst ? '' : text;
                        textElement.style.visibility = 'visible';
                        textElement.style.opacity = '1';
                    }
                },
                when: {
                    show: () => {
                        const textElement = document.querySelector('.shepherd-text');
                        if (textElement && isFirst) {
                            typeEffect(textElement, text);
                        }
                    }
                }
            });
        };

        addStep('welcome', 'Welcome', 'Welcome to the Reading Comprehension Study. Let\'s explore the main features.', '.study-main-content', 'bottom', true);
        addStep('task-info', 'Task Progress', 'Here you can see your current task progress and timer.', '#study-task-info', 'right');
        addStep('text-processing', 'Text Processing', 'Select text to use these options: Simplify, Structure, Essential, or Analyze.', '#floatingDialog', 'left');
        addStep('chat-assistant', 'Chat Assistant', 'Use the chat to ask questions about the content.', '.study-chat-container', 'top');
        addStep('navigation', 'Navigation', 'Use these buttons to navigate between tasks.', '#study-task-navigation', 'top');

        tour.steps[0].options.buttons = [{ text: 'Next', action: tour.next }];
        tour.steps[tour.steps.length - 1].options.buttons = [
            { text: 'Back', action: tour.back, classes: 'shepherd-button-secondary' },
            { text: 'Finish', action: tour.complete }
        ];

        if (!sessionStorage.getItem('tourCompleted')) {
            tour.start();
            sessionStorage.setItem('tourCompleted', 'true');
        }

        const restartTourBtn = document.createElement('button');
        restartTourBtn.textContent = 'Restart Tour';
        restartTourBtn.style.cssText = `
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 1000;
            background: #174ea6;
            color: white;
            border: none;
            border-radius: 3px;
            padding: 8px 12px;
            cursor: pointer;
            font-size: 12px;
            transition: background 0.2s;
        `;
        restartTourBtn.addEventListener('mouseover', () => {
            restartTourBtn.style.background = '#1a5fcc';
        });
        restartTourBtn.addEventListener('mouseout', () => {
            restartTourBtn.style.background = '#174ea6';
        });
        restartTourBtn.addEventListener('click', () => {
            tour.start();
        });
        document.body.appendChild(restartTourBtn);
    } else {
        console.error('.study-container not found');
    }
});

           mdc.autoInit();

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
                    window.location.href = 'https://ww2.unipark.de/uc/expgroup/';
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

 document.addEventListener('DOMContentLoaded', function() {
    const floatingMenu = document.querySelector('#floatingDialog');
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
<div id="ai-loading-overlay" style="display: none;">
  <div class="ai-loading-content">
    <svg viewBox="0 0 100 100">
      <g fill="none" stroke="#202654" stroke-linecap="round" stroke-linejoin="round" stroke-width="6">
        <!-- left line -->
        <path d="M 21 40 V 59">
          <animateTransform
            attributeName="transform"
            attributeType="XML"
            type="rotate"
            values="0 21 59; 180 21 59"
            dur="2s"
            repeatCount="indefinite" />
        </path>
        <!-- right line -->
        <path d="M 79 40 V 59">
          <animateTransform
            attributeName="transform"
            attributeType="XML"
            type="rotate"
            values="0 79 59; -180 79 59"
            dur="2s"
            repeatCount="indefinite" />
        </path>
        <!-- top line -->
        <path d="M 50 21 V 40">
          <animate
            attributeName="d"
            values="M 50 21 V 40; M 50 59 V 40"
            dur="2s"
            repeatCount="indefinite" />
        </path>
        <!-- btm line -->
        <path d="M 50 60 V 79">
          <animate
            attributeName="d"
            values="M 50 60 V 79; M 50 98 V 79"
            dur="2s"
            repeatCount="indefinite" />
        </path>
        <!-- top box -->
        <path d="M 50 21 L 79 40 L 50 60 L 21 40 Z">
          <animate
            attributeName="stroke"
            values="rgba(32,38,84,1); rgba(100,100,100,0)"
            dur="2s"
            repeatCount="indefinite" />
        </path>
        <!-- mid box -->
        <path d="M 50 40 L 79 59 L 50 79 L 21 59 Z"/>
        <!-- btm box -->
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

<style>
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

  #ai-loading-overlay svg {
    height: 150px;
    width: 150px;
  }

  #ai-loading-message {
    margin-top: 20px;
    font-size: 18px;
    color: #202654;
    font-weight: bold;
  }
</style>

   </body>
</html>
