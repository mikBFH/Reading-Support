
# Reading Comprehension Study

This code base is designed to conduct a reading comprehension study, providing an interactive environment for participants to read passages, answer questions, and utilize AI-assisted tools for text analysis.

## Features

1. User Registration through prolific and Demographics Collection
2. Multiple Reading Tasks with Timed Sections
3. Interactive Text Processing Tools (in experimental condition):
   - Simplify
   - Structure
   - Essential
   - Analyze
4. AI-Powered Chat Assistant
5. Progress Tracking and Task Navigation
6. Result Summary and Detailed Feedback

## Main Components

- `experiment.php`: Main file containing all the logic and HTML structure for the study
- `baseline.php`: Version with only the DP4 feature (chat about the document)
- `control.php`: Basic version without AI-assisted features
- `demo.php`: Demonstration version with full features applied to a PDF document

## Setup

1. Ensure you have PHP and MySQL installed on your server.
2. Update the database connection details in the `experiment.php` file.
3. Place all PHP files in your web server directory.
4. Ensure the `uploads` directory is writable by the web server.

## Dependencies

- Material Design Components (MDC) Web
- Font Awesome
- Shepherd.js for the guided tour
- pdf.js (for demo.php)

## File Structure

- `experiment.php`: Main study implementation
- `baseline.php`: Baseline version with limited features
- `control.php`: Control version without AI features
- `demo.php`: Demonstration version with PDF support
- `test/questions_answers.json`: JSON file containing questions and answers for tasks

## Usage

1. Participants start by registering and providing demographic information.
2. They are then guided through a series of reading tasks.
3. For each task, participants can use text processing tools and the AI chat assistant (in experimental condition).
4. After completing all tasks, participants are shown their results.

## Demo Version

The demo version (`demo.php`) is available at https://readingparadox.com/public_html/tools/democopy.php. This version demonstrates the full feature set of the experiment applied to a PDF document, allowing potential participants or researchers to experience the tool's capabilities.

## Key Components

- User Authentication
- Task Timer
- Text Selection and Processing (experimental condition)
- AI-powered Chat Interface
- Progress Tracking
- Result Calculation and Display
- PDF Support (in demo version)

## Security Notes

- Ensure to implement proper security measures, including input validation and SQL injection prevention.
- Protect sensitive information and API keys.

## Customization

- Task content and duration can be modified in the `$task_content` and `taskDurations` variables.
- Styling can be adjusted in the embedded CSS within the HTML.

## Browser Compatibility

- The platform includes a browser check feature, recommending Google Chrome for optimal performance.

## Support

For any issues or questions, please contact: mik1@bfh.ch

---

Note: This README is based on the provided information about the project structure. Adjust as necessary if there are additional files or setup steps not mentioned.
